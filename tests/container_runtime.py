"""Real Docker startup, browser access, persistence and shutdown checks before publication."""
import base64
import hashlib
import html
from html.parser import HTMLParser
import http.cookiejar
import json
import re
import socket
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid


def docker(*arguments, check=True):
    return subprocess.run(
        ["docker", *arguments], check=check, text=True, capture_output=True, timeout=45
    ).stdout.strip()


def state(name):
    return json.loads(docker("inspect", name))[0]["State"]


def wait_for(name, condition, message, seconds=90):
    deadline = time.monotonic() + seconds
    while time.monotonic() < deadline:
        if condition():
            return
        if not state(name)["Running"]:
            raise AssertionError("Container exited before " + message)
        time.sleep(0.5)
    raise AssertionError("Timed out waiting for " + message)


def request(opener, origin, path, data=None):
    if data is not None:
        data = urllib.parse.urlencode(data).encode()
    req = urllib.request.Request(origin + path, data, headers={"User-Agent": "ContainerSmoke/1"})
    try:
        with opener.open(req, timeout=5) as response:
            return response.status, response.read(), response.geturl()
    except urllib.error.HTTPError as response:
        return response.code, response.read(), response.geturl()


def websocket(port):
    key = base64.b64encode(b"container-smoke!").decode()
    handshake = (
        "GET /ws HTTP/1.1\r\n"
        f"Host: 127.0.0.1:{port}\r\n"
        "Connection: Upgrade\r\nUpgrade: websocket\r\n"
        f"Sec-WebSocket-Key: {key}\r\nSec-WebSocket-Version: 13\r\n\r\n"
    )
    with socket.create_connection(("127.0.0.1", port), timeout=5) as connection:
        connection.sendall(handshake.encode())
        headers = b""
        while b"\r\n\r\n" not in headers:
            chunk = connection.recv(4096)
            if not chunk:
                break
            headers += chunk
        expected = base64.b64encode(
            hashlib.sha1((key + "258EAFA5-E914-47DA-95CA-C5AB0DC85B11").encode()).digest()
        )
        return headers.startswith(b"HTTP/1.1 101") and expected.lower() in headers.lower()


def profile_token(body):
    class Inputs(HTMLParser):
        token = None

        def handle_starttag(self, tag, attributes):
            attributes = dict(attributes)
            if tag == "input" and attributes.get("name") == "profileToken":
                self.token = attributes.get("value")

    inputs = Inputs()
    inputs.feed(body.decode("utf-8"))
    assert inputs.token, "Profile must include a form token"
    return inputs.token


