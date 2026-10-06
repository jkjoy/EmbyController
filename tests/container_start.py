"""Exercise the real start.sh with fixture commands in an isolated process group.

Run with Python 3 on Linux, or with Git Bash installed on Windows. No Docker,
production files, database, or runtime configuration is used by these tests.
"""

import os
from pathlib import Path
import subprocess
import tempfile
import time


SHELL = "sh" if os.name != "nt" else r"C:\Program Files\Git\bin\bash.exe"
SOURCE = Path(__file__).resolve().parents[1] / "docker" / "start.sh"
if not SOURCE.is_file():
    SOURCE = Path("/start.sh")
SERVICES = {"php-fpm", "caddy", "workerman", "crond", "queue"}

MOCK = r'''#!/bin/sh
name=${0##*/}
case "$name:$2" in
    php:migrate:run) name=migrate ;;
    php:settings:import-env) name=import ;;
    php:settings:queue-worker) name=queue ;;
    php:*) name=workerman ;;
esac
if [ "$name" = caddy ] && [ "$1" = validate ]; then name=validate; fi
case "$name" in
    migrate|import|validate|emby-rotate-logs)
        echo "init|$name" >> "$FIXTURE_DIR/events"
        if [ -f "$FIXTURE_DIR/fail-$name" ]; then exit "$(cat "$FIXTURE_DIR/fail-$name")"; fi
        exit 0 ;;
    mock-child) name="child-$1" ;;
esac
delay_pid=
child_pid=
stop() {
    trap '' TERM INT
    for pid in $delay_pid $child_pid; do
        kill -TERM "$pid" 2>/dev/null || true
        wait "$pid" 2>/dev/null || true
    done
    echo "stop|$name|$$" >> "$FIXTURE_DIR/events"
    exit 0
}
trap stop TERM INT
echo "start|$name|$$" >> "$FIXTURE_DIR/events"
case "$name" in
    child-*) ;;
    *) "$FIXTURE_DIR/bin/mock-child" "$name" & child_pid=$! ;;
esac
while :; do
    if [ -f "$FIXTURE_DIR/fail-$name" ]; then
        code=$(cat "$FIXTURE_DIR/fail-$name")
        rm "$FIXTURE_DIR/fail-$name"
        echo "exit|$name|$$|$code" >> "$FIXTURE_DIR/events"
        # Deliberately leave a child behind to verify group cleanup on failure.
        exit "$code"
    fi
    sleep 0.05 & delay_pid=$!
    wait "$delay_pid"
    delay_pid=
done
'''


def shell_path(path):
    value = path.resolve().as_posix()
    if os.name == "nt":
        value = "/" + value[0].lower() + value[2:]
    return value


def write(path, value):
    with path.open("w", encoding="utf-8", newline="\n") as output:
        output.write(value)
    path.chmod(0o755)


