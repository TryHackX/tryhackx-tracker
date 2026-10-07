#!/usr/bin/env python3
"""The metadata worker's own decisions, without libtorrent and without a database (1.74.0):

  * SRV-1 — which trackers a magnet may make this server announce to (only public addresses, every
    address a name resolves to; a UDP tracker pinned to the address checked), no web seeds, no private
    direct peers; the operator's own trackers always, as written;
  * PERF-16 — the working concurrency following the yield, a fetch that found nobody given up after
    NO_PEERS_SECONDS, the "any" lane resting after it came back empty, what the heartbeat reports.

    python tests/worker_test.py

libtorrent and pymysql are stubbed the way tests/meta_order_test.py does it: neither has anything to do
with these decisions, and the alternative is a suite that only runs on the production machine.
"""
import json, os, sys, tempfile, time, types

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'worker'))
for name, attrs in (('libtorrent', {'__version__': '0.0-stub'}), ('pymysql', {})):
    if name not in sys.modules:
        mod = types.ModuleType(name)
        for k, v in attrs.items():
            setattr(mod, k, v)
        if name == 'pymysql':
            mod.cursors = types.SimpleNamespace(DictCursor=object)
            mod.err = types.SimpleNamespace(OperationalError=Exception, InterfaceError=Exception)
        sys.modules[name] = mod

import worker as W  # noqa: E402

fails = 0
n = 0


def check(name, ok, info=''):
    global fails, n
    n += 1
    print(('PASS ' if ok else 'FAIL ') + name + ('' if ok or info == '' else '  -> ' + str(info)[:300]))
    if not ok:
        fails += 1


# ── SRV-1: which addresses are "the public internet" ─────────────────────────
public = ['93.184.216.34', '1.1.1.1', '8.8.8.8', '2606:4700:4700::1111', '2a00:1450:4001:81c::200e']
private = ['127.0.0.1', '127.8.9.10', '10.0.0.5', '172.16.0.1', '172.31.255.254', '192.168.1.1', '169.254.169.254',
           '100.64.0.1', '0.0.0.0', '255.255.255.255', '224.0.0.1', '192.0.2.10', '198.51.100.7', '203.0.113.5',
           '::1', '::', 'fe80::1', 'fc00::1', 'fd00:ec2::254', 'ff02::1', '::ffff:127.0.0.1', '::ffff:10.1.2.3',
           '2002:7f00:1::1', '2002:c0a8:101::1', 'not-an-address', '']
check('public addresses are public: ' + ', '.join(public), all(W.address_is_public(a) for a in public),
      [a for a in public if not W.address_is_public(a)])
check('loopback, private, link-local (the metadata address), shared, unspecified, broadcast, multicast, documentation, '
      'unique-local, IPv4 inside IPv6 (mapped, 6to4) and nonsense are not',
      not any(W.address_is_public(a) for a in private), [a for a in private if W.address_is_public(a)])
check('… a zone id or brackets do not change the answer',
      not W.address_is_public('fe80::1%eth0') and not W.address_is_public('[::1]') and W.address_is_public('[2606:4700:4700::1111]'))

# ── SRV-1: one tracker named by a magnet ─────────────────────────────────────
DNS = {
    'tracker.example.org': ['93.184.216.34'],
    'v6.example.org': ['2606:4700:4700::1111'],
    'both.example.org': ['93.184.216.34', '2606:4700:4700::1111'],
    'localhost': ['127.0.0.1', '::1'],
    'rebind.example.org': ['93.184.216.34', '10.0.0.7'],        # one public answer is not enough
    'metadata.example.org': ['169.254.169.254'],
    '0x7f.1': ['127.0.0.1'],                                      # what libc makes of these forms
    '2130706433': ['127.0.0.1'],
}
asked = []


def fake_resolve(host, port):
    asked.append((host, port))
    return list(DNS.get(host, []))


ok, url = W.tracker_allowed('http://tracker.example.org/announce', fake_resolve)
check('an http tracker on a public address is used, as written (its name: virtual hosts, TLS)',
      ok and url == 'http://tracker.example.org/announce', (ok, url))
