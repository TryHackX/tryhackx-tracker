#!/usr/bin/env python3
"""
Pictures and profile covers (1.63.0) over HTTP — the member endpoints, the two streams, the panel:
    python tests/usermedia_test.py     (needs the local server and a bootstrapped database)

A picture goes up as ONE multipart request carrying the file and its framing; what the server keeps
is decided by the bytes (a PNG declared as a JPEG is a PNG, an SVG declared as a PNG is refused),
and what it streams back is WebP with the headers of a picture that can never become a page. The
uncropped source answers its owner and nobody else. Reframing, removing, the rate limit, the
permission and the feature switch each get their own check, and so does the body that is too large
for PHP to keep — which must say "too large", not "bad token". Then the panel: taking a member's
picture down (they are told), and the site's own default picture and cover.

Every switch it leans on is set at the start and put back in the finally, with the member group's
permissions, the two accounts and every row they left.
"""
import http.cookiejar
import json
import os
import re
import struct
import subprocess
import sys
import urllib.error
import urllib.request
import uuid

BASE = os.environ.get("SMOKE_BASE", "http://127.0.0.1:8089/")
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
BOOT = ("$root = '" + ROOT.replace("\\", "/") + "';"
        "require_once $root . '/config/app.php'; require_once $root . '/config/database.php';"
        "require_once $root . '/includes/settings.php'; require_once $root . '/includes/functions.php';"
        "require_once $root . '/includes/schema.php'; require_once $root . '/includes/whitelist.php';"
        "require_once $root . '/includes/mail.php'; require_once $root . '/includes/users.php';"
        "require_once $root . '/includes/usermedia.php';"
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


def phpstr(v):
    """A PHP single-quoted string literal: nothing inside it is interpolated."""
    return "'" + str(v).replace("\\", "\\\\").replace("'", "\\'") + "'"


def multipart(fields, file=None):
    """fields: {name: str}; file: (filename, content_type, bytes) sent as `file`."""
    b = "----umpy" + uuid.uuid4().hex
    out = b""
    for k, v in fields.items():
        out += ("--%s\r\nContent-Disposition: form-data; name=\"%s\"\r\n\r\n%s\r\n" % (b, k, v)).encode()
    if file is not None:
        fn, ct, data = file
        out += ("--%s\r\nContent-Disposition: form-data; name=\"file\"; filename=\"%s\"\r\nContent-Type: %s\r\n\r\n"
                % (b, fn, ct)).encode() + data + b"\r\n"
    out += ("--%s--\r\n" % b).encode()
    return out, "multipart/form-data; boundary=" + b


class Client:
    def __init__(self):
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))
        self.csrf = None

    def page(self, action):
        with self.opener.open(BASE + ("?action=" + action if action else ""), timeout=30) as r:
            html = r.read().decode("utf-8", "replace")
        m = re.search(r'name="csrf_token" value="([^"]+)"', html) or re.search(r'id="account-csrf" value="([^"]+)"', html)
        if m:
            self.csrf = m.group(1)
        return r.status, html

    def raw(self, url, headers=None):
        req = urllib.request.Request(url if url.startswith("http") else BASE + url.lstrip("/"), headers=headers or {})
        try:
            with self.opener.open(req, timeout=30) as r:
                return r.status, dict(r.headers), r.read()
        except urllib.error.HTTPError as e:
            return e.code, dict(e.headers), e.read()

    def send(self, endpoint, data, ctype, headers=None):
        h = {"Accept": "application/json", "Content-Type": ctype}
        h.update(headers or {})
        req = urllib.request.Request(BASE + "api.php?endpoint=" + endpoint, data=data, method="POST", headers=h)
        try:
            with self.opener.open(req, timeout=60) as r:
                status, text = r.status, r.read().decode("utf-8", "replace")
        except urllib.error.HTTPError as e:
            status, text = e.code, e.read().decode("utf-8", "replace")
        self.last_text = text
        try:
            return status, json.loads(text or "{}")
        except Exception:
            # A development PHP with display_errors on prints its own start-up warning (a body over
            # post_max_size) ahead of the answer; the answer is still the JSON after it.
            i = text.find("{")
            try:
                return status, json.loads(text[i:]) if i >= 0 else {}
            except Exception:
                return status, {}

    def api(self, endpoint, method="GET", body=None, csrf_header=False):
        if method == "GET":
            s, h, b = self.raw("api.php?endpoint=" + endpoint, {"Accept": "application/json"})
            try:
                return s, json.loads(b.decode() or "{}")
            except Exception:
                return s, {}
        return self.send(endpoint, json.dumps(body).encode(), "application/json",
                         {"X-CSRF-Token": self.csrf} if csrf_header and self.csrf else None)

    def upload(self, endpoint, fields, file=None, csrf_header=False):
        body, ct = multipart(fields, file)
        return self.send(endpoint, body, ct, {"X-CSRF-Token": self.csrf} if csrf_header and self.csrf else None)


