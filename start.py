#!/usr/bin/env python3
"""Check requirements, then start noize-buffet: the web server and the agent worker together.

Usage: python3 start.py            (then open http://localhost:8000)
       NB_PORT=8080 python3 start.py

Ctrl+C stops both.
"""
import os
import re
import shutil
import signal
import socket
import subprocess
import sys
import threading
from pathlib import Path

ROOT = Path(__file__).resolve().parent
PORT = int(os.environ.get("NB_PORT", "8000"))


def report(ok, name, fix=""):
    print(f"  {'OK     ' if ok else 'MISSING'}  {name}" + ("" if ok or not fix else f"\n           fix: {fix}"))
    return ok


def run(cmd):
    try:
        return subprocess.run(cmd, capture_output=True, text=True, timeout=60)
    except (FileNotFoundError, subprocess.TimeoutExpired):
        return None


def check_requirements():
    print("Checking requirements...")
    ok = True
    r = run(["yt-dlp", "--version"])
    ok &= report(bool(r and r.returncode == 0), f"yt-dlp {r.stdout.strip() if r else ''}".strip(),
                 "python3 -m pip install -U yt-dlp")
    r = run(["php", "-r", "echo PHP_VERSION;"])
    version = r.stdout.strip() if r and r.returncode == 0 else ""
    parts = [int(x) for x in re.findall(r"\d+", version)[:2]]
    ok &= report(len(parts) == 2 and tuple(parts) >= (8, 1), f"PHP 8.1+ (found {version or 'none'})",
                 "install PHP 8.1 or newer")
    r = run(["php", "-m"])
    ok &= report(bool(r and "pdo_sqlite" in r.stdout), "PHP pdo_sqlite extension",
                 "install your system's PHP SQLite package (e.g. php-sqlite3)")
    report(True, f"Python {sys.version.split()[0]}")
    r = run(["claude", "--version"])
    ok &= report(bool(r and r.returncode == 0), f"Claude Code {r.stdout.strip() if r and r.returncode == 0 else ''}".strip(),
                 "install Claude Code (https://claude.com/claude-code) and run `claude` once to log in")
    return ok


def check_env():
    env_file, example = ROOT / ".env", ROOT / ".env.example"
    if not env_file.exists() and example.exists():
        shutil.copyfile(example, env_file)
        print(f"  created {env_file.name} from {example.name}")
    text = env_file.read_text(encoding="utf-8") if env_file.exists() else ""
    has_key = bool(re.search(r"^\s*TYPESAFE_API(_KEY)?\s*=\s*\S+", text, re.M))
    if has_key:
        report(True, "TypeSafe API key in .env")
        return
    bar = "!" * 72
    print(f"\n{bar}\n"
          "  No TypeSafe API key found in .env\n\n"
          "  Comment mining (coming soon) will fall back to a simple keyword filter:\n"
          "  noisier leads, fewer comments read, and more of your Claude usage.\n"
          "  A normal month on TypeSafe costs well under $5; check https://typesafe.ai\n"
          "  for current free credits, then put TYPESAFE_API=your-key in .env\n"
          f"{bar}\n")


def port_free(port):
    with socket.socket() as s:
        return s.connect_ex(("127.0.0.1", port)) != 0


def pipe_output(proc, prefix):
    for line in proc.stdout:
        print(f"{prefix} {line}", end="", flush=True)


def main():
    if not check_requirements():
        sys.exit("\nFix the missing requirements above, then run python3 start.py again.")
    check_env()
    (ROOT / "data").mkdir(exist_ok=True)
    if not port_free(PORT):
        sys.exit(f"\nPort {PORT} is already in use. Stop whatever is using it, or run: NB_PORT=8080 python3 start.py")

    env = dict(os.environ, PHP_CLI_SERVER_WORKERS="4")
    procs = {
        "[web]  ": subprocess.Popen(["php", "-S", f"localhost:{PORT}", "-t", "player"], cwd=ROOT, env=env,
                                     stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True, start_new_session=True),
        "[agent]": subprocess.Popen(["php", "scripts/job_worker.php"], cwd=ROOT, env=env,
                                     stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True, start_new_session=True),
    }
    for prefix, proc in procs.items():
        threading.Thread(target=pipe_output, args=(proc, prefix), daemon=True).start()
    print(f"\nnoize-buffet is running: open http://localhost:{PORT}\nPress Ctrl+C to stop.\n", flush=True)

    def stop(*_):
        print("\nStopping...", flush=True)
        for proc in procs.values():
            try:
                os.killpg(proc.pid, signal.SIGTERM)  # the whole group: server workers and any running agent
            except ProcessLookupError:
                pass
        for proc in procs.values():
            proc.wait()
        sys.exit(0)

    signal.signal(signal.SIGINT, stop)
    signal.signal(signal.SIGTERM, stop)
    while True:
        for prefix, proc in procs.items():
            if proc.poll() is not None:
                print(f"\n{prefix.strip()} exited with code {proc.returncode}; stopping the rest.", flush=True)
                stop()
        threading.Event().wait(1)


if __name__ == "__main__":
    main()
