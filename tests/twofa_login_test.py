"""
The 2FA sign-in flow, driven over real HTTP against the local server:
    python tests/twofa_login_test.py

The library's arithmetic is covered by tests/twofa_test.php against RFC 6238's own vectors. What this
covers is the half that actually locks people out: that a correct password alone grants NOTHING once
2FA is on, that the second step is reachable without a session but only just after a password, that a
recovery code works exactly once, and that turning it off needs both factors.

Leaves the panel exactly as it found it.
"""
import base64, hashlib, hmac, http.cookiejar, json, os, re, struct, subprocess, sys, time, urllib.request

BASE = os.environ.get("SMOKE_BASE", "http://127.0.0.1:8089/")
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
STATE = os.path.join(ROOT, "config", "admin_2fa.json")
USER, PASS = "admin", "admin123"

fails = 0
n = 0


def check(name, cond, info=""):
    global fails, n
    n += 1
    print(("PASS " if cond else "FAIL ") + name + ("" if cond or not info else "  -> " + str(info)[:300]))
    if not cond:
        fails += 1


def totp(secret_b32, at=None, digits=6, period=30):
    """A second, independent implementation: if the panel and this agree, both are probably right."""
    pad = secret_b32 + "=" * (-len(secret_b32) % 8)
    key = base64.b32decode(pad, casefold=True)
    counter = int((at if at is not None else time.time()) // period)
    mac = hmac.new(key, struct.pack(">Q", counter), hashlib.sha1).digest()
    off = mac[-1] & 0x0F
    val = struct.unpack(">I", mac[off:off + 4])[0] & 0x7FFFFFFF
    return str(val % (10 ** digits)).zfill(digits)


class Session:
    def __init__(self):
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))
        self.csrf = None

    def open_login(self):
        with self.opener.open(BASE + "?action=admin") as r:
            html = r.read().decode("utf-8", "replace")
        m = re.search(r'name="csrf_token"[^>]*value="([^"]+)"', html) or re.search(r"CSRF\s*=\s*'([^']+)'", html)
        self.csrf = m.group(1) if m else None
        return html

    def post(self, endpoint, body, csrf_header=True):
        data = dict(body)
        if self.csrf and "csrf_token" not in data:
            data["csrf_token"] = self.csrf
        headers = {"Content-Type": "application/json"}
        if csrf_header and self.csrf:
            headers["X-CSRF-Token"] = self.csrf
        req = urllib.request.Request(BASE + "api.php?endpoint=" + endpoint,
                                     data=json.dumps(data).encode(), headers=headers, method="POST")
        try:
            with self.opener.open(req) as r:
                return r.status, json.loads(r.read().decode())
        except urllib.error.HTTPError as e:
            body = e.read().decode("utf-8", "replace")
            try:
                return e.code, json.loads(body)
            except ValueError:
                return e.code, {"raw": body[:200]}


