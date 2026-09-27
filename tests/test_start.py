"""start.py's requirement check: one line per requirement, then the commands to install what's missing."""
import contextlib
import io
import subprocess
import sys
from pathlib import Path
from unittest import mock

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
import start  # noqa: E402

fails = 0


def check(cond, msg):
    global fails
    fails += not cond
    print(f"  {'ok  ' if cond else 'FAIL'}  {msg}")


def ran(out, code=0):
    return subprocess.CompletedProcess([], code, stdout=out, stderr="")


def fake_run(have):
    """A stand-in for start.run that answers as if only the tools in `have` were installed."""
    def run(cmd):
        name = Path(cmd[0]).name if cmd[0] != sys.executable else "pip"
        if name == "php" and "php" in have:
            modules = "\n".join(m for m in ("pdo_sqlite", "intl", "mbstring") if m in have)
            return ran("8.3.6" if "-r" in cmd else modules)
        if name == "claude" and "auth" in cmd and "claude" in have:
            return ran('{"loggedIn": %s}' % ("true" if "login" in have else "false"))
        if name in have:
            return ran("1.0")
        return None
    return run


def check_with(have, manager, need_typesafe=True, modules_missing=False):
    which = {"timeout": "/usr/bin/timeout" if "timeout" in have else None,
             "apt-get": "/usr/bin/apt-get" if manager == "apt" else None,
             "brew": "/opt/homebrew/bin/brew" if manager == "brew" else None}
    out = io.StringIO()
    with mock.patch.object(start, "run", fake_run(have)), \
            mock.patch.object(start.shutil, "which", lambda n: which.get(n)), \
            mock.patch("importlib.util.find_spec", lambda m: None if modules_missing else object()), \
            contextlib.redirect_stdout(out):
        ok = start.check_requirements(need_typesafe)
    return ok, out.getvalue()


everything = {"php", "pdo_sqlite", "intl", "mbstring", "timeout", "pip", "yt-dlp", "claude", "login"}
print("everything installed")
ok, out = check_with(everything, "apt")
check(ok and "MISSING" not in out and "To install" not in out, "all OK, and no install list")

print("a fresh Ubuntu missing the PHP extensions, yt-dlp and Claude Code")
ok, out = check_with({"php", "timeout", "pip"}, "apt", modules_missing=True)
check(not ok, "not ok")
check("MISSING  PHP intl extension" in out and "MISSING  yt-dlp" in out and "MISSING  Claude Code" in out,
      "each missing thing is named")
plan = out.split("To install what's missing, run:")[1]
check("sudo apt install php-sqlite3 php-intl php-mbstring\n" in plan, "one apt command for all the PHP extensions")
check(plan.count("python3 -m pip install -r requirements.txt") == 1,
      "yt-dlp and the TypeSafe packages share one pip line")
check("curl -fsSL https://claude.ai/install.sh | bash" in plan and "Then run python3 start.py again." in out,
      "and Claude Code, then what next")

print("macOS with nothing but Python")
ok, out = check_with({"pip"}, "brew")
plan = out.split("To install what's missing, run:")[1]
check(plan.count("brew install php") == 1, "brew install php once (it brings the extensions)")
check("brew install coreutils" in plan and "gnubin" in plan, "coreutils, and how to put timeout on the PATH")

print("neither apt nor brew")
ok, out = check_with({"pip", "yt-dlp", "claude"}, None)
check("install PHP 8.1 or newer" in out and "install GNU coreutils" in out, "plain descriptions instead of commands")

print("Claude Code installed but not logged in")
ok, out = check_with(everything - {"login"}, "apt")
check(not ok and "MISSING  logged in to Claude Code" in out and "    claude auth login" in out,
      "reported, with claude auth login to fix it")

print("--no-typesafe")
ok, out = check_with(everything, "apt", need_typesafe=False, modules_missing=True)
check(ok and "TypeSafe" not in out, "TypeSafe's packages aren't checked when running without it")

print("ALL PASSED" if fails == 0 else f"FAILED {fails}")
sys.exit(1 if fails else 0)
