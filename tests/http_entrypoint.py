"""Real Caddy/FastCGI/SQLite HTTP regression; only temporary files and loopback sockets.

Run in the image: python3 tests/http_entrypoint.py
Windows can pass --caddy, --php and --php-cgi to validate the same FastCGI protocol.
"""
import argparse
import base64
import hashlib
import http.client
import json
import os
from pathlib import Path
import re
import shutil
import socket
import socketserver
import sqlite3
import subprocess
import tempfile
import threading
import time
import urllib.parse


def expect(condition, message):
    if not condition:
        raise AssertionError(message)


def port():
    with socket.socket() as listener:
        listener.bind(("127.0.0.1", 0))
        return listener.getsockname()[1]


def php_string(value):
    return "'" + str(value).replace("\\", "\\\\").replace("'", "\\'") + "'"


def run(command, environment, cwd):
    result = subprocess.run(command, env=environment, cwd=cwd, capture_output=True, timeout=45)
    expect(result.returncode == 0, "Command failed: " + " ".join(command) + "\n" + result.stderr.decode(errors="replace"))
    return result.stdout


def wait_port(number, process):
    deadline = time.monotonic() + 15
    while time.monotonic() < deadline:
        expect(process.poll() is None, "HTTP/FastCGI process exited before listening")
        try:
            with socket.create_connection(("127.0.0.1", number), timeout=0.2):
                return
        except OSError:
            time.sleep(0.05)
    raise AssertionError("Process failed to listen on its test port")


