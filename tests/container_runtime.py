"""Real Docker startup, browser access, persistence and shutdown checks before publication."""
import base64
import hashlib
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
        status, body, final_url = request(opener, origin, "/media/user/login", {
            "username": "admin", "password": "A123456"
        })
        assert status == 200 and "/media/user/login" not in final_url, "Administrator login/session failed"
        status, body, _ = request(opener, origin, "/media/admin/setting", {"siteName": "Direct Port Smoke"})
        assert status == 200 and json.loads(body)["code"] == 200, "Admin setting did not persist"
        assert websocket(port), "Same-port WebSocket upgrade failed"

        docker("stop", "--time", "10", name)
        assert state(name)["ExitCode"] == 0, "Normal shutdown required a kill or returned an error"
        docker("rm", name)
        _, origin, opener = start()
        assert b"Direct Port Smoke" in request(opener, origin, "/media/user/login")[1], "Database setting lost after recreation"

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
