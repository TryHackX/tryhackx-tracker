#!/usr/bin/env python3
"""
The Content-Security-Policy, checked on SERVED pages rather than in the source:

    python tests/csp_headers_test.py

Two comments in includes/csp.php name this file as the thing that makes their claims true, and for
a while it did not exist. The claims are worth having a test for, and neither can be checked by
reading the tree:

  * "nothing can forget the nonce" is a property of the RENDERED page. A source grep for
    `<script<?= nonceAttr() ?>` passes while a template emits a script through some other path, or
    while a page renders a block a grep never looked at. So this asks the server for each page and
    compares what came back against the nonce in that same response's header.

  * "the response carries exactly one policy" cannot be seen in PHP at all. index.php sends the
    public policy from sendSecurityHeaders() before it knows which page it is about to render, and
    the panel branch sends its own afterwards; header() replaces a same-named header, so one wins.
    Two headers would mean the browser INTERSECTS them, which is how a page silently loses the
    ability to load its own stylesheet. Only the wire shows this.

  * inline on*= handlers. Thirteen were removed for this policy, and the suite that scans for them
    globs templates/ and includes/ only — the handlers that matter now live in assets/js/, where a
    line building `'<button onclick="…">'` is invisible to that scan and to a rendered-page scan on
    a route nobody listed. So the JS files are read directly as well.

The panel routes need a session; without one they redirect to the login page, which is still a
served page worth checking, so the sweep runs signed in and says which routes it actually saw.
"""
import http.cookiejar
import json
import os
import re
import sys
import urllib.error
import urllib.request

BASE = os.environ.get("SMOKE_BASE", "http://127.0.0.1:8089/")
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
ADMIN_USER = os.environ.get("SMOKE_ADMIN_USER", "admin")
ADMIN_PASS = os.environ.get("SMOKE_ADMIN_PASS", "admin123")

fails = 0
n = 0


def check(name, cond, info=""):
    global fails, n
    n += 1
    if not cond:
        fails += 1
    print(("PASS " if cond else "FAIL ") + name + ("" if cond else "  -> " + str(info)[:400]))


jar = http.cookiejar.CookieJar()
opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
CSRF = None


def get(path):
    """(status, headers, body) for a page, following redirects like a browser."""
    try:
        with opener.open(BASE + path, timeout=20) as r:
            return r.status, r.headers, r.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        return e.code, e.headers, e.read().decode("utf-8", "replace")
    except Exception as e:                                    # noqa: BLE001 - a dead server is a result
        return 0, {}, str(e)


def api(endpoint, body):
    req = urllib.request.Request(
        BASE + "api.php?endpoint=" + endpoint,
        data=json.dumps(body).encode(),
        headers={"Content-Type": "application/json", **({"X-CSRF-Token": CSRF} if CSRF else {})},
        method="POST",
    )
    try:
        with opener.open(req, timeout=20) as r:
            return r.status, json.loads(r.read().decode() or "{}")
    except urllib.error.HTTPError as e:
        try:
            return e.code, json.loads(e.read().decode() or "{}")
        except Exception:                                     # noqa: BLE001
            return e.code, {}


s, _, body = get("?action=admin")
if s == 0:
    print("SKIP csp_headers_test -> no server at " + BASE + " (" + str(body)[:80] + ")")
    sys.exit(0)
m = re.search(r'name="csrf_token"[^>]*value="([^"]+)"', body)
CSRF = m.group(1) if m else None
check("csrf token found on the login page", CSRF is not None)
st, j = api("admin/login", {"username": ADMIN_USER, "password": ADMIN_PASS, "csrf_token": CSRF})
signed_in = st == 200 and bool(j.get("success"))
check("signed in for the panel half of the sweep", signed_in, (st, j))