def webp_size(b):
    """Canvas size of a WebP (VP8X, VP8L or VP8), or None."""
    if len(b) < 30 or b[:4] != b"RIFF" or b[8:12] != b"WEBP":
        return None
    c = b[12:16]
    if c == b"VP8X":
        return (1 + int.from_bytes(b[24:27], "little"), 1 + int.from_bytes(b[27:30], "little"))
    if c == b"VP8L":
        v = int.from_bytes(b[21:25], "little")
        return (1 + (v & 0x3FFF), 1 + ((v >> 14) & 0x3FFF))
    if c == b"VP8 ":
        return (int.from_bytes(b[26:28], "little") & 0x3FFF, int.from_bytes(b[28:30], "little") & 0x3FFF)
    return None


def exif_jpeg(jpeg, orientation=1):
    """A JPEG with an APP1 Exif block after SOI carrying an orientation and a GPS position."""
    be16 = lambda v: struct.pack(">H", v)
    be32 = lambda v: struct.pack(">I", v)
    entry = lambda tag, typ, cnt, val: be16(tag) + be16(typ) + be32(cnt) + val
    tiff = b"MM\x00\x2A" + be32(8)
    ifd0 = be16(2) + entry(0x0112, 3, 1, be16(orientation) + b"\x00\x00") + entry(0x8825, 4, 1, be32(38)) + be32(0)
    gps = (be16(4) + entry(1, 2, 2, b"N\x00\x00\x00") + entry(2, 5, 3, be32(92))
           + entry(3, 2, 2, b"E\x00\x00\x00") + entry(4, 5, 3, be32(116)) + be32(0))
    rat = lambda a, b: be32(a) + be32(b)
    data = rat(52, 1) + rat(13, 1) + rat(3000, 100) + rat(21, 1) + rat(0, 1) + rat(1200, 100)
    body = b"Exif\x00\x00" + tiff + ifd0 + gps + data
    return b"\xFF\xD8\xFF\xE1" + be16(len(body) + 2) + body + jpeg[2:]


# ── fixtures, drawn by GD through php so the bytes are real pictures ────────────────────────────
fx = json.loads(php(
    "$h = function (int $w, int $hh) { $im = imagecreatetruecolor($w, $hh);"
    " imagefilledrectangle($im, 0, 0, intdiv($w, 2) - 1, $hh - 1, imagecolorallocate($im, 230, 20, 20));"
    " imagefilledrectangle($im, intdiv($w, 2), 0, $w - 1, $hh - 1, imagecolorallocate($im, 20, 20, 230)); return $im; };"
    "ob_start(); imagepng($h(400, 300)); $png = ob_get_clean();"
    "ob_start(); imagepng($h(600, 600)); $png2 = ob_get_clean();"
    "ob_start(); imagejpeg($h(1600, 500), null, 90); $jpg = ob_get_clean();"
    "echo json_encode(['png' => bin2hex($png), 'png2' => bin2hex($png2), 'jpg' => bin2hex($jpg),"
    " 'post_max' => userMediaIniBytes((string)ini_get('post_max_size')), 'up_max' => userMediaIniBytes((string)ini_get('upload_max_filesize'))]);") or "{}")