def main(image):
    suffix = uuid.uuid4().hex[:12]
    name = "emby-http-smoke-" + suffix
    volume = name + "-data"
    docker("volume", "create", volume)
    try:
        def start():
            docker(
                "run", "-d", "--name", name,
                "--mount", f"type=volume,source={volume},target=/app/data",
                "-p", "127.0.0.1::8018", image,
            )
            details = json.loads(docker("inspect", name))[0]
            assert details["Config"].get("StopSignal") == "SIGTERM", "Supervisor must receive TERM on docker stop"
            port = int(details["NetworkSettings"]["Ports"]["8018/tcp"][0]["HostPort"])
            assert not details["NetworkSettings"]["Ports"].get("9000/tcp")
            assert not details["NetworkSettings"]["Ports"].get("2347/tcp")
            origin = "http://127.0.0.1:" + str(port)
            opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

            def ready():
                try:
                    return request(opener, origin, "/user/login")[0] == 200
                except (OSError, urllib.error.URLError):
                    return False

            wait_for(name, ready, "HTTP login page")
            wait_for(name, lambda: state(name).get("Health", {}).get("Status") == "healthy", "HTTP health check")
            return port, origin, opener

        port, origin, opener = start()
        for path in ["/", "/?entrypoint=query"]:
            status, body, final_url = request(opener, origin, path)
            assert status == 200 and final_url == origin + path and b'id="menuButton"' in body, "Website root must directly render the media home page"
            assert re.search(rb'''["']/media(?:/|["'])''', body) is None, "Home page links must use root application routes"
        status, body, _ = request(opener, origin, "/api/index/ping")
        assert status == 200 and json.loads(body)["msg"] == "pong", "API route must remain available"
        status, body, _ = request(opener, origin, "/assets/index/css/layui.css")
        assert status == 200 and b"layui" in body, "Static resource was not served"
        for path in ["/.env", "/data/emby-controller.sqlite", "/router.php"]:
            assert request(opener, origin, path)[0] in (403, 404), "Private path was exposed: " + path
        status, body, _ = request(opener, origin, "/server/redeemCode", {"code": "MISSING"})
        assert status == 401 and json.loads(body)["code"] == 401, "Anonymous redemption must return JSON 401"
        status, body, final_url = request(opener, origin, "/user/login", {
            "username": "admin", "password": "A123456"
        })
        assert status == 200 and "/user/login" not in final_url, "Administrator login/session failed"
        currency = '积分<&"'
        status, body, _ = request(opener, origin, "/admin/setting", {
            "siteName": "Direct Port Smoke", "currencyName": currency
        })
        assert status == 200 and json.loads(body)["code"] == 200, "Admin setting did not persist"
        for path in ["/finance/user", "/admin/addExchangeCode"]:
            status, body, _ = request(opener, origin, path)
            assert status == 200 and html.escape(currency).encode() in body, "Currency/template render failed: " + path
        status, body, _ = request(opener, origin, "/server/redeemCode")
        assert json.loads(body)["code"] == 405, "GET must not redeem a code"
        status, body, _ = request(opener, origin, "/admin/addExchangeCode", {
            "mode": "batch", "exchangeType": "4", "exchangeCount": "12.34", "generateCount": "2"
        })
        generated = json.loads(body)
        assert status == 200 and generated["code"] == 200, "Balance codes were not generated"
        codes = generated["data"]["codes"]
        assert len(codes) == 2 and len(set(codes)) == 2, "Batch codes must be unique"
        status, body, _ = request(opener, origin, "/admin/exchangeCodeList")
        assert status == 200 and html.escape(currency).encode() in body, "Code list currency/template render failed"
        status, body, _ = request(opener, origin, "/server/redeemCode", {"code": codes[0]})
        redeemed = json.loads(body)
        assert status == 200 and redeemed["code"] == 200 and redeemed["rCoin"] == "12.34", "Balance redemption failed"
        assert currency in redeemed["message"], "Redemption must use the configured currency"
        status, body, _ = request(opener, origin, "/server/redeemCode", {"code": codes[0]})
        assert json.loads(body)["code"] == 400, "Code must only be redeemable once"
        assert websocket(port), "Same-port WebSocket upgrade failed"
        print("PASS: real HTTP/static/admin/session, currency, generation/single-use redemption and same-port WebSocket", flush=True)

        status, body, _ = request(opener, origin, "/user/userconfig")
        assert status == 200, "User settings did not render"
        token = profile_token(body)
        profile = {
            "username": "admin", "nickname": "admin", "email": "profile-smoke@example.com",
            "password": "", "currentPassword": "incorrect", "confirmPassword": "",
            "profileToken": token,
        }
        status, body, _ = request(opener, origin, "/user/update", profile)
        assert json.loads(body)["code"] == 400, "Incorrect current password allowed email change"
        profile["currentPassword"] = "A123456"
        status, body, _ = request(opener, origin, "/user/update", profile)
        assert json.loads(body)["code"] == 200, "Email change must work without SMTP"
        previous_session = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        status, body, final_url = request(previous_session, origin, "/user/login", {
            "username": profile["email"], "password": "A123456"
        })
        assert "/user/login" not in final_url, "Updated email could not log in"
        status, body, _ = request(opener, origin, "/user/userconfig")
        profile.update({"profileToken": profile_token(body), "password": "A234567", "confirmPassword": "A234567"})
        status, body, _ = request(opener, origin, "/user/update", profile)
        changed = json.loads(body)
        assert changed["code"] == 200 and changed["requireLogin"], "Password change must require login"
        for old_session in [opener, previous_session]:
            assert "/user/login" in request(old_session, origin, "/user/userconfig")[2], "Old session survived password change"
        status, body, final_url = request(opener, origin, "/user/login", {
            "username": "admin", "password": "A123456"
        })
        assert "/user/login" in final_url, "Old password still logs in"
        print("PASS: real email update without SMTP, new-email login, password change and old-session invalidation", flush=True)

        docker("stop", "--time", "10", name)
        stopped = state(name)
        assert stopped["ExitCode"] == 0, "Normal shutdown returned: " + json.dumps(stopped)
        docker("rm", name)
        _, origin, opener = start()
        assert b"Direct Port Smoke" in request(opener, origin, "/user/login")[1], "Database setting lost after recreation"
        status, body, final_url = request(opener, origin, "/user/login", {
            "username": "profile-smoke@example.com", "password": "A234567"
        })
        assert "/user/login" not in final_url, "Updated email/password lost after recreation"
        status, body, _ = request(opener, origin, "/finance/user")
        assert status == 200 and b"12.34" in body and html.escape(currency).encode() in body, "Currency/balance lost after recreation"
        status, body, _ = request(opener, origin, "/server/redeemCode", {"code": codes[0]})
        assert json.loads(body)["code"] == 400, "Used code status lost after recreation"
        status, body, _ = request(opener, origin, "/server/redeemCode", {"code": codes[1]})
        redeemed = json.loads(body)
        assert redeemed["code"] == 200 and redeemed["rCoin"] == "24.68", "Unused code/balance lost after recreation"

        # Caddy failing must terminate the whole container instead of leaving background workers alive.
        docker("exec", name, "pkill", "-TERM", "-x", "caddy")
        deadline = time.monotonic() + 15
        while state(name)["Running"] and time.monotonic() < deadline:
            time.sleep(0.5)
        assert not state(name)["Running"] and state(name)["ExitCode"] != 0, "HTTP entrypoint failure was not propagated"
        print("PASS: real container startup, HTTP/static/admin/session, same-port WebSocket, SQLite persistence, graceful shutdown and critical-service failure")
    except Exception:
        print(docker("logs", "--tail", "100", name, check=False), file=sys.stderr)
        raise
    finally:
        docker("rm", "-f", name, check=False)
        docker("volume", "rm", volume, check=False)


if __name__ == "__main__":
    main(sys.argv[1])
