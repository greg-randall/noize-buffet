#!/usr/bin/env python3
"""Check requirements, then start noize-buffet: the web server, the agent worker and the comment-mining worker together.

Usage: python3 start.py                     (port 8000, or a random free one from 8001-8999 if 8000 is taken)
       NB_PORT=8080 python3 start.py        (exactly this port)
       python3 start.py --no-typesafe       (run without a TypeSafe API key)
       python3 start.py --reset             (stop this folder's old servers, delete all your data, start fresh)

Ctrl+C stops them all.
"""
import argparse
import json
import os
import random
import re
import shutil
import signal
import socket
import subprocess
import sys
import threading
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parent
DEFAULT_PORT = 8000
FALLBACK_PORTS = range(8001, 9000)
FALLBACK_TRIES = 25
# What --reset deletes: everything a run creates. .env and config.json are kept.
RESET_PATHS = ["data", "brief.md", "taste.md", "comments"]


def report(ok, name, fix=""):
    print(f"  {'OK     ' if ok else 'MISSING'}  {name}" + ("" if ok or not fix else f"\n           fix: {fix}"))
    return ok


def run(cmd):
    try:
        return subprocess.run(cmd, capture_output=True, text=True, timeout=60)
    except (FileNotFoundError, subprocess.TimeoutExpired):
        return None


# How to install each requirement, per package manager. "apt" covers Ubuntu, Debian and WSL; "brew" macOS.
INSTALL = {
    "php": {"apt": "sudo apt install php-cli", "brew": "brew install php"},
    "pdo_sqlite": {"apt": "sudo apt install php-sqlite3", "brew": "brew install php"},
    "intl": {"apt": "sudo apt install php-intl", "brew": "brew install php"},
    "mbstring": {"apt": "sudo apt install php-mbstring", "brew": "brew install php"},
    "timeout": {"apt": "sudo apt install coreutils",
                "brew": "brew install coreutils  # then put $(brew --prefix)/opt/coreutils/libexec/gnubin "
                        "first on your PATH"},
    "python": {"apt": "sudo apt install python3", "brew": "brew install python"},
    "pip": {"apt": "sudo apt install python3-pip", "brew": "brew install python"},
    "packages": {"any": "python3 -m pip install -r requirements.txt"},
    "claude": {"any": "install Claude Code from https://claude.com/claude-code, then run `claude` once to log in"},
    "login": {"any": "claude auth login"},
}
DESCRIBE = {  # for systems with neither apt nor brew
    "php": "PHP 8.1 or newer", "pdo_sqlite": "PHP's SQLite (pdo_sqlite) extension", "intl": "PHP's intl extension",
    "mbstring": "PHP's mbstring extension", "timeout": "GNU coreutils (for the timeout command)",
    "python": "Python 3.10 or newer", "pip": "pip for Python 3",
}


def package_manager():
    return "apt" if shutil.which("apt-get") else "brew" if shutil.which("brew") else None


def install_plan(missing, manager):
    """The commands (or, without apt or brew, the things) to install for the missing requirement keys, once each."""
    lines, packages = [], []
    for key in missing:
        how = INSTALL[key]
        line = how.get("any") or (how.get(manager) if manager else None) or f"install {DESCRIBE[key]}"
        simple = re.fullmatch(r"(sudo apt install|brew install) (\S+)", line)
        if simple:  # plain package installs are merged into one command, first in the list
            if simple.group(2) not in packages:
                packages.append(simple.group(2))
        elif line not in lines:
            lines.append(line)
    command = "sudo apt install" if manager == "apt" else "brew install"
    return ([f"{command} {' '.join(packages)}"] if packages else []) + lines