check('… and its name was resolved, on the scheme\'s port', ('tracker.example.org', 80) in asked, asked)
ok, url = W.tracker_allowed('https://tracker.example.org:8443/announce?passkey=x', fake_resolve)
check('https with its own port and query: used as written', ok and url == 'https://tracker.example.org:8443/announce?passkey=x', (ok, url))
ok, url = W.tracker_allowed('udp://tracker.example.org:6969/announce', fake_resolve)
check('a udp tracker is PINNED to the address that was checked (no second lookup to rebind)',
      ok and url == 'udp://93.184.216.34:6969/announce', (ok, url))
ok, url = W.tracker_allowed('udp://v6.example.org:1337/announce', fake_resolve)
check('… an IPv6 one in brackets', ok and url == 'udp://[2606:4700:4700::1111]:1337/announce', (ok, url))
ok, url = W.tracker_allowed('udp://93.184.216.34:6969/announce', fake_resolve)
check('… and a udp tracker written as an address stays as it is', ok and url == 'udp://93.184.216.34:6969/announce', (ok, url))

refused = {
    'the cloud metadata endpoint': 'http://169.254.169.254/latest/meta-data/',
    'loopback by address': 'http://127.0.0.1:8080/announce',
    'loopback, IPv6': 'http://[::1]:80/announce',
    'loopback, IPv4 inside IPv6': 'http://[::ffff:127.0.0.1]/announce',
    'a private address, udp': 'udp://10.0.0.5:6969/announce',
    'the LAN': 'http://192.168.1.1/announce',
    '172.16/12': 'http://172.16.0.1/announce',
    'shared address space': 'http://100.64.0.1/announce',
    'link-local IPv6': 'http://[fe80::1]/announce',
    'unique-local IPv6': 'http://[fc00::1]/announce',
    'the unspecified address': 'http://0.0.0.0/announce',
    'a name for loopback': 'http://localhost:6969/announce',
    'a name with one private answer among public ones': 'http://rebind.example.org/announce',
    'a name for the metadata address': 'udp://metadata.example.org:80/announce',
    'a hex shorthand libc reads as loopback': 'http://0x7f.1/announce',
    'a decimal address libc reads as loopback': 'http://2130706433/announce',
    'a name that does not resolve': 'http://nowhere.invalid/announce',
    'a web socket': 'ws://tracker.example.org/announce',
    'a file': 'file:///etc/passwd',
    'gopher': 'gopher://tracker.example.org:70/_x',
    'credentials in the address': 'http://user:secret@tracker.example.org/announce',
    'udp without a port': 'udp://tracker.example.org/announce',
    'a port out of range': 'http://tracker.example.org:99999/announce',
    'no host': 'http:///announce',
    'nothing': '',
}
wrong = {}
for label, u in refused.items():
    ok, why = W.tracker_allowed(u, fake_resolve)
    if ok:
        wrong[label] = why
check('refused: ' + '; '.join(refused), wrong == {}, wrong)
ok, why = W.tracker_allowed('http://localhost:6969/announce', fake_resolve)
check('… and the reason names the address it found', not ok and '127.0.0.1' in why, why)

# ── SRV-1: a magnet's whole list ─────────────────────────────────────────────
own = ['udp://127.0.0.1:6969/announce', 'udp://tracker.opentrackr.org:1337/announce']
kept, dropped = W.filter_magnet_trackers(
    ['http://169.254.169.254/latest/meta-data/', 'udp://tracker.example.org:6969/announce',
     'udp://tracker.example.org:6969/announce', own[0], 'http://tracker.example.org/announce', ' '],
    own, fake_resolve)
check('the magnet keeps its public trackers, once each, pinned where udp',
      kept == ['udp://93.184.216.34:6969/announce', 'http://tracker.example.org/announce'], kept)
check('… drops the metadata address and says why', [d[0] for d in dropped] == ['http://169.254.169.254/latest/meta-data/']
      and 'non-public' in dropped[0][1], dropped)
check('… and leaves the operator\'s own trackers out of both lists (the caller adds them as written — a local one too)',
      own[0] not in kept and own[0] not in [d[0] for d in dropped])
check('direct peers: only public ones', W.public_peers([('93.184.216.34', 6881), ('10.0.0.1', 6881), ('::1', 1), ('2606:4700:4700::1111', 51413)])
      == [('93.184.216.34', 6881), ('2606:4700:4700::1111', 51413)])

# ── SRV-1: the real lookup — bounded in time, cached, failures too ───────────
real_gai = W.socket.getaddrinfo
calls = []


def slow_gai(host, port, *a, **k):
    calls.append(host)
    if host == 'slow.example.org':
        time.sleep(1.0)
    return [(2, 1, 6, '', ('93.184.216.34', port))]