# Every route this app serves as HTML. Panel routes are included even when the session failed —
# the login page is a served page and its policy matters just as much.
ROUTES = [
    "", "?action=info", "?action=tos", "?action=search", "?action=status", "?action=stats",
    "?action=whitelist", "?action=report", "?action=transparency", "?action=login",
    "?action=register", "?action=admin", "?action=settings", "?action=admin-traffic",
    "?action=admin-index", "?action=admin-users", "?action=admin-whitelist", "?action=admin-backups",
    "?action=admin-audit",
]

CSP_HEADERS = ("content-security-policy", "content-security-policy-report-only")
# `<script` not followed by a letter, so `<scripts>` (were there such a thing) is not a script tag.
SCRIPT_RE = re.compile(r"<script(?![a-zA-Z])([^>]*)>", re.I)
# An on*= attribute on a tag. Deliberately not anchored to a tag name: the point is that none exist.
ON_ATTR_RE = re.compile(r"<[a-zA-Z][^>]*?\son[a-z]{3,20}\s*=", re.I)

seen, no_policy, two_policies, missing_nonce, inline_handlers = [], [], [], [], []
for route in ROUTES:
    status, headers, html = get(route)
    if status == 0 or not html.lstrip().lower().startswith(("<!doctype", "<html")):
        continue
    seen.append(route or "/")
    names = [k.lower() for k in headers.keys()] if hasattr(headers, "keys") else []
    policies = [headers[k] for k in (headers.keys() if hasattr(headers, "keys") else [])
                if k.lower() in CSP_HEADERS]
    if len(policies) == 0:
        no_policy.append(route or "/")
        continue
    if len(policies) > 1 or sum(1 for x in names if x in CSP_HEADERS) > 1:
        two_policies.append((route or "/", len(policies)))
    nonce_m = re.search(r"'nonce-([A-Za-z0-9+/=_-]+)'", policies[0])
    nonce = nonce_m.group(1) if nonce_m else None
    for attrs in SCRIPT_RE.findall(html):
        low = attrs.lower()
        if " src=" in low or low.startswith("src="):
            continue                                          # external file: covered by script-src
        if 'type="application/json"' in low or "type='application/json'" in low:
            continue                                          # a data island, not executable
        if nonce is None or ('nonce="' + nonce + '"') not in attrs:
            missing_nonce.append(((route or "/"), attrs.strip()[:70]))
    for hit in ON_ATTR_RE.findall(html):
        inline_handlers.append(((route or "/"), hit[:70]))

check("the sweep actually rendered pages", len(seen) >= 8, seen)
check("every served page carries a policy", no_policy == [], no_policy)
check("and exactly one of it — two would be intersected by the browser", two_policies == [], two_policies)
check("every inline script on a served page carries that response's nonce", missing_nonce == [],
      missing_nonce[:6])
check("no served page carries an inline on*= handler", inline_handlers == [], inline_handlers[:6])

# The handlers a rendered-page sweep cannot see: markup built inside the JavaScript, on a route or
# in a state this sweep never reached.
js_hits = []
jsdir = os.path.join(ROOT, "assets", "js")
for fn in sorted(os.listdir(jsdir)) if os.path.isdir(jsdir) else []:
    if not fn.endswith(".js"):
        continue
    src = open(os.path.join(jsdir, fn), encoding="utf-8", errors="replace").read()
    # `onclick="` / `onclick='` inside a string literal being built into markup. setAttribute('on…')
    # is caught by the same shape. Property assignment (el.onclick = fn) is NOT an inline handler
    # and is deliberately not matched.
    for m2 in re.finditer(r"""["'][^"'\n]{0,80}\son[a-z]{3,20}\s*=\s*["']""", src):
        js_hits.append((fn, m2.group(0)[:60]))
    for m2 in re.finditer(r"""setAttribute\(\s*["']on[a-z]{3,20}["']""", src):
        js_hits.append((fn, m2.group(0)[:60]))
check("no script builds an inline on*= handler into markup", js_hits == [], js_hits[:6])

print(f"{n} checks, {fails} failed")
sys.exit(1 if fails else 0)