class WebSocketStub(socketserver.BaseRequestHandler):
    def handle(self):
        self.request.settimeout(5)
        request = b""
        while b"\r\n\r\n" not in request:
            chunk = self.request.recv(4096)
            if not chunk:
                return
            request += chunk
        text = request.decode("ascii")
        if "Upgrade: websocket" not in text:
            self.request.sendall(b"HTTP/1.1 400 Bad Request\r\nContent-Length: 0\r\n\r\n")
            return
        key = next(line.split(":", 1)[1].strip() for line in text.split("\r\n") if line.lower().startswith("sec-websocket-key:"))
        accept = base64.b64encode(hashlib.sha1((key + "258EAFA5-E914-47DA-95CA-C5AB0DC85B11").encode()).digest())
        self.request.sendall(b"HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: " + accept + b"\r\n\r\n")
        self.request.sendall(b"\x81\x02ok")
        try:
            self.request.recv(4096)
        except OSError:
            pass


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--caddy", default=shutil.which("caddy"))
    parser.add_argument("--php", default=shutil.which("php"))
    parser.add_argument("--php-cgi", default=None)
    parser.add_argument("--php-ini", default=None)
    arguments = parser.parse_args()
    expect(arguments.caddy and arguments.php, "Caddy and PHP must be available")
    project = Path(__file__).resolve().parent.parent
    production_config = project / "docker/Caddyfile"
    if not production_config.is_file():
        production_config = Path("/etc/caddy/Caddyfile")
    expect(production_config.is_file(), "Production Caddyfile is missing")
    processes = []
    handles = []
    with tempfile.TemporaryDirectory(prefix="emby-http-entrypoint-") as temporary:
        root = Path(temporary)
        public = root / "public"
        for directory in [public, root / "config", root / "app", root / "database/migrations", root / "vendor"]:
            directory.mkdir(parents=True, exist_ok=True)
        for file in (project / "config").glob("*.php"):
            (root / "config" / file.name).write_text("<?php return require " + php_string(file.as_posix()) + ";", encoding="utf-8")
        (root / "app/provider.php").write_text("<?php return require " + php_string((project / "app/provider.php").as_posix()) + ";", encoding="utf-8")
        for name in ["event.php", "middleware.php", "common.php"]:
            source = project / "app" / name
            if source.exists():
                (root / "app" / name).write_text("<?php return require " + php_string(source.as_posix()) + ";", encoding="utf-8")
        for name in ["media", "index", "api"]:
            source = project / "app" / name
            if source.is_dir():
                shutil.copytree(source, root / "app" / name)
        (root / "app/service.php").write_text("<?php return [\\think\\migration\\Service::class, \\think\\app\\Service::class];", encoding="utf-8")
        for file in (project / "database/migrations").glob("*.php"):
            shutil.copyfile(file, root / "database/migrations" / file.name)
        (root / "vendor/autoload.php").write_text("<?php return require " + php_string((project / "vendor/autoload.php").as_posix()) + ";", encoding="utf-8")
        shutil.copyfile(project / "public/index.php", public / "index.php")
        for asset in ["static/media/img/movie-img.jpeg", "static/index/img/logo-dark.png"]:
            (public / asset).parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(project / "public" / asset, public / asset)
        # Framework autoload lives in the real project; explicitly isolate its root.
        index = (public / "index.php").read_text(encoding="utf-8")
        (public / "index.php").write_text(index.replace('(new App())', '(new App(dirname(__DIR__)))'), encoding="utf-8")
        (root / "think").write_text("<?php require __DIR__.'/vendor/autoload.php'; (new \\think\\App(__DIR__))->console->run();", encoding="utf-8")
        static = b"HTTP_ENTRYPOINT_STATIC\n"
        (public / "fixture.txt").write_bytes(static)
        (public / "hidden").mkdir()
        for name in [".env", "hidden/.token", "router.php", "danger.PHP", "danger.php.txt"]:
            (public / name).write_text("<?php echo 'HTTP_ENTRYPOINT_MUST_NOT_LEAK';", encoding="utf-8")
        # Internal URL prefixes stay private even if files are misplaced in public.
        (public / "data").mkdir()
        (public / "data/emby-controller.sqlite").write_bytes(b"HTTP_ENTRYPOINT_MUST_NOT_LEAK")
        database = root / "data/http.sqlite"
        environment = {key: value for key, value in os.environ.items() if not key.startswith(("DB_", "APP_", "TG_", "EMBY_", "MAIL_"))}
        environment.update({"DB_DRIVER": "sqlite", "DB_TYPE": "sqlite", "DB_NAME": str(database), "DB_PREFIX": "rc_"})
        php = [arguments.php]
        if arguments.php_ini:
            php.extend(["-c", arguments.php_ini])
        php.extend(["-d", "variables_order=EGPCS", "-d", "opcache.enable_cli=0"])
        run(php + [str(root / "think"), "migrate:run"], environment, root)
        expect(database.is_file(), "Real migrations must create temporary SQLite storage")
        run(php + [str(root / "think"), "settings:import-env"], environment, root)
        with sqlite3.connect(database) as db:
            db.execute("UPDATE rc_user SET userInfo = ? WHERE userName = 'admin'", (json.dumps({"loginIps": ["127.0.0.1"]}),))
        db.close()
        # Issue the real robot credential from the API runtime; HTTP consumes it in media.
        fixture = root / "signin-fixture.php"
        fixture.write_text("<?php require __DIR__.'/vendor/autoload.php'; $app = new \\think\\App(__DIR__); $app->initialize(); $app->setRuntimePath(__DIR__.'/runtime/api/'); \\app\\service\\SystemSettings::save(['signInMinAmount'=>'1','signInMaxAmount'=>'1']); \\think\\facade\\Cache::set('latestMedia-', '[]', 600); echo json_encode(\\app\\service\\SignInService::issueToken(1));", encoding="utf-8")
        issued = json.loads(run(php + [str(fixture)], environment, root))
        expect(issued["code"] == 200, "The API runtime must issue a valid robot sign-in credential")
        signkey = issued["token"]

        http_port, fastcgi_port, ws_port = port(), port(), port()
        websocket = socketserver.ThreadingTCPServer(("127.0.0.1", ws_port), WebSocketStub)
        websocket.daemon_threads = True
        threading.Thread(target=websocket.serve_forever, daemon=True).start()
        logs = root / "processes.log"

        def start(command):
            output = logs.open("ab")
            handles.append(output)
            process = subprocess.Popen(command, env=environment, cwd=root, stdout=output, stderr=output)
            processes.append(process)
            return process

        def configuration(probe=False, trusted=False):
            text = production_config.read_text(encoding="utf-8").replace(":8018", ":" + str(http_port)).replace("/app/public", public.as_posix()).replace("/app/runtime/caddy", (root / 'runtime/caddy').as_posix()).replace("127.0.0.1:9000", "127.0.0.1:" + str(fastcgi_port)).replace("127.0.0.1:2347", "127.0.0.1:" + str(ws_port))
            if trusted:
                text = text.replace("admin off", "admin off\n    servers {\n        trusted_proxies static 127.0.0.1\n        trusted_proxies_strict\n    }")
            if probe:
                # Use a separate temporary public root, never modify the production entrypoint.
                probe_public = root / "probe-public"
                probe_public.mkdir(exist_ok=True)
                (probe_public / "index.php").write_text("<?php header('Content-Type: application/json'); $body=file_get_contents('php://input'); echo json_encode(['server'=>$_SERVER,'get'=>$_GET,'length'=>strlen($body)]);", encoding="utf-8")
                text = text.replace(public.as_posix(), probe_public.as_posix())
            file = root / "Caddyfile"
            file.write_text(text, encoding="utf-8")
            run([arguments.caddy, "validate", "--config", str(file), "--adapter", "caddyfile"], environment, root)
            return file

        caddy_process = None
        try:
            if arguments.php_cgi:
                fastcgi = [arguments.php_cgi]
                if arguments.php_ini:
                    fastcgi.extend(["-c", arguments.php_ini])
                fastcgi.extend(["-d", "variables_order=EGPCS", "-d", "cgi.force_redirect=0", "-b", "127.0.0.1:" + str(fastcgi_port)])
            else:
                fpm = root / "fpm.conf"
                fpm.write_text("[global]\ndaemonize = no\nerror_log = /dev/stderr\n[www]\nlisten = 127.0.0.1:" + str(fastcgi_port) + "\nuser = www-data\ngroup = www-data\npm = static\npm.max_children = 2\nclear_env = no\ncatch_workers_output = yes\n", encoding="utf-8")
                fastcgi = [shutil.which("php-fpm") or "php-fpm", "-F", "-y", str(fpm), "-d", "variables_order=EGPCS"]
            wait_port(fastcgi_port, start(fastcgi))

            def caddy(probe=False, trusted=False):
                nonlocal caddy_process
                if caddy_process is not None:
                    caddy_process.terminate()
                    caddy_process.wait(timeout=10)
                caddy_process = start([arguments.caddy, "run", "--config", str(configuration(probe, trusted)), "--adapter", "caddyfile"])
                wait_port(http_port, caddy_process)

            def request(path, method="GET", body=None, headers=None):
                connection = http.client.HTTPConnection("127.0.0.1", http_port, timeout=20)
                try:
                    connection.request(method, path, body=body, headers={"User-Agent": "EmbyController-HTTP-fixture", **(headers or {})})
                    response = connection.getresponse()
                    return response.status, dict(response.getheaders()), response.read()
                finally:
                    connection.close()

            private_paths = [
                "/data", "/data/emby-controller.sqlite", "/data/emby-controller.sqlite-wal",
                "/data/emby-controller.sqlite-shm", "/runtime/log/example.log", "/backups/archive.zip",
                "/DATA/emby-controller.sqlite", "/%64ata/emby-controller.sqlite",
                "/app", "/config", "/database", "/vendor", "/extend", "/tests", "/docker", "/route",
                "/index.php/data", "/index.php/data/emby-controller.sqlite",
                "/index.php/runtime/log/example.log", "/index.php/backups/archive.zip", "/index.php/config/app.php",
            ]

            def check_private_paths(headers=None):
                for path in private_paths:
                    status, response_headers, body = request(path, headers=headers)
                    expect(status == 404, "Internal path must return 404: " + path)
                    expect(not any(name.lower() == "location" for name in response_headers), "Internal path must not redirect to login: " + path)
                    expect(b"MUST_NOT_LEAK" not in body, "Internal file content must not be exposed: " + path)

            caddy()
            expect(request("/fixture.txt")[2] == static, "Caddy must serve public static files")
            for path in ["/", "/?entrypoint=query", "/index/index"]:
                status, _, body = request(path)
                expect(status == 200 and b'id="menuButton"' in body and b"/user/login" in body, "Website root must directly render the media home page: " + path)
                expect(re.search(rb'''["']/media(?:/|["'])''', body) is None, "Home page links must use root application routes")
            for path in ["/.env", "/hidden/.token", "/router.php", "/router.php/anything", "/danger.PHP", "/danger.php.txt"]:
                status, _, body = request(path)
                expect(status == 404 and b"MUST_NOT_LEAK" not in body, "Hidden/other PHP content must be denied: " + path)
            check_private_paths()
            for path in ["/user/login?entrypoint=query", "/user/login.html?entrypoint=query", "/index.php/user/login?entrypoint=query"]:
                status, _, body = request(path)
                expect(status == 200 and b'name="username"' in body and b'name="password"' in body, "Root application route must reach the actual login page: " + path)
            for path in ["/user/register", "/user/terms", "/user/privacy"]:
                expect(request(path)[0] == 200, "Public user page must render without a login redirect: " + path)
            for path in ["/account/sign", "/account/sign.html", "/index/account/sign", "/index/account/sign.html", "/index.php/account/sign"]:
                status, _, body = request(path + "?signkey=" + signkey)
                expect(status == 200 and signkey.encode() in body and b"/account/sign" in body, "Anonymous robot link must render with a credential from the API runtime: " + path)
                expect(b"/index/account/sign" not in body, "Robot page must submit to the new root URL")
            status, _, body = request("/account/sign?signkey=invalid")
            expect(status == 200 and "签到链接已失效".encode() in body, "Invalid robot links must show their own error page")
            for path in ["/server/crontab?crontabkey=invalid", "/server/resolvePayment?key=invalid"]:
                status, _, body = request(path)
                expect(status == 200 and json.loads(body)["code"] == 400, "Public callbacks must reach credential validation without login: " + path)
            for path, asset in [("/index/getPrimaryImg?id=0", "static/media/img/movie-img.jpeg"), ("/api/common/getHeadImg?id=0", "static/index/img/logo-dark.png")]:
                status, _, body = request(path)
                expect(status == 200 and body == (public / asset).read_bytes(), "Root image endpoint must load its local fallback independently of working directory: " + path)
            status, _, body = request("/index/getLatestMedia", "POST", "", {"Content-Type": "application/x-www-form-urlencoded"})
            expect(status == 200 and json.loads(body)["latestMedia"] == [], "Public latest-media request must share the API runtime cache")
            status, _, body = request("/index/getLineStatus", "POST", "", {"Content-Type": "application/x-www-form-urlencoded"})
            expect(status == 200 and json.loads(body)["code"] == 200, "Public line-status request must remain reachable")
            for path in ["/admin/setting", "/admin/setting?next=/user/login"]:
                status, headers, _ = request(path)
                expect(status in (301, 302) and "/user/login" in headers.get("Location", ""), "Unauthenticated admin route must redirect: " + path)
            for path, data in [
                ("/server/redeemCode", {"code": "MISSING"}),
                ("/user/update", {}),
                ("/user/sendVerifyCode", {"action": "update"}),
            ]:
                for route in [path, path + ".html"]:
                    status, _, body = request(route, "POST", urllib.parse.urlencode(data), {"Content-Type": "application/x-www-form-urlencoded"})
                    expect(status == 401 and json.loads(body)["code"] == 401, "Unauthenticated protected action must return JSON 401: " + route)
            for path in ["/api/ping", "/api/index/ping", "/api/common/ping", "/api/media/ping", "/api/telegram/ping"]:
                status, _, body = request(path)
                expect(status == 200 and json.loads(body)["msg"] == "pong", "The API application must remain available under /api: " + path)
            status, headers, _ = request("/user/login", "POST", urllib.parse.urlencode({"username": "admin", "password": "A123456"}), {"Content-Type": "application/x-www-form-urlencoded"})
            expect(status in (301, 302) and "RANDALLANJIESESSID=" in headers.get("Set-Cookie", ""), "Initial admin must log in through real HTTP and receive a session cookie")
            cookie = headers["Set-Cookie"].split(";", 1)[0]
            status, _, body = request("/admin/setting", headers={"Cookie": cookie})
            expect(status == 200 and b"siteName" in body, "Admin session must survive a subsequent HTTP request")
            for data in [{"money": "1", "method": "usdt"}, {"money": "1", "method": "trx"}, {"money": "1", "method": "alipay"}, {}]:
                status, _, body = request("/server/pay", "POST", urllib.parse.urlencode(data), {"Content-Type": "application/x-www-form-urlencoded", "Cookie": cookie})
                expect(status == 200 and json.loads(body)["code"] == 400, "Unavailable payment methods must stop before creating an order with a missing callback")
            for path in [
                "/user/index", "/user/userconfig", "/user/request", "/user/newRequest", "/user/seek", "/user/comment", "/user/notifications",
                "/server/account", "/server/servers", "/server/devices", "/server/create",
                "/finance/user", "/finance/record", "/finance/payRecord", "/admin/index", "/admin/userList", "/admin/addUser",
                "/admin/exchangeCodeList", "/admin/addExchangeCode", "/admin/lotteryList", "/admin/addLottery", "/admin/request", "/admin/seek", "/admin/logs",
            ]:
                status, _, body = request(path, headers={"Cookie": cookie})
                expect(status == 200 and body, "Authenticated navigation must reach a rendered root page: " + path)
                expect(re.search(rb'''["']/media(?:/|["'])''', body) is None, "Rendered navigation must use root routes: " + path)
            status, _, body = request("/index/account/sign.html", "POST", urllib.parse.urlencode({"signkey": signkey}), {"Content-Type": "application/x-www-form-urlencoded"})
            expect(status == 200 and json.loads(body)["code"] == 200 and json.loads(body)["reward"] == "1.00", "Legacy robot POST must claim the configured reward without a website session")
            status, _, body = request("/account/sign", "POST", urllib.parse.urlencode({"signkey": signkey}), {"Content-Type": "application/x-www-form-urlencoded"})
            expect(json.loads(body)["code"] == 400, "Consumed robot link must reject replay")
            status, _, body = request("/user/sign", "POST", "", {"Content-Type": "application/x-www-form-urlencoded", "Cookie": cookie})
            expect(json.loads(body)["code"] == 400, "Website sign-in must share the same daily limit as robot sign-in")
            with sqlite3.connect(database) as db:
                expect(db.execute("SELECT COUNT(*) FROM rc_finance_record WHERE action = 4").fetchone()[0] == 1, "Robot and website sign-in must record one daily reward")
            # A protected deep link survives the login round trip.
            status, headers, _ = request("/admin/lotteryList?keyword=route-fixture")
            login_cookie = headers["Set-Cookie"].split(";", 1)[0]
            status, headers, _ = request("/user/login", "POST", urllib.parse.urlencode({"username": "admin", "password": "A123456"}), {"Content-Type": "application/x-www-form-urlencoded", "Cookie": login_cookie})
            expect(status in (301, 302) and headers.get("Location", "").endswith("/admin/lotteryList?keyword=route-fixture"), "Login must return to the requested root route and preserve its query")
            check_private_paths({"Cookie": cookie})

            with socket.create_connection(("127.0.0.1", http_port), timeout=5) as client:
                key = base64.b64encode(b"emby-http-test!!").decode()
                client.sendall(("GET /ws HTTP/1.1\r\nHost: 127.0.0.1:" + str(http_port) + "\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Version: 13\r\nSec-WebSocket-Key: " + key + "\r\n\r\n").encode())
                response = b""
                while b"\r\n\r\n" not in response or not response.endswith(b"\x81\x02ok"):
                    response += client.recv(4096)
                expect(response.startswith(b"HTTP/1.1 101") and b"\x81\x02ok" in response, "Caddy must proxy WebSocket upgrade and frames")

            caddy(probe=True)
            spoof = {"X-Forwarded-For": "203.0.113.9", "X-Forwarded-Host": "spoof.invalid", "X-Forwarded-Proto": "https", "X-Forwarded-Port": "666", "X-Real-IP": "203.0.113.8", "CF-Connecting-IP": "203.0.113.7", "X-Rewrite-URL": "/spoof"}
            status, _, body = request("/fixture/path?key=a%2Bb&number=7", headers=spoof)
            probe = json.loads(body)
            server = probe["server"]
            expect(status == 200 and server["PATH_INFO"] == "/fixture/path" and server["SCRIPT_NAME"] == "/index.php", "FastCGI must preserve ThinkPHP PATH_INFO")
            expect(Path(server["SCRIPT_FILENAME"]).resolve() == (root / "probe-public/index.php").resolve(), "Only the temporary front controller must execute")
            expect(probe["get"] == {"key": "a+b", "number": "7"} and server["REQUEST_URI"].startswith("/fixture/path?"), "Original URI and query parameters must survive rewriting")
            expect(server["HTTP_X_FORWARDED_PROTO"] == "http" and server["HTTP_X_FORWARDED_HOST"] == "127.0.0.1:" + str(http_port), "Untrusted scheme/host headers must be replaced")
            expect(server["HTTP_X_REAL_IP"] == "127.0.0.1" and server["HTTP_X_FORWARDED_PORT"] == str(http_port), "Untrusted client-IP/port headers must be replaced")
            expect("HTTP_CF_CONNECTING_IP" not in server and "HTTP_X_REWRITE_URL" not in server, "Untrusted alternate IP/URI headers must be removed")
            expect(request("/", "POST", b"x" * (20 * 1024 * 1024 + 1), {"Content-Type": "application/octet-stream"})[0] == 413, "Request body over 20 MiB must be rejected")

            caddy(probe=True, trusted=True)
            status, _, body = request("/proxy", headers={"Host": "proxy.example:8090", "X-Forwarded-For": "203.0.113.25", "X-Forwarded-Host": "proxy.example:8090", "X-Forwarded-Proto": "https", "X-Forwarded-Port": "8090"})
            trusted = json.loads(body)["server"]
            expect(status == 200 and trusted["PATH_INFO"] == "/proxy", "Proxy probe must preserve the root application route")
            expect(trusted["HTTP_X_FORWARDED_PROTO"] == "https" and trusted["HTTP_X_FORWARDED_HOST"] == "proxy.example:8090", "Explicitly trusted proxy must retain external HTTPS and Host")
            expect(trusted["HTTP_X_REAL_IP"] == "203.0.113.25" and trusted["HTTP_X_FORWARDED_PORT"] == "8090", "Explicitly trusted proxy must retain client IP and external port")
            print("PASS: real root navigation/callbacks/images/API, cross-app robot sign-in/replay/daily limit, login return route, Caddy/FastCGI session/private paths, WebSocket and proxy trust")
        except Exception:
            print(logs.read_text(encoding="utf-8", errors="replace")[-12000:])
            raise
        finally:
            for process in reversed(processes):
                if process.poll() is None:
                    process.terminate()
                    try:
                        process.wait(timeout=10)
                    except subprocess.TimeoutExpired:
                        process.kill()
                        process.wait(timeout=5)
            websocket.shutdown()
            websocket.server_close()
            for handle in handles:
                handle.close()


if __name__ == "__main__":
    main()
