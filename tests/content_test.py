#!/usr/bin/env python3
"""
Descriptions over HTTP (api/index_info.php + api/content_submit.php):
    python tests/content_test.py         (needs the local server and a bootstrapped database)

What only HTTP can show: the content.view gate on the Info endpoint (words shown, or "there are
words" without them), what the endpoint says a reader may do, the submit door for a torrent the
tracker has only seen — in both tracker modes — and what a visitor without the permissions gets.
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
        "require_once $root . '/includes/richtext.php'; require_once $root . '/includes/content.php';"
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
    return p.stdout


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
        with self.opener.open(BASE + "?action=" + action, timeout=30) as r:
            html = r.read().decode("utf-8", "replace")
        m = re.search(r'name="csrf_token" value="([^"]+)"', html) or re.search(r'id="search-csrf" value="([^"]+)"', html)
        if m:
            self.csrf = m.group(1)
        return r.status, html

    def api(self, endpoint, method="GET", body=None):
        url = BASE + "api.php?endpoint=" + endpoint
        data = json.dumps(body).encode() if body is not None else None
        h = {"Accept": "application/json"}
        if data is not None:
            h["Content-Type"] = "application/json"
        req = urllib.request.Request(url, data=data, method=method, headers=h)
        try:
            with self.opener.open(req, timeout=30) as r:
                return r.status, json.loads(r.read().decode() or "{}")
        except urllib.error.HTTPError as e:
            try:
                return e.code, json.loads(e.read().decode() or "{}")
            except Exception:
                return e.code, {}


# The grants travel through json_encode() and $db->quote() on the PHP side: no quote survives three
# layers of escaping otherwise.
def grant(slug, perms):
    pairs = ", ".join("'%s' => %s" % (k, "true" if v else "false") for k, v in perms.items())
    return php("$db->exec(\"UPDATE user_groups SET permissions = JSON_MERGE_PATCH(permissions, \" . $db->quote(json_encode([" + pairs + "])) . \") WHERE slug = '" + slug + "'\");")


SEEN = "c0b1" * 10
SEEN2 = "c0b2" * 10
USER, PASS = "ctweb", "SmokePass123!"

clear_throttles()
was = {}
for k in ("users_enabled", "wl_allow_description", "wl_allow_source_url", "wl_content_review", "wl_content_autopublish", "wl_edit_max_pending", "tracker_mode", "index_enabled", "index_search_enabled"):
    was[k] = php("echo $cfg['" + k + "'] ?? '';").strip()
php("setSetting($db, 'users_enabled', '1'); setSetting($db, 'wl_allow_description', '1'); setSetting($db, 'wl_allow_source_url', '1');"
    "setSetting($db, 'wl_content_review', '1'); setSetting($db, 'wl_content_autopublish', '0'); setSetting($db, 'wl_edit_max_pending', '3');"
    "setSetting($db, 'index_enabled', '1'); setSetting($db, 'index_search_enabled', '1');"
    "$db->prepare('DELETE FROM users WHERE username = ?')->execute(['" + USER + "']);"
    "userCreate($db, $cfg, '" + USER + "', 'ctweb@example.org', '" + PASS + "', '127.0.0.1');"
    "$db->exec(\"UPDATE users SET email_verified = 1 WHERE username = '" + USER + "'\");"
    "foreach (['whitelist','index_hashes','hash_content','wl_content_edits'] as $t) $db->prepare(\"DELETE FROM `$t` WHERE info_hash IN (?, ?)\")->execute(['" + SEEN + "', '" + SEEN2 + "']);"
    "$db->prepare(\"INSERT INTO index_hashes (info_hash, name, meta_status) VALUES (?, 'Seen over HTTP', 'done'), (?, 'Seen in blacklist mode', 'done')\")->execute(['" + SEEN + "', '" + SEEN2 + "']);")
guest_before = php("$st = $db->query(\"SELECT permissions FROM user_groups WHERE slug = 'guest'\"); echo $st->fetchColumn();").strip()
grant("member", {"content.submit": True, "content.propose": True, "content.view": True, "index.view": True})
grant("guest", {"content.submit": False, "content.propose": False, "content.view": False, "index.view": True})

try:
    # ── the reader who may write ──────────────────────────────────────────
    me = Client()
    me.page("login")
    s, j = me.api("user_login", "POST", {"csrf_token": me.csrf, "login": USER, "password": PASS})
    check("the member signs in", s == 200 and j.get("success"), (s, j))
    me.page("search")
    check("the search page hands out a token the panel can post with", bool(me.csrf))
    s, info = me.api("index_info&hash=" + SEEN)
    check("the Info endpoint offers Add for an index-only hash with nothing on it",
          s == 200 and info.get("can_content_submit") is True and info.get("can_content_propose") is False
          and info.get("content_hidden") is False and info.get("description_html") == "", (s, info))
    check("… and says which formats and how long", info.get("content_formats") and int(info.get("content_max") or 0) > 0, info.get("content_formats"))

    s, r = me.api("content_submit", "POST", {"csrf_token": me.csrf, "hash": "magnet:?xt=urn:btih:" + SEEN.upper() + "&dn=x",
                                              "description": "Written [i]from the panel[/i].", "description_format": "bbcode", "source_url": ""})
    check("a description sent from the panel is queued", s == 200 and r.get("success") and r.get("pending") is True and r.get("kind") == "idx", (s, r))
    s, info = me.api("index_info&hash=" + SEEN)
    check("… the author sees that their words are waiting, and gets Propose now, not Add",
          info.get("content_mine") == "pending" and info.get("can_content_submit") is False and info.get("can_content_propose") is True, info)
    check("… nothing is public yet", info.get("description_html") == "" and info.get("content_author") is None, info)

    php("$rec = contentRecordFor($db, '" + SEEN + "'); contentApprove($db, $cfg, 'idx', (int)$rec['id']);")
    s, info = me.api("index_info&hash=" + SEEN)
    check("published: the words and the author are on the panel", "from the panel" in (info.get("description_html") or "")
          and info.get("content_author") == USER and info.get("content_mine") is None, info)
    s, r = me.api("content_submit", "POST", {"csrf_token": me.csrf, "hash": SEEN, "description": "A rewrite.", "description_format": "bbcode", "source_url": ""})
    check("a second submission on it is a proposal", s == 200 and r.get("proposed") is True, (s, r))
    edits = php("echo $db->query(\"SELECT COUNT(*) FROM wl_content_edits WHERE info_hash = '" + SEEN + "' AND status = 'pending' AND hash_content_id IS NOT NULL\")->fetchColumn();").strip()
    check("… recorded against the hash_content row", edits == "1", edits)

    # ── the reader who may not read ───────────────────────────────────────
    grant("member", {"content.view": False})
    s, info = me.api("index_info&hash=" + SEEN)
    check("without content.view the words are withheld and the reader is told there are some",
          s == 200 and info.get("description_html") == "" and info.get("content_author") is None and info.get("content_hidden") is True, info)
    grant("member", {"content.view": True})

    # ── the visitor ───────────────────────────────────────────────────────
    guest = Client()
    guest.page("search")
    s, info = guest.api("index_info&hash=" + SEEN)
    check("a visitor without content.view sees the same withholding", s == 200 and info.get("content_hidden") is True and info.get("can_content_submit") is False, (s, info))
    s, r = guest.api("content_submit", "POST", {"csrf_token": guest.csrf, "hash": SEEN2, "description": "guest words", "description_format": "bbcode", "source_url": ""})
    check("a visitor without content.submit cannot write", s == 403, (s, r))

    # ── the door is the same in blacklist mode ────────────────────────────
    php("setSetting($db, 'tracker_mode', 'blacklist');")
    s, r = me.api("content_submit", "POST", {"csrf_token": me.csrf, "hash": SEEN2, "description": "Blacklist-mode words.", "description_format": "bbcode", "source_url": ""})
    check("in blacklist mode a seen torrent takes a description the same way", s == 200 and r.get("success") and r.get("kind") == "idx", (s, r))
    wl = php("echo $db->query(\"SELECT COUNT(*) FROM whitelist WHERE info_hash = '" + SEEN2 + "'\")->fetchColumn();").strip()
    check("… and no whitelist row was created for it", wl == "0", wl)
    php("setSetting($db, 'tracker_mode', '" + (was["tracker_mode"] or "whitelist") + "');")

    # ── what a bad request gets ───────────────────────────────────────────
    s, r = me.api("content_submit", "POST", {"csrf_token": me.csrf, "hash": "c0b3" * 10, "description": "unknown", "description_format": "bbcode", "source_url": ""})
    check("a hash the tracker never met is a 404", s == 404 and r.get("error"), (s, r))
    s, r = me.api("content_submit", "POST", {"csrf_token": me.csrf, "hash": SEEN, "description": "", "description_format": "bbcode", "source_url": ""})
    check("nothing to attach is a 400", s == 400 and r.get("error"), (s, r))
    s, r = me.api("content_submit", "POST", {"csrf_token": "nope", "hash": SEEN, "description": "x", "description_format": "bbcode", "source_url": ""})
    check("a bad token is a 403", s == 403, (s, r))
finally:
    php("foreach (['whitelist','index_hashes','hash_content','wl_content_edits'] as $t) $db->prepare(\"DELETE FROM `$t` WHERE info_hash IN (?, ?)\")->execute(['" + SEEN + "', '" + SEEN2 + "']);"
        "$db->prepare('DELETE FROM user_notifications WHERE user_id IN (SELECT id FROM users WHERE username = ?)')->execute(['" + USER + "']);"
        "$db->prepare('DELETE FROM users WHERE username = ?')->execute(['" + USER + "']);"
        "$db->prepare(\"UPDATE user_groups SET permissions = ? WHERE slug = 'guest'\")->execute([" + json.dumps(guest_before) + "]);"
        + "".join("setSetting($db, '%s', '%s');" % (k, v) for k, v in was.items() if v != ""))
    clear_throttles()

print("\n%d checks, %d failed" % (n, fails))
sys.exit(1 if fails else 0)
