#!/usr/bin/env python3
"""Example client for the tracker's server-to-server whitelist API (Python 3, stdlib only).

  export TRACKER_API_URL=https://tracker.example.org
  export TRACKER_API_KEY=<key_id>.<secret>          # shown once when the client is created in the admin panel
  python3 api_client_example.py ping
  python3 api_client_example.py submit magnet:?xt=urn:btih:... 0123456789abcdef0123456789abcdef01234567

WARNING: a wrong key bans your IP for `api_ban_days` (30 by default) unless the IP is in the
admin's "API ban exempt IPs" list. Test from an exempt IP first.
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
        "User-Agent": "tracker-api-example/1.0",
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
    if len(sys.argv) < 2 or sys.argv[1] not in ("ping", "submit"):
        print(__doc__); return 1
    if sys.argv[1] == "ping":
        status, js = call("v1/whitelist/ping")
    else:
        items = []
        for tok in sys.argv[2:]:
            items.append({"magnet": tok} if tok.startswith("magnet:") else {"hash": tok})
        status, js = call("v1/whitelist/submit", "POST", {"items": items, "source": "api"})
    print(status, json.dumps(js, indent=2))
    return 0 if status == 200 else 1


if __name__ == "__main__":
    sys.exit(main())
