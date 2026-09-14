#!/usr/bin/env python3
"""
Every public page, fetched as a visitor, answers 200 and carries no PHP warning or notice:
    python tests/guest_pages_test.py            (needs the local server: php -S 127.0.0.1:8089)

Why this exists: from 1.44.0 to 1.51.0 a visitor opening ?action=account produced eight "undefined
variable" warnings per view. On production those go to the error log, where nobody looks unless
something else is wrong; on a dev box with display_errors they are printed into the page — which
is exactly what this test reads. Any template that reads a variable only a signed-in branch sets
is caught here the first time it ships.
"""
import os
import re
import sys
import urllib.error
import urllib.request

BASE = os.environ.get("SMOKE_BASE", "http://127.0.0.1:8089/")
PAGES = ["", "?action=account", "?action=login", "?action=register", "?action=reset", "?action=search",
         "?action=whitelist", "?action=stats", "?action=status", "?action=info", "?action=terms",
         "?action=transparency", "?action=report", "?action=u&name=smokeuser", "?action=u&name=no-such-user-here",
         "?action=members", "?action=lists", "?action=apidocs", "?action=shoutbox", "?action=emotes",
         "?action=no-such-action"]
LEAK = re.compile(r"(Warning|Notice|Deprecated|Fatal error)</b>:|PHP (Warning|Notice|Fatal)|Undefined (variable|array key|index)|Stack trace:")

fails = 0
n = 0


def check(name, cond, info=""):
    global fails, n
    n += 1
    print(("PASS " if cond else "FAIL ") + name + ("" if cond or not info else "  -> " + str(info)[:220]))
    if not cond:
        fails += 1


for p in PAGES:
    url = BASE + p
    try:
        with urllib.request.urlopen(urllib.request.Request(url, headers={"User-Agent": "guest-pages-test"}), timeout=60) as r:
            code, body = r.status, r.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        code, body = e.code, e.read().decode("utf-8", "replace")
    except Exception as e:  # noqa: BLE001
        check("GET %s answers" % (p or "/"), False, e)
        continue
    # An unknown user or action answers a page of its own (the profile says "no such person", the
    # router falls back to the front page); either a 200 or a 404 is fine there — what may not
    # happen is a warning. Everything else a visitor may open is a 200.
    expect = (200, 404) if ("no-such" in p) else (200,)
    check("GET %s answers %s" % (p or "/", "/".join(map(str, expect))), code in expect, code)
    m = LEAK.search(body)
    check("… and leaks no PHP warning into the page", m is None, body[max(0, (m.start() if m else 0) - 80):(m.end() if m else 0) + 120])

print("\n%d checks, %d failed" % (n, fails))
sys.exit(1 if fails else 0)
