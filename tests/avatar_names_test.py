#!/usr/bin/env python3
"""
The picture beside every name (1.63.0 phase B) over HTTP — every endpoint that hands a browser a
person's name, and the pages the server draws one on:
    python tests/avatar_names_test.py     (needs the local server and a bootstrapped database)

Three things are proved here, surface by surface.

1. THE FIELD. Each answer carries the picture's ADDRESS beside the name — the account's own square,
   the generated letter, or on a line the site said, the site's own mark — built on the server at the
   size that surface draws, and '' while pictures are switched off.

2. NO ID. The inbox, the people lists, the directory, "who has this" and the Info panel always sent a
   name and deliberately not the account id behind it. Their rows are compared against the EXACT set
   of keys they had before plus the one new field, so an id cannot have ridden in under any name; and
   every address is matched against the three shapes the server writes, none of which has room for an
   id.

3. NOWHERE ELSE. A person who hid their profile from the reader is in none of the answers the reader
   gets — not by name and not by picture — and a person the reader blocked keeps their picture exactly
   where their name still is (the reader's own list of blocks).

Every switch it leans on is set at the start and put back in the finally, with the member group's
permissions, the four accounts and every row they left.
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
        "require_once $root . '/includes/usermedia.php'; require_once $root . '/includes/shout.php';"
        "$db = getDb(); $cfg = getSettings($db); ensureSchema($db, $cfg); $cfg = getSettings($db, true);")

fails = 0
n = 0


def check(name, cond, info=""):
    global fails, n
    n += 1
    print(("PASS " if cond else "FAIL ") + name + ("" if cond or not info else "  -> " + str(info)[:400]))
    if not cond:
        fails += 1


def php(code):
    p = subprocess.run(["php", "-d", "display_errors=1", "-d", "error_reporting=E_ALL & ~E_DEPRECATED", "-r", BOOT + code],
                       capture_output=True, text=True, cwd=ROOT, encoding="utf-8", errors="replace")
    if p.returncode != 0:
        print("php failed: " + (p.stderr or p.stdout)[:600])
    return p.stdout.strip()


def phpstr(v):
    """A PHP single-quoted string literal: nothing inside it is interpolated."""
    return "'" + str(v).replace("\\", "\\\\").replace("'", "\\'") + "'"


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

    def page(self, query):
        try:
            with self.opener.open(BASE + query, timeout=30) as r:
                status, html = r.status, r.read().decode("utf-8", "replace")
        except urllib.error.HTTPError as e:
            status, html = e.code, e.read().decode("utf-8", "replace")
        m = re.search(r'name="csrf_token" value="([^"]+)"', html) or re.search(r'id="account-csrf" value="([^"]+)"', html)
        if m:
            self.csrf = m.group(1)
        return status, html

    def api(self, endpoint, method="GET", body=None, csrf_header=False):
        h = {"Accept": "application/json"}
        data = None
        if method != "GET":
            h["Content-Type"] = "application/json"
            data = json.dumps(body or {}).encode()
            if csrf_header and self.csrf:
                h["X-CSRF-Token"] = self.csrf
        req = urllib.request.Request(BASE + "api.php?endpoint=" + endpoint, data=data, method=method, headers=h)
        try:
            with self.opener.open(req, timeout=30) as r:
                status, text = r.status, r.read().decode("utf-8", "replace")
        except urllib.error.HTTPError as e:
            status, text = e.code, e.read().decode("utf-8", "replace")
        try:
            return status, json.loads(text or "{}"), text
        except Exception:
            return status, {}, text


PASS = "SmokePass123!"
ALICE, BOB, CAROL, DAVE = "avtalice", "avtbob", "avtcarol", "avtdave"
NAMES = (ALICE, BOB, CAROL, DAVE)
H1 = "a7a7a7a7a7a7a7a7a7a7a7a7a7a7a7a7a7a7a701"
H2 = "a7a7a7a7a7a7a7a7a7a7a7a7a7a7a7a7a7a7a702"
SITE_MARK = "/assets/img/favicon.svg"
SHAPE = re.compile(r"^/api\.php\?endpoint=(?:user_media&h=[0-9a-f]{16}&s=(?:64|128|256)"
                   r"|user_avatar_default&l=[A-Z0-9]&c=(?:[0-9]|1[01]))$|^/assets/img/favicon\.svg$")
SETTINGS = {
    "users_enabled": "1", "users_require_email_verify": "1", "profiles_enabled": "1",
    "avatars_enabled": "1", "avatar_default": "generated",
    "pm_enabled": "1", "pm_who": "all", "pm_live_seconds": "0", "friends_enabled": "1", "directory_enabled": "1",
    "fav_enabled": "1", "fav_public_enabled": "1", "fav_who_enabled": "1",
    "lists_enabled": "1", "lists_public_enabled": "1",
    "index_enabled": "1", "index_search_enabled": "1", "wl_allow_description": "1",
    "shout_enabled": "1", "shout_placement": "both", "shout_system_lines": "0",
    "shout_emotes_enabled": "1", "shout_emote_approval": "1",
}
PERMS = ('{"shout.view":true,"shout.post":true,"pm.send":true,"pm.report":true,"friends.use":true,'
         '"directory.view":true,"favourites.use":true,"favourites.view_others":true,"favourites.public":true,'
         '"lists.use":true,"lists.public":true,"index.view":true,"content.view":true}')

clear_throttles()
was = {k: php("echo array_key_exists('" + k + "', $cfg) ? 'v:' . $cfg['" + k + "'] : 'absent';") for k in SETTINGS}
member_before = php("echo $db->query(\"SELECT permissions FROM user_groups WHERE slug = 'member'\")->fetchColumn();")
audit_floor = int(php("echo (int)$db->query('SELECT COALESCE(MAX(id), 0) FROM audit_log')->fetchColumn();") or 0)
admin_tokens_floor = int(php("echo (int)$db->query('SELECT COALESCE(MAX(id), 0) FROM user_tokens')->fetchColumn();") or 0)


def cleanup():
    # The site's own lines have no author, so no account's cascade takes them: by their words.
    php("$db->exec(\"DELETE FROM shouts WHERE user_id IS NULL AND body LIKE 'avt %'\");"
        "$db->exec(\"DELETE FROM shout_emotes WHERE code = 'avtemo'\");"
        "$db->exec(\"DELETE FROM wl_content_edits WHERE info_hash IN ('" + H1 + "', '" + H2 + "')\");"
        "$db->exec(\"DELETE FROM hash_content WHERE info_hash IN ('" + H1 + "', '" + H2 + "')\");"
        "$db->exec(\"DELETE FROM user_favourites WHERE info_hash IN ('" + H1 + "', '" + H2 + "')\");"
        "$db->exec(\"DELETE FROM user_list_items WHERE info_hash IN ('" + H1 + "', '" + H2 + "')\");"
        "$db->exec(\"DELETE FROM index_hashes WHERE info_hash IN ('" + H1 + "', '" + H2 + "')\");"
        "foreach (['" + "', '".join(NAMES) + "'] as $nm) { $st = $db->prepare('SELECT id FROM users WHERE username = ?');"
        " $st->execute([$nm]); if ($id = (int)$st->fetchColumn()) userDeleteCascade($db, $id); }")


cleanup()
php("".join("setSetting($db, '%s', %s);" % (k, phpstr(v)) for k, v in SETTINGS.items())
    + "$db->prepare(\"UPDATE user_groups SET permissions = JSON_MERGE_PATCH(permissions, ?) WHERE slug = 'member'\")->execute([" + phpstr(PERMS) + "]);"
    + "foreach (['" + "', '".join(NAMES) + "'] as $nm) userCreate($db, $cfg, $nm, $nm . '@example.org', '" + PASS + "', '127.0.0.1');"
    + "$db->exec(\"UPDATE users SET email_verified = 1, status = 'active', profile_listed = 1, fav_public = 1, fav_listed = 1,"
    + " lists_public = 1, pm_who = NULL WHERE username IN ('" + "', '".join(NAMES) + "')\");")
ids = json.loads(php("$o = []; foreach (['" + "', '".join(NAMES) + "'] as $nm) { $st = $db->prepare('SELECT id FROM users WHERE username = ?');"
                     " $st->execute([$nm]); $o[$nm] = (int)$st->fetchColumn(); } echo json_encode($o);") or "{}")
A, B, C, D = (ids.get(x, 0) for x in NAMES)
if min(A, B, C, D) <= 0:
    # Without four real accounts the picture step below would store rows for account 0.
    print("the fixtures did not come up: " + json.dumps(ids))
    cleanup()
    sys.exit(2)
# Real pictures through the real pipeline: GD draws them, userAvatarStore() cuts the squares.
crops = json.loads(php(
    "$pic = function (int $w, int $h, array $c) { $im = imagecreatetruecolor($w, $h);"
    " imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, imagecolorallocate($im, ...$c)); ob_start(); imagepng($im); return ob_get_clean(); };"
    "$o = [];"
    "foreach ([" + str(A) + " => [220, 40, 40], " + str(B) + " => [40, 160, 60], " + str(D) + " => [60, 60, 200]] as $uid => $col) {"
    " $r = userAvatarStore($db, $cfg, $uid, $pic(300, 300, $col), 50, 50, 1); $o['u' . $uid] = (string)($r['crop'] ?? ''); }"
    "echo json_encode($o);") or "{}")
CROP = {ALICE: crops.get("u%d" % A, ""), BOB: crops.get("u%d" % B, ""), DAVE: crops.get("u%d" % D, "")}
check("the fixtures: four accounts, three of them with a picture", min(A, B, C, D) > 0 and all(len(v) == 40 for v in CROP.values()),
      (ids, CROP))


def pic(name, size):
    """The address userAvatarUrl() gives this person at $size CSS px — asked of the server's own helper."""
    return php("echo userAvatarUrl(['username' => " + phpstr(name) + ", 'avatar_sha' => " + (phpstr(CROP[name]) if name in CROP else "null")
               + "], " + str(size) + ", '/', $cfg);")


