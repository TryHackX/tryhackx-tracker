#!/usr/bin/env python3
"""Example client for the tracker's server-to-server API (Python 3, stdlib only).

  export TRACKER_API_URL=https://tracker.example.org
  export TRACKER_API_KEY=<key_id>.<secret>          # shown once when the client is created in the admin panel

  # whitelist scope (forum sync):
  python3 api_client_example.py ping
  python3 api_client_example.py submit magnet:?xt=urn:btih:... 0123456789abcdef0123456789abcdef01234567
  python3 api_client_example.py status 0123456789abcdef0123456789abcdef01234567

  # shop scope (selling a group — lookup, grant and revoke; a users key may do the same — see
  # README "Selling group access"). Send YOUR order id with every grant: a webhook that fires twice
  # then sells one month, not two, and a refund with the same id takes back exactly that order:
  python3 api_client_example.py user-lookup alice
  python3 api_client_example.py user-grant alice premium 1m SHOP-2026-000412 "order #412"
  python3 api_client_example.py user-revoke alice premium SHOP-2026-000412

  # users scope only (creating accounts; the sign-in bridge is v1/auth/*, see ?action=apidocs):
  python3 api_client_example.py user-provision alice alice@example.org premium 1m

  # federation scope (peer metadata exchange — normally worker/federation.py does this):
  python3 api_client_example.py fed-ping
  python3 api_client_example.py fed-export 0

The key's SCOPE (set at creation) decides which endpoints it may call: whitelist | abuse | users |
shop | federation | all. `premium` is the group a default install seeds for selling; the slug is the
operator's choice. A grant without a duration is PERMANENT.

WARNING: a malformed, unknown or wrong key bans your address for `api_ban_days` (30 by default)
unless it is in the admin's "API ban exempt IPs" list — and then the right key is refused from it
too. Test from an exempt IP first; never retry a 403 in a loop.
"""
import json, os, sys, urllib.request, urllib.error

BASE = os.environ.get("TRACKER_API_URL", "").rstrip("/")
KEY = os.environ.get("TRACKER_API_KEY", "")


def call(endpoint, method="GET", body=None):
    url = f"{BASE}/api.php?endpoint={endpoint}"
    data = json.dumps(body).encode() if body is not None else None
    req = urllib.request.Request(url, data=data, method=method, headers={
        "Authorization": f"Bearer {KEY}",
        "Content-Type": "application/json",
        "User-Agent": "tracker-api-example/1.6",
    })
    try:
        with urllib.request.urlopen(req, timeout=15) as r:
            return r.status, json.loads(r.read().decode() or "{}")
    except urllib.error.HTTPError as e:
        try:
            return e.code, json.loads(e.read().decode() or "{}")
        except Exception:
            return e.code, {}


def main():
    if not BASE or not KEY:
        sys.exit("set TRACKER_API_URL and TRACKER_API_KEY")
    cmd = sys.argv[1] if len(sys.argv) > 1 else ""
    a = sys.argv[2:]
    if cmd == "ping":
        status, js = call("v1/whitelist/ping")
    elif cmd == "submit" and a:
        items = [({"magnet": tok} if tok.startswith("magnet:") else {"hash": tok}) for tok in a]
        status, js = call("v1/whitelist/submit", "POST", {"items": items, "source": "api"})
    elif cmd == "user-lookup" and len(a) >= 1:
        status, js = call("v1/users/lookup", "POST", {"login": a[0]})
    elif cmd == "status" and a:
        status, js = call("v1/whitelist/status", "POST", {"items": a})
    elif cmd == "user-grant" and len(a) >= 2:
        body = {"login": a[0], "group": a[1], "duration": a[2] if len(a) > 2 else "permanent"}
        if len(a) > 3:
            body["order_id"] = a[3]
        if len(a) > 4:
            body["note"] = a[4]
        status, js = call("v1/users/grant", "POST", body)
    elif cmd == "user-revoke" and len(a) >= 2:
        body = {"login": a[0], "group": a[1]}
        if len(a) > 2:
            body["order_id"] = a[2]   # take back only that order's time; without it the whole membership goes
        status, js = call("v1/users/revoke", "POST", body)
    elif cmd == "user-provision" and len(a) >= 1:
        body = {"username": a[0], "email": a[1] if len(a) > 1 else ""}
        if len(a) > 2:
            body["group"] = a[2]
            body["duration"] = a[3] if len(a) > 3 else "permanent"
        status, js = call("v1/users/provision", "POST", body)
    elif cmd == "fed-ping":
        status, js = call("v1/federation/ping")
    elif cmd == "fed-export":
        status, js = call("v1/federation/export", "POST",
                          {"since": int(a[0]) if a else 0, "limit": 10, "files": False, "gzip": False})
    else:
        print(__doc__)
        return 1
    print(status, json.dumps(js, indent=2))
    return 0 if status == 200 else 1


if __name__ == "__main__":
    sys.exit(main())
