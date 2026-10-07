"""Real Docker startup, browser access, persistence and shutdown checks before publication."""
import base64
import hashlib
import html
import http.cookiejar
import json
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
                    return request(opener, origin, "/media/user/login")[0] == 200
                except (OSError, urllib.error.URLError):
                    return False

            wait_for(name, ready, "HTTP login page")
            wait_for(name, lambda: state(name).get("Health", {}).get("Status") == "healthy", "HTTP health check")
            return port, origin, opener

        port, origin, opener = start()
        assert request(opener, origin, "/")[0] == 200, "Website root must be browsable"
        status, body, _ = request(opener, origin, "/assets/index/css/layui.css")
        assert status == 200 and b"layui" in body, "Static resource was not served"
        for path in ["/.env", "/data/emby-controller.sqlite", "/router.php"]:
            assert request(opener, origin, path)[0] in (403, 404), "Private path was exposed: " + path
        status, body, _ = request(opener, origin, "/media/server/redeemCode", {"code": "MISSING"})
        assert status == 401 and json.loads(body)["code"] == 401, "Anonymous redemption must return JSON 401"
        status, body, final_url = request(opener, origin, "/media/user/login", {
            "username": "admin", "password": "A123456"
        })
        assert status == 200 and "/media/user/login" not in final_url, "Administrator login/session failed"
        currency = '积分<&"'
        status, body, _ = request(opener, origin, "/media/admin/setting", {
            "siteName": "Direct Port Smoke", "currencyName": currency
        })
        assert status == 200 and json.loads(body)["code"] == 200, "Admin setting did not persist"
        for path in ["/media/finance/user", "/media/admin/addExchangeCode"]:
            status, body, _ = request(opener, origin, path)
            assert status == 200 and html.escape(currency).encode() in body, "Currency/template render failed: " + path
        status, body, _ = request(opener, origin, "/media/server/redeemCode")
        assert json.loads(body)["code"] == 405, "GET must not redeem a code"
        status, body, _ = request(opener, origin, "/media/admin/addExchangeCode", {
            "mode": "batch", "exchangeType": "4", "exchangeCount": "12.34", "generateCount": "2"
        })
        generated = json.loads(body)
        assert status == 200 and generated["code"] == 200, "Balance codes were not generated"
        codes = generated["data"]["codes"]
        assert len(codes) == 2 and len(set(codes)) == 2, "Batch codes must be unique"
        status, body, _ = request(opener, origin, "/media/admin/exchangeCodeList")
        assert status == 200 and html.escape(currency).encode() in body, "Code list currency/template render failed"
        status, body, _ = request(opener, origin, "/media/server/redeemCode", {"code": codes[0]})
        redeemed = json.loads(body)
        assert status == 200 and redeemed["code"] == 200 and redeemed["rCoin"] == "12.34", "Balance redemption failed"
        assert currency in redeemed["message"], "Redemption must use the configured currency"
        status, body, _ = request(opener, origin, "/media/server/redeemCode", {"code": codes[0]})
        assert json.loads(body)["code"] == 400, "Code must only be redeemable once"
        assert websocket(port), "Same-port WebSocket upgrade failed"
        print("PASS: real HTTP/static/admin/session, currency, generation/single-use redemption and same-port WebSocket", flush=True)

        docker("stop", "--time", "10", name)
        stopped = state(name)
        assert stopped["ExitCode"] == 0, "Normal shutdown returned: " + json.dumps(stopped)
        docker("rm", name)
        _, origin, opener = start()
        assert b"Direct Port Smoke" in request(opener, origin, "/media/user/login")[1], "Database setting lost after recreation"
        request(opener, origin, "/media/user/login", {"username": "admin", "password": "A123456"})
        status, body, _ = request(opener, origin, "/media/finance/user")
        assert status == 200 and b"12.34" in body and html.escape(currency).encode() in body, "Currency/balance lost after recreation"
        status, body, _ = request(opener, origin, "/media/server/redeemCode", {"code": codes[0]})
        assert json.loads(body)["code"] == 400, "Used code status lost after recreation"
        status, body, _ = request(opener, origin, "/media/server/redeemCode", {"code": codes[1]})
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