def check_requirements(need_typesafe=True):
    """Check everything noize-buffet needs; print one line each, then what to install for anything missing."""
    print("Checking requirements...")
    missing = []  # INSTALL keys, in the order found

    def need(ok, name, key):
        report(ok, name)
        if not ok and key not in missing:
            missing.append(key)

    r = run(["php", "-r", "echo PHP_VERSION;"])
    version = r.stdout.strip() if r and r.returncode == 0 else ""
    parts = [int(x) for x in re.findall(r"\d+", version)[:2]]
    need(len(parts) == 2 and tuple(parts) >= (8, 1), f"PHP 8.1+ (found {version or 'none'})", "php")
    r = run(["php", "-m"]) if version else None
    modules = r.stdout.split() if r else []
    for module in ("pdo_sqlite", "intl", "mbstring"):
        need(module in modules, f"PHP {module} extension", module)
    # Comment mining puts a time limit on every step with GNU timeout (standard on Linux and WSL, not on macOS).
    need(shutil.which("timeout") is not None, "timeout command (GNU coreutils)", "timeout")
    # The mining scripts use Python 3.10 syntax (list | None).
    need(sys.version_info >= (3, 10), f"Python 3.10+ (found {sys.version.split()[0]})", "python")
    r = run([sys.executable, "-m", "pip", "--version"])
    need(bool(r and r.returncode == 0), "pip", "pip")
    r = run(["yt-dlp", "--version"])
    need(bool(r and r.returncode == 0), f"yt-dlp {r.stdout.strip() if r else ''}".strip(), "packages")
    if need_typesafe:
        import importlib.util
        gone = [m for m in ("typesafe_sdk", "aiolimiter", "dotenv", "tqdm") if importlib.util.find_spec(m) is None]
        need(not gone, "Python packages for TypeSafe" + (f" (missing: {', '.join(gone)})" if gone else ""), "packages")
    r = run(["claude", "--version"])
    version = r.stdout.strip() if r and r.returncode == 0 else ""
    need(bool(version), f"Claude Code {version}".strip(), "claude")
    if version:
        # {"loggedIn": true, ...}; an older Claude Code without `auth status` isn't blocked, just not checked.
        r = run(["claude", "auth", "status"])
        try:
            logged_in = json.loads(r.stdout).get("loggedIn") if r else None
        except ValueError:
            logged_in = None
        if logged_in is not None:
            need(bool(logged_in), "logged in to Claude Code", "login")

    if missing:
        print("\nTo install what's missing, run:\n")
        for line in install_plan(missing, package_manager()):
            print(f"    {line}")
        print("\nThen run python3 start.py again.")
    return not missing


def check_env(no_typesafe):
    """Create .env if missing. Without a TypeSafe key, stop unless --no-typesafe was given."""
    env_file, example = ROOT / ".env", ROOT / ".env.example"
    if not env_file.exists() and example.exists():
        shutil.copyfile(example, env_file)
        print(f"  created {env_file.name} from {example.name}")
    text = env_file.read_text(encoding="utf-8") if env_file.exists() else ""
    has_key = bool(re.search(r"^[ \t]*TYPESAFE_API(_KEY)?[ \t]*=[ \t]*\S+", text, re.M))
    if has_key:
        report(True, "TypeSafe API key in .env")
        return
    bar = "!" * 72
    message = (f"\n{bar}\n"
               "  No TypeSafe API key found in .env\n\n"
               "  Comment mining will fall back to a simple keyword filter:\n"
               "  noisier leads, fewer comments read, and more of your Claude usage.\n"
               "  A normal month on TypeSafe costs well under $5; check https://typesafe.ai\n"
               "  for current free credits, then put TYPESAFE_API=your-key in .env\n")
    if no_typesafe:
        print(message + "\n  Continuing without it (--no-typesafe).\n" + f"{bar}\n")
        return
    sys.exit(message + "\n  To run without it anyway: python3 start.py --no-typesafe\n" + bar)


def port_free(port):
    """True if nothing answers on this port over IPv4 or IPv6 (localhost can mean either)."""
    for family, host in ((socket.AF_INET, "127.0.0.1"), (socket.AF_INET6, "::1")):
        try:
            with socket.socket(family) as s:
                s.settimeout(1)
                if s.connect_ex((host, port)) == 0:
                    return False
        except OSError:
            pass  # e.g. no IPv6 on this machine
    return True


def choose_port():
    """NB_PORT if set (and free); otherwise 8000, or a random free port between 8001 and 8999."""
    if os.environ.get("NB_PORT"):
        port = int(os.environ["NB_PORT"])
        if not port_free(port):
            sys.exit(f"\nPort {port} (from NB_PORT) is already in use. "
                     "Stop whatever is using it, or pick another port.")
        return port
    if port_free(DEFAULT_PORT):
        return DEFAULT_PORT
    for port in random.sample(FALLBACK_PORTS, FALLBACK_TRIES):
        if port_free(port):
            print(f"  port {DEFAULT_PORT} is in use, using {port} instead")
            return port
    sys.exit(f"\nPort {DEFAULT_PORT} and {FALLBACK_TRIES} random ports between {FALLBACK_PORTS[0]} and "
             f"{FALLBACK_PORTS[-1]} are all in use. Pick one yourself: NB_PORT=<port> python3 start.py")