PNG = bytes.fromhex(fx.get("png", ""))
PNG2 = bytes.fromhex(fx.get("png2", ""))
JPG_GPS = exif_jpeg(bytes.fromhex(fx.get("jpg", "")))
SVG = b'<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" width="64" height="64"><rect width="64" height="64"/></svg>'
POST_MAX = int(fx.get("post_max") or 0)
UP_MAX = int(fx.get("up_max") or 0)

USER, PAL, PASS = "umpyalice", "umpybob", "SmokePass123!"
SETTINGS = ("users_enabled", "users_require_email_verify", "profiles_enabled", "avatars_enabled", "covers_enabled",
            "avatar_max_kb", "avatar_max_mp", "cover_height", "cover_height_mobile", "cover_overlay",
            "avatar_default", "avatar_default_sha", "avatar_default_x",
            "avatar_default_y", "avatar_default_zoom", "cover_default_sha", "cover_default_x", "cover_default_y",
            "cover_default_zoom")
clear_throttles()
was = {k: php("echo array_key_exists('" + k + "', $cfg) ? 'v:' . $cfg['" + k + "'] : 'absent';") for k in SETTINGS}
member_before = php("echo $db->query(\"SELECT permissions FROM user_groups WHERE slug = 'member'\")->fetchColumn();")
site_rows = int(php("echo (int)$db->query('SELECT COUNT(*) FROM user_media WHERE user_id IS NULL')->fetchColumn();") or 0)
audit_floor = int(php("echo (int)$db->query('SELECT COALESCE(MAX(id), 0) FROM audit_log')->fetchColumn();") or 0)
php("setSetting($db, 'users_enabled', '1'); setSetting($db, 'users_require_email_verify', '1');"
    "setSetting($db, 'profiles_enabled', '1'); setSetting($db, 'avatars_enabled', '1'); setSetting($db, 'covers_enabled', '1');"
    "setSetting($db, 'avatar_max_kb', '8192'); setSetting($db, 'avatar_max_mp', '24'); setSetting($db, 'avatar_default', 'generated');"
    "$db->exec(\"UPDATE user_groups SET permissions = JSON_MERGE_PATCH(permissions,"
    " '{\\\"profile.avatar\\\":true,\\\"profile.cover\\\":true,\\\"favourites.view_others\\\":true}') WHERE slug = 'member'\");"
    "foreach (['" + USER + "', '" + PAL + "'] as $nm) { $st = $db->prepare('SELECT id FROM users WHERE username = ?');"
    " $st->execute([$nm]); if ($id = (int)$st->fetchColumn()) userDeleteCascade($db, $id); }"
    "userCreate($db, $cfg, '" + USER + "', 'umpyalice@example.org', '" + PASS + "', '127.0.0.1');"
    "userCreate($db, $cfg, '" + PAL + "', 'umpybob@example.org', '" + PASS + "', '127.0.0.1');"
    "$db->exec(\"UPDATE users SET email_verified = 1 WHERE username IN ('" + USER + "', '" + PAL + "')\");")
uid = int(php("echo (int)$db->query(\"SELECT id FROM users WHERE username = '" + USER + "'\")->fetchColumn();") or 0)
pid = int(php("echo (int)$db->query(\"SELECT id FROM users WHERE username = '" + PAL + "'\")->fetchColumn();") or 0)
check("the fixtures and both accounts exist", uid > 0 and pid > 0 and PNG[:4] == b"\x89PNG" and JPG_GPS[:4] == b"\xFF\xD8\xFF\xE1", (uid, pid))


def col(c):
    return php("echo (string)$db->query('SELECT " + c + " FROM users WHERE id = " + str(uid) + "')->fetchColumn();")


def login(name):
    c = Client()
    c.page("login")
    s, j = c.api("user_login", "POST", {"csrf_token": c.csrf, "login": name, "password": PASS})
    return c, s == 200 and j.get("success")


