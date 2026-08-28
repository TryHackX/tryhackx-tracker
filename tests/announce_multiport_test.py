#!/usr/bin/env python3
"""
Extra opentracker instances, seen from the public side:

    python tests/announce_multiport_test.py

Extra instances listen on their OWN UDP ports and share nothing between them. The kernel does not
split one port across processes, and opentracker spreads load across THREADS on a single socket
instead. So an extra instance answers exactly the announces whose magnet names its port -- which
makes the announce URLs on the public pages the entire mechanism. Get them wrong and the extra
process sits at zero for ever while every status card reports it perfectly healthy.

This test exists because a unit test did not catch a real break. That one asserted the template
CONTAINED "$extraUrls", which stayed true after an edit dropped the line that ASSIGNS it: the page
rendered without any extra port and the check went on passing. So this one asks the server for the
actual page and reads what a visitor would see.

The announce blocks only render where they are useful -- the whitelist page shows them when
registration is open, the search form exists when the catalogue is on -- so the test puts the tracker
into that state itself instead of depending on whatever the last run left behind, and puts every
setting back afterwards, including on failure.
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

fails = 0
n = 0


def check(name, cond, info=""):
    global fails, n
    n += 1
    print(("PASS " if cond else "FAIL ") + name + ("" if cond or not info else "  -> " + str(info)[:300]))
    if not cond:
        fails += 1


BOOT = (
    "$root = " + repr(ROOT).lstrip("r") + ";"
    "require_once $root . '/config/app.php';"
    "require_once $root . '/config/database.php';"
    "require_once $root . '/includes/settings.php';"
    "require_once $root . '/includes/functions.php';"
    "require_once $root . '/includes/netlimit.php';"
    "require_once $root . '/includes/opentracker.php';"
    "require_once $root . '/includes/cluster.php';"
    "$db = getDb();"
)


def php(code):
    """Run a snippet with the app bootstrapped, and return its stdout."""
    p = subprocess.run(["php", "-r", BOOT + code], capture_output=True, text=True, cwd=ROOT)
    if p.returncode != 0:
        raise RuntimeError("php failed: " + (p.stderr or p.stdout)[:500])
    return p.stdout


JAR = http.cookiejar.CookieJar()
OPENER = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(JAR))


def get(path):
    with OPENER.open(BASE.rstrip("/") + path, timeout=30) as r:
        return r.read().decode("utf-8", "replace")


def get_anon(path):
    """No cookies. The before/after comparison has to be made as the same visitor both times, and by
    then this process is holding an admin session, which changes the page on its own."""
    with urllib.request.urlopen(BASE.rstrip("/") + path, timeout=30) as r:
        return r.read().decode("utf-8", "replace")


def sign_in(user="admin", password="admin123"):
    """The catalogue search is permission-gated, and the guest group does not carry it. Signing in as
    the panel admin is the shortest way past that -- userCan() short-circuits on a panel session."""
    html = get("?action=admin")
    m = re.search(r'name="csrf_token"[^>]*value="([^"]+)"', html) or re.search(r"CSRF\s*=\s*'([^']+)'", html)
    csrf = m.group(1) if m else None
    body = {"username": user, "password": password}
    if csrf:
        body["csrf_token"] = csrf
    headers = {"Content-Type": "application/json"}
    if csrf:
        headers["X-CSRF-Token"] = csrf
    req = urllib.request.Request(BASE.rstrip("/") + "/api.php?endpoint=admin/login",
                                 data=json.dumps(body).encode(), headers=headers, method="POST")
    try:
        with OPENER.open(req) as r:
            return json.loads(r.read().decode()).get("success") is True
    except urllib.error.HTTPError:
        return False


def php_str(value):
    """A PHP single-quoted literal for a Python string."""
    return "'" + str(value).replace("\\", "\\\\").replace("'", "\\'") + "'"


PRIMARY_UDP = "udp://tracker.example.org:6969/announce"
EXTRA_UDP = "udp://tracker.example.org:6970/announce"
DOWN_PORT = "6971"

# The whitelist page only prints its announce block inside the branch that shows a working
# registration form, so the run needs whitelist mode AND a configured CAPTCHA -- the dummy keys below
# are never sent anywhere, they only make captchaConfigured() true. The search page is the mirror
# image: index.php refuses ?action=search outright unless accounts are on, so users_enabled has to
# stay 1 and the pages are read while signed in as the admin, which is what userCan() short-circuits
# on. Every one of these is put back at the end, including on failure.
TOGGLES = ["ot_cluster_enabled", "ot_cluster_cmd", "tracker_mode",
           "index_enabled", "index_search_enabled", "users_enabled",
           "recaptcha_enabled", "recaptcha_site_key", "recaptcha_secret",
           "whitelist_public_enabled", "whitelist_submit_mode"]

saved = json.loads(php(
    "echo json_encode(['settings' => ["
    + ",".join("'%s' => getSetting($db, '%s', '')" % (k, k) for k in TOGGLES)
    + "], 'state' => is_file(netlimitStateFile()) ? file_get_contents(netlimitStateFile()) : null]);"
))

# The page exactly as it stands right now, before this test touches anything. The final check
# compares against THIS -- not against the forced-off baseline below, which is a state the tracker
# may never have been in.
home_at_start = get_anon("/")

# ── with the cluster off, nothing public may change ─────────────────────────
php("setSetting($db, 'ot_cluster_enabled', '0'); setSetting($db, 'ot_cluster_cmd', ''); "
    "otClusterStateSet([]); echo 'off';")
home_before = get_anon("/")
check("with the cluster off the home page names the primary port", PRIMARY_UDP in home_before)
check("... and no extra port", "6970" not in home_before and DOWN_PORT not in home_before)
check("... and no multi-port note", "announce-extra-note" not in home_before)

try:
    php("""
    setSetting($db, 'ot_cluster_enabled', '1');
    setSetting($db, 'ot_cluster_cmd', 'sudo -n /usr/local/sbin/tracker-cluster.sh');
    setSetting($db, 'tracker_mode', 'whitelist');
    setSetting($db, 'index_enabled', '1');
    setSetting($db, 'index_search_enabled', '1');
    setSetting($db, 'users_enabled', '1');
    setSetting($db, 'whitelist_public_enabled', '1');
    setSetting($db, 'whitelist_submit_mode', 'public');
    setSetting($db, 'recaptcha_enabled', '1');
    setSetting($db, 'recaptcha_site_key', 'test-site-key-not-used');
    setSetting($db, 'recaptcha_secret', 'test-secret-not-used');
    otClusterStateSet(['roster' => [
      'primary'   => ['udp_port' => 6969, 'tcp_port' => 6969],
      'instances' => [
        ['name' => 'edge-a', 'udp_port' => 6970, 'state' => 'active'],
        ['name' => 'edge-b', 'udp_port' => 6971, 'state' => 'inactive'],
      ],
      'count' => 2, 'at' => time(),
    ]]);
    echo 'on';
    """)

    # ── home ────────────────────────────────────────────────────────────────
    home = get("/")
    check("the home page now names the extra instance's port", EXTRA_UDP in home)
    check("... and never names the instance that is not listening", DOWN_PORT not in home)
    check("... and still names the primary's, first",
          PRIMARY_UDP in home and home.index(PRIMARY_UDP) < home.index(EXTRA_UDP))
    check("... and says why there is more than one, in the visitor's own terms",
          "announce-extra-note" in home and "more than one port" in home)
    copy_block = re.search(r'id="announce-copy"[^>]*>(.*?)</span>', home, re.S)
    check("... and the copy button copies every port, not just the first",
          copy_block is not None and EXTRA_UDP in copy_block.group(1)
          and PRIMARY_UDP in copy_block.group(1),
          copy_block.group(1)[:200] if copy_block else "no copy block")

    # ── whitelist ───────────────────────────────────────────────────────────
    wl = get("/?action=whitelist")
    check("the whitelist page carries the extra port too", EXTRA_UDP in wl)
    check("... and not the one that is down", DOWN_PORT not in wl)
    wl_copy = re.search(r'id="wl-announce-copy"[^>]*>(.*?)</span>', wl, re.S)
    check("... and its copy button covers every port",
          wl_copy is not None and EXTRA_UDP in wl_copy.group(1),
          wl_copy.group(1)[:200] if wl_copy else "no copy block")

    # ── search: the client builds magnets in the browser ────────────────────
    check("signed in, so the permission-gated search page is reachable at all", sign_in())
    search = get("/?action=search")
    m = re.search(r'data-announce-extra="([^"]*)"', search)
    if m is None:
        check("the search form hands the extra ports to the browser", False,
              "no data-announce-extra attribute in the page")
    else:
        val = m.group(1).replace("&#039;", "'").replace("&amp;", "&")
        check("the search form hands the extra ports to the browser", EXTRA_UDP in val, val)
        check("... and not the port of the instance that is down", DOWN_PORT not in val, val)

    # ── the magnet the server itself builds ─────────────────────────────────
    magnet = php(
        "require_once $root . '/includes/whitelist.php';"
        "$cfg = getSettings($db, true);"
        "echo buildMagnet(str_repeat('c', 40), 'x', $cfg);"
    ).strip()
    check("the magnet the server builds names the extra port -- the only way a client reaches it",
          "6970" in magnet, magnet)
    check("... and does not name the port nothing is listening on", DOWN_PORT not in magnet, magnet)

finally:
    restore = "".join(
        "setSetting($db, '%s', %s);" % (k, php_str(saved["settings"][k])) for k in TOGGLES
    )
    if saved["state"] is not None:
        restore += "file_put_contents(netlimitStateFile(), %s);" % php_str(saved["state"])
    else:
        restore += "@unlink(netlimitStateFile());"
    php(restore + "echo 'restored';")

check("the test put the tracker back exactly as it found it", get_anon("/") == home_at_start,
      "the public home page differs after the run")

print("\n%d checks, %d failed" % (n, fails))
sys.exit(1 if fails else 0)