def old_processes():
    """This folder's web server, workers, agent, and mining processes (pipeline, children, yt-dlp, filters),
    left over from an earlier run.

    Found through /proc (Linux, including WSL): processes whose working directory is this folder (or inside it) and
    whose command is one of ours. Returns None where /proc isn't available."""
    proc_dir = Path("/proc")
    if not (proc_dir / "self" / "cwd").exists():
        return None
    found, me = [], {os.getpid(), os.getppid()}
    for d in proc_dir.iterdir():
        if not d.name.isdigit() or int(d.name) in me:
            continue
        try:
            cwd = Path(os.readlink(d / "cwd"))
            if cwd != ROOT and ROOT not in cwd.parents:  # the PHP server's workers run in player/
                continue
            args = (d / "cmdline").read_bytes().decode(errors="replace").split("\0")
        except OSError:
            continue  # gone already, or not ours to inspect
        name = Path(args[0]).name
        script = any(a.startswith("scripts/") or "/scripts/" in a for a in args)  # workers and the mining pipeline
        ours = ((name.startswith("php") and ("-S" in args or script))
                or (name == "claude" and "-p" in args)          # the agent and mining children
                or (name.startswith("python") and any("mining/" in a for a in args))
                or any(Path(a).name == "yt-dlp" for a in args[:2]))  # also run as "python3 …/yt-dlp"
        if ours:
            found.append((int(d.name), " ".join(a for a in args if a)[:120]))
    return found


def reset():
    """Stop this folder's old processes and delete everything a run created, after a loud confirmation."""
    procs = old_processes()
    doomed = [ROOT / p for p in RESET_PATHS if (ROOT / p).exists()]
    bar = "!" * 72
    print(f"\n{bar}\n  RESET: THIS PERMANENTLY DELETES ALL YOUR noize-buffet DATA\n{bar}\n")
    print("  Deletes (your queue, ratings, notes, chat history, brief, taste notes, mined comments and leads, logs):")
    for path in doomed:
        print(f"    - {path.relative_to(ROOT)}{'/' if path.is_dir() else ''}")
    if not doomed:
        print("    (nothing to delete)")
    print("\n  Stops these old processes from this folder:")
    if procs is None:
        print("    (can't look for them on this system: stop any other start.py for this folder yourself first)")
    for pid, cmd in procs or []:
        print(f"    - pid {pid}: {cmd}")
    if procs == []:
        print("    (none running)")
    print("\n  Kept: .env and config.json\n")
    print("  THERE IS NO UNDO. ARE YOU SURE?! YOU CAN'T RECOVER FROM THIS!\n")
    try:
        answer = input("  Type RESET (in capitals) to go ahead, anything else to cancel: ")
    except EOFError:
        answer = ""
    if answer.strip() != "RESET":
        sys.exit("\n  Cancelled. Nothing was changed.")

    for pid, _ in procs or []:
        try:
            os.kill(pid, signal.SIGTERM)
        except ProcessLookupError:
            pass
    deadline = time.time() + 5
    while procs and time.time() < deadline and any(Path(f"/proc/{pid}").exists() for pid, _ in procs):
        time.sleep(0.2)
    for pid, _ in procs or []:
        if Path(f"/proc/{pid}").exists():
            try:
                os.kill(pid, signal.SIGKILL)
            except ProcessLookupError:
                pass
    if procs:
        print(f"  stopped {len(procs)} old process(es)")
    for path in doomed:
        if path.is_dir():
            shutil.rmtree(path)
        else:
            path.unlink()
        print(f"  deleted {path.relative_to(ROOT)}")
    print("  Reset done. Starting fresh.\n")


def pipe_output(proc, prefix):
    for line in proc.stdout:
        print(f"{prefix} {line}", end="", flush=True)


def main():
    parser = argparse.ArgumentParser(description="Start the noize-buffet web server, agent worker and mining worker.")
    parser.add_argument("--no-typesafe", action="store_true",
                        help="run without a TypeSafe API key (comment mining falls back to a keyword filter)")
    parser.add_argument("--reset", action="store_true",
                        help="stop this folder's old servers and DELETE all your data (queue, ratings, chat, "
                             "brief.md, taste.md, mined comments), after confirming; then start fresh")
    args = parser.parse_args()
    if args.reset:
        reset()
    if not check_requirements(need_typesafe=not args.no_typesafe):
        sys.exit(1)
    check_env(args.no_typesafe)
    (ROOT / "data").mkdir(exist_ok=True)
    port = choose_port()

    env = dict(os.environ, PHP_CLI_SERVER_WORKERS="4")
    popen = dict(cwd=ROOT, env=env, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True, start_new_session=True)
    procs = {
        "[web]  ": subprocess.Popen(["php", "-S", f"localhost:{port}", "-t", "player"], **popen),
        "[agent]": subprocess.Popen(["php", "scripts/job_worker.php"], **popen),
        "[mine] ": subprocess.Popen(["php", "scripts/mine_worker.php"], **popen),
    }
    for prefix, proc in procs.items():
        threading.Thread(target=pipe_output, args=(proc, prefix), daemon=True).start()
    print(f"\nnoize-buffet is running: open http://localhost:{port}\nPress Ctrl+C to stop.\n", flush=True)

    def stop(*_):
        print("\nStopping...", flush=True)
        for proc in procs.values():
            try:
                os.killpg(proc.pid, signal.SIGTERM)  # the whole group: server workers, agent and mining
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
