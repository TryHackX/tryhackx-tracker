"""Render-level check of the two UI reports, the way smoke_admin.py authenticates."""
import http.cookiejar, json, re, sys, urllib.request

BASE = "http://127.0.0.1:8089/"
jar = http.cookiejar.CookieJar()
opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))

with opener.open(BASE + "?action=admin") as r:
    html = r.read().decode("utf-8", "replace")
m = re.search(r'name="csrf_token"[^>]*value="([^"]+)"', html) or re.search(r"CSRF\s*=\s*'([^']+)'", html)
if not m:
    sys.exit("no csrf token on the login page")
csrf = m.group(1)

req = urllib.request.Request(
    BASE + "api.php?endpoint=admin/login",
    data=json.dumps({"username": "admin", "password": "admin123", "csrf_token": csrf}).encode(),
    headers={"Content-Type": "application/json", "X-CSRF-Token": csrf}, method="POST")
with opener.open(req) as r:
    login = json.loads(r.read().decode())
print("login:", login.get("success"), login.get("error", ""))
if not login.get("success"):
    sys.exit(1)

fails = 0


def check(name, cond, info=""):
    global fails
    print(("PASS " if cond else "FAIL ") + name + ("" if cond or not info else "  -> " + str(info)[:300]))
    if not cond:
        fails += 1


# ── 1. Reports: the tab bar must no longer repeat the header's page links ──
with opener.open(BASE + "?action=admin") as r:
    dash = r.read().decode("utf-8", "replace")

tabs = re.search(r'<div class="source-tabs">(.*?)</div>', dash, re.S)
check("reports: the tab bar is still there", tabs is not None)
bar = tabs.group(1) if tabs else ""
check("reports: no page links left in the tab bar", 'source-tab-link' not in bar, bar[-200:])
check("reports: the view tabs survived",
      all(x in bar for x in ['data-source="reports"', 'data-source="archives"',
                             'data-source="appeals"', 'data-source="appeal_archives"']))
# the header bar must still carry every page, or the links were not moved but lost
for page in ["admin-whitelist", "admin-index", "admin-traffic", "admin-users", "admin-backups", "settings"]:
    check("reports: the header still links %s" % page, ("?action=" + page) in dash)
pairs = re.findall(r'\?action=(admin-whitelist|admin-index|admin-traffic|admin-users|admin-backups)\b', dash)
counts = {p: pairs.count(p) for p in set(pairs)}
check("reports: each page is linked exactly once now", all(v == 1 for v in counts.values()), counts)

# ── 2. Traffic: the outbound block is in the first paint, disabled, with no invented number ──
with opener.open(BASE + "?action=admin-traffic") as r:
    traf = r.read().decode("utf-8", "replace")

eg = re.search(r'<div class="nl-tune[^"]*" id="net-egress-tune">(.*?)<div class="nl-chart-head">', traf, re.S)
check("traffic: the outbound block is rendered server-side", eg is not None)
block = eg.group(1) if eg else ""
head = re.search(r'<div class="(nl-tune[^"]*)" id="net-egress-tune">', traf)
check("traffic: it is not hidden any more", head is not None and 'd-hidden' not in head.group(1), head.group(1) if head else "")
check("traffic: it is marked as pending instead", head is not None and 'nl-tune-pending' in head.group(1))
check("traffic: the outbound number does NOT start at a made-up 50000", 'value="50000"' not in block, block[:200])
check("traffic: the outbound input starts empty and disabled",
      'id="net-epps-input"' in block and 'value=""' in block and 'disabled' in block)
check("traffic: the outbound slider starts disabled",
      re.search(r'id="net-epps-range"[^>]*disabled', block) is not None)
check("traffic: both outbound buttons start disabled",
      re.search(r'id="btn-net-esuggest"[^>]*disabled', block) is not None
      and re.search(r'id="btn-net-eapply"[^>]*disabled', block) is not None)
check("traffic: it says why it is waiting", 'Reading the outbound rule' in block)

# the inbound half must be unchanged: rendered by PHP, enabled, carrying the saved value
inb = re.search(r'<div class="nl-tune" id="net-tune">(.*?)id="net-egress-tune"', traf, re.S)
# "disabled" as an ATTRIBUTE inside a tag, not the word appearing in a comment
inb_body = re.sub(r'<!--.*?-->', '', inb.group(1), flags=re.S) if inb else ''
inb_disabled = re.findall(r'<(?:input|button|select)[^>]*disabled[^>]*>', inb_body)
check("traffic: the inbound block is untouched and enabled", inb is not None and not inb_disabled, inb_disabled)
check("traffic: the inbound number still comes from PHP",
      re.search(r'id="net-pps-input"[^>]*value="\d+"', traf) is not None)

print("\n%d failed" % fails)
sys.exit(1 if fails else 0)