W.socket.getaddrinfo = slow_gai
try:
    W._dns_cache.clear()
    t0 = time.time()
    got = W.resolve_host('slow.example.org', 80, timeout=0.2)
    took = time.time() - t0
    check('a name server slower than the timeout costs the timeout, not its own time, and counts as "does not resolve"',
          got == [] and took < 0.8, (got, round(took, 2)))
    calls.clear()
    check('… and the failure is remembered (no second lookup at once)', W.resolve_host('slow.example.org', 80, timeout=0.2) == [] and calls == [], calls)
    got1 = W.resolve_host('fast.example.org', 6969)
    got2 = W.resolve_host('fast.example.org', 6969)
    check('a good answer is cached: one lookup for two questions', got1 == ['93.184.216.34'] == got2 and calls.count('fast.example.org') == 1, calls)
finally:
    W.socket.getaddrinfo = real_gai
    W._dns_cache.clear()

# ── SRV-1: start() hands libtorrent only what passed ─────────────────────────
class P:
    pass


def parse_magnet(m):
    p = P()
    p.trackers = ['http://169.254.169.254/latest/meta-data/', 'udp://tracker.example.org:6969/announce']
    p.url_seeds = ['http://10.0.0.9/seed']
    p.peers = [('192.168.1.5', 6881), ('93.184.216.34', 6881)]
    p.flags = 0
    p.save_path = None
    return p


added = []
W.lt.parse_magnet_uri = parse_magnet
W.lt.torrent_flags = types.SimpleNamespace(upload_mode=1, auto_managed=2, paused=4)
W._dns_cache[('tracker.example.org', 6969)] = (time.time() + 600, ['93.184.216.34'])
fake = types.SimpleNamespace(
    cfg=types.SimpleNamespace(trackers=list(own), tmp_dir=tempfile.gettempdir(), timeout=180),
    ses=types.SimpleNamespace(add_torrent=lambda p: added.append(p) or 'handle'),
    active={},
)
W.Worker.start(fake, {'token': 't1', 'info_hash': 'ab' * 20, 'magnet_link': 'magnet:?xt=urn:btih:' + 'ab' * 20,
                      '_q': {'table': 'whitelist'}})
p = added[0] if added else None
check('start(): libtorrent gets the public magnet tracker (pinned) and the operator\'s own, never the metadata address',
      p is not None and p.trackers == ['udp://93.184.216.34:6969/announce'] + own, getattr(p, 'trackers', None))
check('… no web seeds, and only the public direct peer', p is not None and p.url_seeds == [] and p.peers == [('93.184.216.34', 6881)],
      (getattr(p, 'url_seeds', None), getattr(p, 'peers', None)))
check('… in upload mode, not paused, not auto-managed (as before)', p is not None and p.flags & 1 and not p.flags & 2 and not p.flags & 4, getattr(p, 'flags', None))
W._dns_cache.clear()

# ── PERF-16: the working concurrency follows the yield ───────────────────────
now = 1_000_000.0


def outcomes(total, good, age=60):
    return [(now - age, i < good) for i in range(total)]


check('fewer than ADAPT_MIN_OUTCOMES fetches say nothing yet: the configured number runs',
      W.adaptive_concurrency(32, outcomes(W.ADAPT_MIN_OUTCOMES - 1, 0), now) == 32)
check('a yield at or above ADAPT_LOW keeps the configured number', W.adaptive_concurrency(32, outcomes(100, 10), now) == 32)
check('below ADAPT_LOW it halves', W.adaptive_concurrency(32, outcomes(100, 5), now) == 16)
check('below ADAPT_VERY_LOW it quarters', W.adaptive_concurrency(32, outcomes(100, 1), now) == 8)
check('… never under min(configured, 4)', W.adaptive_concurrency(6, outcomes(100, 0), now) == 4 and W.adaptive_concurrency(3, outcomes(100, 0), now) == 3)
check('a configured 2 or 1 has nothing to give back', W.adaptive_concurrency(2, outcomes(100, 0), now) == 2 and W.adaptive_concurrency(1, outcomes(100, 0), now) == 1)
check('fetches older than ADAPT_MAX_AGE are forgotten (an old drought does not hold the number down)',
      W.adaptive_concurrency(32, outcomes(100, 0, age=W.ADAPT_MAX_AGE + 5), now) == 32)

