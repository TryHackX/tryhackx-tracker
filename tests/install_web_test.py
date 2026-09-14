#!/usr/bin/env python3
"""
A BROWSER INSTALL MUST FINISH BY ITSELF (needs the local database server):

    python tests/install_web_test.py

── why this test exists ──────────────────────────────────────────────────────────────────────────
tests/install_test.php builds a fresh install from the statement list and compares it with an
upgraded one — but it does that IN THE CLI, and the CLI is the one context where schemaHeavyAllowed()
says yes. So it could not see the bug this test is about.

`index_hashes` carries a FULLTEXT index, which makes ADD COLUMN / ADD KEY on it a full table
rebuild. Outside the CLI, trackerSchemaGuardedStatements() therefore DEFERS those ALTERs
(schemaDeferHeavy) and ensureSchema() deliberately does not record the new schema_version — the
schema really is not at that version. Correct on a live site; fatal in install.php, because
INSTALL.md tells the operator to open the installer IN A BROWSER. Under php-fpm / Apache / the
built-in server, PHP_SAPI is not 'cli', the vote columns and the four composite indexes were
missing from the base CREATE TABLE and so had to come from those deferred ALTERs, and the installer
stopped with "the database schema stopped at version 0" and never wrote config/installed.lock. Only
a later CLI run (php tools/janitor.php) finished the job.

So this drives the real installer over HTTP under a NON-CLI SAPI (`php -S`, PHP_SAPI = 'cli-server')
against an empty database, exactly as a browser would, and then looks at what landed.

It touches nothing of the working tree: the repo is copied to a temporary directory (without .git,
scratchpad, and without config/, which is re-created holding only app.php/.htaccess), so the
installer's config/database.php, config/hash.txt and config/installed.lock are written into the
copy. The scratch database is created empty and dropped again at the end.

When the environment is not there — no config/database.php to borrow credentials from, no mysql
client, no php, the database server down, the port already in use — it says so and exits 0. The
battery runs every tests/*_test.py; a missing local tool is not a failing tracker.
"""
import glob
import http.cookiejar
import os
import re
import shutil
import socket
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PORT = int(os.environ.get("TRACKER_INSTALL_PORT", "8097"))
DB_NAME = os.environ.get("TRACKER_INSTALL_DB", "tracker_fresh_web")

fails = 0
n = 0


def check(name, cond, info=""):
    global fails, n
    n += 1
    print(("PASS " if cond else "FAIL ") + name + ("" if cond or not info else "  -> " + str(info)[:500]))
    if not cond:
        fails += 1


def skip(why):
    """Environment, not tracker. Say what is missing and leave the battery green."""
    print("PASS install_web_test — skipped: " + why)
    sys.exit(0)


# ── the environment ───────────────────────────────────────────────────────────────────────────────
def db_credentials():
    """The same trick tests/install_test.php uses: borrow the connection the tests already have.
    config/database.php is either the generated getDb() with a DSN, or DB_* constants."""
    path = os.path.join(ROOT, "config", "database.php")
    if not os.path.isfile(path):
        return None
    src = open(path, encoding="utf-8", errors="replace").read()
    m = re.search(r"new\s+PDO\(\s*(['\"])(.+?)\1\s*,\s*(['\"])(.*?)\3\s*,\s*(['\"])(.*?)\5", src, re.S)
    if m:
        dsn, user, pw = m.group(2), m.group(4), m.group(6)
    else:
        def const(key, default):
            mm = re.search(r"define\(\s*['\"]" + key + r"['\"]\s*,\s*['\"](.*?)['\"]", src)
            return mm.group(1) if mm else default
        dsn = "mysql:host=%s;port=%s" % (const("DB_HOST", "127.0.0.1"), const("DB_PORT", "3306"))
        user, pw = const("DB_USER", "root"), const("DB_PASS", "")
    host = re.search(r"host=([^;'\"]+)", dsn)
    port = re.search(r"port=(\d+)", dsn)
    return (host.group(1) if host else "127.0.0.1", port.group(1) if port else "3306", user, pw)


def find_mysql():
    env = os.environ.get("TRACKER_MYSQL", "")
    if env and os.path.isfile(env):
        return env
    # The MariaDB client first: this project's server is MariaDB, and the MySQL 8 client shipped
    # next to it in WAMP does not always agree with it about authentication.
    for pattern in (r"C:\wamp64\bin\mariadb\*\bin\mysql.exe", r"C:\wamp64\bin\mysql\*\bin\mysql.exe"):
        hits = sorted(glob.glob(pattern))
        if hits:
            return hits[-1]
    return shutil.which("mysql") or shutil.which("mysql.exe")


