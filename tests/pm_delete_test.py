#!/usr/bin/env python3
"""
"Delete this conversation" means FOR ME (api/user_messages.php, 1.64.0, schema 70) — and since 1.73.0
(schema 90) it means INTO MY TRASH first, and for good only when I say so or the Trash runs out:
    python tests/pm_delete_test.py        (needs the local server and a bootstrapped database)

`message_threads` holds one row per pair, so a conversation cannot be deleted without deleting
somebody else's copy of it. What each side gets instead is a Trash edge (`u_*_trash_upto`, 1.73.0) and a
watermark (`u_*_cleared_id`, v70) of its own, and every read path of the Inbox shows only what is above
both.

This drives both accounts over HTTP and asks, after A has pressed Delete (op `trash`):
  · A's inbox does not list the thread, A's window is empty, A's unread count is 0 — while A's Trash
    lists it, whole, and opens it;
  · B's inbox lists it, whole, with its own unread count — nothing of B's has moved;
  · B writes once more: the thread is back for A, showing ONLY that message, and the preview and
    the unread count are about that message alone — the older part still in A's Trash;
  · a word that appears only in the deleted half is not an Inbox deep-search hit for A, is a Trash one,
    and still is an Inbox one for B;
  · the explicit operations answer as they should over HTTP: a stale Undo (restore from an edge that is
    no longer the Trash's) changes nothing; `purge` moves A's watermark over the Trash — for good; the
    old name `delete` is `trash`; `empty_trash`; each needs the CSRF token;
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
                 ("pm_live_seconds", "0"), ("pm_max_per_day", "50"), ("antispam_enabled", "0"),
                 ("pm_trash_days", "30"), ("pm_archive_returns", "1")):
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

    def marks():
        """A's and B's side of the thread: [cleared_id, trash_upto, trashed_at is set] each."""
        out = php("$st = $db->prepare('SELECT t.*, a.id AS aid FROM message_threads t"
                  " JOIN users a ON a.username = ? JOIN users b ON b.username = ?"
                  " WHERE (t.u_low = a.id AND t.u_high = b.id) OR (t.u_low = b.id AND t.u_high = a.id)');"
                  "$st->execute(['" + A + "', '" + B + "']); $r = $st->fetch(PDO::FETCH_ASSOC);"
                  "$sa = (int)$r['u_low'] === (int)$r['aid'] ? 'u_low' : 'u_high'; $sb = $sa === 'u_low' ? 'u_high' : 'u_low';"
                  "echo json_encode(['a' => [(int)$r[$sa . '_cleared_id'], (int)$r[$sa . '_trash_upto'], $r[$sa . '_trashed_at'] !== null],"
                  " 'b' => [(int)$r[$sb . '_cleared_id'], (int)$r[$sb . '_trash_upto'], $r[$sb . '_trashed_at'] !== null]]);")
        return json.loads(out)

    # ── A presses Delete: into A's Trash ──────────────────────────────────────────────────────
    s, j = ca.api("user_messages", "POST", {"op": "trash", "with": B})
    check("A deletes the conversation — into the Trash", s == 200 and j.get("success") and j.get("changed") and not j.get("final")
          and j.get("upto") == max(ids_before) and j.get("was") == 0, (s, j))
    check("… the answer says where it is now and what the tabs hold",
          (j.get("state") or {}).get("place") == "trash" and (j.get("counts") or {}).get("trash") == 1 and int(j.get("unread") or 0) == 0, j)
    mk = marks()
    check("exactly ONE side's Trash moved, to the last message there is — no watermark, nothing deleted",
          mk["a"] == [0, max(ids_before), True] and mk["b"] == [0, 0, False], (mk, max(ids_before)))
    s, j2 = ca.api("user_messages", "POST", {"op": "trash", "with": B})
    check("… and Delete again changes nothing (explicit, not a toggle)", s == 200 and j2.get("success") and not j2.get("changed"), j2)

    s, inbox_a = ca.api("user_messages")
    check("A's inbox does not list it any more", thread_of(inbox_a, B) is None, inbox_a.get("threads"))
    check("… and A's unread count is nothing", int(inbox_a.get("unread") or 0) == 0, inbox_a.get("unread"))
    s, trash_a = ca.api("user_messages&view=trash")
    tt = thread_of(trash_a, B)
    check("… A's Trash lists it, whole: five messages, the last one previewed",
          s == 200 and trash_a.get("view") == "trash" and tt is not None and tt.get("n") == 5 and WORD in (tt.get("preview") or "")
          and tt.get("until"), trash_a.get("threads"))
    s, view_a = ca.api("user_messages&with=" + B)
    check("… and opening it from anywhere shows what lies in the Trash, saying so",
          s == 200 and view_a.get("success") and view_a.get("part") == "trash" and len(view_a.get("rows") or []) == 5
          and (view_a.get("state") or {}).get("place") == "trash", (s, view_a.get("part"), view_a.get("state")))

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
    check("… while the older five wait in A's Trash (the window says how many)",
          view_a2.get("part") == "live" and (view_a2.get("state") or {}).get("trash") == 5
          and thread_of(ca.api("user_messages&view=trash")[1], B) is not None, view_a2.get("state"))

    # ── the poll agrees with the window it polls for ──────────────────────────────────────────
    php("setSetting($db, 'pm_live_seconds', '5');")
    s, poll_a = ca.api("user_messages&poll=1&with=" + B + "&after=0")
    check("the poll never hands back what A deleted either",
          s == 200 and len(poll_a.get("rows") or []) == 1, [r.get("id") for r in (poll_a.get("rows") or [])])
    php("setSetting($db, 'pm_live_seconds', '0');")

    # ── the deep search ───────────────────────────────────────────────────────────────────────
    s, deep_a = ca.api("user_messages&deep=1&search=" + WORD)
    check("a word said only in the deleted half is not a hit in A's Inbox",
          s == 200 and deep_a.get("deep") and thread_of(deep_a, B) is None, deep_a.get("threads"))
    s, deep_at = ca.api("user_messages&view=trash&deep=1&search=" + WORD)
    check("… it is one in A's Trash, where that half is", s == 200 and thread_of(deep_at, B) is not None, deep_at.get("threads"))
    s, deep_b = cb.api("user_messages&deep=1&search=" + WORD)
    check("… and still is in B's Inbox", s == 200 and thread_of(deep_b, A) is not None, deep_b.get("threads"))

    # ── the explicit operations, over HTTP ────────────────────────────────────────────────────
    s, j = ca.api("user_messages", "POST", {"op": "restore", "with": B, "to": 0, "from": max(ids_before) - 1})
    check("an Undo from an edge that is no longer the Trash's is a no-op, not an error",
          s == 200 and j.get("success") and not j.get("changed") and marks()["a"][1] == max(ids_before), (s, j))
    s, j = ca.api("user_messages", "POST", {"op": "purge", "with": B})
    mk = marks()
    check("purge: for good — A's watermark over the Trash, the Trash empty (edge 0, no moment)",
          s == 200 and j.get("success") and j.get("changed") and j.get("final") and mk["a"] == [max(ids_before), 0, False]
          and mk["b"] == [0, 0, False], (j, mk))
    s, j = ca.api("user_messages", "POST", {"op": "purge", "with": B})
    check("… purge again: nothing to do, no error", s == 200 and j.get("success") and not j.get("changed"), j)
    s, j = ca.api("user_messages", "POST", {"op": "restore", "with": B})
    check("… and nothing of it can be restored any more", s == 200 and not j.get("changed") and marks()["a"][0] == max(ids_before), j)
    # The old name (a page loaded before the update) is the Trash; then the Trash is emptied in one go.
    s, j = ca.api("user_messages", "POST", {"op": "delete", "with": B})
    check("the old name `delete` is `trash` now: restorable", s == 200 and j.get("op") == "trash" and j.get("changed") and not j.get("final"), j)
    s, j = ca.api("user_messages", "POST", {"op": "empty_trash"})
    check("empty_trash: one conversation deleted for good, the Trash empty", s == 200 and j.get("purged") == 1
          and (j.get("counts") or {}).get("trash") == 0 and marks()["a"][1] == 0, j)
    s, j = ca.api("user_messages", "POST", {"op": "archive", "with": B, "csrf_token": "nope"})
    check("… and every one of them needs the page's CSRF token", s == 403 and not j.get("success"), (s, j))
    s, j = ca.api("user_messages", "POST", {"op": "toggle", "with": B})
    check("… and an operation that does not exist is refused, not guessed", s == 400 and j.get("error") == "unknown_op", (s, j))

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