def keys(rows):
    return {tuple(sorted(r.keys())) for r in rows}


try:
    # ── the rows every surface below reads ──────────────────────────────────────────────────────
    php("foreach (['" + H1 + "' => 'avt torrent one', '" + H2 + "' => 'avt torrent two'] as $h => $nm)"
        " $db->prepare(\"INSERT INTO index_hashes (info_hash, name, first_seen, last_seen, seen_count) VALUES (?, ?, NOW(), NOW(), 3)\")->execute([$h, $nm]);"
        "foreach ([" + ",".join(str(x) for x in (A, B, C, D)) + "] as $u) $db->prepare('INSERT INTO user_favourites (user_id, info_hash) VALUES (?, ?)')->execute([$u, '" + H1 + "']);"
        "foreach ([" + str(A) + " => 'avt-pack', " + str(D) + " => 'avt-hidden'] as $u => $slug) {"
        " $db->prepare(\"INSERT INTO user_lists (user_id, name, slug, is_public) VALUES (?, ?, ?, 1)\")->execute([$u, 'List ' . $slug, $slug]);"
        " $db->prepare('INSERT INTO user_list_items (list_id, info_hash, name) VALUES (?, ?, ?)')->execute([(int)$db->lastInsertId(), '" + H1 + "', 'avt']); }"
        "$db->prepare(\"INSERT INTO hash_content (info_hash, description, description_format, content_status, content_user_id) VALUES (?, 'avt words', 'bbcode', 'approved', ?)\")->execute(['" + H1 + "', " + str(A) + "]);"
        "$hc = (int)$db->lastInsertId();"
        "$db->prepare(\"INSERT INTO hash_content (info_hash, description, description_format, content_status, content_user_id) VALUES (?, 'avt waiting', 'bbcode', 'pending', ?)\")->execute(['" + H2 + "', " + str(B) + "]);"
        "$db->prepare(\"INSERT INTO wl_content_edits (hash_content_id, info_hash, description, description_format, status, ip, user_id) VALUES (?, ?, 'avt rewrite', 'bbcode', 'pending', '127.0.0.1', ?)\")->execute([$hc, '" + H1 + "', " + str(B) + "]);"
        # alice -> carol (reported by carol), carol -> bob
        "foreach ([[" + str(A) + ", " + str(C) + ", 'avt hello carol'], [" + str(C) + ", " + str(B) + ", 'avt hello bob']] as [$f, $t, $body]) {"
        " $db->prepare('INSERT INTO message_threads (u_low, u_high, last_message_at) VALUES (?, ?, NOW())')->execute([min($f, $t), max($f, $t)]);"
        " $tid = (int)$db->lastInsertId();"
        " $db->prepare(\"INSERT INTO user_messages (thread_id, sender_id, body, body_format) VALUES (?, ?, ?, 'bbcode')\")->execute([$tid, $f, $body]);"
        " $mid = (int)$db->lastInsertId();"
        # carol answers alice, and reports what alice said before that — so the report has a context line
        " if ($f === " + str(A) + ") {"
        "  $db->prepare(\"INSERT INTO user_messages (thread_id, sender_id, body, body_format) VALUES (?, ?, 'avt a reply', 'bbcode')\")->execute([$tid, " + str(C) + "]);"
        "  $db->prepare(\"INSERT INTO user_messages (thread_id, sender_id, body, body_format) VALUES (?, ?, 'avt rude', 'bbcode')\")->execute([$tid, " + str(A) + "]);"
        "  $rude = (int)$db->lastInsertId();"
        "  $db->prepare(\"INSERT INTO message_reports (message_id, context_id, thread_id, reporter_id, reported_user_id, reason) VALUES (?, ?, ?, ?, ?, 'avt')\")"
        "     ->execute([$rude, $rude - 1, $tid, " + str(C) + ", " + str(A) + "]); } }"
        # bob and carol are friends, alice asked carol, dave hid his profile from carol, carol blocked dave
        "$db->prepare(\"INSERT INTO user_friends (user_id, friend_id, status, accepted_at) VALUES (?, ?, 'accepted', NOW())\")->execute([" + str(B) + ", " + str(C) + "]);"
        "$db->prepare(\"INSERT INTO user_friends (user_id, friend_id, status) VALUES (?, ?, 'pending')\")->execute([" + str(A) + ", " + str(C) + "]);"
        "$db->prepare('INSERT INTO user_blocks (user_id, blocked_id, hide_profile) VALUES (?, ?, 1)')->execute([" + str(D) + ", " + str(C) + "]);"
        "$db->prepare('INSERT INTO user_blocks (user_id, blocked_id, hide_profile) VALUES (?, ?, 0)')->execute([" + str(C) + ", " + str(D) + "]);"
        # the room: alice, carol, the site, and the site speaking about bob
        "$ins = $db->prepare(\"INSERT INTO shouts (user_id, body, body_format, is_system) VALUES (?, ?, ?, ?)\");"
        "$ins->execute([" + str(A) + ", 'avt line from alice', 'bbcode', 0]); $ins->execute([" + str(C) + ", 'avt line from carol', 'bbcode', 0]);"
        "$ins->execute([null, 'avt the site says', 'plain', 1]); $ins->execute([" + str(B) + ", 'avt the site says about bob', 'plain', 1]);"
        # an emote alice uploaded, let through
        "$im = imagecreatetruecolor(32, 32); ob_start(); imagepng($im); $b = ob_get_clean();"
        "$r = shoutEmoteStore($db, $cfg, 'avtemo', 'Avt emote', $b, " + str(A) + ", false);"
        "if (!empty($r['ok'])) shoutEmoteApprove($db, (int)$r['row']['id']);")

    me = Client()
    me.page("?action=login")
    s, j, _ = me.api("user_login", "POST", {"csrf_token": me.csrf, "login": CAROL, "password": PASS})
    check("the reader (who has no picture of her own) signs in", s == 200 and j.get("success"), (s, j))

    seen = []          # every address this run was handed, for the shape check at the end
    texts = []         # every answer carol got, for the "dave appears nowhere" check

    # ── 1. the shoutbox ─────────────────────────────────────────────────────────────────────────
    s, j, t = me.api("shout_list")
    texts.append(t)
    rows = j.get("rows") or []
    by = {r.get("html", "").replace("<p>", "").replace("</p>", ""): r for r in rows}
    ra, rc, rs, rb = (by.get(k) or {} for k in ("avt line from alice", "avt line from carol", "avt the site says", "avt the site says about bob"))
    check("shoutbox: a line by somebody with a picture carries its address (20 px -> the 64 square)",
          ra.get("avatar") == pic(ALICE, 20) and ra["avatar"].endswith("&s=64") and CROP[ALICE][:16] in ra["avatar"], ra)
    check("… a line by somebody without one carries their letter", rc.get("avatar") == pic(CAROL, 20) and "user_avatar_default" in rc.get("avatar", ""), rc)
    check("… a line the site said carries the site's own mark", rs.get("avatar") == SITE_MARK and rs.get("system") is True, rs)
    check("… and so does one the site said ABOUT somebody, signed with their name", rb.get("avatar") == SITE_MARK
          and rb.get("user") == BOB and rb.get("system") is True, rb)
    check("… and the row still carries user_id, which the shoutbox always sent, and nothing new besides the address",
          keys([ra]) == {tuple(sorted(["id", "user", "user_id", "avatar", "time", "at", "ts", "html", "own", "system", "pinned",
                                        "deletable", "mentions_me", "friend"]))}, keys([ra]))
    seen += [r.get("avatar", "") for r in rows]

    s, html = me.page("?action=shoutbox")
    who = re.search(r'<a class="shout-who" title="' + ALICE + r'" href="[^"]+">(<img [^>]+>)' + ALICE + "</a>", html)
    want = ('<img class="avatar shout-av js-avatar" src="' + pic(ALICE, 20).replace("&", "&amp;") + '" data-fallback="'
            + php("echo userAvatarGeneratedUrl('" + ALICE + "', '/');").replace("&", "&amp;")
            + '" width="20" height="20" alt="" loading="lazy" decoding="async">')
    check("the first page draws that picture INSIDE the name's link, the one element userAvatarHtml() draws",
          bool(who) and who.group(1) == want, who.group(0) if who else html[:0])
    site = re.search(r'<span class="shout-who" title="[^"]+">(<img [^>]+>)', html)
    check("… and the site's lines carry its mark, in a span (no profile behind it)",
          bool(site) and 'src="' + SITE_MARK + '"' in site.group(1), site.group(0) if site else "")

    # ── 2. the inbox and a conversation ─────────────────────────────────────────────────────────
    s, j, t = me.api("user_messages")
    texts.append(t)
    th = {x.get("with"): x for x in j.get("threads") or []}
    check("inbox: each conversation carries the other person's picture (32 px -> the 64 square)",
          (th.get(ALICE) or {}).get("avatar") == pic(ALICE, 32) and (th.get(BOB) or {}).get("avatar") == pic(BOB, 32), th)
    check("… and a row is exactly what it was, plus that address — no id", keys(th.values()) ==
          {tuple(sorted(["with", "avatar", "unread", "last_at", "mine", "preview"]))}, keys(th.values()))
    s, j, t = me.api("user_messages&with=" + ALICE)
    texts.append(t)
    check("a conversation carries the picture of the person it is with",
          s == 200 and j.get("with") == ALICE and j.get("with_avatar") == pic(ALICE, 32), (s, j.get("with"), j.get("with_avatar")))
    check("… and its answer has no id of theirs either", set(j.keys()) == {"success", "with", "with_avatar", "rows", "can_write", "reason",
          "may_report", "live", "typing_on", "unread"}, sorted(j.keys()))
    seen += [x.get("avatar", "") for x in th.values()] + [j.get("with_avatar", "")]
    s, j, t = me.api("user_messages&with=" + DAVE)
    texts.append(t)
    check("somebody who hid their profile from the reader: the same not-found, and no picture in it",
          s == 404 and "with_avatar" not in j and "avatar" not in t, (s, t[:200]))

    # ── 3. friends, requests, blocks ────────────────────────────────────────────────────────────
    views = {}
    for v in ("friends", "incoming", "pending", "blocks"):
        s, j, t = me.api("user_people&view=" + v)
        texts.append(t) if v != "blocks" else None
        views[v] = {r.get("username"): r for r in j.get("rows") or []}
        seen += [r.get("avatar", "") for r in j.get("rows") or []]
    check("friends: the friend's picture", (views["friends"].get(BOB) or {}).get("avatar") == pic(BOB, 32), views["friends"])
    check("… a request: the asker's", (views["incoming"].get(ALICE) or {}).get("avatar") == pic(ALICE, 32), views["incoming"])
    check("… my blocks: the picture is where the name is — the reader's own list of whom she blocked",
          (views["blocks"].get(DAVE) or {}).get("avatar") == pic(DAVE, 32), views["blocks"])
    allrows = [r for v in views.values() for r in v.values()]
    check("… and every row is exactly what it was, plus the address — no id",
          keys(allrows) == {tuple(sorted(["username", "avatar", "since", "hide_profile", "note"]))}, keys(allrows))

    # ── 4. the directory ────────────────────────────────────────────────────────────────────────
    s, j, t = me.api("user_directory&per_page=100&search=avt")
    texts.append(t)
    dr = {r.get("username"): r for r in j.get("rows") or []}
    check("directory: each person's picture, and the reader's own letter", (dr.get(ALICE) or {}).get("avatar") == pic(ALICE, 32)
          and (dr.get(BOB) or {}).get("avatar") == pic(BOB, 32) and (dr.get(CAROL) or {}).get("avatar") == pic(CAROL, 32), dr)
    check("… no id in a row", keys(dr.values()) == {tuple(sorted(["username", "avatar", "since", "state", "self"]))}, keys(dr.values()))
    check("… and the one who hid his profile is not there, by name or by picture", DAVE not in dr, sorted(dr))
    seen += [r.get("avatar", "") for r in dr.values()]

    # ── 5. who has this ─────────────────────────────────────────────────────────────────────────
    s, j, t = me.api("hash_favourites&hash=" + H1)
    texts.append(t)
    fr = {r.get("username"): r for r in j.get("rows") or []}
    ls = {r.get("username"): r for r in j.get("lists") or []}
    check("who has this: the people, each with their picture (20 px)", (fr.get(ALICE) or {}).get("avatar") == pic(ALICE, 20)
          and (fr.get(BOB) or {}).get("avatar") == pic(BOB, 20) and (fr.get(CAROL) or {}).get("avatar") == pic(CAROL, 20), fr)
    check("… a row is a name and a picture and nothing else", keys(fr.values()) == {("avatar", "username")}, keys(fr.values()))
    check("… the lists it is on carry their owner's picture", (ls.get(ALICE) or {}).get("avatar") == pic(ALICE, 20)
          and keys(ls.values()) == {tuple(sorted(["name", "slug", "username", "items", "avatar"]))}, (ls, keys(ls.values())))
    check("… and the person who hid his profile is on neither, by name or by picture", DAVE not in fr and DAVE not in ls, (sorted(fr), sorted(ls)))
    seen += [r.get("avatar", "") for r in list(fr.values()) + list(ls.values())]

    # ── 6. the Info panel ───────────────────────────────────────────────────────────────────────
    s, j, t = me.api("index_info&hash=" + H1)
    texts.append(t)
    check("Info panel: the author's picture beside their name", s == 200 and j.get("content_author") == ALICE
          and j.get("content_author_avatar") == pic(ALICE, 20), (s, j.get("content_author"), j.get("content_author_avatar")))
    idish = [k for k in j if k in ("id", "uid", "user_id", "author_id", "content_author_id") or k.endswith("_user_id")]
    check("… and the answer still carries no id of the author", not idish, idish)
    seen.append(j.get("content_author_avatar", ""))

    # ── 7. the pages the server draws ───────────────────────────────────────────────────────────
    s, html = me.page("?action=account")
    nav = re.search(r'<a href="/\?action=account" class="nav-user[^"]*">(<img [^>]+>)' + CAROL, html)
    check("the navigation: the reader's own picture before her name", bool(nav) and "nav-av" in nav.group(1)
          and 'src="' + pic(CAROL, 20).replace("&", "&amp;") + '"' in nav.group(1), nav.group(0) if nav else "")
    h1 = re.search(r"<h1>(.*?)</h1>", html, re.S)
    check("the account heading: the same, at 32, inside the sentence where the name is", bool(h1) and "acc-h1-av" in h1.group(1)
          and re.search(r'<span class="av-who"><img [^>]*acc-h1-av[^>]*>' + CAROL + "</span>", h1.group(1)), h1.group(1) if h1 else "")
    s, html = me.page("?action=emotes")
    em = re.search(r'<a class="av-who" href="/\?action=u&amp;name=' + ALICE + r'">(<img [^>]+>)' + ALICE + "</a>", html)
    check("the emotes page: the uploader's picture inside \"by …\"", bool(em) and "emote-av" in em.group(1)
          and 'src="' + pic(ALICE, 20).replace("&", "&amp;") + '"' in em.group(1), em.group(0) if em else "")

    # ── 8. nowhere else ─────────────────────────────────────────────────────────────────────────
    dave_bits = [CROP[DAVE][:16], DAVE]
    leaked = [b for b in dave_bits for t in texts if b in t]
    check("the person who hid his profile appears in NONE of the reader's answers, by name or by picture", not leaked, leaked)
    bad = [u for u in seen if not SHAPE.match(u or "")]
    # 18 = what this run's own rows produce: 4 lines, 2 conversations and one head, 3 people rows,
    # 3 in the directory, 3 people and 1 list on the hash, 1 author. Fewer means a surface went quiet.
    check("every address handed out is one of the three shapes the server writes — none has room for an id",
          len(seen) >= 18 and not bad, (len(seen), bad[:5]))
    check("… and none of them names an account", not [u for u in seen if re.search(r"[?&](id|uid|user|u|name)=", u)])

    # ── 9. the panel ────────────────────────────────────────────────────────────────────────────
    adm = Client()
    adm.page("?action=admin")
    s, j, _ = adm.api("admin/login", "POST", {"username": "admin", "password": "admin123", "csrf_token": adm.csrf}, csrf_header=True)
    check("the owner signs in to the panel", s == 200 and j.get("success"), (s, j))
    s, j, _ = adm.api("admin/fetch_users&search=avt")
    ur = {r.get("username"): r for r in j.get("rows") or []}
    check("panel users: what is drawn beside each name (24 px -> the 64 square), and the phase A thumb untouched",
          (ur.get(ALICE) or {}).get("name_avatar") == pic(ALICE, 24) and (ur.get(CAROL) or {}).get("name_avatar") == pic(CAROL, 24)
          and (ur.get(ALICE) or {}).get("avatar", "").endswith("&s=128") and (ur.get(CAROL) or {}).get("avatar") == "", ur.get(ALICE))
    s, j, _ = adm.api("admin/user_media&id=" + str(A))
    check("… and the edit window's own answer carries it too", (j.get("user") or {}).get("name_avatar") == pic(ALICE, 24), j)
    s, j, _ = adm.api("admin/fetch_message_reports&status=all&search=avt")
    rep = next((r for r in j.get("reports") or [] if r.get("reported") == ALICE), {})
    check("panel reports: the picture beside the reporter and beside the reported",
          rep.get("reporter_avatar") == pic(CAROL, 20) and rep.get("reported_avatar") == pic(ALICE, 20), json.dumps(rep)[:300] or "no report")
    check("… and beside whoever said the line before it", (rep.get("context") or {}).get("from") == CAROL
          and (rep.get("context") or {}).get("from_avatar") == pic(CAROL, 20), rep.get("context"))
    s, j, _ = adm.api("admin/wl_content", "POST", {"op": "list", "status": "pending", "search": "avt"}, csrf_header=True)
    wc = next((r for r in j.get("rows") or [] if r.get("info_hash") == H2), {})
    check("panel content review: the author's picture on a card", wc.get("author") == BOB and wc.get("author_avatar") == pic(BOB, 20)
          and "author_avatar_sha" not in wc, {k: wc.get(k) for k in ("author", "author_avatar", "author_avatar_sha")})
    s, j, _ = adm.api("admin/wl_content", "POST", {"op": "edits"}, csrf_header=True)
    ed = next((r for r in j.get("rows") or [] if r.get("info_hash") == H1), {})
    check("… and on a proposed rewrite", ed.get("author") == BOB and ed.get("author_avatar") == pic(BOB, 20), {k: ed.get(k) for k in ("author", "author_avatar")})
    s, j, _ = adm.api("admin/shout_emotes")
    eo = next((r for r in j.get("emotes") or [] if r.get("code") == "avtemo"), {})
    check("panel emote manager: the uploader's picture", eo.get("uploader") == ALICE and eo.get("uploader_avatar") == pic(ALICE, 20), eo)
    s, html = adm.page("?action=admin-users")
    tag = re.search(r'<script src="/assets/js/avatar\.js\?v=\d+" data-base="/" data-avatars="1" data-def="[0-9a-f]{0,16}"></script>', html)
    check("… and the panel page loads the same picture code, handed its two facts on its own tag", bool(tag))

    # ── 10. switched off: nothing, anywhere ─────────────────────────────────────────────────────
    php("setSetting($db, 'avatars_enabled', '0');")
    empties = {}
    s, j, _ = me.api("shout_list");                empties["shout"] = {r.get("avatar") for r in j.get("rows") or []}
    s, j, _ = me.api("user_messages");             empties["inbox"] = {x.get("avatar") for x in j.get("threads") or []}
    s, j, _ = me.api("user_messages&with=" + ALICE); empties["thread"] = {j.get("with_avatar")}
    s, j, _ = me.api("user_people&view=friends");  empties["people"] = {r.get("avatar") for r in j.get("rows") or []}
    s, j, _ = me.api("user_directory&search=avt"); empties["dir"] = {r.get("avatar") for r in j.get("rows") or []}
    s, j, _ = me.api("hash_favourites&hash=" + H1)
    empties["who"] = {r.get("avatar") for r in (j.get("rows") or []) + (j.get("lists") or [])}
    s, j, _ = me.api("index_info&hash=" + H1);     empties["info"] = {j.get("content_author_avatar")}
    s, j, _ = adm.api("admin/fetch_users&search=avt"); empties["panel"] = {r.get("name_avatar") for r in j.get("rows") or []}
    check("pictures switched off: every one of those fields is empty — the browser then draws nothing",
          all(v == {""} for v in empties.values()), empties)
    drawn = []
    for q in ("?action=account", "?action=shoutbox", "?action=emotes"):
        s, html = me.page(q)
        drawn += [q] if re.search(r"<img[^>]+js-avatar", html) else []
    check("… and no page draws one either: the navigation, the heading, the room, the emotes", not drawn, drawn)
    php("setSetting($db, 'avatars_enabled', '1');")
finally:
    cleanup()
    php("$db->prepare(\"UPDATE user_groups SET permissions = ? WHERE slug = 'member'\")->execute([" + phpstr(member_before) + "]);"
        + "".join(("$db->prepare('DELETE FROM settings WHERE `key` = ?')->execute(['%s']);" % k) if v == "absent"
                  else ("setSetting($db, '%s', %s);" % (k, phpstr(v[2:]))) for k, v in was.items())
        # What this run's two sign-ins wrote and nothing else: the panel's own line in the log, and a
        # remember-me token the owner's account got for it. The accounts' own went with them.
        + "$db->prepare('DELETE FROM audit_log WHERE id > ?')->execute([" + str(audit_floor) + "]);"
        + "$db->prepare('DELETE FROM user_tokens WHERE id > ?')->execute([" + str(admin_tokens_floor) + "]);")
    clear_throttles()

print("\n%d checks, %d failed" % (n, fails))
sys.exit(1 if fails else 0)
