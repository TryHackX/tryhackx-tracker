#!/usr/bin/env python3
"""
The shoutbox (1.58.0) — the five endpoints end to end:
    python tests/shout_test.py           (needs the local server and a bootstrapped database)

A visitor is told to sign in; a member whose group lost `shout.view` is told the truth instead; the
room pages forwards (`after`) and backwards (`before`) without ever redrawing; a shout is written,
a second one inside the flood interval is refused with how long is left, an over-long one is refused
with the limit it was judged against; deleting somebody else's is 403, an id that never existed is
404, your own is 200; "seen" moves a mark that cannot move backwards; the pulse carries the three
counts the sounds need; the master switch closes everything; and the owner purges the room with
their own password, which a member cannot do at all.

Self-cleaning: the accounts, the shouts and every setting it touched are put back in the finally.
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
        "require_once $root . '/includes/people.php'; require_once $root . '/includes/richtext.php';"
        "require_once $root . '/includes/shout.php';"
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


def count(where):
    """How many rows the RULE says there are — the numbers below are compared against this rather
    than against a constant, so a row written by something else on this shared database cannot flip
    an assertion that is really about the endpoint agreeing with the rule."""
    return int(php("echo (int)$db->query(\"SELECT COUNT(*) FROM shouts WHERE " + where + "\")->fetchColumn();") or -1)


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
        with self.opener.open(BASE + ("?action=" + action if action else ""), timeout=30) as r:
            html = r.read().decode("utf-8", "replace")
        m = re.search(r'name="csrf_token" value="([^"]+)"', html)
        if m:
            self.csrf = m.group(1)
        return r.status, html

    def api(self, endpoint, method="GET", body=None, csrf_header=False):
        url = BASE + "api.php?endpoint=" + endpoint
        data = json.dumps(body).encode() if body is not None else None
        h = {"Accept": "application/json"}
        if data is not None:
            h["Content-Type"] = "application/json"
        if csrf_header and self.csrf:
            h["X-CSRF-Token"] = self.csrf
        req = urllib.request.Request(url, data=data, method=method, headers=h)
        try:
            with self.opener.open(req, timeout=30) as r:
                return r.status, json.loads(r.read().decode() or "{}")
        except urllib.error.HTTPError as e:
            try:
                return e.code, json.loads(e.read().decode() or "{}")
            except Exception:
                return e.code, {}


USER, PAL, MOD, PASS = "shoutuser", "shoutpal", "shoutmod", "SmokePass123!"
SETTINGS = ("users_enabled", "shout_enabled", "shout_placement", "shout_live_seconds",
            "shout_flood_seconds", "shout_max_chars", "shout_widget_rows", "shout_page_rows",
            "shout_format", "shout_keep_rows", "shout_keep_days", "shout_rules",
            # 1.66.0: the two windows, the order, the switches the edit checks lean on
            "shout_edit_minutes", "shout_delete_own_minutes", "shout_order", "desc_max_images",
            "account_picture_side", "account_cover_side")
clear_throttles()
was = {k: php("echo $cfg['" + k + "'] ?? '';") for k in SETTINGS}
member_before = php("$st = $db->query(\"SELECT permissions FROM user_groups WHERE slug = 'member'\"); echo $st->fetchColumn();")
moderator_before = php("$st = $db->query(\"SELECT permissions FROM user_groups WHERE slug = 'moderator'\"); echo $st->fetchColumn();")
# The grants this run relies on, stated rather than inherited — and built on the PHP side, so no
# quote in them has to survive three layers of escaping on the way.
php("setSetting($db, 'users_enabled', '1'); setSetting($db, 'shout_enabled', '1');"
    "setSetting($db, 'shout_placement', 'both'); setSetting($db, 'shout_live_seconds', '10');"
    "setSetting($db, 'shout_flood_seconds', '0'); setSetting($db, 'shout_max_chars', '500');"
    "setSetting($db, 'shout_widget_rows', '5'); setSetting($db, 'shout_page_rows', '100');"
    "setSetting($db, 'shout_format', 'bbcode'); setSetting($db, 'shout_edit_minutes', '10');"
    "setSetting($db, 'shout_delete_own_minutes', '10'); setSetting($db, 'shout_order', 'bottom');"
    "setSetting($db, 'desc_max_images', '3');"
    "$db->prepare(\"UPDATE user_groups SET permissions = JSON_MERGE_PATCH(permissions, ?) WHERE slug = 'member'\")"
    "   ->execute([json_encode(['shout.view' => true, 'shout.post' => true, 'shout.delete_own' => true, 'shout.edit_own' => true])]);"
    "$db->prepare(\"UPDATE user_groups SET permissions = JSON_MERGE_PATCH(permissions, ?) WHERE slug = 'moderator'\")"
    "   ->execute([json_encode(['shout.view' => true, 'shout.post' => true, 'shout.moderate' => true, 'shout.edit_any' => true])]);"
    "$db->prepare(\"UPDATE user_groups SET permissions = JSON_REMOVE(permissions, ?) WHERE slug = 'member' AND JSON_CONTAINS_PATH(permissions, 'one', ?)\")"
    "   ->execute(['$.\"shout.edit_any\"', '$.\"shout.edit_any\"']);"
    "$db->prepare('DELETE FROM users WHERE username IN (?, ?, ?)')->execute(['" + USER + "', '" + PAL + "', '" + MOD + "']);"
    "$db->exec('DELETE FROM shout_mentions'); $db->exec('DELETE FROM shouts');"
    "userCreate($db, $cfg, '" + USER + "', 'shoutuser@example.org', '" + PASS + "', '127.0.0.1');"
    "userCreate($db, $cfg, '" + PAL + "', 'shoutpal@example.org', '" + PASS + "', '127.0.0.1');"
    "userCreate($db, $cfg, '" + MOD + "', 'shoutmod@example.org', '" + PASS + "', '127.0.0.1');"
    "$db->exec(\"UPDATE users SET email_verified = 1 WHERE username IN ('" + USER + "', '" + PAL + "', '" + MOD + "')\");"
    "$gid = (int)$db->query(\"SELECT id FROM user_groups WHERE slug = 'moderator'\")->fetchColumn();"
    "$mid = (int)$db->query(\"SELECT id FROM users WHERE username = '" + MOD + "'\")->fetchColumn();"
    "userGrantGroup($db, $mid, $gid, null, 'shout_test', '', false);")
uid = int(php("echo (int)$db->query(\"SELECT id FROM users WHERE username = '" + USER + "'\")->fetchColumn();") or 0)
pid = int(php("echo (int)$db->query(\"SELECT id FROM users WHERE username = '" + PAL + "'\")->fetchColumn();") or 0)
mod_id = int(php("echo (int)$db->query(\"SELECT id FROM users WHERE username = '" + MOD + "'\")->fetchColumn();") or 0)
check("the three accounts exist", uid > 0 and pid > 0 and mod_id > 0, (uid, pid, mod_id))

# Eight lines by somebody else, oldest first, so the paging below has something to walk.
seeded = php("for ($i = 1; $i <= 8; $i++) {"
             "  $db->prepare(\"INSERT INTO shouts (user_id, body, body_format) VALUES (?, ?, 'plain')\")"
             "     ->execute([" + str(pid) + ", 'seed ' . $i]); $ids[] = (int)$db->lastInsertId(); }"
             "echo implode(',', $ids);")
seed_ids = [int(x) for x in seeded.split(",") if x.strip().isdigit()]
check("eight lines are waiting in the room", len(seed_ids) == 8, seeded)

try:
    guest = Client()
    s, j = guest.api("shout_list")
    check("a visitor is told to sign in", s == 401 and j.get("error") == "login_required", (s, j))
    s, j = guest.api("shout_post", "POST", {"csrf_token": "x", "body": "hello"})
    check("… and cannot write", s in (401, 403), (s, j))

    me = Client()
    me.page("login")
    s, j = me.api("user_login", "POST", {"csrf_token": me.csrf, "login": USER, "password": PASS})
    check("the member signs in", s == 200 and j.get("success"), (s, j))

    # ── reading ──────────────────────────────────────────────────────────────
    s, j = me.api("shout_list")
    rows = j.get("rows") or []
    check("the newest widgetful comes back, oldest first, with the poll baseline and the cadence",
          s == 200 and j.get("success") and len(rows) == 5 and [r["id"] for r in rows] == seed_ids[3:]
          and j.get("newest") == seed_ids[-1] and j.get("has_more") is True and j.get("live") == 10,
          (s, str(j)[:300]))
    # 1.62.0: WHEN is the reader's own clock, formatted by the server — the hour for the list and the
    # full date WITH ITS OFFSET for the title — and the database's own string no longer travels.
    check("a row says who said it, when (in the reader's zone, with its offset), and what it may be done with",
          rows[0]["user"] == PAL and rows[0]["own"] is False and rows[0]["deletable"] is False
          and rows[0]["mentions_me"] is False
          and re.match(r"^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d [+-]\d\d:\d\d$", rows[0].get("at") or "")
          and re.match(r"^\d\d:\d\d$", rows[0].get("time") or "") and rows[0]["time"] == (rows[0].get("at") or "")[11:16]
          and isinstance(rows[0].get("ts"), int) and "created_at" not in rows[0],
          rows[0])
    s, j = me.api("shout_list&after=" + str(seed_ids[5]))
    check("after: only what is newer", s == 200 and [r["id"] for r in j["rows"]] == seed_ids[6:], (s, [r["id"] for r in j.get("rows") or []]))
    s, j = me.api("shout_list&after=" + str(seed_ids[-1]))
    check("… and nothing at all when the reader is up to date", s == 200 and j["rows"] == [] and j["newest"] == seed_ids[-1], (s, str(j)[:200]))
    s, j = me.api("shout_list&before=" + str(seed_ids[3]))
    check("before: only what is older, still oldest first, and there is no more above it",
          s == 200 and [r["id"] for r in j["rows"]] == seed_ids[:3] and j.get("has_more") is False,
          (s, [r["id"] for r in j.get("rows") or []]))

    # ── writing ──────────────────────────────────────────────────────────────
    s, j = me.api("shout_post", "POST", {"csrf_token": me.csrf, "body": "hello [b]room[/b]"})
    mine = (j.get("row") or {}).get("id")
    check("a member writes, and the finished line comes straight back",
          s == 200 and j.get("success") and "<strong>room</strong>" in (j["row"]["html"] or "")
          and j["row"]["own"] is True and j["row"]["deletable"] is True and j["row"]["user"] == USER, (s, str(j)[:300]))
    s, j = me.api("shout_post", "POST", {"csrf_token": "nope", "body": "no token"})
    check("a bad CSRF token is refused", s == 403, (s, j))
    s, j = me.api("shout_post", "POST", {"csrf_token": me.csrf, "body": "   "})
    check("an empty shout is 400", s == 400 and j.get("error") == "empty", (s, j))
    s, j = me.api("shout_post", "POST", {"csrf_token": me.csrf, "body": "x" * 501})
    check("too long is 400, and says the limit", s == 400 and j.get("error") == "too_long" and j.get("limit") == 500, (s, j))
    php("setSetting($db, 'shout_flood_seconds', '60');")
    s, j = me.api("shout_post", "POST", {"csrf_token": me.csrf, "body": "too soon"})
    check("a second shout inside the interval is 429, and says how long is left",
          s == 429 and j.get("error") == "flood" and 0 < (j.get("retry_after") or 0) <= 60, (s, j))
    php("setSetting($db, 'shout_flood_seconds', '0');")
    s, j = me.api("shout_post", "POST", {"csrf_token": me.csrf, "body": "hi @" + PAL + " and @nobodyatall"})
    mentioned = (j.get("row") or {}).get("id")
    check("a name that exists becomes a link, one that does not stays text",
          s == 200 and 'class="shout-mention"' in (j["row"]["html"] or "") and "@nobodyatall" in (j["row"]["html"] or "")
          and j["row"]["html"].count("shout-mention") == 1, (s, (j.get("row") or {}).get("html")))

    # ── deleting ─────────────────────────────────────────────────────────────
    s, j = me.api("shout_delete", "POST", {"csrf_token": me.csrf, "id": seed_ids[0]})
    check("a member may not delete somebody else's line", s == 403 and j.get("error") == "no_permission", (s, j))
    s, j = me.api("shout_delete", "POST", {"csrf_token": me.csrf, "id": 999999999})
    check("an id that never existed is 404", s == 404 and j.get("error") == "not_found", (s, j))
    s, j = me.api("shout_delete", "POST", {"csrf_token": me.csrf, "id": mentioned})
    check("their own line goes", s == 200 and j.get("success"), (s, j))
    s, j = me.api("shout_list&after=0")
    check("… and is gone from the room", mentioned not in [r["id"] for r in j["rows"]], [r["id"] for r in j["rows"]])
    s, j = me.api("shout_delete", "POST", {"csrf_token": me.csrf, "id": mentioned})
    check("deleting it twice is 404", s == 404, (s, j))

    # ── 1.66.0: correcting a line, and the windows ───────────────────────────
    s, j = me.api("shout_post", "POST", {"csrf_token": me.csrf, "body": "a lnie with a typo"})
    own = (j.get("row") or {}).get("id") or 0
    row0 = j.get("row") or {}
    check("a fresh own line comes back with the pencil and the cross, and the window each has left",
          s == 200 and row0.get("editable") is True and 590 < (row0.get("edit_left") or 0) <= 600
          and row0.get("deletable") is True and 590 < (row0.get("del_left") or 0) <= 600, (s, row0))
    s, j = me.api("shout_edit&id=" + str(own))
    check("the editor is handed the STORED words and their format",
          s == 200 and j.get("success") and j.get("body") == "a lnie with a typo" and j.get("format") == "bbcode"
          and 590 < (j.get("left") or 0) <= 600, (s, j))
    s, j = guest.api("shout_edit&id=" + str(own))
    check("a guest is told to sign in", s == 401 and j.get("error") == "login_required", (s, j))
    s, j = me.api("shout_edit", "POST", {"csrf_token": "nope", "id": own, "body": "x"})
    check("a correction with a bad CSRF token is refused", s == 403, (s, j))
    s, j = me.api("shout_edit", "POST", {"csrf_token": me.csrf, "id": own, "body": "a [b]line[/b] with a typo fixed"})
    row1 = j.get("row") or {}
    check("the author corrects the line: the finished row comes back, marked as edited by its author",
          s == 200 and j.get("success") and j.get("changed") is True and "<strong>line</strong>" in (row1.get("html") or "")
          and row1.get("edited") is True and row1.get("edited_mod") is False
          and re.match(r"^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d [+-]\d\d:\d\d$", row1.get("edited_at") or ""), (s, str(j)[:300]))
    check("… and the reply says which language it was written for", j.get("lang") == "en", j.get("lang"))
    # A client claiming to be the author — or claiming the line is its own — changes nothing.
    s, j = me.api("shout_edit", "POST", {"csrf_token": me.csrf, "id": seed_ids[1], "body": "words put in their mouth",
                                         "user_id": uid, "author": USER, "own": True})
    check("somebody else's line is refused, whatever author the request claims",
          s == 403 and j.get("error") == "no_permission" and count("id = " + str(seed_ids[1]) + " AND body = 'seed 2'") == 1, (s, j))
    s, j = me.api("shout_edit", "POST", {"csrf_token": me.csrf, "id": own, "body": "   "})
    check("an empty correction is 400", s == 400 and j.get("error") == "empty", (s, j))
    php("setSetting($db, 'desc_max_images', '0');")
    s, j = me.api("shout_edit", "POST", {"csrf_token": me.csrf, "id": own, "body": "look [img]https://example.org/a.png[/img]"})
    check("a correction the validator refuses is 400 invalid_body", s == 400 and j.get("error") == "invalid_body", (s, j))
    php("setSetting($db, 'desc_max_images', '3'); setSetting($db, 'shout_flood_seconds', '60');")
    s, j = me.api("shout_edit", "POST", {"csrf_token": me.csrf, "id": own, "body": "again, and too soon"})
    check("a second edit inside the flood interval is 429", s == 429 and j.get("error") == "flood" and 0 < (j.get("retry_after") or 0) <= 60, (s, j))
    php("setSetting($db, 'shout_flood_seconds', '0');")
    # The window is the DATABASE's arithmetic on created_at: eleven minutes old is eleven minutes old,
    # whatever the request says about it.
    php("$db->prepare('UPDATE shouts SET created_at = NOW() - INTERVAL 11 MINUTE WHERE id = ?')->execute([" + str(own) + "]);")
    s, j = me.api("shout_edit", "POST", {"csrf_token": me.csrf, "id": own, "body": "too late now",
                                         "created_at": "2099-01-01 00:00:00", "age_s": 0, "edit_left": 600})
    check("outside the window a correction is refused, whatever age the request claims",
          s == 403 and j.get("error") == "too_late", (s, j))
    s, j = me.api("shout_edit&id=" + str(own))
    check("… the editor will not even open", s == 403 and j.get("error") == "too_late", (s, j))
    s, j = me.api("shout_delete", "POST", {"csrf_token": me.csrf, "id": own})
    check("… and the author's window on taking it back has closed as well", s == 403 and j.get("error") == "too_late", (s, j))
    modc = Client()
    modc.page("login")
    s, j = modc.api("user_login", "POST", {"csrf_token": modc.csrf, "login": MOD, "password": PASS})
    check("the moderator signs in", s == 200 and j.get("success"), (s, j))
    audits = int(php("echo (int)$db->query(\"SELECT COUNT(*) FROM audit_log WHERE action = 'shout.edit'\")->fetchColumn();") or 0)
    s, j = modc.api("shout_edit", "POST", {"csrf_token": modc.csrf, "id": own, "body": "a moderator's words"})
    check("a moderator holding shout.edit_any edits it outside any window",
          s == 200 and j.get("success") and (j.get("row") or {}).get("edited_mod") is True, (s, str(j)[:300]))
    s, j = me.api("shout_list&after=" + str(own - 1))
    back = [r for r in (j.get("rows") or []) if r["id"] == own]
    check("… and the author is shown that a MODERATOR changed their words",
          bool(back) and back[0].get("edited") is True and back[0].get("edited_mod") is True and back[0].get("editable") is False, back)
    check("… which is written to the audit log",
          int(php("echo (int)$db->query(\"SELECT COUNT(*) FROM audit_log WHERE action = 'shout.edit' AND target_type = 'shout' AND target_id = '" + str(own) + "'\")->fetchColumn();") or 0) >= 1
          and int(php("echo (int)$db->query(\"SELECT COUNT(*) FROM audit_log WHERE action = 'shout.edit'\")->fetchColumn();") or 0) == audits + 1)
    s, j = modc.api("shout_delete", "POST", {"csrf_token": modc.csrf, "id": own})
    check("… and a moderator takes it down at any time", s == 200 and j.get("success"), (s, j))

    # The day of the week beside the hour, in the reader's language — formatted per request.
    en_days = {"Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"}
    pl_days = {"pon", "wt", "śr", "czw", "pt", "sob", "niedz"}
    s, j = me.api("shout_list")
    rows_en = j.get("rows") or []
    check("every row carries the day of the week beside its hour, in English for an English reader",
          s == 200 and j.get("lang") == "en" and rows_en and all(r.get("day") in en_days and 1 <= (r.get("dow") or 0) <= 7 for r in rows_en),
          [(r.get("day"), r.get("dow")) for r in rows_en])
    plc = Client()
    plc.page("login&lang=pl")                    # the switcher's cookie, as a click on PL sets it
    s, j = plc.api("user_login", "POST", {"csrf_token": plc.csrf, "login": USER, "password": PASS})
    s, j = plc.api("shout_list")
    rows_pl = j.get("rows") or []
    check("… and in Polish for the same reader reading in Polish: the same days, named from the Polish dictionary",
          s == 200 and j.get("lang") == "pl" and rows_pl and all(r.get("day") in pl_days for r in rows_pl)
          and [r.get("dow") for r in rows_pl] == [r.get("dow") for r in rows_en], [(r.get("day"), r.get("dow")) for r in rows_pl])

    # ── seen, and the pulse ──────────────────────────────────────────────────
    php("$db->prepare('UPDATE users SET shout_seen_id = 0 WHERE id = ?')->execute([" + str(uid) + "]);")
    others = count("deleted_at IS NULL AND user_id <> " + str(uid))
    s, j = me.api("user_pulse")
    check("the pulse carries the three shout counts beside the message ones",
          s == 200 and j.get("unread_shout") == others and j.get("unread_shout_friend") == 0
          and j.get("unread_shout_mention") == 0, (s, j, others))
    php("$db->prepare(\"INSERT INTO user_friends (user_id, friend_id, status, accepted_at) VALUES (?, ?, 'accepted', NOW())\")"
        "   ->execute([" + str(uid) + ", " + str(pid) + "]);")
    from_pal = count("deleted_at IS NULL AND user_id = " + str(pid))
    s, j = me.api("user_pulse")
    check("… and the friend half moves when the friendship is accepted",
          j.get("unread_shout_friend") == from_pal and from_pal > 0, (j, from_pal))
    s, j = me.api("shout_seen", "POST", {"csrf_token": me.csrf, "id": seed_ids[3]})
    check("seen moves the mark and says where it landed", s == 200 and j.get("seen") == seed_ids[3], (s, j))
    above = count("deleted_at IS NULL AND user_id <> " + str(uid) + " AND id > " + str(seed_ids[3]))
    s, j = me.api("user_pulse")
    check("… and the counts drop to what is above it",
          j.get("unread_shout") == above and above < others, (j, above, others))
    s, j = me.api("shout_seen", "POST", {"csrf_token": me.csrf, "id": 1})
    check("a slow tab cannot move the mark backwards", j.get("seen") == seed_ids[3], (s, j))
    s, j = me.api("user_me")
    check("user_me carries the same three numbers", j.get("unread_shout") == above, str(j)[:200])

    # ── the permission, and the switch ───────────────────────────────────────
    php("$db->exec(\"UPDATE user_groups SET permissions = JSON_REMOVE(permissions, '$.\\\"shout.view\\\"') WHERE slug = 'member'\");")
    s, j = me.api("shout_list")
    check("without shout.view a signed-in member is told so, not asked to sign in",
          s == 403 and j.get("error") == "no_permission", (s, j))
    s, j = me.api("user_pulse")
    check("… and the pulse stops carrying the numbers entirely", "unread_shout" not in j, j)
    php("$db->exec(\"UPDATE user_groups SET permissions = JSON_MERGE_PATCH(permissions, '{\\\"shout.view\\\":true}') WHERE slug = 'member'\");")
    php("setSetting($db, 'shout_enabled', '0');")
    s, j = me.api("shout_list")
    s2, j2 = me.api("shout_post", "POST", {"csrf_token": me.csrf, "body": "nobody home"})
    check("the master switch closes reading and writing alike",
          s == 403 and j.get("error") == "disabled" and s2 == 403 and j2.get("error") == "disabled", (s, j, s2, j2))
    php("setSetting($db, 'shout_enabled', '1');")

    # ── the owner's purge ────────────────────────────────────────────────────
    adm = Client()
    adm.page("admin")
    s, j = adm.api("admin/login", "POST", {"username": "admin", "password": "admin123", "csrf_token": adm.csrf}, csrf_header=True)
    check("the owner signs in", s == 200 and j.get("success"), (s, j))
    s, j = me.api("admin/shout_purge", "POST", {"password": "admin123"}, csrf_header=True)
    check("a member cannot reach the purge at all", s in (401, 403), (s, j))

    # ── the settings round-trip ──────────────────────────────────────────────
    # Every field on the card, saved at once with nonsense in it: a key missing from the allowed list
    # is silently ignored, which looks exactly like a save that worked, and a missing clamp is a
    # number nothing downstream expects.
    s, j = adm.api("admin/save_settings", "POST", {
        "shout_enabled": "1", "shout_placement": "nonsense", "shout_format": "rtf",
        "shout_widget_rows": "999", "shout_page_rows": "1", "shout_max_chars": "99999",
        "shout_flood_seconds": "-3", "shout_live_seconds": "9999", "shout_keep_rows": "1",
        "shout_keep_days": "99999", "shout_rules": "  be nice  "}, csrf_header=True)
    # The card's original eleven: SETTINGS grew in 1.66.0 with keys this save does not send.
    keys = list(SETTINGS[1:12])
    stored = dict(zip(keys, php("$out = []; foreach (['" + "','".join(keys) + "'] as $k) $out[] = (string)($cfg[$k] ?? '');"
                                " echo implode('|', $out);").split("|")))
    check("every field is saveable, and the nonsense is clamped or coerced rather than refused",
          s == 200 and j.get("success") and stored == {
              "shout_enabled": "1", "shout_placement": "home", "shout_live_seconds": "120",
              "shout_flood_seconds": "0", "shout_max_chars": "2000", "shout_widget_rows": "100",
              "shout_page_rows": "20", "shout_format": "bbcode", "shout_keep_rows": "100",
              "shout_keep_days": "3650", "shout_rules": "be nice"}, (s, stored))
    php("setSetting($db, 'shout_live_seconds', '10'); setSetting($db, 'shout_flood_seconds', '0');"
        "setSetting($db, 'shout_max_chars', '500'); setSetting($db, 'shout_widget_rows', '5');"
        "setSetting($db, 'shout_page_rows', '100'); setSetting($db, 'shout_keep_rows', '2000');"
        "setSetting($db, 'shout_keep_days', '30'); setSetting($db, 'shout_placement', 'both');")
    # 1.66.0: the two windows, the order and the two account-page sides, with nonsense in each.
    s, j = adm.api("admin/save_settings", "POST", {
        "shout_edit_minutes": "99999", "shout_delete_own_minutes": "-3", "shout_order": "sideways",
        "account_picture_side": "middle", "account_cover_side": "nowhere"}, csrf_header=True)
    keys66 = ["shout_edit_minutes", "shout_delete_own_minutes", "shout_order", "account_picture_side", "account_cover_side"]
    stored66 = dict(zip(keys66, php("$c = getSettings($db, true); $out = []; foreach (['" + "','".join(keys66) + "'] as $k) $out[] = (string)($c[$k] ?? '');"
                                    " echo implode('|', $out);").split("|")))
    check("the 1.66.0 fields are saveable, clamped to a day and coerced to their shipped answers",
          s == 200 and j.get("success") and stored66 == {"shout_edit_minutes": "1440", "shout_delete_own_minutes": "0",
                                                         "shout_order": "bottom", "account_picture_side": "left",
                                                         "account_cover_side": "right"}, (s, stored66))
    s, j = adm.api("admin/save_settings", "POST", {"shout_edit_minutes": "0", "account_picture_side": "right",
                                                   "account_cover_side": "left"}, csrf_header=True)
    stored66 = dict(zip(keys66, php("$c = getSettings($db, true); $out = []; foreach (['" + "','".join(keys66) + "'] as $k) $out[] = (string)($c[$k] ?? '');"
                                    " echo implode('|', $out);").split("|")))
    check("… 0 survives the clamp (no window at all), and either side can be chosen for either block",
          stored66["shout_edit_minutes"] == "0" and stored66["account_picture_side"] == "right" and stored66["account_cover_side"] == "left", stored66)
    s, j = adm.api("admin/save_settings", "POST", {"account_media_side": "left"}, csrf_header=True)
    check("… and the old single setting is not a thing anybody can save any more",
          php("$st = $db->query(\"SELECT COUNT(*) FROM settings WHERE `key` = 'account_media_side'\"); echo (int)$st->fetchColumn();") == "0")
    php("$db->exec('UPDATE shouts SET created_at = NOW() - INTERVAL 10 DAY WHERE id <= " + str(seed_ids[3]) + "');")
    # A purge counts ROWS, including the ones a moderator deleted — a soft delete hides a line from
    # the room, it does not remove it until retention or a purge does.
    total_before = count("1 = 1")
    old_before = count("created_at < (NOW() - INTERVAL 5 DAY)")
    s, j = adm.api("admin/shout_purge", "POST", {"password": "admin123", "older_than_days": 5}, csrf_header=True)
    left = count("1 = 1")
    check("a purge by age removes only what is older",
          s == 200 and old_before > 0 and j.get("deleted") == old_before and left == total_before - old_before,
          (s, j, total_before, old_before, left))
    s, j = adm.api("admin/shout_purge", "POST", {"password": "admin123", "older_than_days": None}, csrf_header=True)
    check("a purge with no number empties the room",
          s == 200 and j.get("deleted") == left and count("1 = 1") == 0, (s, j, left))
    check("… and it is written to the audit log",
          int(php("echo (int)$db->query(\"SELECT COUNT(*) FROM audit_log WHERE action = 'shout.purge'\")->fetchColumn();") or 0) >= 2)
    # LAST, because a wrong answer here counts against the sign-in lockout as well as this session.
    s, j = adm.api("admin/shout_purge", "POST", {"password": "not-the-password"}, csrf_header=True)
    check("a wrong owner password purges nothing", s in (401, 403) and not j.get("success"), (s, j))
finally:
    php("$db->prepare('DELETE FROM user_friends WHERE user_id = ? OR friend_id = ?')->execute([" + str(uid) + ", " + str(uid) + "]);"
        "$db->exec('DELETE FROM shout_mentions'); $db->exec('DELETE FROM shouts');"
        # Through userDeleteCascade(): the moderator has a group membership, which a bare DELETE of
        # the account would leave behind pointing at nobody.
        "foreach (['" + USER + "', '" + PAL + "', '" + MOD + "'] as $nm) { $st = $db->prepare('SELECT id FROM users WHERE username = ?');"
        " $st->execute([$nm]); if ($id = (int)$st->fetchColumn()) userDeleteCascade($db, $id); }"
        "$db->prepare(\"UPDATE user_groups SET permissions = ? WHERE slug = 'member'\")->execute([" + json.dumps(member_before) + "]);"
        "$db->prepare(\"UPDATE user_groups SET permissions = ? WHERE slug = 'moderator'\")->execute([" + json.dumps(moderator_before) + "]);"
        + "".join("setSetting($db, '%s', '%s');" % (k, v) for k, v in was.items()))
    clear_throttles()

print("\n%d checks, %d failed" % (n, fails))
sys.exit(1 if fails else 0)