check('a fetch with nobody found after NO_PEERS_SECONDS is given up', W.gave_up_without_peers(0, W.NO_PEERS_SECONDS, 0, 0))
check('… not a second earlier', not W.gave_up_without_peers(0, W.NO_PEERS_SECONDS - 1, 0, 0))
check('… not while a peer is connected, nor while one is known and being tried',
      not W.gave_up_without_peers(0, 999, 0, 1) and not W.gave_up_without_peers(0, 999, 3, 0))

# ── PERF-16: the "any" lane rests after coming back empty ────────────────────
IDX = {'table': 'index_hashes', 'orderable': True}
lanes = []


def claim_from(q, sel, lane, result=None):
    lanes.append(lane)
    return result


wk = types.SimpleNamespace(_any_empty_at={}, claim_targets=lambda: [(IDX, 'seeders')])
wk.any_lane_due = lambda q, now=None: W.Worker.any_lane_due(wk, q, now)
wk.claim_from = lambda q, sel, lane: claim_from(q, sel, lane)
W.Worker.claim(wk)
first = list(lanes)
lanes.clear()
W.Worker.claim(wk)
check('both lanes empty: "any" is asked once, then rests for ANY_LANE_EVERY',
      first == ['priority', 'bulk', 'any'] and lanes == ['priority', 'bulk'], (first, lanes))
check('… and is due again after it', W.Worker.any_lane_due(wk, IDX, time.time() + W.ANY_LANE_EVERY + 1))
wk._any_empty_at = {}
wk.claim_from = lambda q, sel, lane: claim_from(q, sel, lane, {'info_hash': 'x'} if lane == 'any' else None)
lanes.clear()
row = W.Worker.claim(wk)
W.Worker.claim(wk)
check('a lane that FOUND a row is not rested: the next claim asks it again', row == {'info_hash': 'x'} and lanes.count('any') == 2, lanes)
check('the lanes themselves are unchanged (tests/meta_order_test.py holds their SQL)', W.CLAIM_LANES == ('priority', 'bulk', 'any'))

# ── PERF-16: the heartbeat says both numbers, and finish() feeds the yield ───
hb = os.path.join(tempfile.mkdtemp(), 'heartbeat')
import collections  # noqa: E402
hw = types.SimpleNamespace(
    cfg=types.SimpleNamespace(heartbeat=hb, concurrency=4, max_files=5000),
    _outcomes=collections.deque([(time.time(), i < 1) for i in range(100)], maxlen=W.ADAPT_WINDOW),
    _working=None, active={}, _order_mode='oldest', _order_shares={},
)
hw.effective_concurrency = lambda: 32
hw.effective_max_files = lambda: 5000
hw.working_concurrency = lambda now=None: W.Worker.working_concurrency(hw, now)
hw.yield_pct = lambda now=None: W.Worker.yield_pct(hw, now)
W.Worker.heartbeat(hw)
with open(hb, encoding='utf-8') as fh:
    beat = json.load(fh)
check('the heartbeat keeps `concurrency` = what was asked (the panel compares it with the setting)…',
      beat.get('concurrency') == 32, beat)
check('… and adds what runs now, the yield it follows and over how many fetches',
      beat.get('concurrency_now') == 8 and beat.get('yield_pct') == 1.0 and beat.get('outcomes') == 100, beat)

fw = types.SimpleNamespace(
    active={'tok': {'row': {'id': 7, 'info_hash': 'cd' * 20, '_q': {'table': 'whitelist', 'key_col': 'id', 'files': 'whitelist_files', 'files_fk': 'whitelist_id'}},
                    'handle': 'h', 'started': time.time() - 70}},
    ses=types.SimpleNamespace(remove_torrent=lambda *a: None),
    db=types.SimpleNamespace(query=lambda *a, **k: 1),
    cfg=types.SimpleNamespace(index_table='index_hashes', index_keep_files=True),
    _outcomes=collections.deque(maxlen=W.ADAPT_WINDOW),
)
W.lt.options_t = types.SimpleNamespace(delete_files=1)
W.Worker.finish(fw, 'tok', False, None, 'no peers within 60 s')
check('a fetch given up counts against the yield', len(fw._outcomes) == 1 and fw._outcomes[0][1] is False, list(fw._outcomes))

print()
print('%d checks, %d failed' % (n, fails))
sys.exit(1 if fails else 0)