def port_free(port):
    s = socket.socket()
    try:
        s.bind(("127.0.0.1", port))
        return True
    except OSError:
        return False
    finally:
        s.close()


try:
    creds = db_credentials()
    php_bin = shutil.which("php") or shutil.which("php.exe")
    mysql_bin = find_mysql()
except Exception as exc:                                    # discovery must never take the suite down
    skip("could not read the local configuration (%s)" % exc)

if creds is None:
    skip("config/database.php missing — run the local bootstrap first")
if php_bin is None:
    skip("no php on PATH")
if mysql_bin is None:
    skip("no mysql client found (set TRACKER_MYSQL to its full path)")
if not port_free(PORT):
    skip("127.0.0.1:%d is already in use (set TRACKER_INSTALL_PORT)" % PORT)

HOST, DB_PORT, DB_USER, DB_PASS = creds


def mysql(query, database=None, timeout=60):
    args = [mysql_bin, "-h", HOST, "-P", DB_PORT, "-u", DB_USER]
    if DB_PASS:
        args.append("--password=" + DB_PASS)
    if database:
        args.append("--database=" + database)
    args += ["--default-character-set=utf8mb4", "-N", "-B", "-e", query]
    p = subprocess.run(args, capture_output=True, text=True, encoding="utf-8", errors="replace", timeout=timeout)
    return p.returncode, p.stdout.strip(), (p.stderr or "").strip()


rc, out, err = mysql("SELECT 1")
if rc != 0:
    skip("the database server at %s:%s is not answering (%s)" % (HOST, DB_PORT, err[:160]))

# The version the installer has to reach, read from the source rather than repeated here.
schema_src = open(os.path.join(ROOT, "includes", "schema.php"), encoding="utf-8", errors="replace").read()
m = re.search(r"const\s+TRACKER_SCHEMA_VERSION\s*=\s*(\d+)", schema_src)
if not m:
    skip("TRACKER_SCHEMA_VERSION not found in includes/schema.php")
SCHEMA_VERSION = int(m.group(1))

# What the working tree's own config looked like before we started. Nothing here may touch it.
def stamp(path):
    try:
        st = os.stat(path)
        return (st.st_size, st.st_mtime_ns)
    except OSError:
        return None


live_config = {f: stamp(os.path.join(ROOT, "config", f)) for f in ("database.php", "installed.lock", "hash.txt")}

# ── a copy of the site, and an empty database ─────────────────────────────────────────────────────
tmp = tempfile.mkdtemp(prefix="tracker_install_web_")
site = os.path.join(tmp, "site")
server = None
log_path = os.path.join(tmp, "server.log")
log_fh = None


def ignored(directory, names):
    drop = {".git", "scratchpad", "node_modules", "__pycache__", ".venv", "venv", ".idea", ".vscode"}
    out = {x for x in names if x in drop}
    if os.path.abspath(directory) == os.path.abspath(ROOT):
        out.add("config")          # re-created below with only the files a deploy actually ships
    return out