try:
    # ── a visitor ────────────────────────────────────────────────────────────────────────────────
    guest = Client()
    guest.page("login")
    s, j = guest.api("user_avatar")
    check("a visitor asking for the editor is told to sign in", s == 401 and j.get("error") == "login_required", (s, j))
    s, j = guest.upload("user_avatar", {"csrf_token": guest.csrf or "x", "x": "50", "y": "50", "zoom": "1"}, ("a.png", "image/png", PNG))
    check("… and uploads nothing", s in (401, 403), (s, j))

    # ── a member ─────────────────────────────────────────────────────────────────────────────────
    me, ok = login(USER)
    check("the member signs in", ok)
    s, j = me.api("user_avatar")
    check("the editor state says they may, and quotes what this server will really take",
          s == 200 and j.get("success") and j.get("may") is True and j.get("max_bytes") == min(8192 * 1024, UP_MAX if UP_MAX > 0 else 1 << 40, POST_MAX - 16384 if POST_MAX > 0 else 1 << 40)
          and (j.get("avatar") or {}).get("has") is False, (s, j))
    s, j = me.upload("user_avatar", {"csrf_token": "nope", "x": "50", "y": "50", "zoom": "1"}, ("a.png", "image/png", PNG))
    check("a bad CSRF token is refused", s == 403, (s, j))
    s, j = me.upload("user_avatar", {"csrf_token": me.csrf, "x": "50", "y": "50", "zoom": "1"})
    check("a request with no file and no op is not an upload", s == 400 and j.get("error") == "unknown_op", (s, j))
    s, j = me.upload("user_avatar", {"csrf_token": me.csrf, "op": "upload", "x": "50", "y": "50", "zoom": "1"})
    check("an upload with no file says so", s == 400 and j.get("error") == "no_file", (s, j))
    s, j = me.upload("user_avatar", {"csrf_token": me.csrf, "x": "50", "y": "50", "zoom": "1"}, ("pretty.png", "image/png", SVG))
    check("an SVG sent as pretty.png, image/png is refused as the SVG it is",
          s == 415 and j.get("error") == "unsupported" and j.get("detail") == "svg" and "SVG" in (j.get("message") or ""), (s, j))
    s, j = me.upload("user_avatar", {"csrf_token": me.csrf, "x": "20", "y": "80", "zoom": "2"}, ("holiday.jpg", "image/jpeg", PNG))
    av = j.get("avatar") or {}
    check("a PNG declared as a JPEG goes in as what its bytes say, framed in the same request",
          s == 200 and j.get("success") and av.get("has") is True and av.get("x") == 20 and av.get("zoom") == 2
          and re.search(r"endpoint=user_media&h=[0-9a-f]{16}&s=128$", av.get("url128") or ""), (s, j))
    crop1 = col("avatar_sha")
    check("… and the account row holds the crop id the page was handed", len(crop1) == 40 and crop1[:16] in (av.get("url128") or ""), crop1)

    # ── the stream ──────────────────────────────────────────────────────────────────────────────
    s, h, body = guest.raw(av["url128"])
    etag = h.get("ETag") or h.get("Etag")
    check("the square streams to anybody, as WebP, 128 px",
          s == 200 and (h.get("Content-Type") or "") == "image/webp" and webp_size(body) == (128, 128), (s, h.get("Content-Type"), webp_size(body)))
    check("… cached for a year and immutable, with an ETag", bool(etag) and "max-age=31536000" in (h.get("Cache-Control") or "")
          and "immutable" in (h.get("Cache-Control") or "") and "public" in (h.get("Cache-Control") or ""), h.get("Cache-Control"))
    check("… nosniff, inline, and a policy that forbids everything",
          h.get("X-Content-Type-Options") == "nosniff" and (h.get("Content-Disposition") or "").startswith("inline")
          and "default-src 'none'" in (h.get("Content-Security-Policy") or ""), (h.get("Content-Disposition"), h.get("Content-Security-Policy")))
    check("… and neither the address nor the file name carries an account id or the uploader's file name",
          str(uid) not in re.sub(r"h=[0-9a-f]+", "", av["url128"]) and "holiday" not in (h.get("Content-Disposition") or "")
          and not re.search(r"[?&](id|uid|user|name)=", av["url128"]), (av["url128"], h.get("Content-Disposition")))
    s, h, body = guest.raw(av["url128"], {"If-None-Match": etag or '"x"'})
    check("a browser that already has it gets 304 and no bytes", s == 304 and body == b"", (s, len(body)))
    s, h, body = guest.raw(av["url256"])
    # 400×300 at zoom 2 is a 150 px window: there is no 256 square to cut without enlarging, so the
    # address for 256 is answered with the 128 — which is the whole of "never upscale".
    check("asking a 150 px window (zoom 2) for its 256 is answered with the 128: never enlarged",
          s == 200 and webp_size(body) == (128, 128), webp_size(body))
    s, h, body = guest.raw("api.php?endpoint=user_media&h=0123456789abcdef&s=64")
    check("an address that never existed is 404", s == 404, s)
    s, h, body = guest.raw("api.php?endpoint=user_media&h=" + crop1[:16] + "'--&s=64")
    check("… and so is one that is not a hash", s == 404, s)

    # ── the source: its owner's alone ───────────────────────────────────────────────────────────
    src = av.get("src") or ""
    s, h, body = me.raw(src)
    check("the uncropped source streams to its owner, private and not stored",
          s == 200 and webp_size(body) == (400, 300) and "no-store" in (h.get("Cache-Control") or "") and "private" in (h.get("Cache-Control") or ""),
          (s, h.get("Cache-Control"), webp_size(body)))
    s, h, body = guest.raw(src)
    check("… and is simply not there for a visitor", s == 404, s)
    pal, ok = login(PAL)
    s, h, body = pal.raw(src)
    check("… nor for another member", ok and s == 404, s)

    # ── reframing ───────────────────────────────────────────────────────────────────────────────
    s, j = me.upload("user_avatar", {"csrf_token": me.csrf, "op": "position", "x": "70", "y": "30", "zoom": "1.25"})
    check("reframing re-cuts the squares without an upload", s == 200 and (j.get("avatar") or {}).get("zoom") == 1.25, (s, j))
    crop2 = col("avatar_sha")
    s, h, body = guest.raw(av["url128"])
    check("… the old crop is gone from its old address (nothing left behind)", crop2 != crop1 and s == 404, (crop1[:8], crop2[:8], s))
    s, j = me.upload("user_avatar", {"csrf_token": me.csrf, "op": "position", "x": "left", "y": "30", "zoom": "1"})
    check("a focus that is not a number is refused with 422", s == 422 and j.get("error") == "bad_focus", (s, j))
    s, j = me.api("user_avatar", "POST", {"csrf_token": me.csrf, "op": "position", "x": 10, "y": 10, "zoom": "huge"})
    check("… a zoom likewise, and the JSON form of the request is understood too", s == 422 and j.get("error") == "bad_zoom", (s, j))
    s, j = me.api("user_avatar", "POST", {"csrf_token": me.csrf, "op": "position", "x": 999, "y": -5, "zoom": 9})
    check("numbers out of range are clamped, not refused (100 / 0 / 4)", s == 200 and (j.get("avatar") or {}).get("x") == 100
          and (j.get("avatar") or {}).get("y") == 0 and (j.get("avatar") or {}).get("zoom") == 4, (s, j))

    # ── the cover ───────────────────────────────────────────────────────────────────────────────
    s, j = me.upload("user_cover", {"csrf_token": me.csrf, "x": "30", "y": "60", "zoom": "1.5"}, ("beach.jpg", "image/jpeg", JPG_GPS))
    cv = j.get("cover") or {}
    check("a cover goes up with its framing", s == 200 and j.get("success") and cv.get("has") is True and cv.get("x") == 30, (s, j))
    s, h, body = guest.raw(cv.get("src") or "")
    check("… streams as WebP at its own size (1600×500 is under the 2400 bound)", s == 200 and webp_size(body) == (1600, 500), webp_size(body))
    check("… and carries none of the EXIF or GPS the JPEG arrived with", b"Exif" not in body and b"GPS" not in body)
    s, h, body = guest.raw(cv.get("thumb") or "")
    check("its thumb is 1000 px wide", s == 200 and webp_size(body) == (1000, 313), webp_size(body))
    s, j = me.upload("user_cover", {"csrf_token": me.csrf, "op": "position", "x": "10", "y": "90", "zoom": "0.75"})
    check("reframing a cover writes the numbers and nothing else", s == 200 and (j.get("cover") or {}).get("zoom") == 0.75
          and "--cv-z:0.75" in ((j.get("cover") or {}).get("css") or ""), (s, j))

    # ── the rate limit ──────────────────────────────────────────────────────────────────────────
    clear_throttles()
    me, ok = login(USER)
    codes = []
    for i in range(7):
        s, j = me.upload("user_avatar", {"csrf_token": me.csrf, "x": "50", "y": "50", "zoom": "1"}, ("a.png", "image/png", PNG2))
        codes.append(s)
    check("six uploads a minute, and the seventh is 429", codes[:6] == [200] * 6 and codes[6] == 429, codes)
    s, j = me.upload("user_avatar", {"csrf_token": me.csrf, "op": "position", "x": "40", "y": "40", "zoom": "1"})
    check("… while a reframe has an allowance of its own", s == 200, (s, j))
    clear_throttles()
    me, ok = login(USER)

    # ── too large, twice over ───────────────────────────────────────────────────────────────────
    if UP_MAX > 0 and (POST_MAX <= 0 or UP_MAX + 200000 < POST_MAX):
        big = b"\x89PNG\r\n\x1a\n" + os.urandom(UP_MAX + 100000)
        s, j = me.upload("user_avatar", {"csrf_token": me.csrf, "x": "50", "y": "50", "zoom": "1"}, ("big.png", "image/png", big))
        check("a file over PHP's upload limit is 413 and says it is too large", s == 413 and j.get("error") == "too_large", (s, j))
    if POST_MAX > 0 and POST_MAX < 64 * 1024 * 1024:
        huge = os.urandom(POST_MAX + 50000)
        s, j = me.upload("user_avatar", {"csrf_token": me.csrf, "x": "50", "y": "50", "zoom": "1"}, ("huge.png", "image/png", huge))
        # 413 on a server that keeps its warnings to itself; a local PHP with display_errors on has
        # already sent a 200 with its warning by the time this runs, so there the answer is the JSON.
        warned = "POST Content-Length" in (getattr(me, "last_text", "") or "")
        check("a body over post_max_size says 'too large', not 'bad token'",
              j.get("error") == "too_large" and (s == 413 or warned), (s, j, warned))

    # ── the permission and the switch ───────────────────────────────────────────────────────────
    php("$db->exec(\"UPDATE user_groups SET permissions = JSON_REMOVE(permissions, '$.\\\"profile.avatar\\\"') WHERE slug = 'member'\");")
    s, j = me.upload("user_avatar", {"csrf_token": me.csrf, "x": "50", "y": "50", "zoom": "1"}, ("a.png", "image/png", PNG))
    check("without profile.avatar a member cannot set a picture", s == 403 and j.get("error") == "no_permission", (s, j))
    s, j = me.upload("user_avatar", {"csrf_token": me.csrf, "op": "remove"})
    check("… but can always take their own down", s == 200 and j.get("success") and col("avatar_sha") == "", (s, j))
    php("$db->exec(\"UPDATE user_groups SET permissions = JSON_MERGE_PATCH(permissions, '{\\\"profile.avatar\\\":true}') WHERE slug = 'member'\");")
    s, j = me.upload("user_avatar", {"csrf_token": me.csrf, "x": "50", "y": "50", "zoom": "1"}, ("a.png", "image/png", PNG))
    av = j.get("avatar") or {}
    check("with it back, a picture again", s == 200 and av.get("has") is True, (s, j))
    php("setSetting($db, 'avatars_enabled', '0');")
    s, j = me.upload("user_avatar", {"csrf_token": me.csrf, "op": "position", "x": "50", "y": "50", "zoom": "1"})
    check("pictures switched off: the endpoint refuses", s == 403 and j.get("error") == "disabled", (s, j))
    s, h, body = guest.raw(av.get("url128") or "")
    check("… and the squares stop answering the public", s == 404, s)
    php("setSetting($db, 'avatars_enabled', '1');")

    # ── the generated letter ────────────────────────────────────────────────────────────────────
    s, h, body = guest.raw("api.php?endpoint=user_avatar_default&l=A&c=3")
    check("the generated picture: a small SVG with the letter in it",
          s == 200 and (h.get("Content-Type") or "").startswith("image/svg+xml") and b">A</text>" in body
          and b"<script" not in body, (s, h.get("Content-Type"), body[:80]))
    check("… immutable, nosniff, and a policy that forbids everything",
          "immutable" in (h.get("Cache-Control") or "") and h.get("X-Content-Type-Options") == "nosniff"
          and "default-src 'none'" in (h.get("Content-Security-Policy") or ""), (h.get("Cache-Control"), h.get("Content-Security-Policy")))
    bad = [guest.raw("api.php?endpoint=user_avatar_default&" + q)[0] for q in ("l=a&c=3", "l=A&c=12", "l=A&c=03", "l=AB&c=1", "l=%3C&c=1")]
    check("… and only the canonical form answers (one address per picture)", bad == [404] * 5, bad)

    # ── the panel ───────────────────────────────────────────────────────────────────────────────
    adm = Client()
    adm.page("admin")
    s, j = adm.api("admin/login", "POST", {"username": "admin", "password": "admin123", "csrf_token": adm.csrf}, csrf_header=True)
    check("the owner signs in to the panel", s == 200 and j.get("success"), (s, j))
    s, j = me.api("admin/user_media&id=" + str(uid))
    check("a member cannot reach the panel endpoint", s in (401, 403), s)
    s, j = adm.api("admin/user_media&id=" + str(uid))
    check("the panel sees whether an account has a picture and a cover", s == 200 and (j.get("user") or {}).get("has_avatar") is True
          and (j.get("user") or {}).get("has_cover") is True, (s, j))
    s, j = adm.api("admin/user_media", "POST", {"op": "remove_avatar", "id": uid})
    check("… a write without the CSRF header is refused", s == 403, (s, j))
    php("$db->prepare('DELETE FROM user_notifications WHERE user_id = ?')->execute([" + str(uid) + "]);")
    s, j = adm.api("admin/user_media", "POST", {"op": "remove_avatar", "id": uid}, csrf_header=True)
    check("the panel takes a member's picture down", s == 200 and j.get("success") and (j.get("user") or {}).get("has_avatar") is False
          and col("avatar_sha") == "", (s, j))
    note = php("echo (string)$db->query('SELECT title FROM user_notifications WHERE user_id = " + str(uid) + " ORDER BY id DESC LIMIT 1')->fetchColumn();")
    check("… and the member is told, rather than left to find out", "picture" in note.lower() or "zdj" in note.lower(), note)
    audit = php("echo (string)$db->query(\"SELECT CONCAT(action, '|', target_id, '|', ok) FROM audit_log WHERE action = 'user.media' ORDER BY id DESC LIMIT 1\")->fetchColumn();")
    check("… and it is in the audit log", audit == "user.media|" + str(uid) + "|1", audit)
    s, j = adm.api("admin/user_media", "POST", {"op": "remove_cover", "id": uid}, csrf_header=True)
    check("… and the cover likewise", s == 200 and col("cover_sha") == "" and
          php("echo (int)$db->query('SELECT COUNT(*) FROM user_media WHERE user_id = " + str(uid) + "')->fetchColumn();") == "0", (s, j))

    # The site's own images — only when this database has none of its own to lose.
    if site_rows == 0:
        s, j = adm.upload("admin/user_media", {"op": "default_upload", "kind": "avatar", "x": "50", "y": "50", "zoom": "1"},
                          ("site.png", "image/png", PNG2), csrf_header=True)
        site = (j.get("site") or {}).get("avatar") or {}
        check("the owner sets the site's default picture", s == 200 and site.get("has") is True and site.get("preview"), (s, j))
        s, h, body = guest.raw(site.get("preview") or "")
        check("… it streams like anybody's", s == 200 and webp_size(body) == (128, 128), (s, webp_size(body)))
        s, h, body = guest.raw(site.get("src") or "")
        check("… and its source is the panel's alone", s == 404 and adm.raw(site.get("src") or "")[0] == 200, s)
        s, j = adm.api("admin/user_media", "POST", {"op": "default_position", "kind": "avatar", "x": 20, "y": 20, "zoom": 2}, csrf_header=True)
        check("… reframes", s == 200 and ((j.get("site") or {}).get("avatar") or {}).get("zoom") == 2, (s, j))
        s, j = adm.upload("admin/user_media", {"op": "default_upload", "kind": "cover", "x": "40", "y": "40", "zoom": "1"},
                          ("site.jpg", "image/jpeg", JPG_GPS), csrf_header=True)
        check("… and sets a default cover", s == 200 and ((j.get("site") or {}).get("cover") or {}).get("has") is True, (s, j))
        for kind in ("avatar", "cover"):
            s, j = adm.api("admin/user_media", "POST", {"op": "default_remove", "kind": kind}, csrf_header=True)
            check("… and removes the default " + kind, s == 200 and ((j.get("site") or {}).get(kind) or {}).get("has") is False, (s, j))
        check("nothing of the site's is left behind",
              php("echo (int)$db->query('SELECT COUNT(*) FROM user_media WHERE user_id IS NULL')->fetchColumn();") == "0")
    else:
        print("SKIP the site default checks: this database already has default images of its own")

    # ── the settings round-trip ─────────────────────────────────────────────────────────────────
    s, j = adm.api("admin/save_settings", "POST", {
        "avatars_enabled": "yes", "covers_enabled": "0", "avatar_max_kb": "99999", "avatar_max_mp": "1",
        "cover_height": "5000", "cover_height_mobile": "-3", "cover_overlay": "rainbow", "avatar_default": "sometimes"}, csrf_header=True)
    keys = ["avatars_enabled", "covers_enabled", "avatar_max_kb", "avatar_max_mp", "cover_height", "cover_height_mobile", "cover_overlay", "avatar_default"]
    stored = dict(zip(keys, php("$c = getSettings($db, true); $o = []; foreach (['" + "','".join(keys) + "'] as $k) $o[] = (string)($c[$k] ?? '');"
                                " echo implode('|', $o);").split("|")))
    check("every field is saveable, and nonsense is clamped or coerced rather than refused",
          s == 200 and j.get("success") and stored == {"avatars_enabled": "0", "covers_enabled": "0", "avatar_max_kb": "20480",
                                                       "avatar_max_mp": "4", "cover_height": "600", "cover_height_mobile": "96",
                                                       "cover_overlay": "gradient", "avatar_default": "generated"}, (s, stored))
finally:
    php("foreach (['" + USER + "', '" + PAL + "'] as $nm) { $st = $db->prepare('SELECT id FROM users WHERE username = ?');"
        " $st->execute([$nm]); if ($id = (int)$st->fetchColumn()) userDeleteCascade($db, $id); }"
        + ("$db->exec('DELETE FROM user_media WHERE user_id IS NULL');" if site_rows == 0 else "")
        # The panel lines this run wrote about pictures, and no other: the log is somebody's record.
        + "$db->prepare(\"DELETE FROM audit_log WHERE action = 'user.media' AND id > ?\")->execute([" + str(audit_floor) + "]);"
        + "$db->prepare(\"UPDATE user_groups SET permissions = ? WHERE slug = 'member'\")->execute([" + phpstr(member_before) + "]);"
        + "".join(("$db->prepare('DELETE FROM settings WHERE `key` = ?')->execute(['%s']);" % k) if v == "absent"
                  else ("setSetting($db, '%s', %s);" % (k, phpstr(v[2:]))) for k, v in was.items()))
    clear_throttles()

print("\n%d checks, %d failed" % (n, fails))
sys.exit(1 if fails else 0)
