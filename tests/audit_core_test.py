#!/usr/bin/env python3
"""
The endpoints that apply the 1.56.x audit's answers, over real HTTP:
    python tests/audit_core_test.py      (needs the local server and a bootstrapped database)

The library half — userIsActive(), adminSessionValid(), currentUser(), the remember-token rotation,
the panel's own TOTP and the inbox counters — is tests/audit_core_test.php. What only a real request
can show is here:

  * a timed ban ends by itself: the sign-in form lets yesterday's ban in and turns tomorrow's away;
  * the account endpoint that verifies a password is a guessing surface, and has a budget;
  * rotating the OWNER's password reaches the mirror row, or the old one keeps opening the panel
    through the account sign-in;
  * a moderator may edit a member and may not touch somebody who can open the panel;
  * the report card: reopening keeps what the first moderator wrote, "for ever" is a date, and a ban
    the owner made from the Users page is not this card's to lift;
  * a hidden block hides the conversation, not merely the button;
  * the per-day message ceiling refuses the message after the last one it allows;
  * Settings clamps a ceiling instead of storing a typo.

Leaves the panel's password, the settings it touched and every account it made exactly as it found
them — including on a failure, which is why the whole body is inside one try/finally.
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
NEW_ADMIN_PASS = "NewPass123!x"
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
HASH_FILE = os.path.join(ROOT, "config", "hash.txt")
PASS = "SmokePass123!"
BOOT = ("$root = '" + ROOT.replace("\\", "/") + "';"
        "require_once $root . '/config/app.php'; require_once $root . '/config/database.php';"
        "require_once $root . '/includes/settings.php'; require_once $root . '/includes/functions.php';"
        "require_once $root . '/includes/schema.php'; require_once $root . '/includes/whitelist.php';"
        "require_once $root . '/includes/mail.php'; require_once $root . '/includes/users.php';"
        "require_once $root . '/includes/richtext.php'; require_once $root . '/includes/people.php';"
        "$db = getDb(); $cfg = getSettings($db); ensureSchema($db, $cfg);")

NAMES = ["audhttp", "audtarget", "audmoder", "audstaff", "audplain",
         "audrep1", "audrep2", "audfa", "audfb", "audma", "audmb", "audpa", "audpb"]

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


def sql(statement):
    """One statement, nothing read back — the fixture knob these checks turn between requests."""
    return php("$db->exec(\"" + statement + "\");")


def col(query):
    return php("echo (string)($db->query(\"" + query + "\")->fetchColumn() ?? '');")


def clear_throttles():
    for f in ("login_attempts.json", "rate_limits.json", "rate_limits.json.lock"):
        try:
            os.remove(os.path.join(ROOT, "config", f))
        except OSError:
            pass


class Client:
    """One browser. `page` scrapes the CSRF token the way a form or the panel shell hands it over."""

    def __init__(self):
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))
        self.csrf = None

    def page(self, action=""):
        with self.opener.open(BASE + "?action=" + action, timeout=30) as r:
            html = r.read().decode("utf-8", "replace")
        m = (re.search(r'name="csrf_token"[^>]*value="([^"]+)"', html)
             or re.search(r'data-csrf="([^"]+)"', html))
        if m:
            self.csrf = m.group(1)
        return r.status, html

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

    def sign_in(self, username, password=PASS):
        self.page("login")
        return self.call("user_login", {"login": username, "password": password})

    def open_admin(self):
        self.page("admin")
        return self.call("admin/login", {"username": ADMIN_USER, "password": ADMIN_PASS})


# ── fixtures ──────────────────────────────────────────────────────────────────
clear_throttles()
was = {k: php("echo $cfg['" + k + "'] ?? '';") for k in ("users_enabled", "friends_enabled", "pm_enabled", "pm_max_per_day", "fav_max_per_user", "auth_bridge_ttl")}
admin_name = php("echo (string)($cfg['admin_username'] ?? 'admin');") or "admin"
hash_backup = open(HASH_FILE, "rb").read() if os.path.isfile(HASH_FILE) else None
# THE OWNER'S MIRROR ROW HAS TO BE MADE HERE WHEN IT IS NOT THERE.
#
# `users` is TRUNCATEd by tests/users_test.php, and the row is seeded by a data migration that only
# runs on a schema bump — so on a database any other suite has been through, the row the panel
# password is mirrored into simply does not exist, and a test that assumed it would quietly check
# nothing. Made the way includes/schema.php makes it, and taken away again at the end if it was this
# file that made it.
mirror_backup = json.loads(php(
    "$st = $db->prepare('SELECT id, pass_hash, sessions_valid_from FROM users WHERE username = ?');"
    "$st->execute(['" + admin_name + "']); $r = $st->fetch(PDO::FETCH_ASSOC);"
    "if ($r) { echo json_encode(['made' => false] + $r); }"
    "else {"
    "  $db->prepare('INSERT INTO users (username, email, pass_hash, email_verified) VALUES (?, NULL, ?, 1)')"
    "     ->execute(['" + admin_name + "', ADMIN_PASSWORD_HASH]);"
    "  $id = (int)$db->lastInsertId();"
    "  $g = (int)($db->query(\"SELECT id FROM user_groups WHERE slug = 'admin'\")->fetchColumn() ?: 0);"
    "  if ($g > 0) { $db->prepare(\"INSERT IGNORE INTO user_group_members (user_id, group_id, granted_by, note)"
    " VALUES (?, ?, 'audit test', 'panel admin')\")->execute([$id, $g]); }"
    "  echo json_encode(['made' => true, 'id' => $id, 'pass_hash' => '', 'sessions_valid_from' => 0]);"
    "}") or "{}")

php("setSetting($db, 'users_enabled', '1'); setSetting($db, 'friends_enabled', '1'); setSetting($db, 'pm_enabled', '1');")   # the follow and message checks need both features on, whatever the previous suite left
ids = json.loads(php(
    "$names = ['" + "','".join(NAMES) + "'];"
    "$in = \"'\" . implode(\"','\", $names) . \"'\";"
    "$db->exec(\"DELETE FROM user_tokens WHERE user_id IN (SELECT id FROM users WHERE username IN ($in))\");"
    "$db->exec(\"DELETE FROM users WHERE username IN ($in)\");"
    "$db->exec(\"DELETE FROM user_groups WHERE slug = 'audgrp'\");"
    "foreach ($names as $u) { userCreate($db, $cfg, $u, $u . '@example.org', '" + PASS + "', '127.0.0.1'); }"
    "$db->exec(\"UPDATE users SET email_verified = 1, pm_who = 'all' WHERE username IN ($in)\");"
    "$db->prepare(\"INSERT INTO user_groups (slug, name, description, color, priority, is_default, is_system, permissions)"
    " VALUES ('audgrp', 'Audit Mod', 'tests/audit_core_test.py', '', 400, 0, 0, ?)\")"
    "   ->execute([json_encode(['panel.access' => true, 'panel.users.edit' => true])]);"
    "$g = userGroupBySlug($db, 'audgrp');"
    "$ids = []; foreach ($names as $u) { $st = $db->prepare('SELECT id FROM users WHERE username = ?'); $st->execute([$u]); $ids[$u] = (int)$st->fetchColumn(); }"
    "userGrantGroup($db, $ids['audmoder'], (int)$g['id'], null, 'audit test', '', false);"
    "userGrantGroup($db, $ids['audstaff'], (int)$g['id'], null, 'audit test', '', false);"
    "$t = pmThreadFor($db, $ids['audrep1'], $ids['audrep2']);"
    "$ins = $db->prepare('INSERT INTO user_messages (thread_id, sender_id, body, body_format) VALUES (?, ?, ?, ?)');"
    "$ins->execute([(int)$t['id'], $ids['audrep2'], 'the line complained about', 'bbcode']); $m1 = (int)$db->lastInsertId();"
    "$ins->execute([(int)$t['id'], $ids['audrep2'], 'a second line', 'bbcode']); $m2 = (int)$db->lastInsertId();"
    "$rep = $db->prepare('INSERT INTO message_reports (message_id, context_id, thread_id, reporter_id, reported_user_id, reason) VALUES (?, NULL, ?, ?, ?, ?)');"
    "$rep->execute([$m1, (int)$t['id'], $ids['audrep1'], $ids['audrep2'], 'rude']); $ids['r1'] = (int)$db->lastInsertId();"
    "$rep->execute([$m2, (int)$t['id'], $ids['audrep1'], $ids['audrep2'], 'rude again']); $ids['r2'] = (int)$db->lastInsertId();"
    "$ids['thread'] = (int)$t['id']; $ids['group'] = (int)$g['id']; echo json_encode($ids);") or "{}")
check("every fixture account exists", all(ids.get(u, 0) > 0 for u in NAMES), ids)
check("the two reports and the group exist", ids.get("r1", 0) > 0 and ids.get("r2", 0) > 0 and ids.get("group", 0) > 0, ids)
check("the owner is mirrored into the user list", int(mirror_backup.get("id") or 0) > 0, mirror_backup)

try:
    # ── 1. a timed ban ends by itself, at the sign-in form ────────────────────
    clear_throttles()
    sql("UPDATE users SET status = 'banned', banned_until = NOW() - INTERVAL 1 DAY WHERE id = " + str(ids["audhttp"]))
    st, j = Client().sign_in("audhttp")
    check("a ban that ran out yesterday lets the account back in", st == 200 and j.get("success") is True, (st, j))
    sql("UPDATE users SET status = 'banned', banned_until = NOW() + INTERVAL 1 DAY WHERE id = " + str(ids["audhttp"]))
    st, j = Client().sign_in("audhttp")
    check("a ban that runs until tomorrow turns it away with 403", st == 403, (st, j))
    # NULL is not a date in the past. If it ever reads as one, every permanent ban lifts itself.
    sql("UPDATE users SET status = 'banned', banned_until = NULL WHERE id = " + str(ids["audhttp"]))
    st, j = Client().sign_in("audhttp")
    check("a ban with no date at all is still a ban", st == 403, (st, j))
    sql("UPDATE users SET status = 'active', banned_until = NULL WHERE id = " + str(ids["audhttp"]))

    # ── 2. user_update is a password-guessing surface, and has a budget ───────
    clear_throttles()
    me = Client()
    st, j = me.sign_in("audhttp")
    check("the member signs in for the budget check", st == 200 and j.get("success"), (st, j))
    clear_throttles()
    codes = []
    for i in range(21):
        st, j = me.call("user_update", {"current_password": "definitely-not-it", "new_password": NEW_ADMIN_PASS})
        codes.append(st)
    check("the twenty attempts the budget allows are answered, not throttled",
          codes[:20] == [403] * 20, codes[:20])
    check("the twenty-first is refused with 429", codes[20] == 429, codes)
    clear_throttles()

    # ── the panel ────────────────────────────────────────────────────────────
    panel = Client()
    st, j = panel.open_admin()
    check("the panel signs in", st == 200 and j.get("success"), (st, j))

    # ── 3. a status change has no date left over from an older, timed ban ─────
    sql("UPDATE users SET status = 'banned', banned_until = '2030-01-01 00:00:00' WHERE id = " + str(ids["audtarget"]))
    st, j = panel.call("admin/user_update", {"id": ids["audtarget"], "status": "active"})
    check("the Users page lifts the ban", st == 200 and "status" in (j.get("changed") or []), (st, j))
    left = col("SELECT IFNULL(banned_until, 'NULL') FROM users WHERE id = " + str(ids["audtarget"]))
    check("… and takes the date with it, so the janitor cannot undo a later ban", left == "NULL", left)
    # The same for a ban SET here: a ban from the Users page is until somebody lifts it.
    sql("UPDATE users SET banned_until = '2030-01-01 00:00:00' WHERE id = " + str(ids["audtarget"]))
    st, j = panel.call("admin/user_update", {"id": ids["audtarget"], "status": "banned"})
    left = col("SELECT IFNULL(banned_until, 'NULL') FROM users WHERE id = " + str(ids["audtarget"]))
    check("a ban made here carries no date either", st == 200 and left == "NULL", (st, left))
    sql("UPDATE users SET status = 'active', banned_until = NULL WHERE id = " + str(ids["audtarget"]))

    # ── 4. the report card ───────────────────────────────────────────────────
    st, j = panel.call("admin/message_report_action",
                       {"id": ids["r1"], "action": "close", "note": "n1", "reply": "r1"})
    check("the report closes with a note and an answer", st == 200 and j.get("status") == "closed", (st, j))
    st, j = panel.call("admin/message_report_action",
                       {"id": ids["r1"], "action": "reopen", "note": "", "reply": ""})
    check("reopening answers 200", st == 200 and j.get("status") == "open", (st, j))
    # A freshly drawn card has empty boxes; writing them on reopen erased what the card promises to
    # keep — the next moderator would see a report nobody had ever answered.
    row = json.loads(php("$st = $db->prepare('SELECT status, note, reply FROM message_reports WHERE id = ?');"
                         "$st->execute([" + str(ids["r1"]) + "]); echo json_encode($st->fetch(PDO::FETCH_ASSOC) ?: []);") or "{}")
    check("… and keeps the note and the answer the first moderator left",
          row.get("note") == "n1" and row.get("reply") == "r1" and row.get("status") == "open", row)

    st, j = panel.call("admin/message_report_action",
                       {"id": ids["r2"], "action": "ban", "days": 0, "note": "", "reply": ""})
    check("a ban with no number of days answers 200", st == 200 and j.get("action") == "ban", (st, j))
    who = json.loads(php("$st = $db->prepare('SELECT status, IFNULL(banned_until, \\'NULL\\') AS until FROM users WHERE id = ?');"
                         "$st->execute([" + str(ids["audrep2"]) + "]); echo json_encode($st->fetch(PDO::FETCH_ASSOC) ?: []);") or "{}")
    # "For ever" is a date far enough away to mean it, so the column stays one kind of thing and
    # every reader is one comparison.
    check("… and is stored as a date far enough away to mean for ever",
          who.get("status") == "banned" and who.get("until") == "2099-12-31 23:59:59", who)
    st, j = panel.call("admin/message_report_action", {"id": ids["r2"], "action": "unban", "note": "", "reply": ""})
    who = json.loads(php("$st = $db->prepare('SELECT status, IFNULL(banned_until, \\'NULL\\') AS until FROM users WHERE id = ?');"
                         "$st->execute([" + str(ids["audrep2"]) + "]); echo json_encode($st->fetch(PDO::FETCH_ASSOC) ?: []);") or "{}")
    check("… and this card can lift the ban it made",
          st == 200 and who.get("status") == "active" and who.get("until") == "NULL", (st, who))

    # A ban the OWNER made from the Users page carries no date, and is not this card's to shorten.
    sql("UPDATE users SET status = 'banned', banned_until = NULL WHERE id = " + str(ids["audrep2"]))
    st, j = panel.call("admin/message_report_action", {"id": ids["r2"], "action": "ban", "days": 7, "note": "", "reply": ""})
    check("a timed ban over the owner's permanent one is refused with 409",
          st == 409 and j.get("error") == "ban_is_permanent", (st, j))
    st, j = panel.call("admin/message_report_action", {"id": ids["r2"], "action": "unban", "note": "", "reply": ""})
    check("… and lifting it from here is refused with 403", st == 403 and j.get("error") == "ban_is_permanent", (st, j))
    still = col("SELECT status FROM users WHERE id = " + str(ids["audrep2"]))
    check("… and the account is still banned after both refusals", still == "banned", still)
    sql("UPDATE users SET status = 'active', banned_until = NULL WHERE id = " + str(ids["audrep2"]))

    # ── 5. Settings clamps a ceiling rather than storing a typo ───────────────
    st, j = panel.call("admin/save_settings", {"fav_max_per_user": "99999"})
    check("Settings accepts the save", st == 200 and j.get("success"), (st, j))
    check("… with the favourites ceiling clamped to its maximum",
          col("SELECT `value` FROM settings WHERE `key` = 'fav_max_per_user'") == "5000")
    st, j = panel.call("admin/save_settings", {"auth_bridge_ttl": "99999"})
    check("… and the bridge ticket's lifetime clamped to its own",
          st == 200 and col("SELECT `value` FROM settings WHERE `key` = 'auth_bridge_ttl'") == "900", (st, j))

    # ── 6. the owner's password reaches the mirror row ───────────────────────
    # The panel admin is mirrored into `users` as a member of the admin group, and that row is what
    # ?action=login checks. A rotation that wrote only config/hash.txt left the OLD password opening
    # the panel through the account sign-in — the door nobody thinks to try.
    try:
        st, j = panel.call("admin/change_password",
                           {"current_password": ADMIN_PASS, "new_password": NEW_ADMIN_PASS})
        check("the owner changes the panel password", st == 200 and j.get("success"), (st, j))
        check("the file the panel reads holds the new password",
              php("echo password_verify('" + NEW_ADMIN_PASS + "', trim((string)file_get_contents('"
                  + HASH_FILE.replace("\\", "/") + "'))) ? '1' : '0';") == "1")
        check("… and so does the mirror row the account sign-in checks",
              php("$st = $db->prepare('SELECT pass_hash FROM users WHERE username = ?');"
                  "$st->execute(['" + admin_name + "']);"
                  "echo password_verify('" + NEW_ADMIN_PASS + "', (string)$st->fetchColumn()) ? '1' : '0';") == "1")
        check("… and the old one opens neither",
              php("$st = $db->prepare('SELECT pass_hash FROM users WHERE username = ?');"
                  "$st->execute(['" + admin_name + "']);"
                  "echo password_verify('" + ADMIN_PASS + "', (string)$st->fetchColumn()) ? '1' : '0';") == "0")
    finally:
        # Straight back, both halves. The endpoint cannot put 'admin123' back (it is below its own
        # rules), so the bytes that were there go back where they were.
        if hash_backup is not None:
            with open(HASH_FILE, "wb") as fh:
                fh.write(hash_backup)
        if mirror_backup.get("pass_hash"):
            php("$db->prepare('UPDATE users SET pass_hash = ?, sessions_valid_from = ? WHERE username = ?')"
                "->execute([" + json.dumps(mirror_backup.get("pass_hash", "")) + ", "
                + str(int(mirror_backup.get("sessions_valid_from") or 0)) + ", '" + admin_name + "']);")
    check("the panel password is the one the suite started with",
          php("echo password_verify('" + ADMIN_PASS + "', trim((string)file_get_contents('"
              + HASH_FILE.replace("\\", "/") + "'))) ? '1' : '0';") == "1")
    st, j = Client().open_admin()
    check("… and it still opens the panel", st == 200 and j.get("success"), (st, j))

    # ── 7. a moderator edits members, never staff ────────────────────────────
    mod = Client()
    st, j = mod.sign_in("audmoder")
    check("the moderator signs in", st == 200 and j.get("success"), (st, j))
    # Signing in opened a PANEL session through the account — that is the only writer of
    # admin_via_user, and the whole moderator permission map hangs off it.
    st, j = mod.call("admin/user_update", {"id": ids["audplain"], "status": "banned"})
    check("a moderator with panel.users.edit may ban an ordinary member",
          st == 200 and "status" in (j.get("changed") or []), (st, j))
    sql("UPDATE users SET status = 'active', banned_until = NULL WHERE id = " + str(ids["audplain"]))
    st, j = mod.call("admin/user_update", {"id": ids["audstaff"], "status": "banned"})
    check("… and may not ban somebody who can open the panel",
          st == 403 and j.get("error") == "target_is_staff", (st, j))
    check("… who is, in fact, still active",
          col("SELECT status FROM users WHERE id = " + str(ids["audstaff"])) == "active")

    # ── 8. following somebody who already asked you ──────────────────────────
    php("$db->prepare(\"INSERT INTO user_friends (user_id, friend_id, status, accepted_at) VALUES (?, ?, 'accepted', NOW())\")"
        "->execute([" + str(ids["audfb"]) + ", " + str(ids["audfa"]) + "]);")
    fa = Client()
    st, j = fa.sign_in("audfa")
    check("the follower signs in", st == 200 and j.get("success"), (st, j))
    st, j = fa.call("user_people", {"op": "follow", "user": "audfb"})
    # A second, pending row would leave friendState() answering by index order, and "Cancel request"
    # deleting the friendship. A stale tab is where this click comes from.
    check("following somebody who already holds the friendship says 'friends'",
          st == 200 and j.get("state") == "friends", (st, j))
    pair = col("SELECT COUNT(*) FROM user_friends WHERE (user_id = " + str(ids["audfa"]) + " AND friend_id = " + str(ids["audfb"])
               + ") OR (user_id = " + str(ids["audfb"]) + " AND friend_id = " + str(ids["audfa"]) + ")")
    check("… without a second row", pair == "1", pair)
    asked = col("SELECT COUNT(*) FROM user_notifications WHERE user_id = " + str(ids["audfb"]) + " AND type = 'friend_request'")
    check("… and without asking a question that is already answered", asked == "0", asked)

    php("$db->exec('DELETE FROM user_friends WHERE user_id IN (" + str(ids["audfa"]) + "," + str(ids["audfb"])
        + ") OR friend_id IN (" + str(ids["audfa"]) + "," + str(ids["audfb"]) + ")');"
        "$db->exec('DELETE FROM user_notifications WHERE user_id = " + str(ids["audfb"]) + "');")
    st1, j1 = fa.call("user_people", {"op": "follow", "user": "audfb"})
    st2, j2 = fa.call("user_people", {"op": "follow", "user": "audfb"})
    check("asking twice is answered twice", st1 == 200 and st2 == 200, (st1, j1, st2, j2))
    check("… and delivers exactly one request, not one per click",
          col("SELECT COUNT(*) FROM user_notifications WHERE user_id = " + str(ids["audfb"])
              + " AND type = 'friend_request'") == "1")
    check("… from exactly one row", col("SELECT COUNT(*) FROM user_friends WHERE user_id = " + str(ids["audfa"])
                                        + " AND friend_id = " + str(ids["audfb"]) + "") == "1")

    # ── 9. a block that hides the profile hides the conversation too ─────────
    php("$db->prepare('INSERT INTO user_blocks (user_id, blocked_id, hide_profile) VALUES (?, ?, 1)')"
        "->execute([" + str(ids["audmb"]) + ", " + str(ids["audma"]) + "]);")
    ma = Client()
    st, j = ma.sign_in("audma")
    check("the blocked reader signs in", st == 200 and j.get("success"), (st, j))
    st, j = ma.call("user_messages&with=audmb")
    check("a hidden block answers not-found for the whole conversation", st == 404, (st, j))
    st, j = ma.call("user_messages&can=audmb")
    check("… and the may-I-write question says the same word the profile does",
          st == 200 and j.get("ok") is False and j.get("reason") == "not_found", (st, j))
    # A plain block is NOT silent: the sender is told, because a message that vanishes teaches
    # somebody that the site is broken.
    php("$db->exec('UPDATE user_blocks SET hide_profile = 0 WHERE user_id = " + str(ids["audmb"])
        + " AND blocked_id = " + str(ids["audma"]) + "');")
    st, j = ma.call("user_messages&can=audmb")
    check("a plain block says 'blocked' instead", st == 200 and j.get("reason") == "blocked", (st, j))
    st, j = ma.call("user_messages&with=audmb")
    check("… and the conversation is readable again", st == 200 and j.get("success") is True, (st, j))

    # ── 10. the per-day ceiling refuses the one after the last it allows ─────
    php("setSetting($db, 'pm_max_per_day', '1');")
    pa = Client()
    st, j = pa.sign_in("audpa")
    check("the sender signs in", st == 200 and j.get("success"), (st, j))
    st, j = pa.call("user_messages", {"op": "send", "to": "audpb", "body": "the one message of the day", "format": "bbcode"})
    check("the message the ceiling allows goes through", st == 200 and j.get("success"), (st, j))
    st, j = pa.call("user_messages", {"op": "send", "to": "audpb", "body": "and one too many", "format": "bbcode"})
    check("the next one is refused with 429 and named", st == 429 and j.get("error") == "day_limit", (st, j))
    # The refusal rolls back the transaction it was taken inside — the row lock is not a write.
    check("… and nothing was written", col("SELECT COUNT(*) FROM user_messages WHERE sender_id = " + str(ids["audpa"])) == "1")

finally:
    idlist = ",".join(str(ids.get(u, 0)) for u in NAMES) or "0"
    php("$ids = [" + idlist + "];"
        "$in = implode(',', array_map('intval', $ids)) ?: '0';"
        "$db->exec(\"DELETE FROM message_reports WHERE reporter_id IN ($in) OR reported_user_id IN ($in)\");"
        "$db->exec(\"DELETE FROM user_messages WHERE thread_id IN (SELECT id FROM message_threads WHERE u_low IN ($in) OR u_high IN ($in))\");"
        "$db->exec(\"DELETE FROM message_threads WHERE u_low IN ($in) OR u_high IN ($in)\");"
        "$db->exec(\"DELETE FROM user_friends WHERE user_id IN ($in) OR friend_id IN ($in)\");"
        "$db->exec(\"DELETE FROM user_blocks WHERE user_id IN ($in) OR blocked_id IN ($in)\");"
        "$db->exec(\"DELETE FROM user_notifications WHERE user_id IN ($in)\");"
        "$db->exec(\"DELETE FROM user_tokens WHERE user_id IN ($in)\");"
        "$db->exec(\"DELETE FROM user_twofa WHERE user_id IN ($in)\");"
        "$db->exec(\"DELETE FROM user_group_members WHERE user_id IN ($in)\");"
        "$db->exec(\"DELETE FROM users WHERE id IN ($in)\");"
        "$db->exec(\"DELETE FROM user_groups WHERE slug = 'audgrp'\");"
        + "".join("setSetting($db, '%s', '%s');" % (k, v) for k, v in was.items() if v != ""))
    if mirror_backup.get("made") and int(mirror_backup.get("id") or 0) > 0:
        php("$id = " + str(int(mirror_backup["id"])) + ";"
            "$db->exec(\"DELETE FROM user_group_members WHERE user_id = $id\");"
            "$db->exec(\"DELETE FROM users WHERE id = $id\");")
    if hash_backup is not None:
        with open(HASH_FILE, "wb") as fh:
            fh.write(hash_backup)
    clear_throttles()

print("\n%d checks, %d failed" % (n, fails))
sys.exit(1 if fails else 0)
