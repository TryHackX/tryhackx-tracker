#!/usr/bin/env python3
"""
Selling a group over HTTP — v1/users/grant, v1/users/revoke and the `shop` scope (1.65.0):
    python tests/shop_api_test.py       (needs the local server: php -S 127.0.0.1:8089)

What only HTTP can show, and what this file exists for: a shop's webhook FIRES TWICE. The same
order id sent twice must sell one month, not two, and the second call must come back with the same
dates as the first so the customer is told one thing rather than two. That property lives in the
endpoint — in the order of a computation, a test-and-set and a reply — so it cannot be proved by
calling a function. tests/groups_matrix_test.php proves the pieces it is built from.

Also here: the three ways of naming a buyer, the refusal to cut short a permanent membership, the
refund that takes back one order and leaves a later one alone, `effective: false` with its reason,
and the narrow scope actually refusing the two endpoints it exists to withhold.

Self-cleaning: the key, the accounts, the memberships and the order rows are all removed at the end,
and every setting it changes is put back to what it found.
"""
import json
import os
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
        "require_once $root . '/includes/api_auth.php';"
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
    """One PHP statement run against the live test database. argv, never a shell string."""
    p = subprocess.run(["php", "-d", "error_reporting=E_ALL & ~E_DEPRECATED", "-r", BOOT + code],
                       capture_output=True, text=True, cwd=ROOT, encoding="utf-8", errors="replace")
    if p.returncode != 0:
        print("php failed: " + (p.stderr or p.stdout)[:400])
    return (p.stdout or "").strip()


def call(endpoint, body, bearer):
    """POST a JSON body with a bearer key. Returns (status, parsed body)."""
    req = urllib.request.Request(BASE + "api.php?endpoint=" + endpoint,
                                 data=json.dumps(body).encode("utf-8"), method="POST")
    req.add_header("Content-Type", "application/json")
    req.add_header("Authorization", "Bearer " + bearer)
    try:
        with urllib.request.urlopen(req, timeout=30) as r:
            return r.status, json.loads(r.read().decode("utf-8", "replace"))
    except urllib.error.HTTPError as e:
        raw = e.read().decode("utf-8", "replace")
        try:
            return e.code, json.loads(raw)
        except ValueError:
            return e.code, {"raw": raw[:300]}
    except urllib.error.URLError as e:
        print("the local server is not answering at " + BASE + " (" + str(e.reason) + ")")
        sys.exit(2)


# ── the switches this run leans on, stated and remembered ────────────────────
before = php("$k=['api_enabled','users_enabled','users_require_email_verify'];"
             "$o=[]; foreach($k as $x){$o[$x]=$cfg[$x] ?? null;} echo json_encode($o);")
try:
    saved = json.loads(before)
except ValueError:
    print("could not read the settings: " + before)
    sys.exit(2)
php("foreach(['api_enabled'=>'1','users_enabled'=>'1','users_require_email_verify'=>'1'] as $k=>$v)"
    "{$db->prepare('INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)')->execute([$k,$v]);}")

# The key, its secret kept only here, and two accounts to sell to.
setup = php(
    "$db->prepare(\"DELETE FROM api_clients WHERE label = 'shop-api-test'\")->execute();"
    "$c = apiClientCreate($db, 'shop-api-test', 'shop');"
    "$u = apiClientCreate($db, 'shop-api-test-users', 'users');"
    "$db->prepare(\"UPDATE api_clients SET label='shop-api-test' WHERE id=?\")->execute([$u['id']]);"
    "foreach(['shoptest_buyer','shoptest_unver'] as $nm){"
    "  $st=$db->prepare('SELECT id FROM users WHERE username = ?'); $st->execute([$nm]);"
    "  if($id=(int)$st->fetchColumn()) userDeleteCascade($db,$id);"
    "  $r = userCreate($db,$cfg,$nm,$nm.'@example.org','Password123!','127.0.0.1');"
    "  $ids[$nm]=(int)($r['user']['id'] ?? 0);"
    "}"
    "$db->prepare('UPDATE users SET email_verified = 1 WHERE id = ?')->execute([$ids['shoptest_buyer']]);"
    "$db->prepare('INSERT INTO user_identities (user_id, client_id, provider, external_id) VALUES (?,?,?,?)')"
    "   ->execute([$ids['shoptest_buyer'], $c['id'], 'shop-api-test', 'EXT-991']);"
    "echo json_encode(['shop'=>$c['key_id'].'.'.$c['secret'],'users'=>$u['key_id'].'.'.$u['secret'],"
    "                  'shop_id'=>$c['id'],'buyer'=>$ids['shoptest_buyer'],'unver'=>$ids['shoptest_unver']]);")
