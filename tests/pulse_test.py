#!/usr/bin/env python3
"""
The account badge's pulse (api/user_pulse.php, 1.55.0):
    python tests/pulse_test.py           (needs the local server and a bootstrapped database)

A visitor gets 401 and `live: 0`; a member gets the two counts and the cadence from Settings; the
counts move when a notification lands; the badge markup carries the cadence; 0 in Settings means
the endpoint says 0 (and the page's loop never starts); the session is released before the reads.
"""
import http.cookiejar
import json
import os
import re
import subprocess
import sys
import urllib.error
import urllib.request

BASE = os.environ.get("SMOKE_BASE", "http://127.0.0.1:8089/")
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
BOOT = ("$root = '" + ROOT.replace("\\", "/") + "';"
        "require_once $root . '/config/app.php'; require_once $root . '/config/database.php';"
        "require_once $root . '/includes/settings.php'; require_once $root . '/includes/functions.php';"
        "require_once $root . '/includes/schema.php'; require_once $root . '/includes/whitelist.php';"
        "require_once $root . '/includes/mail.php'; require_once $root . '/includes/users.php';"
        "$db = getDb(); $cfg = getSettings($db); ensureSchema($db, $cfg);")

fails = 0
n = 0


def check(name, cond, info=""):
    global fails, n
    n += 1
    print(("PASS " if cond else "FAIL ") + name + ("" if cond or not info else "  -> " + str(info)[:300]))
    if not cond:
        fails += 1


def php(code):
    p = subprocess.run(["php", "-d", "display_errors=1", "-d", "error_reporting=E_ALL & ~E_DEPRECATED", "-r", BOOT + code],
                       capture_output=True, text=True, cwd=ROOT, encoding="utf-8", errors="replace")
    if p.returncode != 0:
        print("php failed: " + (p.stderr or p.stdout)[:400])
    return p.stdout.strip()


def clear_throttles():
    for f in ("login_attempts.json", "rate_limits.json", "rate_limits.json.lock"):
        try:
            os.remove(os.path.join(ROOT, "config", f))
        except OSError:
            pass


class Client:
    def __init__(self):
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))
        self.csrf = None

    def page(self, action):
        with self.opener.open(BASE + "?action=" + action, timeout=30) as r:
            html = r.read().decode("utf-8", "replace")
        m = re.search(r'name="csrf_token" value="([^"]+)"', html)
        if m:
            self.csrf = m.group(1)
        return r.status, html

    def api(self, endpoint, method="GET", body=None):
        url = BASE + "api.php?endpoint=" + endpoint
        data = json.dumps(body).encode() if body is not None else None
        h = {"Accept": "application/json"}
        if data is not None:
            h["Content-Type"] = "application/json"
        req = urllib.request.Request(url, data=data, method=method, headers=h)
        try:
            with self.opener.open(req, timeout=30) as r:
                return r.status, json.loads(r.read().decode() or "{}")
        except urllib.error.HTTPError as e:
            try:
                return e.code, json.loads(e.read().decode() or "{}")
            except Exception:
                return e.code, {}


USER, PASS = "pulseuser", "SmokePass123!"
clear_throttles()
was = {k: php("echo $cfg['" + k + "'] ?? '';") for k in ("users_enabled", "site_live_seconds")}
php("setSetting($db, 'users_enabled', '1'); setSetting($db, 'site_live_seconds', '45');"
    "$db->prepare('DELETE FROM users WHERE username = ?')->execute(['" + USER + "']);"
    "userCreate($db, $cfg, '" + USER + "', 'pulse@example.org', '" + PASS + "', '127.0.0.1');"
    "$db->exec(\"UPDATE users SET email_verified = 1 WHERE username = '" + USER + "'\");"
    "$db->prepare('DELETE FROM user_notifications WHERE user_id = (SELECT id FROM users WHERE username = ?)')->execute(['" + USER + "']);")
uid = int(php("echo (int)$db->query(\"SELECT id FROM users WHERE username = '" + USER + "'\")->fetchColumn();") or 0)
check("the account exists", uid > 0, uid)

try:
    guest = Client()
    s, j = guest.api("user_pulse")
    check("a visitor gets 401 and no cadence", s == 401 and j.get("live") == 0, (s, j))

    me = Client()
    me.page("login")
    s, j = me.api("user_login", "POST", {"csrf_token": me.csrf, "login": USER, "password": PASS})
    check("the member signs in", s == 200 and j.get("success"), (s, j))
    s, j = me.api("user_pulse")
    check("a member gets the two counts and the cadence", s == 200 and j.get("success") and j.get("unread") == 0 and j.get("unread_pm") == 0 and j.get("live") == 45, (s, j))
    check("… and nothing else — numbers, never content", set(j.keys()) <= {"success", "unread", "unread_pm", "live"}, list(j.keys()))
    php("userNotify($db, " + str(uid) + ", 'account', 'Pulse fixture', 'a line');")
    s, j = me.api("user_pulse")
    check("a notification that landed is counted on the next pulse", j.get("unread") == 1, j)
    s, html = me.page("")
    check("the badge carries the cadence for the page's loop", 'id="nav-unread" hidden data-pulse="45"' in html)
    src = open(os.path.join(ROOT, "assets", "js", "app.js"), encoding="utf-8").read()
    check("the loop reads it, skips hidden tabs, and shares one answer across tabs",
          "dataset.pulse" in src and "document.hidden || inFlight" in src and "localStorage.setItem(KEY" in src and "addEventListener('storage'" in src)

    php("setSetting($db, 'site_live_seconds', '0');")
    s, j = me.api("user_pulse")
    check("0 in Settings: the endpoint says 0, which stops the loop", j.get("live") == 0, j)
    s, html = me.page("")
    check("… and the badge carries 0, so the loop never starts", 'data-pulse="0"' in html)
    php("setSetting($db, 'site_live_seconds', '5');")
    s, j = me.api("user_pulse")
    check("a value below the floor is raised to it", j.get("live") == 10, j)
    php("setSetting($db, 'site_live_seconds', '9999');")
    s, j = me.api("user_pulse")
    check("… and above the ceiling lowered to it", j.get("live") == 300, j)

    ep = open(os.path.join(ROOT, "api", "user_pulse.php"), encoding="utf-8").read()
    check("the endpoint releases the session before it reads", "session_write_close()" in ep and ep.index("session_write_close()") < ep.index("userUnreadCount"))
finally:
    php("$db->prepare('DELETE FROM user_notifications WHERE user_id = ?')->execute([" + str(uid) + "]);"
        "$db->prepare('DELETE FROM users WHERE username = ?')->execute(['" + USER + "']);"
        + "".join("setSetting($db, '%s', '%s');" % (k, v) for k, v in was.items() if v != ""))
    clear_throttles()

print("\n%d checks, %d failed" % (n, fails))
sys.exit(1 if fails else 0)
