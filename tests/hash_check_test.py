#!/usr/bin/env python3
"""
The status page's hash check over HTTP (api/hash_check.php):
    python tests/hash_check_test.py         (needs the local server: php -S 127.0.0.1:8089, and the smoke fixtures)

What only HTTP can show: the gate (a visitor without status.hash_check is refused, a member is not),
the rate limit (a setting, per address, 429 past it), one hash spelled three ways giving one answer,
and what a bad input gets. The lookup itself is tests/hash_check_test.php.
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
    p = subprocess.run(["php", "-d", "error_reporting=E_ALL & ~E_DEPRECATED", "-r", BOOT + code],
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
        m = re.search(r'name="csrf_token" value="([^"]+)"', html)
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


HASH = "1c1e" * 10
SEEN = "1c2d" * 10          # the index's own hash, with words on it: the two gated sections
B32 = base64.b32encode(bytes.fromhex(HASH)).decode()
MAGNET = "magnet:?xt=urn:btih:" + HASH.upper() + "&dn=Hash+check+fixture&tr=udp%3A%2F%2Ftracker.example.org%3A6969%2Fannounce"

# Its own member account. The stateful PHP suites truncate `users` on their way out, and this runs
# after them in the battery, so the smoke fixtures' smokeuser is not something this test may rely on.
USER, PASS = "hcuser", "SmokePass123!"
clear_throttles()
was_users = php("echo $cfg['users_enabled'] ?? '0';").strip()
php("$db->prepare('DELETE FROM users WHERE username = ?')->execute(['" + USER + "']);"
    "userCreate($db, $cfg, '" + USER + "', 'hc@example.org', '" + PASS + "', '127.0.0.1');"
    "$db->exec(\"UPDATE users SET email_verified = 1 WHERE username = '" + USER + "'\");")
# The grant is put on the member group HERE, not assumed from the migration: the stateful PHP
# suites rewrite the group's permissions before this runs (tests/users_test.php), and the migration's
# one-time marker is already in place, so what the migration did is tests/hash_check_test.php's
# business. This test is about the gate: with the grant, an answer; without it, a refusal.
GRANT = ("$db->exec(" + chr(34) + "UPDATE user_groups SET permissions = JSON_MERGE_PATCH(permissions, "
         + chr(34) + " . $db->quote(json_encode(['status.hash_check' => %s])) . " + chr(34)
         + ") WHERE slug = 'member'" + chr(34) + ");")
php(GRANT % "true")


# The sections of the answer are gated by the permissions that publish the same facts on the Info
# panel (includes/hashcheck.php: hashCheckGates), so each of those is put on the group and taken off
# it here, one at a time. Over HTTP, because userEffectivePermissions() memoises per process and a
# revocation inside one would not be seen.
def grant(perms, slug="member"):
    pairs = ", ".join("'%s' => %s" % (k, "true" if v else "false") for k, v in perms.items())
    php("$db->exec(\"UPDATE user_groups SET permissions = JSON_MERGE_PATCH(permissions, \" . $db->quote(json_encode([" + pairs + "])) . \") WHERE slug = '" + slug + "'\");")


was_limit = php("echo $cfg['rate_limit_hash_check'] ?? '120';").strip()
was_index = {k: php("echo $cfg['" + k + "'] ?? '';").strip()
             for k in ("index_enabled", "index_search_enabled", "index_search_include_whitelist")}
member_before = php("echo $db->query(\"SELECT permissions FROM user_groups WHERE slug = 'member'\")->fetchColumn();").strip()
php("setSetting($db, 'users_enabled', '1'); setSetting($db, 'rate_limit_hash_check', '120');"
    "setSetting($db, 'index_enabled', '1'); setSetting($db, 'index_search_enabled', '1');"
    "setSetting($db, 'index_search_include_whitelist', '1');"
    "$db->prepare('DELETE FROM whitelist WHERE info_hash = ?')->execute(['" + HASH + "']);"
    "$db->prepare(\"INSERT INTO whitelist (info_hash, name, source, created_at, banned, probe_status) VALUES (?, 'Hash check fixture', 'admin', '2026-09-02 09:00:00', 0, 'passed')\")->execute(['" + HASH + "']);"
    "foreach (['index_hashes', 'hash_content'] as $t) $db->prepare(\"DELETE FROM `$t` WHERE info_hash = ?\")->execute(['" + SEEN + "']);"
    "$db->prepare(\"INSERT INTO index_hashes (info_hash, name, first_seen, last_seen, seen_count, last_seeders, last_leechers, meta_status, files_count)"
    " VALUES (?, 'Seen over HTTP', '2026-08-01 08:00:00', '2026-09-10 20:30:00', 7, 11, 2, 'done', 4)\")->execute(['" + SEEN + "']);"
    "$db->prepare(\"INSERT INTO hash_content (info_hash, description, description_format, content_status)"
    " VALUES (?, 'Words about it.', 'bbcode', 'approved')\")->execute(['" + SEEN + "']);")
grant({"index.view": True, "whitelist.view": True, "content.view": True})

try:
    # ── the gate ──────────────────────────────────────────────────────────
    guest = Client()
    guest.page("status")
    s, j = guest.api("hash_check&hash=" + HASH)
    check("a visitor without the permission is refused", s == 403 and "error" in j, (s, j))
    s, html = guest.page("status")
    check("… and the form is not on the page for them", 'id="hc-form"' not in html)

    member = Client()
    member.page("login")
    s, j = member.api("user_login", "POST", {"csrf_token": member.csrf, "login": USER, "password": PASS})
    check("the member signs in", s == 200 and j.get("success"), (s, j))
    s, j = member.api("user_me")
    check("the member carries status.hash_check", "status.hash_check" in (j.get("permissions") or []), (s, j))
    s, html = member.page("status")
    check("the form is on the page for them", 'id="hc-form"' in html and 'id="hc-result"' in html)

    # ── the answer, and one hash spelled three ways ───────────────────────
    s, a = member.api("hash_check&hash=" + HASH)
    check("a registered hash answers 200 with its state", s == 200 and a.get("success") and (a.get("registered") or {}).get("state") == "live", (s, a))
    check("… known, not banned, never seen", a.get("known") is True and a.get("banned") is None and a.get("seen") is None, a)
    s, b = member.api("hash_check&hash=" + B32)
    s2, c = member.api("hash_check&hash=" + urllib.request.quote(MAGNET, safe=""))
    check("the base32 spelling and the magnet give the same hash", b.get("hash") == HASH and c.get("hash") == HASH, (b.get("hash"), c.get("hash")))
    check("… and the same answer", b.get("registered") == a.get("registered") and c.get("registered") == a.get("registered"))
    s, u = member.api("hash_check&hash=" + "1c1f" * 10)
    check("a hash nobody has met is answered too, as unknown", s == 200 and u.get("known") is False and u.get("registered") is None, (s, u))

    # ── one permission buys the question, not every answer ────────────────
    s, full = member.api("hash_check&hash=" + SEEN)
    check("with all three catalogue permissions the answer is whole",
          s == 200 and (full.get("seen") or {}).get("times") == 7 and (full.get("content") or {}).get("status") == "approved"
          and full.get("meta", {}).get("name") == "Seen over HTTP" and full.get("withheld") == [], (s, full))

    grant({"index.view": False})
    s, j = member.api("hash_check&hash=" + SEEN)
    check("without index.view there is no swarm section", s == 200 and j.get("seen") is None and "seen" in (j.get("withheld") or []), (s, j))
    check("… and no name, metadata or file counts out of the index row either",
          j.get("meta", {}).get("name") is None and j.get("meta", {}).get("status") == "none"
          and j.get("files", {}).get("fetched") == 0 and j.get("files", {}).get("total") is None, j)
    check("… but the hash is still known, and a ban would still be answered", j.get("known") is True and j.get("banned") is None, j)
    grant({"index.view": True})

    grant({"whitelist.view": False})
    s, j = member.api("hash_check&hash=" + HASH)
    check("without whitelist.view there is no registered section", s == 200 and j.get("registered") is None
          and "registered" in (j.get("withheld") or []) and j.get("known") is True, (s, j))
    grant({"whitelist.view": True})

    grant({"content.view": False})
    s, j = member.api("hash_check&hash=" + SEEN)
    check("without content.view there is no content section, and the rest is untouched",
          s == 200 and j.get("content") is None and "content" in (j.get("withheld") or [])
          and (j.get("seen") or {}).get("times") == 7, (s, j))
    grant({"content.view": True})

    php("setSetting($db, 'index_search_enabled', '0');")
    s, j = member.api("hash_check&hash=" + SEEN)
    check("the search switched off closes the swarm section for everybody", s == 200 and j.get("seen") is None, (s, j))
    php("setSetting($db, 'index_search_enabled', '1');")
    php("setSetting($db, 'index_search_include_whitelist', '0');")
    s, j = member.api("hash_check&hash=" + HASH)
    check("a search configured without whitelist rows closes the registered section", s == 200 and j.get("registered") is None, (s, j))
    php("setSetting($db, 'index_search_include_whitelist', '1');")

    # ── what a bad input gets ─────────────────────────────────────────────
    s, e = member.api("hash_check&hash=")
    check("an empty input is a 400 with a sentence", s == 400 and e.get("error"), (s, e))
    s, e = member.api("hash_check&hash=not-a-hash")
    check("garbage is a 400 with a sentence", s == 400 and e.get("error"), (s, e))
    s, e = member.api("hash_check&hash=" + urllib.request.quote("magnet:?dn=no-hash-here", safe=""))
    check("a magnet without a btih is a 400", s == 400 and e.get("error"), (s, e))

    # ── the gate is the grant, nothing else ───────────────────────────────
    php(GRANT % "false")
    s, e = member.api("hash_check&hash=" + HASH)
    check("with the grant taken away the same member is refused", s == 403, (s, e))
    s, html = member.page("status")
    check("… and the form leaves their page too", 'id="hc-form"' not in html)
    php(GRANT % "true")

    # ── the limit ─────────────────────────────────────────────────────────
    php("setSetting($db, 'rate_limit_hash_check', '3');")
    clear_throttles()
    codes = [member.api("hash_check&hash=" + HASH)[0] for _ in range(4)]
    check("three checks pass and the fourth is 429 with the limit at 3", codes == [200, 200, 200, 429], codes)
    php("setSetting($db, 'rate_limit_hash_check', '0');")
    clear_throttles()
    codes = [member.api("hash_check&hash=" + HASH)[0] for _ in range(5)]
    check("0 means no limit", codes == [200] * 5, codes)
finally:
    php("setSetting($db, 'rate_limit_hash_check', '" + (was_limit or "120") + "'); setSetting($db, 'users_enabled', '" + (was_users or "1") + "');"
        + "".join("setSetting($db, '%s', '%s');" % (k, v) for k, v in was_index.items() if v != "")
        + "$db->prepare('DELETE FROM whitelist WHERE info_hash = ?')->execute(['" + HASH + "']);"
        "foreach (['index_hashes', 'hash_content'] as $t) $db->prepare(\"DELETE FROM `$t` WHERE info_hash = ?\")->execute(['" + SEEN + "']);"
        "$db->prepare('DELETE FROM users WHERE username = ?')->execute(['" + USER + "']);"
        "$db->prepare(\"UPDATE user_groups SET permissions = ? WHERE slug = 'member'\")->execute([" + json.dumps(member_before) + "]);")
    clear_throttles()

print("\n%d checks, %d failed" % (n, fails))
sys.exit(1 if fails else 0)