try:
    env = json.loads(setup)
except ValueError:
    print("could not set the test up: " + setup)
    sys.exit(2)
SHOP, USERS = env["shop"], env["users"]


def cleanup():
    php("$db->prepare(\"DELETE o FROM user_group_orders o JOIN api_clients c ON c.id=o.client_id"
        " WHERE c.label LIKE 'shop-api-test%'\")->execute();"
        "$db->prepare(\"DELETE FROM api_clients WHERE label LIKE 'shop-api-test%'\")->execute();"
        "foreach(['shoptest_buyer','shoptest_unver'] as $nm){"
        "  $st=$db->prepare('SELECT id FROM users WHERE username = ?'); $st->execute([$nm]);"
        "  if($id=(int)$st->fetchColumn()) userDeleteCascade($db,$id);}")
    for k, v in saved.items():
        if v is None:
            php("$db->prepare('DELETE FROM settings WHERE `key` = ?')->execute(['" + k + "']);")
        else:
            php("$db->prepare('INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)')"
                "->execute(['" + k + "', '" + str(v) + "']);")


def expiry_of(login="shoptest_buyer", group="premium"):
    return php("$u = userFindByLogin($db, '" + login + "');"
               "$g = userGroupBySlug($db, '" + group + "');"
               "$m = $u && $g ? userMembership($db, (int)$u['id'], (int)$g['id']) : null;"
               "echo $m === null ? 'none' : (string)($m['expires_at'] ?? 'forever');")


