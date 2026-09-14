#!/usr/bin/env python3
"""
The list/favourites audit fixes, over HTTP:
    python tests/audit_lists_test.py     (needs the local server and a bootstrapped database)

The PHP half (tests/audit_lists_test.php) runs the endpoints in a child process and can read what
they return; what it cannot see is the layer this file is about — the status code, and the rate
limiter, which is a file on disk shared by every request from one address.

Here: a `hide_profile` block answers 404 to that one reader and 200 to everybody else; a create that
loses the unique key answers a clean 409 rather than a 500; the cap answers 409 too; the file-name
search inside somebody's own list is charged to the search page's hourly bucket; and an account that
holds a permission only through the administrator's blanket is told it may not publish.
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
        "require_once $root . '/includes/favourites.php'; require_once $root . '/includes/lists.php';"
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

    def login(self, user, password):
        self.page("login")
        return self.api("user_login", "POST", {"csrf_token": self.csrf, "login": user, "password": password})


PASSWORD = "SmokePass123!"
OWNER, PEER, BLOCK, ADMIN = "audithttpown", "audithttppeer", "audithttpblk", "audithttpadm"
NAMES = (OWNER, PEER, BLOCK, ADMIN)
HASH = "c3" * 20
KEYS = ("users_enabled", "profiles_enabled", "index_enabled", "fav_enabled", "fav_public_enabled",
        "lists_enabled", "lists_public_enabled", "lists_max_per_user", "rate_limit_index_search",
        "index_search_include_whitelist")

clear_throttles()
was = {k: php("echo $cfg['" + k + "'] ?? '';") for k in KEYS}
was_perms = php("echo (string)$db->query(\"SELECT permissions FROM user_groups WHERE slug = 'member'\")->fetchColumn();")

php("setSettings($db, ['users_enabled' => '1', 'profiles_enabled' => '1', 'index_enabled' => '1',"
    "  'fav_enabled' => '1', 'fav_public_enabled' => '1', 'lists_enabled' => '1',"
    "  'lists_public_enabled' => '1', 'lists_max_per_user' => '20', 'rate_limit_index_search' => '120',"
    "  'index_search_include_whitelist' => '1']);"
    "$db->exec(\"UPDATE user_groups SET permissions = JSON_MERGE_PATCH(permissions,"
    "  '{\\\"lists.use\\\":true,\\\"lists.public\\\":true,\\\"favourites.use\\\":true,\\\"favourites.public\\\":true,"
    "    \\\"favourites.view_others\\\":true,\\\"index.view\\\":true,\\\"index.files\\\":true,"
    "    \\\"index.magnet\\\":true,\\\"whitelist.view\\\":true}') WHERE slug = 'member'\");")

# The fixtures: four accounts, one public list with one row in it, and a block that hides the
# owner's profile from exactly one of them.
setup = "$cfg = getSettings($db, true);"
for u in NAMES:
    setup += ("$old = $db->prepare('SELECT id FROM users WHERE username = ?'); $old->execute(['" + u + "']);"
              "foreach ($old->fetchAll(PDO::FETCH_COLUMN) as $oid) { userDeleteCascade($db, (int)$oid); }"
              "userCreate($db, $cfg, '" + u + "', '" + u + "@example.org', '" + PASSWORD + "', '127.0.0.1');")
setup += ("$db->exec(\"UPDATE users SET email_verified = 1, fav_public = 1, fav_listed = 1, lists_public = 1"
          "  WHERE username IN ('" + "','".join(NAMES) + "')\");"
          "$id = function (string $u) use ($db): int { $s = $db->prepare('SELECT id FROM users WHERE username = ?');"
          "  $s->execute([$u]); return (int)$s->fetchColumn(); };"
          "$db->prepare(\"INSERT INTO whitelist (info_hash, name, source, created_at, banned)"
          "  VALUES (?, 'Audit http fixture', 'web', NOW(), 0)"
          "  ON DUPLICATE KEY UPDATE name = VALUES(name), banned = 0\")->execute(['" + HASH + "']);"
          "$db->prepare(\"INSERT INTO user_lists (user_id, name, slug, description, is_public)"
          "  VALUES (?, 'Http pack', 'http-pack', '', 1)\")->execute([$id('" + OWNER + "')]);"
          "$lid = (int)$db->lastInsertId();"
          "$db->prepare('INSERT INTO user_list_items (list_id, info_hash, name) VALUES (?, ?, ?)')"
          "  ->execute([$lid, '" + HASH + "', 'Audit http fixture']);"
          "$db->prepare('INSERT IGNORE INTO user_favourites (user_id, info_hash) VALUES (?, ?)')"
          "  ->execute([$id('" + OWNER + "'), '" + HASH + "']);"
          "$db->prepare('INSERT INTO user_blocks (user_id, blocked_id, hide_profile) VALUES (?, ?, 1)"
          "  ON DUPLICATE KEY UPDATE hide_profile = 1')->execute([$id('" + OWNER + "'), $id('" + BLOCK + "')]);"
          # The blanket account: in the system admin group and in no group that GRANTS anything.
          "$db->prepare('DELETE FROM user_group_members WHERE user_id = ?')->execute([$id('" + ADMIN + "')]);"
          "$g = userGroupBySlug($db, 'admin');"
          "userGrantGroup($db, $id('" + ADMIN + "'), (int)$g['id'], null, 'audit test', '', false);"
          "echo $lid;")
LIST_ID = php(setup)
check("the fixtures are in place", LIST_ID.isdigit() and int(LIST_ID) > 0, LIST_ID)

try:
    owner, peer, blocked, blanket = Client(), Client(), Client(), Client()
    for cl, u in ((owner, OWNER), (peer, PEER), (blocked, BLOCK), (blanket, ADMIN)):
        s, j = cl.login(u, PASSWORD)
        check("%s signs in" % u, s == 200 and j.get("success"), (s, j))

    # ── the block, from the outside ────────────────────────────────────────────
    for ep, qs in (("user_favourites", "&user=" + OWNER), ("user_lists", "&user=" + OWNER),
                   ("user_list_items", "&list=" + LIST_ID)):
        s, j = blocked.api(ep + qs)
        check("%s is 404 for the reader the profile is hidden from" % ep,
              s == 404 and j.get("error") == "not_found", (s, j))
        s, j = peer.api(ep + qs)
        check("… and 200 for everybody else", s == 200 and j.get("success"), (s, j))

    # ── the blanket is not consent ─────────────────────────────────────────────
    s, j = blanket.api("user_lists")
    check("an account with the administrator's blanket is not offered publishing",
          s == 200 and j.get("may_publish") is False, (s, j))
    s, j = blanket.api("user_privacy")
    check("… and the privacy page agrees about both features",
          s == 200 and j.get("may_publish") is False and j.get("lists_may_publish") is False, (s, j))
    s, j = blanket.api("user_lists", "POST", {"csrf_token": blanket.csrf, "op": "create", "name": "Blanket pack"})
    check("… it may still make a list", s == 200 and j.get("success"), (s, j))
    blanket_list = j.get("id")
    s, j = blanket.api("user_lists", "POST", {"csrf_token": blanket.csrf, "op": "visibility",
                                              "id": blanket_list, "value": 1})
    check("… and publishing it is refused with 403", s == 403 and j.get("error") == "no_permission", (s, j))

    # ── a create that loses the unique key is a 409, not a 500 ─────────────────
    # Losing the race for a slug is rare and cannot be scheduled from out here, so the collision is
    # arranged: a trigger that raises the same SQLSTATE the unique key raises. What is under test is
    # the answer, not the trigger.
    made = php("try { $db->exec(\"CREATE TRIGGER tmp_audit_dup BEFORE INSERT ON user_lists FOR EACH ROW"
               "  BEGIN IF NEW.name = 'Dup pack' THEN SIGNAL SQLSTATE '23000'"
               "  SET MESSAGE_TEXT = 'duplicate name'; END IF; END\"); echo 'ok'; }"
               "catch (Throwable $e) { echo 'no: ' . $e->getMessage(); }")
    if made == "ok":
        s, j = owner.api("user_lists", "POST", {"csrf_token": owner.csrf, "op": "create", "name": "Dup pack"})
        check("a duplicate name answers 409 and says which problem it was",
              s == 409 and j.get("error") == "duplicate_name", (s, j))
        php("$db->exec('DROP TRIGGER IF EXISTS tmp_audit_dup');")
    else:
        check("the duplicate-name collision could be arranged", False, made)

    # ── the cap is still a cap, and answers the same way ───────────────────────
    php("setSetting($db, 'lists_max_per_user', '1');")
    s, j = owner.api("user_lists", "POST", {"csrf_token": owner.csrf, "op": "create", "name": "One too many"})
    check("past the cap the answer is 409 with the limit on it",
          s == 409 and j.get("error") == "too_many_lists" and j.get("limit") == 1, (s, j))
    php("setSetting($db, 'lists_max_per_user', '20');")

    # ── the file search is charged to the search page's own bucket ─────────────
    php("setSetting($db, 'rate_limit_index_search', '2');")
    clear_throttles()
    codes = []
    for _ in range(3):
        s, j = owner.api("user_favourites&search=au&files=1")
        codes.append(s)
    check("the third file search in the window is refused, not served", codes == [200, 200, 429], codes)
    clear_throttles()
    s, j = owner.api("user_favourites&search=au&files=0")
    check("… and the same search without the file arm is not charged at all", s == 200 and j.get("success"), (s, j))
    php("setSetting($db, 'rate_limit_index_search', '120');")
    clear_throttles()
finally:
    php("$db->exec('DROP TRIGGER IF EXISTS tmp_audit_dup');"
        "foreach (['" + "','".join(NAMES) + "'] as $u) {"
        "  $s = $db->prepare('SELECT id FROM users WHERE username = ?'); $s->execute([$u]);"
        "  foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $oid) { userDeleteCascade($db, (int)$oid); }"
        "  $db->prepare('DELETE FROM users WHERE username = ?')->execute([$u]); }"
        "$db->prepare('DELETE FROM whitelist WHERE info_hash = ?')->execute(['" + HASH + "']);"
        + ("$db->prepare(\"UPDATE user_groups SET permissions = ? WHERE slug = 'member'\")->execute(["
           + json.dumps(was_perms) + "]);" if was_perms else "")
        + "".join("setSetting($db, '%s', '%s');" % (k, v) for k, v in was.items() if v != ""))
    clear_throttles()

print("\n%d checks, %d failed" % (n, fails))
sys.exit(1 if fails else 0)