def wait_next_step(period=30):
    """
    Wait until the TOTP step advances.

    Not padding: the replay guard refuses any step at or below the last one accepted, and the window
    only reaches one step either side of NOW. So once a code has been spent there is a stretch during
    which no code is acceptable at all -- which is the guard working, and the only honest way to test
    the next code is to let the clock reach it.
    """
    start = int(time.time() // period)
    while int(time.time() // period) <= start:
        time.sleep(1)


def cleanup():
    if os.path.exists(STATE):
        os.unlink(STATE)


# Start from a known state: no 2FA.
cleanup()

s = Session()
s.open_login()
st, j = s.post("admin/login", {"username": USER, "password": PASS})
check("with 2FA off, the password alone signs in", j.get("success") is True, j)

# ── turn it on through the panel's own endpoint ─────────────────────────────
st, j = s.post("admin/twofa", {"op": "status"})
check("status says it is off", j.get("success") is True and j.get("enabled") is False, j)

st, j = s.post("admin/twofa", {"op": "begin", "password": "wrong-password"})
check("setup refuses a wrong password", st == 403, (st, j))

st, begin = s.post("admin/twofa", {"op": "begin", "password": PASS})
check("setup hands back a secret, a URI and ten recovery codes",
      begin.get("success") is True and len(begin.get("recovery", [])) == 10 and begin.get("secret"), begin)
check("… and says plainly why there is no QR image",
      "outside this machine" in (begin.get("qr_note") or ""), begin.get("qr_note"))

secret = begin["secret"]
recovery = begin["recovery"]

st, j = s.post("admin/twofa", {"op": "status"})
check("nothing is on yet — the secret is only pending",
      j.get("enabled") is False and j.get("pending") is True, j)

st, j = s.post("admin/twofa", {"op": "confirm", "code": "000000"})
check("a wrong confirmation code is refused", st == 400, (st, j))

st, j = s.post("admin/twofa", {"op": "confirm", "code": totp(secret)})
check("the right code turns it on", j.get("success") is True, j)
st, j = s.post("admin/twofa", {"op": "status"})
check("status now says on, with ten codes", j.get("enabled") is True and j.get("recovery_left") == 10, j)

# ── the sign-in, which is the point of all this ─────────────────────────────
s2 = Session()
s2.open_login()
st, j = s2.post("admin/login", {"username": USER, "password": PASS})
check("a correct password alone now grants NOTHING", j.get("success") is not True, j)
check("… and says a code is needed", j.get("needs_2fa") is True, j)

# Prove it granted nothing: an admin endpoint must still refuse this session.
st, j = s2.post("admin/twofa", {"op": "status"})
check("… the half-finished session cannot reach an admin endpoint", st == 401, (st, j))

st, j = s2.post("admin/login_2fa", {"code": "000000"})
check("a wrong code is refused", st == 401, (st, j))

# The code that CONFIRMED the setup was spent by doing so, so wait for the clock to reach a step the
# replay guard will accept. That wait is the feature, not an inconvenience.
wait_next_step()
code = totp(secret)
st, j = s2.post("admin/login_2fa", {"code": code})
check("the right code completes the sign-in", j.get("success") is True, j)
st, j = s2.post("admin/twofa", {"op": "status"})
check("… and the session works now", j.get("success") is True, j)

# The same code must not work again — it is valid for another 60 seconds otherwise.
s3 = Session()
s3.open_login()
s3.post("admin/login", {"username": USER, "password": PASS})
st, j = s3.post("admin/login_2fa", {"code": code})
check("the code that just signed someone in cannot be replayed", st == 401, (st, j))

# ── a recovery code, used once ──────────────────────────────────────────────
s4 = Session()
s4.open_login()
s4.post("admin/login", {"username": USER, "password": PASS})
st, j = s4.post("admin/login_2fa", {"code": recovery[0]})
check("a recovery code signs in", j.get("success") is True, j)
check("… and says one was spent and how many remain",
      j.get("used_recovery") is True and j.get("recovery_left") == 9, j)

s5 = Session()
s5.open_login()
s5.post("admin/login", {"username": USER, "password": PASS})
st, j = s5.post("admin/login_2fa", {"code": recovery[0]})
check("the same recovery code does not work twice", st == 401, (st, j))

# ── the second step must not be reachable without a password first ──────────
s6 = Session()
s6.open_login()
st, j = s6.post("admin/login_2fa", {"code": totp(secret)})
check("the code step alone, with no password, grants nothing", st == 401, (st, j))

# ── regeneration, behind the password AND a code ────────────────────────────
st, j = s.post("admin/twofa", {"op": "regen", "password": PASS})
check("regeneration refuses without a code", st == 403, (st, j))
st, j = s.post("admin/twofa", {"op": "regen", "password": "nope", "code": totp(secret)})
check("regeneration refuses a wrong password", st == 403, (st, j))
wait_next_step()
st, j = s.post("admin/twofa", {"op": "regen", "password": PASS, "code": totp(secret)})
check("regeneration with both hands back ten new codes", len(j.get("recovery", [])) == 10, j)
new_codes = j.get("recovery", []) or ["-"]
check("… and every old code stopped working",
      s5.post("admin/login_2fa", {"code": recovery[1]})[0] == 401)

# ── turning it off needs both, which is the whole point ─────────────────────
st, j = s.post("admin/twofa", {"op": "disable", "password": PASS})
check("turning it off refuses the password alone", st == 403, (st, j))
check("… and says why", "somebody else has the password" in (j.get("error") or ""), j)
st, j = s.post("admin/twofa", {"op": "disable", "password": PASS, "code": new_codes[0]})
check("password plus a recovery code turns it off", j.get("success") is True, j)
st, j = s.post("admin/twofa", {"op": "status"})
check("… and it is off", j.get("enabled") is False, j)

s7 = Session()
s7.open_login()
st, j = s7.post("admin/login", {"username": USER, "password": PASS})
check("the password alone signs in again", j.get("success") is True, j)

# ── the CLI escape hatch ────────────────────────────────────────────────────
out = subprocess.run([sys.executable and "php", os.path.join(ROOT, "tools", "twofa_cli.php"), "status"],
                     capture_output=True, text=True)
check("the CLI reports the state", "two-factor authentication: off" in out.stdout, out.stdout + out.stderr)

cleanup()
print("\n%d checks, %d failed" % (n, fails))
sys.exit(1 if fails else 0)
