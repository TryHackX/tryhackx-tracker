#!/usr/bin/env python3
"""
A reported message's reporter hears back (api/admin/message_report_action.php, 1.54.0):
    python tests/message_report_test.py      (needs the local server and a bootstrapped database)

As the panel (an admin session over HTTP): close with an answer, reopen, remove the message, silence
the account — and after each, who was told what. The reporter gets the outcome and the moderator's
words; the removed message's author gets the fact; a lifted mute says nothing to anybody about the
report. The answer is kept on the report row and comes back with the listing.
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
ADMIN_USER = os.environ.get("SMOKE_ADMIN_USER", "admin")
ADMIN_PASS = os.environ.get("SMOKE_ADMIN_PASS", "admin123")
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
BOOT = ("$root = '" + ROOT.replace("\\", "/") + "';"
        "require_once $root . '/config/app.php'; require_once $root . '/config/database.php';"
        "require_once $root . '/includes/settings.php'; require_once $root . '/includes/functions.php';"
        "require_once $root . '/includes/schema.php'; require_once $root . '/includes/whitelist.php';"
        "require_once $root . '/includes/mail.php'; require_once $root . '/includes/users.php';"
        "require_once $root . '/includes/richtext.php'; require_once $root . '/includes/people.php';"
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


class Session:
    def __init__(self):
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))
        self.csrf = None

    def open_admin(self):
        with self.opener.open(BASE + "?action=admin", timeout=30) as r:
            html = r.read().decode("utf-8", "replace")
        m = re.search(r'name="csrf_token"[^>]*value="([^"]+)"', html) or re.search(r'data-csrf="([^"]+)"', html) or re.search(r"CSRF\s*=\s*'([^']+)'", html)
        self.csrf = m.group(1) if m else None

    def call(self, endpoint, body=None):
        url = BASE + "api.php?endpoint=" + endpoint
        data = None
        h = {"Accept": "application/json"}
        if body is not None:
            body = dict(body)
            if self.csrf and "csrf_token" not in body:
                body["csrf_token"] = self.csrf
            data = json.dumps(body).encode()
            h["Content-Type"] = "application/json"
            if self.csrf:
                h["X-CSRF-Token"] = self.csrf
        req = urllib.request.Request(url, data=data, method="POST" if data is not None else "GET", headers=h)
        try:
            with self.opener.open(req, timeout=30) as r:
                return r.status, json.loads(r.read().decode() or "{}")
        except urllib.error.HTTPError as e:
            try:
                return e.code, json.loads(e.read().decode() or "{}")
            except Exception:
                return e.code, {}


NAMES = ("rpalice", "rpbob")
clear_throttles()
was_users = php("echo $cfg['users_enabled'] ?? '';")
php("setSetting($db, 'users_enabled', '1');")
ids = json.loads(php(
    "foreach (['rpalice','rpbob'] as $u) { $db->prepare('DELETE FROM users WHERE username = ?')->execute([$u]); userCreate($db, $cfg, $u, $u . '@example.org', 'SmokePass123!', '127.0.0.1'); }"
    "$db->exec(\"UPDATE users SET email_verified = 1 WHERE username IN ('rpalice','rpbob')\");"
    "$a = (int)$db->query(\"SELECT id FROM users WHERE username = 'rpalice'\")->fetchColumn();"
    "$b = (int)$db->query(\"SELECT id FROM users WHERE username = 'rpbob'\")->fetchColumn();"
    "$t = pmThreadFor($db, $a, $b);"
    "$ins = $db->prepare('INSERT INTO user_messages (thread_id, sender_id, body, body_format) VALUES (?, ?, ?, ?)');"
    "$ins->execute([(int)$t['id'], $b, 'the line complained about', 'bbcode']); $m1 = (int)$db->lastInsertId();"
    "$ins->execute([(int)$t['id'], $b, 'a second line, complained about later', 'bbcode']); $m2 = (int)$db->lastInsertId();"
    "$rep = $db->prepare('INSERT INTO message_reports (message_id, context_id, thread_id, reporter_id, reported_user_id, reason) VALUES (?, NULL, ?, ?, ?, ?)');"
    "$rep->execute([$m1, (int)$t['id'], $a, $b, 'rude']); $r1 = (int)$db->lastInsertId();"
    "$rep->execute([$m2, (int)$t['id'], $a, $b, 'rude again']); $r2 = (int)$db->lastInsertId();"
    "echo json_encode(['a' => $a, 'b' => $b, 't' => (int)$t['id'], 'm1' => $m1, 'm2' => $m2, 'r1' => $r1, 'r2' => $r2]);"
))
check("the fixtures exist", ids.get("r1", 0) > 0 and ids.get("r2", 0) > 0, ids)


def notes(uid, typ):
    out = php("$st = $db->prepare(\"SELECT title, body FROM user_notifications WHERE user_id = ? AND type = ? ORDER BY id\"); $st->execute([" + str(uid) + ", '" + typ + "']); echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));")
    try:
        return json.loads(out)
    except Exception:
        return []


try:
    s = Session()
    s.open_admin()
    st, j = s.call("admin/login", {"username": ADMIN_USER, "password": ADMIN_PASS})
    check("the panel signs in", st == 200 and j.get("success"), (st, j))

    # ── close, with an answer ─────────────────────────────────────────────
    st, j = s.call("admin/message_report_action", {"id": ids["r1"], "action": "close", "note": "for the log", "reply": "Thanks — dealt with."})
    check("close answers 200", st == 200 and j.get("success") and j.get("status") == "closed", (st, j))
    a = notes(ids["a"], "report")
    check("the reporter hears that it was closed, with the moderator's words",
          len(a) == 1 and "closed" in a[0]["title"] and "rpbob" in a[0]["title"] and "dealt with" in a[0]["body"], a)
    check("… and the note for the log did not travel", "for the log" not in a[0]["body"] if a else False, a)
    check("the other account heard nothing about a close", notes(ids["b"], "account") == [], notes(ids["b"], "account"))
    st, j = s.call("admin/fetch_message_reports&status=closed&search=rpbob")
    row = next((r for r in (j.get("reports") or []) if r.get("id") == ids["r1"]), None)
    check("the listing carries the answer and the note", row and row.get("reply") == "Thanks — dealt with." and row.get("note") == "for the log", (st, row))

    # ── reopen says nothing ───────────────────────────────────────────────
    st, j = s.call("admin/message_report_action", {"id": ids["r1"], "action": "reopen", "note": "", "reply": ""})
    check("reopen answers 200", st == 200 and j.get("status") == "open", (st, j))
    check("… and tells the reporter nothing", len(notes(ids["a"], "report")) == 1)

    # ── the message is removed: both hear ─────────────────────────────────
    st, j = s.call("admin/message_report_action", {"id": ids["r1"], "action": "delete_message", "note": "", "reply": ""})
    check("delete_message answers 200", st == 200 and j.get("deleted") == ids["m1"], (st, j))
    a = notes(ids["a"], "report")
    check("the reporter hears that the line was removed, with the generic thank-you", len(a) == 2 and "removed" in a[1]["title"] and "Thank you" in a[1]["body"], a)
    b = notes(ids["b"], "account")
    check("the author hears that one of their messages was removed — the fact, no note, no reply", len(b) == 1 and "removed" in b[0]["title"] and "for the log" not in b[0]["body"], b)
    gone = php("echo (int)$db->query('SELECT COUNT(*) FROM user_messages WHERE id = " + str(ids["m1"]) + "')->fetchColumn();")
    check("… and the line is gone", gone == "0", gone)

    # ── a mute: handled, not named ────────────────────────────────────────
    st, j = s.call("admin/message_report_action", {"id": ids["r2"], "action": "mute", "days": 7, "note": "", "reply": ""})
    check("mute answers 200", st == 200 and j.get("action") == "mute", (st, j))
    a = notes(ids["a"], "report")
    check("the reporter hears 'handled', and neither 'mute' nor 'silenced'",
          len(a) == 3 and "handled" in a[2]["title"] and "mute" not in (a[2]["title"] + a[2]["body"]).lower() and "silenc" not in (a[2]["title"] + a[2]["body"]).lower(), a)
    b = notes(ids["b"], "account")
    check("the muted account still hears that it was silenced (1.49.0)", len(b) == 2 and "silenced" in b[1]["title"], b)
    st, j = s.call("admin/message_report_action", {"id": ids["r2"], "action": "unmute", "note": "", "reply": ""})
    check("lifting the mute tells the reporter nothing new", st == 200 and len(notes(ids["a"], "report")) == 3)
finally:
    php("foreach ([" + str(ids.get("a", 0)) + ", " + str(ids.get("b", 0)) + "] as $u) { $db->prepare('DELETE FROM user_notifications WHERE user_id = ?')->execute([$u]); }"
        "$db->prepare('DELETE FROM message_reports WHERE thread_id = ?')->execute([" + str(ids.get("t", 0)) + "]);"
        "$db->prepare('DELETE FROM user_messages WHERE thread_id = ?')->execute([" + str(ids.get("t", 0)) + "]);"
        "$db->prepare('DELETE FROM message_threads WHERE id = ?')->execute([" + str(ids.get("t", 0)) + "]);"
        "$db->prepare(\"DELETE FROM users WHERE username IN ('rpalice','rpbob')\")->execute();"
        + ("setSetting($db, 'users_enabled', '" + was_users + "');" if was_users else ""))
    clear_throttles()

print("\n%d checks, %d failed" % (n, fails))
sys.exit(1 if fails else 0)