try:
    # ── 1. the scope ─────────────────────────────────────────────────────────
    st, b = call("v1/users/lookup", {"login": "shoptest_buyer"}, SHOP)
    check("a shop key may look an account up", st == 200 and b.get("found") is True, b)
    check("… and is told whether a grant would do anything", b.get("effective") is True, b)
    st, b = call("v1/users/provision", {"username": "shoptest_new", "email": "shoptest_new@example.org"}, SHOP)
    check("a shop key may NOT create accounts", st == 403 and b.get("error") == "forbidden", (st, b))
    st, b = call("v1/auth/merge", {"external_id": "1", "login": "shoptest_buyer"}, SHOP)
    check("… and may not reach the sign-in bridge's merge", st == 403 and b.get("error") == "forbidden", (st, b))
    check("… the account it could have taken over is untouched",
          php("$u=userFindByLogin($db,'shoptest_buyer'); echo $u ? 'here' : 'gone';") == "here")

    # ── 2. one payment, one month — however many times the webhook fires ─────
    order = "SHOP-TEST-0001"
    st, first = call("v1/users/grant",
                     {"login": "shoptest_buyer", "group": "premium", "duration": "1m",
                      "order_id": order, "note": "order #1"}, SHOP)
    check("the first call grants the month", st == 200 and first.get("ok") is True
          and first.get("group") == "premium" and first.get("replayed") is False, (st, first))
    check("… and says what the membership was before it", "previous_expires_at" in first
          and first["previous_expires_at"] is None, first)
    after_first = expiry_of()
    st, again = call("v1/users/grant",
                     {"login": "shoptest_buyer", "group": "premium", "duration": "1m",
                      "order_id": order, "note": "order #1"}, SHOP)
    check("the retry is answered from the order book", st == 200 and again.get("replayed") is True, (st, again))
    check("… with the SAME end date the customer was already told",
          again.get("expires_at") == first.get("expires_at"), (first.get("expires_at"), again.get("expires_at")))
    check("… and the membership did not move an inch", expiry_of() == after_first,
          (after_first, expiry_of()))
    check("… one order row, not two",
          php("$st=$db->prepare('SELECT COUNT(*) FROM user_group_orders WHERE order_id = ?');"
              "$st->execute(['" + order + "']); echo (int)$st->fetchColumn();") == "1")

    # A DIFFERENT order id is a different purchase and does stack.
    st, second = call("v1/users/grant",
                      {"login": "shoptest_buyer", "group": "premium", "duration": "1m",
                       "order_id": "SHOP-TEST-0002"}, SHOP)
    check("a second order extends from where the first ended",
          st == 200 and second.get("previous_expires_at") == first.get("expires_at")
          and second.get("expires_at") > first.get("expires_at"), (st, second))

    # ── 3. the refund takes back one order, not the lot ──────────────────────
    st, ref = call("v1/users/revoke",
                   {"login": "shoptest_buyer", "group": "premium", "order_id": "SHOP-TEST-0002"}, SHOP)
    check("refunding the second order leaves the first one's month alone",
          st == 200 and ref.get("removed") is False and ref.get("expires_at") == first.get("expires_at"),
          (st, ref, first.get("expires_at")))
    st, ref2 = call("v1/users/revoke",
                    {"login": "shoptest_buyer", "group": "premium", "order_id": "SHOP-TEST-0002"}, SHOP)
    check("… and a retried refund takes nothing away twice",
          st == 200 and ref2.get("replayed") is True and expiry_of() == first.get("expires_at"),
          (st, ref2, expiry_of()))
    st, ref3 = call("v1/users/revoke",
                    {"login": "shoptest_buyer", "group": "premium", "order_id": order}, SHOP)
    check("refunding the first one removes the membership it created",
          st == 200 and ref3.get("removed") is True and expiry_of() == "none", (st, ref3, expiry_of()))
    st, b = call("v1/users/revoke",
                 {"login": "shoptest_buyer", "group": "premium", "order_id": "SHOP-TEST-NOPE"}, SHOP)
    check("an order nobody has ever sent is a 404, not a silent success", st == 404 and b.get("error") == "order_not_found", (st, b))

    # ── 4. naming the buyer ──────────────────────────────────────────────────
    st, b = call("v1/users/grant", {"user_id": env["buyer"], "group": "premium", "duration": "7d",
                                    "order_id": "SHOP-TEST-ID"}, SHOP)
    check("a buyer can be named by this tracker's own id", st == 200 and b.get("user_id") == env["buyer"], (st, b))
    st, b = call("v1/users/grant", {"external_id": "EXT-991", "group": "premium", "duration": "7d",
                                    "order_id": "SHOP-TEST-EXT"}, SHOP)
    check("… or by the id in the shop's own system, through the bridge's link",
          st == 200 and b.get("user_id") == env["buyer"], (st, b))
    st, b = call("v1/users/grant", {"external_id": "EXT-991", "login": "shoptest_buyer",
                                    "group": "premium", "duration": "7d"}, SHOP)
    check("two names in one call is refused rather than guessed",
          st == 422 and b.get("error") == "identity_ambiguous", (st, b))
    st, b = call("v1/users/grant", {"group": "premium", "duration": "7d"}, SHOP)
    check("… and no name at all is refused too", st == 422 and b.get("error") == "identity_required", (st, b))
    st, b = call("v1/users/grant", {"external_id": "EXT-NOBODY", "group": "premium", "duration": "7d"}, SHOP)
    check("an external id this key has never linked is simply not found",
          st == 404 and b.get("error") == "user_not_found", (st, b))

    # ── 5. `until` replaces, and will not quietly end a permanent membership ─
    php("$u=userFindByLogin($db,'shoptest_buyer'); $g=userGroupBySlug($db,'premium');"
        "userGrantGroup($db,(int)$u['id'],(int)$g['id'],null,'test','forever',false);")
    st, b = call("v1/users/grant", {"login": "shoptest_buyer", "group": "premium",
                                    "until": "2027-01-01", "order_id": "SHOP-TEST-UNTIL"}, SHOP)
    check("an `until` that would end a permanent membership is refused",
          st == 409 and b.get("error") == "would_shorten_permanent", (st, b))
    check("… and the membership is still permanent", expiry_of() == "forever", expiry_of())
    st, b = call("v1/users/grant", {"login": "shoptest_buyer", "group": "premium", "until": "2027-01-01",
                                    "force": True, "order_id": "SHOP-TEST-FORCE"}, SHOP)
    check("… unless the shop says it means it", st == 200 and str(b.get("expires_at", "")).startswith("2027-01-01"), (st, b))
    check("… and the reply says what it replaced", b.get("previous_expires_at") is None, b)
    st, b = call("v1/users/grant", {"login": "shoptest_buyer", "group": "premium", "until": "2001-01-01"}, SHOP)
    check("a date in the past is not a membership", st == 422 and b.get("error") == "invalid_until", (st, b))

    # ── 6. a grant that is recorded and does nothing yet ─────────────────────
    st, b = call("v1/users/grant", {"login": "shoptest_unver", "group": "premium", "duration": "1m",
                                    "order_id": "SHOP-TEST-UNVER"}, SHOP)
    check("selling to an unverified account works and says it does not work yet",
          st == 200 and b.get("ok") is True and b.get("effective") is False
          and b.get("reason") == "email_unverified", (st, b))
    check("… and the membership really is recorded, waiting for them",
          expiry_of("shoptest_unver") not in ("none", ""), expiry_of("shoptest_unver"))

    # ── 7. the guards that were already there, still there ───────────────────
    st, b = call("v1/users/grant", {"login": "shoptest_buyer", "group": "moderator", "duration": "1m"}, SHOP)
    check("a group carrying panel access cannot be sold", st == 403 and b.get("error") == "group_not_grantable", (st, b))
    st, b = call("v1/users/grant", {"login": "shoptest_buyer", "group": "guest", "duration": "1m"}, SHOP)
    check("and neither can guest", st == 422 and b.get("error") == "group_not_grantable", (st, b))
    st, b = call("v1/users/grant", {"login": "shoptest_buyer", "group": "premium", "duration": "forever"}, SHOP)
    check("an unknown duration names the ones that work", st == 422 and "1m" in (b.get("allowed") or []), (st, b))

    # ── 8. a full `users` key can still do everything it could ───────────────
    st, b = call("v1/users/grant", {"login": "shoptest_buyer", "group": "premium", "duration": "1d"}, USERS)
    check("a users key still grants without an order id, exactly as before",
          st == 200 and b.get("ok") is True and b.get("order_id") is None, (st, b))
    st, b = call("v1/users/revoke", {"login": "shoptest_buyer", "group": "premium"}, USERS)
    check("… and its plain revoke is still the hard stop",
          st == 200 and b.get("removed") is True and expiry_of() == "none", (st, b))

    # ── 9. the log has a line for each of them ───────────────────────────────
    logged = php("$st=$db->query(\"SELECT action, target_id, summary FROM audit_log"
                 " WHERE action IN ('user.grant','user.revoke') AND summary LIKE '%premium%'"
                 " ORDER BY id DESC LIMIT 5\"); echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));")
    try:
        rows = json.loads(logged)
    except ValueError:
        rows = []
    check("the audit log names the action, the account and the group",
          len(rows) >= 2 and any(r["action"] == "user.grant" for r in rows)
          and any(r["action"] == "user.revoke" for r in rows)
          and all(str(r["target_id"]).isdigit() for r in rows), logged[:300])
    check("… and an order id is in the line it belongs to",
          any("order" in (r["summary"] or "") for r in rows), logged[:300])
finally:
    cleanup()
    # By the ACTOR, which for an API call is the key's label: exactly the lines this run wrote, and
    # nothing that was in the log before it.
    php("$db->prepare(\"DELETE FROM audit_log WHERE actor_name LIKE 'shop-api-test%'\")->execute();")

print("\n%d checks, %d failed" % (n, fails))
sys.exit(1 if fails else 0)