class Fixture:
    def __init__(self, failure=None):
        self.temp = tempfile.TemporaryDirectory(prefix="emby-start-test-")
        self.root = Path(self.temp.name)
        self.app = self.root / "app"
        self.bin = self.root / "bin"
        self.bin.mkdir()
        (self.app / "database" / "migrations").mkdir(parents=True)
        write(self.app / "database" / "migrations" / "fixture.php", "fixture\n")
        for name in SERVICES | {"php", "mock-child", "emby-rotate-logs"}:
            write(self.bin / name, MOCK)
        write(self.bin / "su-exec", '#!/bin/sh\nshift\nexec "$@"\n')
        write(self.bin / "chown", "#!/bin/sh\nexit 0\n")
        self.script = self.root / "start.sh"
        text = SOURCE.read_text(encoding="utf-8").replace("/app", shell_path(self.app))
        text = text.replace("/usr/local/bin/emby-rotate-logs", shell_path(self.bin / "emby-rotate-logs"))
        # All fixture injection is confined to this temporary copy.
        marker = 'export PATH="' + shell_path(self.bin) + ':$PATH"\n'
        marker += 'echo "$$" > "$FIXTURE_DIR/controller.pid"\n'
        text = text.replace("set -e\n", "set -e\n" + marker, 1)
        write(self.script, text)
        if failure:
            self.fail(*failure)
        self.log = (self.root / "output").open("w", encoding="utf-8")
        env = os.environ.copy()
        env["FIXTURE_DIR"] = shell_path(self.root)
        if os.name == "nt":
            # Job control creates the same independent group that tini -g uses.
            command = [SHELL, "-lc", 'set -m; sh "$1" & child=$!; wait "$child"', "--", shell_path(self.script)]
        else:
            command = [SHELL, str(self.script)]
        self.process = subprocess.Popen(command, cwd=str(self.app), env=env,
                                        stdout=self.log, stderr=self.log,
                                        start_new_session=os.name != "nt")
        self.until(lambda: (self.root / "controller.pid").exists(), 3)
        self.pid = int((self.root / "controller.pid").read_text())

    def events(self):
        path = self.root / "events"
        return [line.split("|") for line in path.read_text().splitlines()] if path.exists() else []

    def until(self, condition, timeout):
        deadline = time.monotonic() + timeout
        while time.monotonic() < deadline:
            if condition():
                return
            time.sleep(0.05)
        raise AssertionError("Timed out:\n" + (self.root / "output").read_text(encoding="utf-8", errors="replace"))

    def ready(self):
        wanted = SERVICES | {"child-" + name for name in SERVICES}
        self.until(lambda: wanted <= {e[1] for e in self.events() if e[0] == "start"}, 4)
        assert self.process.poll() is None

    def fail(self, name, status):
        write(self.root / ("fail-" + name), str(status))

    def signal(self, name, group=False):
        target = ("-" if group else "") + str(self.pid)
        # MSYS killpg can report ESRCH after delivering to processes that exit
        # during its iteration; wait() below verifies delivery and cleanup.
        subprocess.run([SHELL, "-c", 'kill "-$1" -- "$2"', "--", name, target],
                       check=not (os.name == "nt" and group), stderr=subprocess.PIPE)

    def wait(self, expected, timeout=4):
        status = self.process.wait(timeout=timeout)
        assert status == expected, (status, expected, (self.root / "output").read_text())
        # Every descendant, including a child orphaned by a crashed master,
        # must have received TERM and stopped before the controller finishes.
        entries = self.events()
        started = {e[2] for e in entries if e[0] == "start"}
        finished = {e[2] for e in entries if e[0] in {"exit", "stop"}}
        assert started <= finished, entries

    def close(self):
        if self.process.poll() is None:
            self.signal("TERM")
            try:
                self.process.wait(timeout=10)
            except subprocess.TimeoutExpired:
                subprocess.run([SHELL, "-c", 'kill -KILL -- "-$1"', "--", str(self.pid)], check=False)
                self.process.wait(timeout=3)
        self.log.close()
        self.temp.cleanup()


def verify(label, action, failure=None):
    fixture = Fixture(failure)
    try:
        action(fixture)
        print("PASS: " + label, flush=True)
    finally:
        fixture.close()


def stop_test(fixture, name, group=False):
    fixture.ready()
    fixture.signal(name, group)
    fixture.wait(0)


def crash_test(fixture, name, status):
    fixture.ready()
    fixture.fail(name, status)
    fixture.wait(status or 1)


def queue_test(fixture):
    fixture.ready()
    for status in (12, 0):
        starts = sum(e[:2] == ["start", "queue"] for e in fixture.events())
        fixture.fail("queue", status)
        fixture.until(lambda: sum(e[:2] == ["start", "queue"] for e in fixture.events()) > starts, 7)
        assert fixture.process.poll() is None
    fixture.signal("TERM")
    fixture.wait(0)


if __name__ == "__main__":
    for name in ("TERM", "INT"):
        verify("clean " + name + " shutdown", lambda f, n=name: stop_test(f, n))
        verify("clean group " + name + " shutdown", lambda f, n=name: stop_test(f, n, True))
    for name, status in (("php-fpm", 0), ("php-fpm", 6), ("caddy", 7), ("workerman", 9), ("crond", 8)):
        verify(name + " exit " + str(status) + " stops all processes",
               lambda f, n=name, s=status: crash_test(f, n, s))
    verify("queue exit 12 and 0 restart without stopping services", queue_test)
    for name, status in (("migrate", 23), ("import", 24), ("validate", 25)):
        verify(name + " failure exits before starting services", lambda f, s=status: f.wait(s), (name, status))