try:
    shutil.copytree(ROOT, site, ignore=ignored)
    os.makedirs(os.path.join(site, "config"), exist_ok=True)
    for keep in ("app.php", ".htaccess"):
        src = os.path.join(ROOT, "config", keep)
        if os.path.isfile(src):
            shutil.copy2(src, os.path.join(site, "config", keep))
    # Proof that this really is a non-CLI SAPI. Without it the whole test could pass for the wrong
    # reason — under the CLI every migration is allowed and the bug cannot appear.
    with open(os.path.join(site, "_sapi_probe.php"), "w", encoding="utf-8", newline="\n") as fh:
        fh.write("<?php echo PHP_SAPI;\n")

    check("the installer is present in the copy and the copy has no install state",
          os.path.isfile(os.path.join(site, "install.php"))
          and not os.path.exists(os.path.join(site, "config", "installed.lock"))
          and not os.path.exists(os.path.join(site, "config", "database.php")))

    # The shape of the fix, not only its effect: a revert of the constant would otherwise only show
    # up as a slow-burn failure the day somebody adds another guarded column.
    inst_src = open(os.path.join(ROOT, "install.php"), encoding="utf-8", errors="replace").read()
    i_def = inst_src.find("define('TRACKER_SCHEMA_FORCE_HEAVY'")
    # the require itself, not the word in the comments above it
    i_req = inst_src.find("require_once __DIR__ . '/includes/schema.php'")
    check("install.php allows the heavy migrations before it loads the schema",
          i_def >= 0 and i_req >= 0 and i_def < i_req,
          "define at %d, first schema.php require at %d" % (i_def, i_req))

    rc, out, err = mysql("DROP DATABASE IF EXISTS `%s`; CREATE DATABASE `%s` "
                         "CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" % (DB_NAME, DB_NAME))
    if rc != 0:
        skip("cannot create the scratch database %s (%s)" % (DB_NAME, err[:160]))
    rc, out, err = mysql("SHOW TABLES", DB_NAME)
    check("the scratch database starts empty", rc == 0 and out == "", out[:200])

    # ── the installer, over HTTP, under cli-server ────────────────────────────────────────────────
    log_fh = open(log_path, "wb")
    server = subprocess.Popen([php_bin, "-d", "display_errors=1", "-S", "127.0.0.1:%d" % PORT, "-t", site],
                              cwd=site, stdout=log_fh, stderr=log_fh)
    base = "http://127.0.0.1:%d/" % PORT
    jar = http.cookiejar.CookieJar()
    opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))

    class NoRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self, req, fp, code, msg, headers, newurl):
            return None                                     # a 302 is the answer we want to read

    stay = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar), NoRedirect())

    sapi = ""
    deadline = time.time() + 25
    while time.time() < deadline:
        if server.poll() is not None:
            break
        try:
            with urllib.request.urlopen(base + "_sapi_probe.php", timeout=5) as r:
                sapi = r.read().decode("utf-8", "replace").strip()
            break
        except Exception:
            time.sleep(0.3)
    if not sapi:
        tail = ""
        try:
            log_fh.flush()
            tail = open(log_path, "rb").read()[-400:].decode("utf-8", "replace")
        except OSError:
            pass
        skip("the built-in PHP server never came up on 127.0.0.1:%d (%s)" % (PORT, tail.replace("\n", " ")))

    check("the installer runs under a non-CLI SAPI, where the heavy migrations are declined",
          sapi != "" and sapi != "cli", sapi)

    def post(step, fields, timeout=240):
        data = urllib.parse.urlencode(fields).encode()
        req = urllib.request.Request(base + "install.php?step=%d" % step, data=data, method="POST")
        try:
            with stay.open(req, timeout=timeout) as r:
                return r.status, r.headers.get("Location", ""), r.read().decode("utf-8", "replace")
        except urllib.error.HTTPError as e:
            return e.code, e.headers.get("Location", ""), e.read().decode("utf-8", "replace")

    with opener.open(base + "install.php", timeout=60) as r:
        welcome = r.read().decode("utf-8", "replace")
    check("step 1 is the wizard, not an already-installed page", "Installation Wizard" in welcome,
          welcome[:200])

    # The host field is what an operator types. A server on a non-default port has to be told, and
    # the field goes straight into the DSN, so this is the same string a person would enter here.
    host_field = HOST if DB_PORT == "3306" else "%s;port=%s" % (HOST, DB_PORT)
    status, loc, body = post(2, {"db_host": host_field, "db_name": DB_NAME,
                                 "db_user": DB_USER, "db_pass": DB_PASS})
    check("step 2 builds the tables and moves on", status in (301, 302, 303) and "step=3" in loc,
          "%s %s %s" % (status, loc, re.sub(r"\s+", " ", body)[:300]))

    status, loc, body = post(3, {
        "admin_user": "admin",
        "admin_pass": "Install-Test-9x!",
        "admin_pass2": "Install-Test-9x!",
        "site_name": "Fresh Web Install",
        "site_url": "http://127.0.0.1:%d" % PORT,
        "site_email": "tracker@example.com",
        "mail_from_email": "",
        "announce_url": "",
        "announce_url_https": "",
        "captcha_provider": "recaptcha",
        "captcha_site_key": "",
        "captcha_secret": "",
        "blacklist_path": os.path.join(site, "config", "blacklist"),
    })
    flat = re.sub(r"\s+", " ", body)
    check("step 3 reports a finished install rather than a half-built schema",
          status in (301, 302, 303) and "step=4" in loc, "%s %s %s" % (status, loc, flat[:400]))
    check("… and never the deferral message this test exists for",
          "stopped at version" not in body and "Setup error" not in body, flat[:400])

    if loc:
        try:
            with opener.open(base + loc.lstrip("./"), timeout=60) as r:
                done = r.read().decode("utf-8", "replace")
        except urllib.error.HTTPError as e:
            done = e.read().decode("utf-8", "replace")
        # The lock is written before the redirect, so the page an operator lands on is the
        # already-installed notice rather than step 4's own markup. Either is an installed tracker.
        check("the page after the installer is an installed tracker",
              "Installation Complete" in done or "Already Installed" in done,
              re.sub(r"\s+", " ", done)[:200])

    check("config/installed.lock was written (in the copy, never in the working tree)",
          os.path.isfile(os.path.join(site, "config", "installed.lock")))
    check("… and so was the generated config/database.php",
          os.path.isfile(os.path.join(site, "config", "database.php")))

    # ── what actually landed in the database ──────────────────────────────────────────────────────
    rc, landed, err = mysql("SELECT `value` FROM `settings` WHERE `key` = 'schema_version'", DB_NAME)
    check("the browser install lands on the current schema version",
          rc == 0 and landed.strip() == str(SCHEMA_VERSION),
          "%s vs %d (%s)" % (landed.strip() or "nothing", SCHEMA_VERSION, err[:120]))

    def columns(table):
        rc, out, err = mysql("SHOW COLUMNS FROM `%s`" % table, DB_NAME)
        return {line.split("\t")[0] for line in out.splitlines() if line.strip()} if rc == 0 else set()

    def indexes(table):
        rc, out, err = mysql("SHOW INDEX FROM `%s`" % table, DB_NAME)
        return {line.split("\t")[2] for line in out.splitlines() if len(line.split("\t")) > 2} if rc == 0 else set()

    ih_cols = columns("index_hashes")
    want = {"votes_up", "votes_down", "score_x100", "votes_count"}
    check("index_hashes has the four rating columns", want <= ih_cols, "missing: " + ", ".join(sorted(want - ih_cols)))

    ih_idx = indexes("index_hashes")
    want = {"idx_index_seed_seen", "idx_index_meta_seed", "idx_index_meta_seen", "idx_index_meta_completed"}
    check("… and the four composite indexes the catalogue needs", want <= ih_idx,
          "missing: " + ", ".join(sorted(want - ih_idx)))

    wl_cols = columns("whitelist")
    want = {"source_url", "description", "description_format", "content_status", "content_reviewed_at",
            "content_rejected_note", "content_user_id", "probe_status", "probe_started_at", "probe_error",
            "dead_since"}
    check("whitelist has the eleven description / probe columns the pages select by name",
          want <= wl_cols, "missing: " + ", ".join(sorted(want - wl_cols)))
    want = {"votes_up", "votes_down", "score_x100", "votes_count"}
    check("… and its own four rating columns", want <= wl_cols, "missing: " + ", ".join(sorted(want - wl_cols)))
    want = {"idx_whitelist_content", "idx_whitelist_dead", "idx_whitelist_probe"}
    wl_idx = indexes("whitelist")
    check("… and the three indexes that come with them", want <= wl_idx,
          "missing: " + ", ".join(sorted(want - wl_idx)))

    check("api_clients.abuse_auto_block is there too", "abuse_auto_block" in columns("api_clients"))
    check("users.bulk_optout is there too", "bulk_optout" in columns("users"))

    # The site itself, not only the schema: the admin group the data migrations seed.
    rc, groups, err = mysql("SELECT slug FROM user_groups ORDER BY slug", DB_NAME)
    check("the seeded groups exist, so the data migrations ran as well",
          rc == 0 and "admin" in groups.split() and "member" in groups.split(), groups.replace("\n", " "))

    check("the working tree's own config was not touched",
          all(stamp(os.path.join(ROOT, "config", f)) == live_config[f] for f in live_config),
          {f: (live_config[f], stamp(os.path.join(ROOT, "config", f))) for f in live_config})

finally:
    if server is not None and server.poll() is None:
        server.terminate()
        try:
            server.wait(timeout=10)
        except subprocess.TimeoutExpired:
            server.kill()
    if log_fh is not None:
        try:
            log_fh.close()
        except OSError:
            pass
    try:
        mysql("DROP DATABASE IF EXISTS `%s`" % DB_NAME, timeout=60)
    except Exception:
        print("note: could not drop the scratch database %s — drop it by hand" % DB_NAME)
    shutil.rmtree(tmp, ignore_errors=True)

print("\n%d checks, %d failed" % (n, fails))
sys.exit(1 if fails else 0)
