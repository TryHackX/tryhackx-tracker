#!/usr/bin/env python3
"""
Sounds (1.56.0) — the four endpoints end to end:
    python tests/sounds_test.py           (needs the local server and a bootstrapped database)

A visitor gets 401; a member gets the library, their (muted) preferences and the site defaults; the
account page grows the tab and the badge carries a config only once sounds are on; what is saved is
clamped and checked against the library; the feature switch and the permission both close it. The
owner uploads a real file, is refused a PNG, a duplicate and an oversized one, the upload streams
with an ETag (and a 304), becomes a site default, and deleting it clears that default and a member's
pick of it.
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
        "require_once $root . '/includes/sounds.php';"
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


USER, PASS = "snduser", "SmokePass123!"
clear_throttles()
was = {k: php("echo $cfg['" + k + "'] ?? '';") for k in ("users_enabled", "sounds_enabled", "sound_default_notification", "sound_default_message")}
member_before = php("$st = $db->query(\"SELECT permissions FROM user_groups WHERE slug = 'member'\"); echo $st->fetchColumn();")
php("setSetting($db, 'users_enabled', '1'); setSetting($db, 'sounds_enabled', '1');"
    "setSetting($db, 'sound_default_notification', 'b:ding'); setSetting($db, 'sound_default_message', '');"
    "$db->exec(\"UPDATE user_groups SET permissions = JSON_MERGE_PATCH(permissions, '{\\\"sounds.use\\\":true}') WHERE slug = 'member'\");"
    "$db->prepare('DELETE FROM users WHERE username = ?')->execute(['" + USER + "']);"
    "$db->exec(\"DELETE FROM sounds WHERE name LIKE 'Py test%'\");"
    "userCreate($db, $cfg, '" + USER + "', 'snd@example.org', '" + PASS + "', '127.0.0.1');"
    "$db->exec(\"UPDATE users SET email_verified = 1 WHERE username = '" + USER + "'\");")
uid = int(php("echo (int)$db->query(\"SELECT id FROM users WHERE username = '" + USER + "'\")->fetchColumn();") or 0)
check("the account exists", uid > 0, uid)
mp3 = open(os.path.join(ROOT, "assets", "sounds", "ding.mp3"), "rb").read()
sid = None

try:
    guest = Client()
    s, j = guest.api("sounds")
    check("a visitor gets 401 from the library", s == 401, (s, j))
    s, j = guest.api("user_sound_prefs", "POST", {"prefs": {"on": 1}})
    check("… and cannot save anything", s in (401, 403), (s, j))

    me = Client()
    me.page("login")
    s, j = me.api("user_login", "POST", {"csrf_token": me.csrf, "login": USER, "password": PASS})
    check("the member signs in", s == 200 and j.get("success"), (s, j))
    s, j = me.api("sounds")
    lib = {e["id"]: e for e in (j.get("library") or [])}
    check("a member gets the library, muted preferences and the site defaults",
          s == 200 and j.get("success") and len(lib) >= 10 and "b:ding" in lib
          and j.get("prefs", {}).get("on") == 0 and j["prefs"]["ev"] == {"notification": None, "message": None}
          and j.get("defaults") == {"notification": "b:ding", "message": ""} and j.get("kinds") == ["notification", "message"], (s, str(j)[:300]))
    check("the shipped files are served as static files", lib["b:ding"]["url"].endswith("assets/sounds/ding.mp3"), lib["b:ding"])
    s, html = me.page("account")
    check("the account page has the tab and the pane with its data block",
          'data-pane="sounds"' in html and 'id="acc-pane-sounds"' in html and 'id="snd-data"' in html and 'id="snd-ev-message"' in html)
    s, html = me.page("")
    check("muted: the badge carries no config and there is no note", 'data-sounds=' not in html and 'id="sound-chip"' not in html and 'id="nav-unread"' in html)

    s, j = me.api("user_sound_prefs", "POST", {"csrf_token": me.csrf, "prefs": {"on": 1, "vol": 250, "pre": -5, "pre_kind": "silence", "ev": {"notification": "b:ding", "message": "c:424242"}}})
    check("saving clamps the numbers and drops an id the library lacks",
          s == 200 and j.get("success") and j["prefs"]["vol"] == 100 and j["prefs"]["pre"] == 0 and j["prefs"]["pre_kind"] == "silence"
          and j["prefs"]["ev"] == {"notification": "b:ding", "message": None}, (s, j))
    check("… and answers with what the page will play", (j.get("client") or {}).get("vol") == 100 and j["client"]["ev"]["notification"]["id"] == "b:ding" and j["client"]["ev"]["message"] is None, j.get("client"))
    s, html = me.page("")
    m = re.search(r'data-sounds="([^"]+)"', html)
    cfg_attr = json.loads(m.group(1).replace("&quot;", '"').replace("&amp;", "&")) if m else None
    check("switched on: the badge carries the config and the note is in the page",
          cfg_attr is not None and cfg_attr["ev"]["notification"]["url"].endswith("assets/sounds/ding.mp3") and 'id="sound-chip"' in html, (m.group(1)[:200] if m else html.count("nav-unread")))
    check("the page loads the script", "assets/js/sounds.js" in html)
    s, j = me.api("user_sound_prefs", "POST", {"csrf_token": me.csrf, "prefs": {"on": 1, "ev": {"notification": "", "message": ""}}})
    check("every event silent: nothing for the page to play", s == 200 and j.get("client") is None and j["prefs"]["ev"] == {"notification": "", "message": ""}, (s, j))
    s, j = me.api("user_sound_prefs", "POST", {"csrf_token": "nope", "prefs": {"on": 1}})
    check("a bad CSRF token is refused", s == 403, (s, j))

    php("setSetting($db, 'sounds_enabled', '0');")
    s, j = me.api("sounds")
    s2, html = me.page("account")
    s3, home = me.page("")
    check("the feature switch closes the endpoint, the tab and the script", s == 403 and 'data-pane="sounds"' not in html and "assets/js/sounds.js" not in home, (s, j))
    php("setSetting($db, 'sounds_enabled', '1');"
        "$db->exec(\"UPDATE user_groups SET permissions = JSON_REMOVE(permissions, '$.\\\"sounds.use\\\"') WHERE slug = 'member'\");")
    s, j = me.api("sounds")
    s2, html = me.page("account")
    check("without sounds.use the member is refused and has no tab", s == 403 and 'data-pane="sounds"' not in html, (s, j))
    php("$db->exec(\"UPDATE user_groups SET permissions = JSON_MERGE_PATCH(permissions, '{\\\"sounds.use\\\":true}') WHERE slug = 'member'\");")

    # ── the owner ────────────────────────────────────────────────────────────
    adm = Client()
    s, html = adm.page("admin")
    check("the admin login page carries a token", adm.csrf is not None)
    s, j = adm.api("admin/login", "POST", {"username": "admin", "password": "admin123", "csrf_token": adm.csrf}, csrf_header=True)
    check("the owner signs in", s == 200 and j.get("success"), (s, j))
    s, j = adm.api("admin/sounds")
    check("the owner sees the uploads, the shipped files and the limits",
          s == 200 and j.get("success") and isinstance(j.get("sounds"), list) and len(j.get("builtins") or []) >= 10 and j.get("max_bytes") == 524288, (s, str(j)[:200]))
    check("a member cannot", me.api("admin/sounds")[0] in (401, 403))
    s, j = adm.api("admin/sounds", "POST", {"op": "upload", "name": "Py test ding", "data": "data:audio/mpeg;base64," + base64.b64encode(mp3).decode()}, csrf_header=True)
    sid = (j.get("added") or {}).get("sid")
    num = (j.get("added") or {}).get("id")
    check("a real MP3 uploads (as a data: URL, the way FileReader hands it over)", s == 200 and j.get("success") and sid and sid.startswith("c:") and any(x["sid"] == sid and x["mime"] == "audio/mpeg" and x["bytes"] == len(mp3) for x in j["sounds"]), (s, str(j)[:200]))
    s, j = adm.api("admin/sounds", "POST", {"op": "upload", "name": "Py test dup", "data": base64.b64encode(mp3).decode()}, csrf_header=True)
    check("the same file again is refused", s == 400 and "already" in (j.get("error") or ""), (s, j))
    s, j = adm.api("admin/sounds", "POST", {"op": "upload", "name": "Py test png", "data": base64.b64encode(b"\x89PNG\r\n\x1a\n" + b"\0" * 3000).decode()}, csrf_header=True)
    check("a PNG is refused, by its bytes", s == 400 and "not" in (j.get("error") or ""), (s, j))
    s, j = adm.api("admin/sounds", "POST", {"op": "upload", "name": "Py test big", "data": base64.b64encode(b"x" * (600 * 1024)).decode()}, csrf_header=True)
    check("an oversized file is refused before it is decoded", s == 400 and "512" in (j.get("error") or ""), (s, j))
    s, j = adm.api("admin/sounds", "POST", {"op": "upload", "name": "Py test bad64", "data": "@@@not base64@@@"}, csrf_header=True)
    check("garbage is refused", s == 400, (s, j))

    s, h, body = guest.raw("sound&id=" + str(num))
    etag = h.get("ETag") or h.get("Etag")
    check("the upload streams to anyone as the type it was sniffed to be, with an ETag and nosniff",
          s == 200 and body == mp3 and (h.get("Content-Type") or "").startswith("audio/mpeg") and etag and h.get("X-Content-Type-Options") == "nosniff"
          and "immutable" in (h.get("Cache-Control") or ""), (s, {k: v for k, v in h.items() if k.lower() in ("content-type", "etag", "cache-control", "content-length")}))
    s, h, body = guest.raw("sound&id=" + str(num), {"If-None-Match": etag or '"x"'})
    check("… and answers 304 to a browser that has it", s == 304 and body == b"", (s, len(body)))
    s, h, body = guest.raw("sound&id=999999")
    check("an unknown id is 404", s == 404, s)
    s, j = me.api("sounds")
    check("the member's library now offers it", s == 200 and any(e["id"] == sid and e["custom"] and e["ms"] for e in j["library"]), sid)

    s, j = adm.api("admin/save_settings", "POST", {"sound_default_message": sid}, csrf_header=True)
    check("the owner makes it the default for messages", s == 200, (s, j))
    s, j = me.api("sounds")
    check("… which the member is told", j.get("defaults", {}).get("message") == sid, j.get("defaults"))
    s, j = adm.api("admin/save_settings", "POST", {"sound_default_notification": "c:424242"}, csrf_header=True)
    check("a default the library lacks is stored as nothing", s == 200 and php("echo $cfg['sound_default_notification'] ?? '';") == "", (s, j))
    s, j = me.api("user_sound_prefs", "POST", {"csrf_token": me.csrf, "prefs": {"on": 1, "ev": {"message": sid}}})
    check("the member picks it", s == 200 and j["prefs"]["ev"]["message"] == sid, (s, j))
    s, j = adm.api("admin/sounds", "POST", {"op": "delete", "id": num}, csrf_header=True)
    check("the owner deletes it", s == 200 and j.get("success") and not any(x["sid"] == sid for x in j["sounds"]), (s, j))
    s, j = me.api("sounds")
    check("… the default it was is cleared and the member's pick falls back to the site default",
          j.get("defaults", {}).get("message") == "" and j["prefs"]["ev"]["message"] is None and not any(e["id"] == sid for e in j["library"]), (j.get("defaults"), j.get("prefs")))
    s, h, body = guest.raw("sound&id=" + str(num))
    check("… and its URL is gone", s == 404, s)
    s, j = adm.api("admin/sounds", "POST", {"op": "delete", "id": num}, csrf_header=True)
    check("deleting it again is 404", s == 404, (s, j))
    s, j = adm.api("admin/sounds", "POST", {"op": "nonsense"}, csrf_header=True)
    check("an unknown op is 400", s == 400, (s, j))
finally:
    php("$db->prepare('DELETE FROM users WHERE username = ?')->execute(['" + USER + "']);"
        "$db->exec(\"DELETE FROM sounds WHERE name LIKE 'Py test%'\");"
        "$db->prepare(\"UPDATE user_groups SET permissions = ? WHERE slug = 'member'\")->execute([" + json.dumps(member_before) + "]);"
        + "".join("setSetting($db, '%s', '%s');" % (k, v) for k, v in was.items()))
    clear_throttles()

print("\n%d checks, %d failed" % (n, fails))
sys.exit(1 if fails else 0)
