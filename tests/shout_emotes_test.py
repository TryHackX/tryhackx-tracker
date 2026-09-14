#!/usr/bin/env python3
"""
Shoutbox emotes and stickers (1.59.0) — the five endpoints and the page, end to end:
    python tests/shout_emotes_test.py     (needs the local server and a bootstrapped database)

The picture stream is PUBLIC and cached hard, and it carries the three headers that make an
uploaded SVG harmless: the type this site sniffed, nosniff, and a policy that forbids everything.
The list is for signed-in readers with `shout.view`. Uploading is a permission of its own that
nobody has until the operator grants it, and what arrives is judged by its BYTES — an SVG with a
script in it, something that is not a picture at all, something over the size cap, a code that is
not a code, the same bytes twice, the same code twice, and one person's share of the room.

Then the part a reader sees: a shout carrying `:code:` comes back with an image in it, and a shout
that is nothing but a sticker token comes back as one big image. The page at ?action=emotes lists
what the room understands, and says nothing at all when the shoutbox is closed to this reader.

Self-cleaning: the accounts, the uploads, the shouts and every setting it touched are put back in
the finally.
"""
import base64
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

    def raw(self, endpoint, headers=None):
        req = urllib.request.Request(BASE + "api.php?endpoint=" + endpoint, headers=headers or {})
        try:
            with self.opener.open(req, timeout=30) as r:
                return r.status, dict(r.headers), r.read()
        except urllib.error.HTTPError as e:
            return e.code, dict(e.headers), e.read()

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


def svg(inner="", attrs="", size='width="32" height="32" viewBox="0 0 32 32"'):
    return ('<svg xmlns="http://www.w3.org/2000/svg" ' + size + ' ' + attrs
            + '><rect width="32" height="32" fill="#123"/>' + inner + "</svg>").encode()


def b64(data):
    return base64.b64encode(data).decode()


USER, PAL, PASS = "emopyuser", "emopypal", "SmokePass123!"
SETTINGS = ("users_enabled", "shout_enabled", "shout_placement", "shout_live_seconds", "shout_flood_seconds",
            "shout_format", "shout_emotes_enabled", "shout_stickers_enabled", "shout_emote_max_kb",
            "shout_emote_max_px", "shout_emote_per_user")
clear_throttles()
was = {k: php("echo $cfg['" + k + "'] ?? '';") for k in SETTINGS}
member_before = php("$st = $db->query(\"SELECT permissions FROM user_groups WHERE slug = 'member'\"); echo $st->fetchColumn();")
php("setSetting($db, 'users_enabled', '1'); setSetting($db, 'shout_enabled', '1');"
    "setSetting($db, 'shout_placement', 'both'); setSetting($db, 'shout_live_seconds', '10');"
    "setSetting($db, 'shout_flood_seconds', '0'); setSetting($db, 'shout_format', 'bbcode');"
    "setSetting($db, 'shout_emotes_enabled', '1'); setSetting($db, 'shout_stickers_enabled', '1');"
    "setSetting($db, 'shout_emote_max_kb', '64'); setSetting($db, 'shout_emote_max_px', '128');"
    "setSetting($db, 'shout_emote_per_user', '20');"
    "$db->exec(\"UPDATE user_groups SET permissions = JSON_MERGE_PATCH(permissions,"
    " '{\\\"shout.view\\\":true,\\\"shout.post\\\":true,\\\"shout.delete_own\\\":true}') WHERE slug = 'member'\");"
    "$db->exec(\"UPDATE user_groups SET permissions = JSON_REMOVE(permissions, '$.\\\"shout.upload_emote\\\"') WHERE slug = 'member'\");"
    "$db->prepare('DELETE FROM users WHERE username IN (?, ?)')->execute(['" + USER + "', '" + PAL + "']);"
    "$db->exec(\"DELETE FROM shout_emotes WHERE code LIKE 'zzpy%'\");"
    "$db->exec('DELETE FROM shout_mentions'); $db->exec('DELETE FROM shouts');"
    "userCreate($db, $cfg, '" + USER + "', 'emopyuser@example.org', '" + PASS + "', '127.0.0.1');"
    "userCreate($db, $cfg, '" + PAL + "', 'emopypal@example.org', '" + PASS + "', '127.0.0.1');"
    "$db->exec(\"UPDATE users SET email_verified = 1 WHERE username IN ('" + USER + "', '" + PAL + "')\");")
