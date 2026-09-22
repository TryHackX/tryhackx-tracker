#!/usr/bin/env python3
"""
"Delete this conversation" means FOR ME (api/user_messages.php, 1.64.0, schema 70):
    python tests/pm_delete_test.py        (needs the local server and a bootstrapped database)

`message_threads` holds one row per pair, so a conversation cannot be deleted without deleting
somebody else's copy of it. What each side gets instead is a watermark — the id of the last message
they had when they pressed delete — and every read path shows only what is above their own.

This drives both accounts over HTTP and asks, after A has deleted:
  · A's inbox does not list the thread, A's window is empty, A's unread count is 0;
  · B's inbox lists it, whole, with its own unread count — nothing of B's has moved;
  · B writes once more: the thread is back for A, showing ONLY that message, and the preview and
    the unread count are about that message alone;
  · a word that appears only in the deleted half is not a deep-search hit for A and still is for B;
  · nothing was removed from `user_messages` — a reported message has to stay readable to the panel.

Self-cleaning: the two accounts, their thread and its messages go in the finally, and every setting
it writes is put back.
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
        "require_once $root . '/includes/richtext.php'; require_once $root . '/includes/people.php';"
        "$db = getDb(); $cfg = getSettings($db); ensureSchema($db, $cfg);")

A, B, PASS = "pmdel_a", "pmdel_b", "SmokePass123!"
WORD = "kalamazoo"          # said only in the half that gets deleted

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
            os.unlink(os.path.join(ROOT, "config", f))
        except OSError:
            pass


class Client:
    def __init__(self):
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))
        self.csrf = None

    def page(self, action):
        with self.opener.open(BASE + ("?action=" + action if action else ""), timeout=30) as r:
            html = r.read().decode("utf-8", "replace")
        m = re.search(r'name="csrf_token" value="([^"]+)"', html)
        if m:
            self.csrf = m.group(1)
        return r.status, html

    def api(self, endpoint, method="GET", body=None):
        url = BASE + "api.php?endpoint=" + endpoint
        # This endpoint reads the token out of the BODY (api/user_messages.php), so every POST
        # carries it there; the header goes with it because the rest of the site's do.
        if body is not None and "csrf_token" not in body:
            body["csrf_token"] = self.csrf
        data = json.dumps(body).encode() if body is not None else None
        h = {"Accept": "application/json"}
        if data is not None:
            h["Content-Type"] = "application/json"
            h["X-CSRF-Token"] = self.csrf or ""
        req = urllib.request.Request(url, data=data, method=method, headers=h)
        try:
            with self.opener.open(req, timeout=30) as r:
                return r.status, json.loads(r.read().decode() or "{}")
        except urllib.error.HTTPError as e:
            try:
                return e.code, json.loads(e.read().decode() or "{}")
            except Exception:
                return e.code, {}

    def signin(self, who):
        self.page("login")
        s, j = self.api("user_login", "POST", {"csrf_token": self.csrf, "login": who, "password": PASS})
        # The account page is what carries the CSRF token every later POST uses.
        self.page("account")
        return s == 200 and bool(j.get("success"))


def thread_of(j, who):
    for t in (j.get("threads") or []):
        if t.get("with") == who:
            return t
    return None


was = {}
try:
    clear_throttles()
    # Every switch this test relies on, said out loud.
    for k, v in (("users_enabled", "1"), ("pm_enabled", "1"), ("pm_who", "all"),
                 ("pm_live_seconds", "0"), ("pm_max_per_day", "50")):
        was[k] = php("echo (string)($cfg['" + k + "'] ?? '');")
        php("setSetting($db, '" + k + "', '" + v + "');")
    member_before = php("$st = $db->query(\"SELECT permissions FROM user_groups WHERE slug = 'member'\"); echo (string)$st->fetchColumn();")
    php("$db->exec(\"UPDATE user_groups SET permissions = JSON_MERGE_PATCH(permissions,"
        " '{\\\"pm.send\\\":true,\\\"pm.report\\\":true}') WHERE slug = 'member'\");")
    php("foreach (['" + A + "', '" + B + "'] as $nm) { $st = $db->prepare('SELECT id FROM users WHERE username = ?');"
        " $st->execute([$nm]); if ($id = (int)$st->fetchColumn()) userDeleteCascade($db, $id); }"
        "userCreate($db, $cfg, '" + A + "', 'pmdela@example.org', '" + PASS + "', '127.0.0.1');"
        "userCreate($db, $cfg, '" + B + "', 'pmdelb@example.org', '" + PASS + "', '127.0.0.1');"
        "$db->exec(\"UPDATE users SET email_verified = 1 WHERE username IN ('" + A + "', '" + B + "')\");")

    ca, cb = Client(), Client()
    check("both accounts sign in", ca.signin(A) and cb.signin(B))

    # ── a conversation of five lines: three from A, two from B ────────────────────────────────
    for i in (1, 2, 3):
        ca.api("user_messages", "POST", {"op": "send", "to": B, "body": "from A number %d %s" % (i, WORD), "format": "bbcode"})
    for i in (1, 2):
        cb.api("user_messages", "POST", {"op": "send", "to": A, "body": "from B number %d %s" % (i, WORD), "format": "bbcode"})
    s, opened = ca.api("user_messages&with=" + B)
    check("A sees the whole conversation before deleting", s == 200 and len(opened.get("rows") or []) == 5,
          (s, len(opened.get("rows") or [])))
    ids_before = [r["id"] for r in (opened.get("rows") or [])]

    # A reports one of B's lines: the panel must still be able to read it afterwards.
    reported = [r["id"] for r in (opened.get("rows") or []) if not r["mine"]][0]
    s, j = ca.api("user_messages", "POST", {"op": "report", "message": reported, "reason": "for the record"})
    check("A reports one of B's lines", s == 200 and j.get("success"), (s, j))

    # ── A deletes ─────────────────────────────────────────────────────────────────────────────
    s, j = ca.api("user_messages", "POST", {"op": "delete", "with": B})
    check("A deletes the conversation", s == 200 and j.get("success"), (s, j))
    mark = php("$st = $db->prepare('SELECT u_low_cleared_id, u_high_cleared_id FROM message_threads t"
               " JOIN users a ON a.username = ? JOIN users b ON b.username = ?"
               " WHERE (t.u_low = a.id AND t.u_high = b.id) OR (t.u_low = b.id AND t.u_high = a.id)');"
               "$st->execute(['" + A + "', '" + B + "']); $r = $st->fetch(PDO::FETCH_ASSOC);"
               "echo (int)$r['u_low_cleared_id'], ',', (int)$r['u_high_cleared_id'];")
    lo, hi = [int(x) for x in mark.split(",")]
    check("exactly ONE side's watermark moved, to the last message there is",
          (lo == max(ids_before) and hi == 0) or (hi == max(ids_before) and lo == 0), (mark, max(ids_before)))

    s, inbox_a = ca.api("user_messages")
    check("A's inbox does not list it any more", thread_of(inbox_a, B) is None, inbox_a.get("threads"))
    check("… and A's unread count is nothing", int(inbox_a.get("unread") or 0) == 0, inbox_a.get("unread"))
    s, view_a = ca.api("user_messages&with=" + B)
    check("… and opening it shows an empty conversation, not an error",
          s == 200 and view_a.get("success") and (view_a.get("rows") or []) == [], (s, view_a))

    s, inbox_b = cb.api("user_messages")
    tb = thread_of(inbox_b, A)
    check("B's inbox still lists it", tb is not None, inbox_b.get("threads"))
    s, view_b = cb.api("user_messages&with=" + A)
    check("… whole: nothing of B's copy moved", len(view_b.get("rows") or []) == 5, len(view_b.get("rows") or []))

    # ── B writes once more: it comes back for A, showing only that ────────────────────────────
    s, j = cb.api("user_messages", "POST", {"op": "send", "to": A, "body": "after the deletion", "format": "bbcode"})
    check("B writes again", s == 200 and j.get("success"), (s, j))
    s, inbox_a2 = ca.api("user_messages")
    ta = thread_of(inbox_a2, B)
    check("the conversation is back in A's inbox", ta is not None, inbox_a2.get("threads"))
    check("… its preview is the new message, not the old ones",
          ta and ta.get("preview") == "after the deletion", ta and ta.get("preview"))
    check("… and it counts ONE waiting, not three", ta and int(ta.get("unread") or 0) == 1, ta and ta.get("unread"))
    check("… which is also the number on the badge", int(inbox_a2.get("unread") or 0) == 1, inbox_a2.get("unread"))
    s, view_a2 = ca.api("user_messages&with=" + B)
    rows_a2 = view_a2.get("rows") or []
    check("… and A's window holds that one message and nothing before it",
          len(rows_a2) == 1 and "after the deletion" in rows_a2[0]["html"], [r["html"] for r in rows_a2])

    # ── the poll agrees with the window it polls for ──────────────────────────────────────────
    php("setSetting($db, 'pm_live_seconds', '5');")
    s, poll_a = ca.api("user_messages&poll=1&with=" + B + "&after=0")
    check("the poll never hands back what A deleted either",
          s == 200 and len(poll_a.get("rows") or []) == 1, [r.get("id") for r in (poll_a.get("rows") or [])])
    php("setSetting($db, 'pm_live_seconds', '0');")

    # ── the deep search ───────────────────────────────────────────────────────────────────────
    s, deep_a = ca.api("user_messages&deep=1&search=" + WORD)
    check("a word said only in the deleted half is not a hit for A",
          s == 200 and deep_a.get("deep") and thread_of(deep_a, B) is None, deep_a.get("threads"))
    s, deep_b = cb.api("user_messages&deep=1&search=" + WORD)
    check("… and still is for B", s == 200 and thread_of(deep_b, A) is not None, deep_b.get("threads"))

    # ── nothing was destroyed ─────────────────────────────────────────────────────────────────
    left = php("$st = $db->prepare('SELECT COUNT(*) FROM user_messages WHERE id IN ("
               + ",".join(str(i) for i in ids_before) + ")'); $st->execute(); echo (int)$st->fetchColumn();")
    check("every message is still stored — the watermark hides, it does not delete",
          left == str(len(ids_before)), (left, len(ids_before)))
    rep = php("$st = $db->prepare('SELECT COUNT(*) FROM message_reports r JOIN user_messages m ON m.id = r.message_id"
              " WHERE r.message_id = ?'); $st->execute([" + str(reported) + "]); echo (int)$st->fetchColumn();")
    check("… and the reported one is still readable to the panel, with its report", rep == "1", rep)

finally:
    php("$db->prepare('DELETE r FROM message_reports r JOIN users u ON u.id = r.reporter_id WHERE u.username = ?')->execute(['" + A + "']);"
        "foreach (['" + A + "', '" + B + "'] as $nm) { $st = $db->prepare('SELECT id FROM users WHERE username = ?');"
        " $st->execute([$nm]); if ($id = (int)$st->fetchColumn()) userDeleteCascade($db, $id); }")
    try:
        if member_before:
            php("$st = $db->prepare(\"UPDATE user_groups SET permissions = ? WHERE slug = 'member'\");"
                "$st->execute([" + json.dumps(member_before) + "]);")
    except NameError:
        pass
    for k, v in was.items():
        php("setSetting($db, '" + k + "', " + json.dumps(v) + ");")
    clear_throttles()

print("\n%d checks, %d failed" % (n, fails))
sys.exit(1 if fails else 0)
