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


USER, PAL, PASS = "shoutuser", "shoutpal", "SmokePass123!"
SETTINGS = ("users_enabled", "shout_enabled", "shout_placement", "shout_live_seconds",
            "shout_flood_seconds", "shout_max_chars", "shout_widget_rows", "shout_page_rows",
            "shout_format", "shout_keep_rows", "shout_keep_days", "shout_rules")
clear_throttles()
was = {k: php("echo $cfg['" + k + "'] ?? '';") for k in SETTINGS}
member_before = php("$st = $db->query(\"SELECT permissions FROM user_groups WHERE slug = 'member'\"); echo $st->fetchColumn();")
php("setSetting($db, 'users_enabled', '1'); setSetting($db, 'shout_enabled', '1');"
    "setSetting($db, 'shout_placement', 'both'); setSetting($db, 'shout_live_seconds', '10');"
    "setSetting($db, 'shout_flood_seconds', '0'); setSetting($db, 'shout_max_chars', '500');"
    "setSetting($db, 'shout_widget_rows', '5'); setSetting($db, 'shout_page_rows', '100');"
    "setSetting($db, 'shout_format', 'bbcode');"
    "$db->exec(\"UPDATE user_groups SET permissions = JSON_MERGE_PATCH(permissions,"
    " '{\\\"shout.view\\\":true,\\\"shout.post\\\":true,\\\"shout.delete_own\\\":true}') WHERE slug = 'member'\");"
    "$db->prepare('DELETE FROM users WHERE username IN (?, ?)')->execute(['" + USER + "', '" + PAL + "']);"
    "$db->exec('DELETE FROM shout_mentions'); $db->exec('DELETE FROM shouts');"
    "userCreate($db, $cfg, '" + USER + "', 'shoutuser@example.org', '" + PASS + "', '127.0.0.1');"
    "userCreate($db, $cfg, '" + PAL + "', 'shoutpal@example.org', '" + PASS + "', '127.0.0.1');"
    "$db->exec(\"UPDATE users SET email_verified = 1 WHERE username IN ('" + USER + "', '" + PAL + "')\");")
uid = int(php("echo (int)$db->query(\"SELECT id FROM users WHERE username = '" + USER + "'\")->fetchColumn();") or 0)
pid = int(php("echo (int)$db->query(\"SELECT id FROM users WHERE username = '" + PAL + "'\")->fetchColumn();") or 0)
check("both accounts exist", uid > 0 and pid > 0, (uid, pid))

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
    keys = list(SETTINGS[1:])
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
        "$db->prepare('DELETE FROM users WHERE username IN (?, ?)')->execute(['" + USER + "', '" + PAL + "']);"
        "$db->prepare(\"UPDATE user_groups SET permissions = ? WHERE slug = 'member'\")->execute([" + json.dumps(member_before) + "]);"
        + "".join("setSetting($db, '%s', '%s');" % (k, v) for k, v in was.items()))
    clear_throttles()

print("\n%d checks, %d failed" % (n, fails))
sys.exit(1 if fails else 0)