uid = int(php("echo (int)$db->query(\"SELECT id FROM users WHERE username = '" + USER + "'\")->fetchColumn();") or 0)
check("both accounts exist", uid > 0 and int(php("echo (int)$db->query(\"SELECT id FROM users WHERE username = '" + PAL + "'\")->fetchColumn();") or 0) > 0, uid)

# A shipped emote to stream: the migration seeded them from assets/emotes/*.svg.
shipped = php("$e = $db->query(\"SELECT id, code, sha1, bytes FROM shout_emotes WHERE uploaded_by IS NULL ORDER BY code LIMIT 1\")->fetch();"
              " echo json_encode($e ?: null);")
shipped = json.loads(shipped or "null")
check("the shipped examples are in the table", shipped is not None and int(shipped["id"]) > 0, shipped)
mine_id = None

try:
    # ── the stream: public, cached, and defanged ─────────────────────────────
    guest = Client()
    s, h, body = guest.raw("shout_emote&id=" + str(shipped["id"]))
    etag = h.get("ETag") or h.get("Etag")
    check("a picture streams to anybody, as the type this site sniffed",
          s == 200 and len(body) == int(shipped["bytes"]) and (h.get("Content-Type") or "").startswith("image/svg+xml"),
          (s, h.get("Content-Type"), len(body)))
    check("… with an ETag and a month of immutable caching",
          bool(etag) and "immutable" in (h.get("Cache-Control") or ""), (etag, h.get("Cache-Control")))
    check("… with nosniff, so nothing re-reads it as HTML", h.get("X-Content-Type-Options") == "nosniff", h.get("X-Content-Type-Options"))
    csp = h.get("Content-Security-Policy") or ""
    check("… and with a policy that forbids everything but an inline style",
          "default-src 'none'" in csp and "style-src 'unsafe-inline'" in csp, csp)
    s, h, body = guest.raw("shout_emote&id=" + str(shipped["id"]), {"If-None-Match": etag or '"x"'})
    check("a browser that already has it gets 304 and no bytes", s == 304 and body == b"", (s, len(body)))
    s, h, body = guest.raw("shout_emote&id=999999")
    check("an id that never existed is 404", s == 404, s)
    s, j = guest.api("shout_emotes")
    check("but the LIST is not public: a visitor is told to sign in", s == 401 and j.get("error") == "login_required", (s, j))
    s, j = guest.api("shout_emote_upload", "POST", {"csrf_token": "x", "code": "zzpyx", "data": b64(svg())})
    check("… and a visitor uploads nothing", s in (401, 403), (s, j))

    # ── the list, for a member ───────────────────────────────────────────────
    me = Client()
    me.page("login")
    s, j = me.api("user_login", "POST", {"csrf_token": me.csrf, "login": USER, "password": PASS})
    check("the member signs in", s == 200 and j.get("success"), (s, j))
    s, j = me.api("shout_emotes")
    codes = [e["code"] for e in (j.get("emotes") or [])]
    check("the member is handed the vocabulary, the limits and the answer to 'may I add one'",
          s == 200 and j.get("success") and shipped["code"] in codes and j.get("may_upload") is False
          and j.get("stickers") is True and j.get("max_kb") == 64 and j.get("max_px") == 128 and j.get("per_user") == 20,
          (s, str(j)[:300]))
    one = [e for e in j["emotes"] if e["code"] == shipped["code"]][0]
    check("… and each entry is what the picker needs, and nothing more",
          sorted(one.keys()) == ["code", "h", "id", "name", "sticker", "url", "w"]
          and "endpoint=shout_emote&id=" in one["url"] and one["sticker"] is False, one)

    # ── uploading is its own permission ──────────────────────────────────────
    s, j = me.api("shout_emote_upload", "POST", {"csrf_token": me.csrf, "code": "zzpyno", "data": b64(svg())})
    check("a member without shout.upload_emote is refused", s == 403 and j.get("error") == "no_permission", (s, j))
    php("$db->exec(\"UPDATE user_groups SET permissions = JSON_MERGE_PATCH(permissions,"
        " '{\\\"shout.upload_emote\\\":true}') WHERE slug = 'member'\");")
    s, j = me.api("shout_emote_upload", "POST", {"csrf_token": me.csrf, "code": "zzpyone", "name": "Py one",
                                                 "data": "data:image/svg+xml;base64," + b64(svg("<title>one</title>"))})
    mine_id = (j.get("emote") or {}).get("id")
    check("with the permission it goes in, data: URL prefix and all",
          s == 200 and j.get("success") and (j.get("emote") or {}).get("code") == "zzpyone"
          and (j["emote"]["w"], j["emote"]["h"]) == (32, 32), (s, str(j)[:300]))
    s, j = me.api("shout_emote_upload", "POST", {"csrf_token": "nope", "code": "zzpycsrf", "data": b64(svg("<title>c</title>"))})
    check("a bad CSRF token is refused", s == 403, (s, j))

    # What the bytes say, not what the name says.
    s, j = me.api("shout_emote_upload", "POST", {"csrf_token": me.csrf, "code": "zzpyevil",
                                                 "data": b64(svg("<script>alert(1)</script>"))})
    check("an SVG carrying a script is refused, and says WHY",
          s == 400 and j.get("error") == "unsafe_svg" and j.get("detail") == "script", (s, j))
    s, j = me.api("shout_emote_upload", "POST", {"csrf_token": me.csrf, "code": "zzpyload",
                                                 "data": b64(svg("", 'onload="alert(1)"'))})
    check("… and so is one with an onload handler", s == 400 and j.get("error") == "unsafe_svg" and j.get("detail") == "handler", (s, j))
    s, j = me.api("shout_emote_upload", "POST", {"csrf_token": me.csrf, "code": "zzpyjunk", "data": b64(b"nonsense " * 40)})
    check("something that is not a picture at all says that instead", s == 400 and j.get("error") == "not_image", (s, j))
    s, j = me.api("shout_emote_upload", "POST", {"csrf_token": me.csrf, "code": "zzpybig",
                                                 "data": b64(b"\x89PNG\r\n\x1a\n" + b"\0" * (200 * 1024))})
    check("a PNG over the size cap is 413, before anything decodes it", s == 413 and j.get("error") == "too_large", (s, j))
    s, j = me.api("shout_emote_upload", "POST", {"csrf_token": me.csrf, "code": "zzpywide",
                                                 "data": b64(svg("<title>w</title>", "", 'width="400" height="400" viewBox="0 0 400 400"'))})
    check("bigger than the pixel cap is 400 and says the size it was",
          s == 400 and j.get("error") == "too_big_px" and j.get("detail") == "400x400", (s, j))
    s, j = me.api("shout_emote_upload", "POST", {"csrf_token": me.csrf, "code": "ZZ Bad!", "data": b64(svg("<title>b</title>"))})
    check("a code that is not a code is 400", s == 400 and j.get("error") == "bad_code", (s, j))
    s, j = me.api("shout_emote_upload", "POST", {"csrf_token": me.csrf, "code": "zzpydupe", "data": b64(svg("<title>one</title>"))})
    check("the same bytes again are 409, and name the code that already has them",
          s == 409 and j.get("error") == "duplicate" and j.get("detail") == "zzpyone", (s, j))
    s, j = me.api("shout_emote_upload", "POST", {"csrf_token": me.csrf, "code": "zzpyone", "data": b64(svg("<title>other</title>"))})
    check("the same code again is 409", s == 409 and j.get("error") == "code_taken", (s, j))
    php("setSetting($db, 'shout_emote_per_user', '1');")
    s, j = me.api("shout_emote_upload", "POST", {"csrf_token": me.csrf, "code": "zzpytwo", "data": b64(svg("<title>two</title>"))})
    check("one person's share of the room is a number, and it is 409 when it runs out",
          s == 409 and j.get("error") == "too_many", (s, j))
    php("setSetting($db, 'shout_emote_per_user', '20');")

    # ── what a reader sees ───────────────────────────────────────────────────
    s, j = me.api("shout_post", "POST", {"csrf_token": me.csrf, "body": "look at this :zzpyone: thing"})
    html = (j.get("row") or {}).get("html") or ""
    check("a shout carrying a token comes back with the image in it",
          s == 200 and 'class="shout-emote"' in html and 'alt=":zzpyone:"' in html and ":zzpyone: thing" not in html,
          (s, html[:200]))
    s, j = me.api("shout_post", "POST", {"csrf_token": me.csrf, "body": ":zzpyone:"})
    check("… and on its own it is still inline while it is not a sticker",
          'class="shout-emote"' in ((j.get("row") or {}).get("html") or ""), (j.get("row") or {}).get("html"))
    php("$db->prepare('UPDATE shout_emotes SET is_sticker = 1 WHERE code = ?')->execute(['zzpyone']);")
    s, j = me.api("shout_post", "POST", {"csrf_token": me.csrf, "body": "  :zzpyone:  "})
    sticker_html = (j.get("row") or {}).get("html") or ""
    check("a shout that is nothing but a sticker token is one big image and nothing else",
          s == 200 and sticker_html.startswith('<img class="shout-sticker"') and sticker_html.count("<img") == 1,
          (s, sticker_html[:200]))
    php("setSetting($db, 'shout_stickers_enabled', '0');")
    s, j = me.api("shout_list")
    last = (j.get("rows") or [])[-1] if j.get("rows") else {}
    check("with stickers switched off the same line is an ordinary inline emote",
          'class="shout-emote"' in (last.get("html") or ""), (last.get("html") or "")[:200])
    php("setSetting($db, 'shout_stickers_enabled', '1');"
        "$db->prepare('UPDATE shout_emotes SET is_sticker = 0 WHERE code = ?')->execute(['zzpyone']);")

    # ── the page ─────────────────────────────────────────────────────────────
    s, html = me.page("emotes")
    check("the page lists what the room understands, with the codes people have to type",
          s == 200 and 'id="emotes-page"' in html and 'data-code="' + shipped["code"] + '"' in html
          and ":" + shipped["code"] + ":" in html, (s, len(html)))
    check("… and offers the upload form to a member who may add one", 'id="emote-upload"' in html)

    # ── the other member ─────────────────────────────────────────────────────
    pal = Client()
    pal.page("login")
    s, j = pal.api("user_login", "POST", {"csrf_token": pal.csrf, "login": PAL, "password": PASS})
    check("the second member signs in", s == 200 and j.get("success"), (s, j))
    s, j = pal.api("shout_emote_delete", "POST", {"csrf_token": pal.csrf, "id": mine_id})
    check("somebody else's picture is not theirs to remove", s == 403 and j.get("error") == "not_yours", (s, j))
    s, j = pal.api("shout_emote_delete", "POST", {"csrf_token": pal.csrf, "id": shipped["id"]})
    check("… and neither is the site's own", s == 403 and j.get("error") == "not_yours", (s, j))

    # ── the switch, and the permission ───────────────────────────────────────
    php("$db->exec(\"UPDATE user_groups SET permissions = JSON_REMOVE(permissions, '$.\\\"shout.view\\\"') WHERE slug = 'member'\");")
    s, j = me.api("shout_emotes")
    check("without shout.view the list says so rather than asking for a sign-in",
          s == 403 and j.get("error") == "no_permission", (s, j))
    php("$db->exec(\"UPDATE user_groups SET permissions = JSON_MERGE_PATCH(permissions, '{\\\"shout.view\\\":true}') WHERE slug = 'member'\");")
    php("setSetting($db, 'shout_emotes_enabled', '0');")
    s, j = me.api("shout_emotes")
    s2, h2, b2 = guest.raw("shout_emote&id=" + str(shipped["id"]))
    s3, html = me.page("emotes")
    # The switch closes the list and the page — but NOT the picture: a stored emote still streams so
    # the owner's manager can preview what it is about to switch back on (api/shout_emote.php, and the
    # reason api/sound.php streams a sound whatever the feature switch says). shoutRenderEmotes() is
    # where "off" bites — the tag is no longer written into a shout.
    check("the emote switch closes the list and the page, and the picture still previews for the manager",
          s == 403 and j.get("error") == "disabled" and s2 in (200, 304) and 'id="emotes-page"' not in html, (s, s2, s3))
    s, j = me.api("shout_list")
    check("… and a line that used a token is text again",
          all("<img" not in (r.get("html") or "") for r in (j.get("rows") or [])), str(j)[:200])
    php("setSetting($db, 'shout_emotes_enabled', '1');")

    # ── the owner's manager ──────────────────────────────────────────────────
    adm = Client()
    adm.page("admin")
    s, j = adm.api("admin/login", "POST", {"username": "admin", "password": "admin123", "csrf_token": adm.csrf}, csrf_header=True)
    check("the owner signs in", s == 200 and j.get("success"), (s, j))
    check("a member cannot reach the manager at all", me.api("admin/shout_emotes")[0] in (401, 403))
    s, j = adm.api("admin/shout_emotes")
    rows = {e["code"]: e for e in (j.get("emotes") or [])}
    check("the owner sees everything, with who put each one there",
          s == 200 and j.get("success") and shipped["code"] in rows and rows[shipped["code"]]["uploader"] is None
          and rows.get("zzpyone", {}).get("uploader") == USER and j.get("max_kb") == 64, (s, str(j)[:300]))
    s, j = adm.api("admin/shout_emotes", "POST", {"op": "upload", "code": "zzpysite", "name": "Py site",
                                                  "data": b64(svg("<title>site</title>")), "sticker": True}, csrf_header=True)
    site_id = (j.get("added") or {}).get("id")
    rows = {e["code"]: e for e in (j.get("emotes") or [])}
    check("the owner adds one, and it belongs to the site rather than to an account",
          s == 200 and j.get("success") and rows.get("zzpysite", {}).get("uploader") is None
          and rows["zzpysite"]["sticker"] is True, (s, str(j)[:200]))
    s, j = adm.api("admin/shout_emotes", "POST", {"op": "disable", "id": site_id}, csrf_header=True)
    rows = {e["code"]: e for e in (j.get("emotes") or [])}
    check("switching one off leaves it listed for the manager", s == 200 and rows["zzpysite"]["enabled"] is False, (s, str(j)[:200]))
    s, j = me.api("shout_emotes")
    check("… and takes it out of the picker", "zzpysite" not in [e["code"] for e in (j.get("emotes") or [])], str(j)[:200])
    s, j = adm.api("admin/shout_emotes", "POST", {"op": "enable", "id": site_id}, csrf_header=True)
    s2, j2 = adm.api("admin/shout_emotes", "POST", {"op": "sticker", "id": site_id, "on": False}, csrf_header=True)
    rows = {e["code"]: e for e in (j2.get("emotes") or [])}
    check("on again, and no longer a sticker", s == 200 and s2 == 200 and rows["zzpysite"]["enabled"] is True
          and rows["zzpysite"]["sticker"] is False, (s, s2))
    s, j = adm.api("admin/shout_emotes", "POST", {"op": "upload", "code": "zzpysite", "data": b64(svg("<title>dupe code</title>"))}, csrf_header=True)
    check("the panel is judged by the same rules as anybody else", s == 409, (s, j))
    s, j = adm.api("admin/shout_emotes", "POST", {"op": "delete", "id": site_id}, csrf_header=True)
    check("the owner removes it", s == 200 and "zzpysite" not in [e["code"] for e in (j.get("emotes") or [])], (s, str(j)[:200]))
    s, j = adm.api("admin/shout_emotes", "POST", {"op": "delete", "id": site_id}, csrf_header=True)
    check("deleting it twice is 404", s == 404, (s, j))
    s, j = adm.api("admin/shout_emotes", "POST", {"op": "nonsense"}, csrf_header=True)
    check("an unknown op is 400", s == 400, (s, j))

    # ── the member takes their own back ──────────────────────────────────────
    s, j = me.api("shout_emote_delete", "POST", {"csrf_token": me.csrf, "id": mine_id})
    check("a member removes what they uploaded", s == 200 and j.get("success"), (s, j))
    s, j = me.api("shout_emotes")
    check("… and it is gone from the vocabulary", "zzpyone" not in [e["code"] for e in (j.get("emotes") or [])], str(j)[:200])
    s, j = me.api("shout_list")
    check("… while the lines that used it simply show the text again",
          any(":zzpyone:" in (r.get("html") or "") for r in (j.get("rows") or [])), str(j)[:300])
    s, j = me.api("shout_emote_delete", "POST", {"csrf_token": me.csrf, "id": mine_id})
    check("deleting it twice is 404, in the same vocabulary shout_delete answers in",
          s == 404 and j.get("error") == "not_found", (s, j))

    # ── the settings round-trip ──────────────────────────────────────────────
    # Nonsense in every field at once: a key missing from the allowed list is silently ignored, which
    # looks exactly like a save that worked, and a missing clamp is a number nothing downstream expects.
    s, j = adm.api("admin/save_settings", "POST", {
        "shout_emotes_enabled": "yes", "shout_stickers_enabled": "0",
        "shout_emote_max_kb": "99999", "shout_emote_max_px": "1", "shout_emote_per_user": "0"}, csrf_header=True)
    keys = ["shout_emotes_enabled", "shout_stickers_enabled", "shout_emote_max_kb", "shout_emote_max_px", "shout_emote_per_user"]
    stored = dict(zip(keys, php("$out = []; foreach (['" + "','".join(keys) + "'] as $k) $out[] = (string)($cfg[$k] ?? '');"
                                " echo implode('|', $out);").split("|")))
    check("every field is saveable, and the nonsense is clamped or coerced rather than refused",
          s == 200 and j.get("success") and stored == {
              "shout_emotes_enabled": "0", "shout_stickers_enabled": "0", "shout_emote_max_kb": "512",
              "shout_emote_max_px": "32", "shout_emote_per_user": "1"}, (s, stored))
finally:
    php("$db->exec(\"DELETE FROM shout_emotes WHERE code LIKE 'zzpy%'\");"
        "$db->exec('DELETE FROM shout_mentions'); $db->exec('DELETE FROM shouts');"
        "$db->prepare('DELETE FROM users WHERE username IN (?, ?)')->execute(['" + USER + "', '" + PAL + "']);"
        "$db->prepare(\"UPDATE user_groups SET permissions = ? WHERE slug = 'member'\")->execute([" + json.dumps(member_before) + "]);"
        + "".join("setSetting($db, '%s', '%s');" % (k, v) for k, v in was.items()))
    clear_throttles()

print("\n%d checks, %d failed" % (n, fails))
sys.exit(1 if fails else 0)
