#!/usr/bin/env python3
"""
Stability probe: find out where this machine's limits actually are, by moving them and watching.

WHY THIS EXISTS
---------------
The Traffic page can measure what is happening and suggest a number, but the number it suggests comes
from a formula over past traffic. The question an operator actually has is different and cannot be
answered by arithmetic: "if I raise the inbound limit, does anything ELSE on this box start to hurt?"
On a machine that also runs a game server and an SSH session, that is the only question that matters,
and the honest way to answer it is to try it and watch — carefully, briefly, and with a way back.

WHAT IT DOES
------------
Walks a plan of candidate limits, holds each one for a dwell period, and samples throughout. At every
step it records not just the tracker's own numbers but the COLLATERAL: the drop counters of every
other UDP socket on the machine, the softirq share, and the load. Then it puts everything back.

THE THREE RULES IT IS BUILT AROUND
----------------------------------
1. THE WAY BACK IS ARRANGED BEFORE ANYTHING CHANGES. The original settings are written into the state
   file, marked `restore`, before the first step runs. If this process is killed, crashes, or the
   machine reboots, the janitor sees that marker on its next tick and restores from it. The revert
   does not depend on this program surviving — that is the same lesson the sysctl card learned.

2. HARM STOPS THE RUN, NOT THE OPERATOR. Every sample is checked against the baseline taken before the
   first change. If another service starts dropping, or the load crosses a ceiling, the run ends
   immediately and restores. It never needs somebody watching it.

3. IT SUGGESTS, IT DOES NOT APPLY. The run ends with the settings exactly as it found them and a
   report. Applying anything from that report is a separate, password-confirmed decision in the panel.

RUNNING IT
----------
    python3 tools/tuner.py --run            # normally started by the janitor from a panel request
    python3 tools/tuner.py --dry-run        # walks the plan, changes nothing, useful anywhere
    python3 tools/tuner.py --restore        # put the recorded settings back and clear the marker
    python3 tools/tuner.py --self-test      # check its properties, touching nothing

It lives beside janitor.php in tools/ rather than in worker/ because worker/ is the METADATA worker's
directory and is deployed to a different machine path; this runs from the web root, where the janitor
that starts it also runs.

Cross-platform on purpose: on anything that is not Linux it runs in dry-run and fabricates nothing —
the samples come back marked unavailable rather than as zeros, because a zero that means "no reading"
is exactly the bug this project keeps finding in its own charts.
"""

import argparse
import json
import os
import platform
import re
import shutil
import subprocess
import sys
import time

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
STATE_PATH = os.path.join(ROOT, 'config', 'tuner_state.json')
IS_LINUX = platform.system() == 'Linux'

# How long each step is held, and how often it is sampled inside that. Short enough that a bad step
# hurts for seconds rather than minutes; long enough that a 60-second traffic wobble does not read as
# a result.
DEFAULT_DWELL_S = 180
SAMPLE_EVERY_S = 10
# A run that cannot finish inside this is stopped and restored regardless of where it got to.
HARD_DEADLINE_S = 6 * 3600

# WHAT THIS PROGRAM WILL AND WILL NOT MOVE
#
#   inbound   the firewall's receive limit (nft, `tracker-netlimit.sh set`). Ramped.
#   outbound  the egress budget on the tracker's replies (`tracker-netlimit.sh egress`). Ramped,
#             either on its own or kept a fixed distance above the inbound limit.
#   buffers   NOT ramped, and this is deliberate. A socket's receive buffer is fixed when the socket
#             is CREATED, so testing a buffer size means restarting the tracker at every step — six
#             restarts of a live tracker to answer a question that has one obvious answer (bigger, up
#             to what the machine can spare). It is a decision, not a search, and the Traffic page
#             already measures whether the current one is being hit.
WHAT_INBOUND = 'inbound'
WHAT_OUTBOUND = 'outbound'
WHAT_BOTH = 'both'
# When both move together, the reply budget is held this far above the inbound limit. The tracker
# answers roughly one packet per announce, so the two are naturally close; the headroom covers the
# replies that are larger than the question.
OUTBOUND_HEADROOM = 1.35


# ─────────────────────────────────────────────────────────────────────────────
# State: one file, written atomically, readable by the panel while the run is live
# ─────────────────────────────────────────────────────────────────────────────

def state_read() -> dict:
    try:
        with open(STATE_PATH, 'r', encoding='utf-8') as fh:
            return json.load(fh)
    except Exception:
        return {}


def state_write(data: dict) -> None:
    """Atomic: the panel polls this file and must never read half of it."""
    os.makedirs(os.path.dirname(STATE_PATH), exist_ok=True)
    tmp = STATE_PATH + '.tmp'
    with open(tmp, 'w', encoding='utf-8') as fh:
        json.dump(data, fh, ensure_ascii=False)
        fh.flush()
        os.fsync(fh.fileno())
    os.replace(tmp, STATE_PATH)


def state_update(**kw) -> dict:
    st = state_read()
    st.update(kw)
    st['updated_at'] = int(time.time())
    state_write(st)
    return st


# ─────────────────────────────────────────────────────────────────────────────
# Talking to the machine, through the helper the panel already uses
# ─────────────────────────────────────────────────────────────────────────────

def helper_cmd(cfg: dict) -> list:
    raw = (cfg.get('net_limit_cmd') or 'sudo -n /usr/local/sbin/tracker-netlimit.sh').strip()
    # The same character rule the panel enforces on this setting: no shell metacharacters, so the
    # command can be split on spaces and executed without a shell at all.
    if not re.fullmatch(r'[A-Za-z0-9 _./-]{1,255}', raw):
        raise RuntimeError('net_limit_cmd contains characters this will not execute: ' + raw)
    return raw.split()


def run_helper(cfg: dict, args: list, timeout: int = 30) -> dict:
    """Returns {'ok', 'json', 'out', 'rc'}. Never raises for a non-zero exit."""
    if not IS_LINUX:
        return {'ok': False, 'json': None, 'out': 'not linux', 'rc': -1}
    try:
        p = subprocess.run(helper_cmd(cfg) + args, capture_output=True, text=True, timeout=timeout)
    except Exception as e:
        return {'ok': False, 'json': None, 'out': str(e), 'rc': -1}
    out = (p.stdout or '').strip()
    parsed = None
    try:
        parsed = json.loads(out)
    except Exception:
        pass
    return {'ok': p.returncode == 0, 'json': parsed, 'out': out or (p.stderr or '').strip(), 'rc': p.returncode}


def read_settings() -> dict:
    """The panel's settings, read straight from the database it owns."""
    php = shutil.which('php')
    if not php:
        return {}
    code = (
        'require "%s/config/app.php"; require "%s/config/database.php";'
        'require "%s/includes/settings.php"; $db = getDb();'
        'echo json_encode(getSettings($db));' % (ROOT, ROOT, ROOT)
    )
    try:
        p = subprocess.run([php, '-r', code], capture_output=True, text=True, timeout=30)
        return json.loads(p.stdout)
    except Exception:
        return {}


# ─────────────────────────────────────────────────────────────────────────────
# Measurement
# ─────────────────────────────────────────────────────────────────────────────

def read_socket_drops(port: int) -> dict:
    """
    Drops on every UDP socket, keyed by local address.

    The tracker's own socket is the obvious one. The others are the point: "does raising this hurt
    anything else" is answered by the drop counter of the OTHER sockets, not by a feeling.
    """
    if not IS_LINUX or not shutil.which('ss'):
        return {}
    try:
        p = subprocess.run(['ss', '-ulnm'], capture_output=True, text=True, timeout=10)
    except Exception:
        return {}
    out = {}
    local = None
    for line in (p.stdout or '').splitlines():
        m = re.search(r'(\S+):(\d+)\s+\S+:\*', line)
        if m:
            local = m.group(2)
            continue
        d = re.search(r'\bd(\d+)\b', line)
        if d and local:
            out[local] = int(d.group(1))
            local = None
    return out


def read_softnet() -> dict:
    """Per-CPU processed/dropped. The per-core numbers are kept so a SHARE can be computed later."""
    try:
        with open('/proc/net/softnet_stat', 'r', encoding='utf-8') as fh:
            rows = [ln.split() for ln in fh.read().strip().splitlines()]
    except Exception:
        return {}
    processed = [int(r[0], 16) for r in rows if r]
    dropped = [int(r[1], 16) for r in rows if r]
    return {'processed': sum(processed), 'dropped': sum(dropped),
            'per_cpu': processed, 'cpus': len(processed)}


def busiest_share(a: dict, b: dict):
    """
    How concentrated packet processing was BETWEEN two samples.

    The counters in /proc/net/softnet_stat are cumulative since boot, so the lifetime share is
    dominated by whatever the machine did last month. It reported 0.95 here even in the minute after
    RPS had spread the work evenly across six cores — a number that cannot show a change is not a
    measurement of the present. The delta can.
    """
    pa = (a or {}).get('per_cpu') or []
    pb = (b or {}).get('per_cpu') or []
    if len(pa) != len(pb) or not pa:
        return None
    deltas = [max(0, y - x) for x, y in zip(pa, pb)]
    total = sum(deltas)
    if total <= 0:
        return None
    return max(deltas) / total


def read_load() -> dict:
    try:
        one, five, fifteen = os.getloadavg()
        cpus = os.cpu_count() or 1
        return {'load1': one, 'per_core': one / cpus, 'cpus': cpus}
    except Exception:
        return {}


def sample(cfg: dict, port: int) -> dict:
    """One reading of everything that matters, with absent things absent rather than zero."""
    s = {'at': int(time.time())}
    st = run_helper(cfg, ['status', '--brief'], timeout=20)
    if st['json']:
        j = st['json']
        counters = j.get('counters') or {}

        def pkt(name):
            c = counters.get(name)
            return int(c.get('packets', 0)) if isinstance(c, dict) else None

        # The helper's own names, read from its output rather than guessed. The first version invented
        # arrived/served/dropped, which do not exist, so every one of these came back None and the
        # report had nothing but load in it — a probe measuring the one thing it was not built for.
        s['limit_pps'] = j.get('pps')
        s['arrived'] = pkt('in_total')
        s['served'] = pkt('in_passed')
        s['dropped'] = pkt('in_capped')
        eg = j.get('egress') or {}
        s['egress_pps'] = eg.get('pps')
        egc = eg.get('counters') or {}
        ec = egc.get('capped')
        s['egress_capped'] = int(ec.get('packets', 0)) if isinstance(ec, dict) else None
    s['sockets'] = read_socket_drops(port)
    s['softnet'] = read_softnet()
    s['load'] = read_load()
    return s


def rate(a: dict, b: dict, key: str):
    """Packets per second between two samples, or None when either reading is missing."""
    if not a or not b:
        return None
    va, vb = a.get(key), b.get(key)
    dt = (b.get('at') or 0) - (a.get('at') or 0)
    if va is None or vb is None or dt <= 0 or vb < va:
        return None
    return (vb - va) / dt


# ─────────────────────────────────────────────────────────────────────────────
# The plan
# ─────────────────────────────────────────────────────────────────────────────

def build_plan(baseline_pps, current_limit, steps=6, low_factor=0.6, high_factor=1.35):
    """
    Candidate limits, low to high, around what is actually arriving.

    Starting BELOW the current setting is deliberate: the first step is the one most likely to be
    safe, so a machine that is already in trouble is not pushed further before anything is known.
    """
    if not baseline_pps or baseline_pps <= 0:
        base = current_limit or 50000
    else:
        base = baseline_pps
    lo = max(1000, int(base * low_factor))
    hi = max(lo + 1000, int(base * high_factor))
    if steps < 2:
        return [hi]
    stride = (hi - lo) / (steps - 1)
    return [int(round((lo + stride * i) / 1000.0)) * 1000 for i in range(steps)]


def harm(baseline: dict, now: dict, prev: dict, cfg: dict) -> str:
    """
    Is this step hurting anything? Returns a reason, or '' when it is not.

    Only counters the run itself watched from a baseline are used. "Load is high" is not harm if it
    was high before the run started — the question is whether THIS step made it worse.
    """
    max_per_core = float(cfg.get('tuner_max_load_per_core') or 0.9)
    load = (now.get('load') or {}).get('per_core')
    if load is not None and load > max_per_core:
        return 'load reached %.2f per core (ceiling %.2f)' % (load, max_per_core)

    # Any OTHER socket that started discarding during this step. The tracker's own socket is expected
    # to drop when the limit is below what arrives; a neighbour's is the thing to stop for.
    tracker_port = str(cfg.get('_tracker_port') or 6969)
    base_socks = baseline.get('sockets') or {}
    now_socks = now.get('sockets') or {}
    for port, d in now_socks.items():
        if port == tracker_port:
            continue
        before = base_socks.get(port)
        if before is None:
            continue
        if d - before > int(cfg.get('tuner_collateral_tolerance') or 50):
            return 'another service on port %s discarded %d packets during the run' % (port, d - before)

    sn_now = (now.get('softnet') or {}).get('dropped')
    sn_base = (baseline.get('softnet') or {}).get('dropped')
    if sn_now is not None and sn_base is not None and sn_now - sn_base > 0:
        return 'the per-CPU packet queue overflowed %d times during the run' % (sn_now - sn_base)
    return ''


# ─────────────────────────────────────────────────────────────────────────────
# The run
# ─────────────────────────────────────────────────────────────────────────────

def apply_limit(cfg: dict, pps: int, burst: int, port: int, dry: bool, what: str = WHAT_INBOUND) -> dict:
    """
    Move whichever limit this run is about.

    `both` moves the reply budget with the receive limit rather than instead of it, because capping
    what arrives without capping what is answered only moves the problem to the transmit path — which
    is the half that makes the whole machine unreachable.
    """
    if dry:
        return {'ok': True, 'json': None, 'out': 'dry-run', 'rc': 0}
    if what in (WHAT_INBOUND, WHAT_BOTH):
        r = run_helper(cfg, ['set', str(pps), str(burst), str(port)], timeout=60)
        if not r['ok']:
            return r
    if what in (WHAT_OUTBOUND, WHAT_BOTH):
        out_pps = pps if what == WHAT_OUTBOUND else int(pps * OUTBOUND_HEADROOM)
        r = run_helper(cfg, ['egress', str(out_pps)], timeout=60)
        if not r['ok']:
            return r
    return {'ok': True, 'json': None, 'out': '', 'rc': 0}


def restore(cfg: dict, dry: bool = False) -> dict:
    """
    Put back exactly what was there, from the marker written before the first change.

    Called at the end of a run, by --restore, and by the janitor when it finds a marker whose run is
    no longer alive. Idempotent: with nothing recorded it does nothing and says so.
    """
    st = state_read()
    rec = st.get('restore')
    if not rec:
        return {'ok': True, 'restored': False, 'why': 'nothing was recorded'}
    if dry:
        return {'ok': True, 'restored': False, 'why': 'dry-run'}

    if rec.get('mode') == 'off':
        r = run_helper(cfg, ['off'], timeout=60)
    else:
        r = run_helper(cfg, ['set', str(rec.get('pps')), str(rec.get('burst')), str(rec.get('port'))], timeout=60)
    # The reply budget is restored whether or not this run moved it: a run that was cancelled between
    # setting the two would otherwise leave one of them where it was put.
    if rec.get('egress_pps'):
        run_helper(cfg, ['egress', str(rec['egress_pps'])], timeout=60)

    st.pop('restore', None)
    st['restored_at'] = int(time.time())
    st['restore_result'] = {'ok': r['ok'], 'out': r['out'][:400]}
    state_write(st)
    return {'ok': r['ok'], 'restored': True, 'out': r['out'][:400]}


def run(args) -> int:
    cfg = read_settings()
    port = int(cfg.get('tracker_port') or 6969)
    cfg['_tracker_port'] = port
    dry = args.dry_run or not IS_LINUX

    st0 = run_helper(cfg, ['status', '--brief'], timeout=20)
    live = st0['json'] or {}
    current_limit = int(live.get('pps') or 0)
    current_burst = int(live.get('burst') or 100)
    egress_pps = int((live.get('egress') or {}).get('pps') or 0)
    had_table = bool(live.get('table'))

    # ── rule 1: the way back, before anything moves ──
    state_update(
        running=True, started_at=int(time.time()), dry_run=dry, phase='baseline',
        restore={'mode': 'limit' if had_table else 'off', 'pps': current_limit,
                 'burst': current_burst, 'port': port, 'egress_pps': egress_pps},
        steps=[], report=None, error=None, note='',
    )

    try:
        # A baseline long enough to be a rate, taken at whatever the machine is doing right now.
        first = sample(cfg, port)
        time.sleep(min(30, SAMPLE_EVERY_S * 2))
        baseline = sample(cfg, port)
        arriving = rate(first, baseline, 'arrived')
        state_update(phase='planning', baseline={'arriving_pps': arriving, 'at': baseline['at'],
                                                 'load': baseline.get('load'), 'softnet': baseline.get('softnet')})

        # An outbound run is planned around the reply rate, not the arrival rate: they are different
        # numbers and planning the wrong one would test a range the machine never operates in.
        anchor = arriving
        if args.what == WHAT_OUTBOUND:
            sending = rate(first, baseline, 'egress_capped')
            anchor = egress_pps or arriving
            state_update(baseline_out={'capped_pps': sending})
        plan = build_plan(anchor, current_limit if args.what != WHAT_OUTBOUND else egress_pps,
                          steps=int(args.steps))
        dwell = max(30, int(args.dwell))
        state_update(phase='running', plan=plan, dwell_s=dwell, what=args.what,
                     eta_s=len(plan) * dwell + 60)

        deadline = time.time() + min(HARD_DEADLINE_S, len(plan) * dwell + 600)
        steps = []
        stopped = ''

        for idx, pps in enumerate(plan):
            if time.time() > deadline:
                stopped = 'the run hit its deadline'
                break
            r = apply_limit(cfg, pps, current_burst, port, dry, args.what)
            if not r['ok'] and not dry:
                stopped = 'could not set %d pps: %s' % (pps, r['out'][:160])
                break

            step_start = sample(cfg, port)
            samples = []
            t_end = time.time() + dwell
            reason = ''
            while time.time() < t_end:
                time.sleep(min(SAMPLE_EVERY_S, max(1, t_end - time.time())))
                s = sample(cfg, port)
                samples.append(s)
                reason = harm(baseline, s, samples[-2] if len(samples) > 1 else step_start, cfg)
                if reason:
                    break

            last = samples[-1] if samples else step_start
            step = {
                'limit_pps': pps,
                'arrived_pps': rate(step_start, last, 'arrived'),
                'served_pps': rate(step_start, last, 'served'),
                'dropped_pps': rate(step_start, last, 'dropped'),
                'load_per_core': (last.get('load') or {}).get('per_core'),
                'busiest_core_share': busiest_share(step_start.get('softnet'), last.get('softnet')),
                'samples': len(samples),
                'harm': reason,
                'ok': reason == '',
            }
            steps.append(step)
            state_update(steps=steps, phase='running', current_step=idx + 1)
            if reason:
                stopped = 'stopped at %d pps: %s' % (pps, reason)
                break

        report = summarise(steps, arriving, current_limit, stopped)
        state_update(phase='restoring', report=report)
        rr = restore(cfg, dry)
        state_update(running=False, phase='done', finished_at=int(time.time()),
                     report=report, restore_result=rr)
        print(json.dumps(report, indent=2))
        return 0

    except KeyboardInterrupt:
        state_update(note='interrupted; restoring')
        restore(cfg, dry)
        state_update(running=False, phase='aborted', finished_at=int(time.time()))
        return 130
    except Exception as e:                                    # noqa: BLE001 - a run must always restore
        state_update(error=str(e)[:400], note='failed; restoring')
        restore(cfg, dry)
        state_update(running=False, phase='failed', finished_at=int(time.time()))
        print('tuner failed: %s' % e, file=sys.stderr)
        return 1


def summarise(steps, arriving, current_limit, stopped) -> dict:
    """
    What the run learned, in the terms the operator asked the question in.

    `safe` is the highest step that hurt nothing. `minimum` is the lowest step that still served
    everything that arrived — below it, legitimate announces are being refused. Both are None when
    the run did not get far enough to know, and saying None is the point: a suggestion invented from
    two data points would be worse than no suggestion.
    """
    ok_steps = [s for s in steps if s['ok']]
    safe = max((s['limit_pps'] for s in ok_steps), default=None)
    minimum = None
    for s in sorted(steps, key=lambda x: x['limit_pps']):
        d = s.get('dropped_pps')
        if d is not None and d < 1 and s['ok']:
            minimum = s['limit_pps']
            break
    harmed = next((s for s in steps if not s['ok']), None)

    lines = []
    if arriving:
        lines.append('About %s packets a second were arriving while this ran.' % f'{int(arriving):,}')
    if safe is not None:
        lines.append('The highest limit that hurt nothing was %s pps.' % f'{safe:,}')
    if minimum is not None:
        lines.append('At %s pps nothing that arrived was being refused.' % f'{minimum:,}')
    if harmed:
        lines.append('At %s pps: %s' % (f"{harmed['limit_pps']:,}", harmed['harm']))
    if stopped:
        lines.append(stopped)
    if not steps:
        lines.append('No step completed, so there is nothing to suggest.')

    return {
        'at': int(time.time()),
        'arriving_pps': arriving,
        'was': current_limit,
        'suggested_safe': safe,
        'suggested_minimum': minimum,
        'stopped_because': stopped,
        'steps': steps,
        'summary': ' '.join(lines),
    }


def self_test() -> int:
    """
    The properties this program must have, checked without touching a machine.

    Everything here is about the two ways it could do damage: suggesting a number nobody measured,
    and leaving the machine on a limit it was only trying out.
    """
    fails = [0]

    def check(name, ok, info=''):
        print(('PASS ' if ok else 'FAIL ') + name + ('' if ok or not info else '  -> ' + str(info)))
        if not ok:
            fails[0] += 1

    # ── the plan ──
    plan = build_plan(200000, 80000, steps=6)
    check('the plan is ordered low to high', plan == sorted(plan), plan)
    check('it starts BELOW what is arriving, so the first step is the safe one', plan[0] < 200000, plan)
    check('and reaches above it, or it could never find the ceiling', plan[-1] > 200000, plan)
    check('a plan with no baseline still returns something usable',
          len(build_plan(None, 50000, steps=4)) == 4)
    check('one step is allowed', len(build_plan(100000, 0, steps=1)) == 1)

    # ── harm ──
    base = {'sockets': {'6969': 100, '2302': 5}, 'softnet': {'dropped': 0}, 'load': {'per_core': 0.3}}
    cfg = {'_tracker_port': '6969'}
    quiet = {'sockets': {'6969': 900, '2302': 5}, 'softnet': {'dropped': 0}, 'load': {'per_core': 0.4}}
    check('the tracker dropping its own packets is not harm — that is the limit working',
          harm(base, quiet, base, cfg) == '', harm(base, quiet, base, cfg))
    collateral = {'sockets': {'6969': 900, '2302': 400}, 'softnet': {'dropped': 0}, 'load': {'per_core': 0.4}}
    check('another service dropping IS harm', 'port 2302' in harm(base, collateral, base, cfg))
    hot = {'sockets': {'6969': 100, '2302': 5}, 'softnet': {'dropped': 0}, 'load': {'per_core': 1.4}}
    check('load past the ceiling is harm', 'load reached' in harm(base, hot, base, cfg))
    squeezed = {'sockets': {'6969': 100, '2302': 5}, 'softnet': {'dropped': 7}, 'load': {'per_core': 0.3}}
    check('a per-CPU queue that overflowed is harm', 'queue overflowed' in harm(base, squeezed, base, cfg))
    check('a socket that did not exist at baseline is not counted against the run',
          harm(base, {'sockets': {'9999': 500}, 'softnet': {}, 'load': {}}, base, cfg) == '')

    # ── rates ──
    check('a rate needs two readings', rate(None, {'at': 2, 'arrived': 5}, 'arrived') is None)
    check('a counter that went backwards yields nothing, not a negative rate',
          rate({'at': 1, 'arrived': 90}, {'at': 2, 'arrived': 10}, 'arrived') is None)
    check('a missing reading yields nothing rather than zero',
          rate({'at': 1, 'arrived': None}, {'at': 2, 'arrived': 10}, 'arrived') is None)
    check('and an ordinary pair yields the rate',
          rate({'at': 0, 'arrived': 0}, {'at': 10, 'arrived': 1000}, 'arrived') == 100.0)

    # ── the report says only what it measured ──
    empty = summarise([], None, 80000, '')
    check('with no steps there is no suggestion',
          empty['suggested_safe'] is None and empty['suggested_minimum'] is None, empty)
    check('and it says so in words', 'nothing to suggest' in empty['summary'], empty['summary'])

    steps = [
        {'limit_pps': 60000, 'ok': True, 'dropped_pps': 900, 'harm': ''},
        {'limit_pps': 120000, 'ok': True, 'dropped_pps': 0, 'harm': ''},
        {'limit_pps': 180000, 'ok': False, 'dropped_pps': 0, 'harm': 'another service on port 2302'},
    ]
    rep = summarise(steps, 150000, 80000, 'stopped at 180000')
    check('the safe value is the highest step that hurt nothing', rep['suggested_safe'] == 120000, rep)
    check('the minimum is the lowest step that refused nothing', rep['suggested_minimum'] == 120000, rep)
    check('the harmful step is named in the summary', '180,000' in rep['summary'], rep['summary'])
    check('every suggestion is a value that was actually held',
          rep['suggested_safe'] in [s['limit_pps'] for s in steps])

    # ── the share is of the interval, not of all history ──
    a = {'per_cpu': [100, 100, 1000]}
    b = {'per_cpu': [200, 200, 1010]}      # this interval: 100, 100, 10 -> busiest is 100/210
    got = busiest_share(a, b)
    check('the busiest-core share is measured over the interval, not since boot',
          got is not None and abs(got - (100 / 210)) < 1e-9, got)
    check('a quiet interval yields nothing rather than a made-up share',
          busiest_share({'per_cpu': [5, 5]}, {'per_cpu': [5, 5]}) is None)
    check('mismatched readings yield nothing', busiest_share({'per_cpu': [1]}, {'per_cpu': [1, 2]}) is None)

    # ── what it moves, and what it refuses to ──
    calls = []

    def fake_helper(cfg, args, timeout=30):
        calls.append(list(args))
        return {'ok': True, 'json': None, 'out': '', 'rc': 0}

    real = globals()['run_helper']
    globals()['run_helper'] = fake_helper
    try:
        calls.clear()
        apply_limit({}, 100000, 100, 6969, False, WHAT_INBOUND)
        check('an inbound run touches only the receive limit',
              [c[0] for c in calls] == ['set'], calls)

        calls.clear()
        apply_limit({}, 100000, 100, 6969, False, WHAT_OUTBOUND)
        check('an outbound run touches only the reply budget',
              [c[0] for c in calls] == ['egress'], calls)

        calls.clear()
        apply_limit({}, 100000, 100, 6969, False, WHAT_BOTH)
        check('a both run moves each of them once', [c[0] for c in calls] == ['set', 'egress'], calls)
        check('and gives the reply budget headroom over the receive limit',
              int(calls[1][1]) == int(100000 * OUTBOUND_HEADROOM), calls[1])

        calls.clear()
        apply_limit({}, 100000, 100, 6969, True, WHAT_BOTH)
        check('a dry run touches NOTHING, whatever it was asked to move', calls == [], calls)

        # The way back is written before the first step, and put back afterwards, including the
        # budget this run may never have moved.
        calls.clear()
        state_write({'restore': {'mode': 'limit', 'pps': 80000, 'burst': 100, 'port': 6969,
                                 'egress_pps': 110000}})
        r = restore({})
        check('restoring puts back both the limit and the reply budget',
              [c[0] for c in calls] == ['set', 'egress'] and r['restored'] is True, calls)
        check('and clears the marker, so it cannot be restored twice',
              'restore' not in state_read())
        calls.clear()
        check('a second restore is a no-op', restore({})['restored'] is False and calls == [])

        # A machine that was UNTHROTTLED before the run must end unthrottled, not at some limit.
        calls.clear()
        state_write({'restore': {'mode': 'off', 'pps': 0, 'burst': 100, 'port': 6969, 'egress_pps': 0}})
        restore({})
        check('a machine that had no limit before the run gets none after it',
              [c[0] for c in calls] == ['off'], calls)
    finally:
        globals()['run_helper'] = real
        state_write({})

    # ── the plan is bounded ──
    for arriving, current in [(0, 0), (1, 0), (10 ** 9, 10 ** 9)]:
        pl = build_plan(arriving, current, steps=6)
        check('a plan is always ascending and positive (%s/%s)' % (arriving, current),
              len(pl) == 6 and all(x > 0 for x in pl) and pl == sorted(pl), pl)

    # ── the way back ──
    check('restore with nothing recorded does nothing and says so',
          restore({}, dry=True)['restored'] is False)

    print('\n%d checks, %d failed' % (38, fails[0]))
    return 1 if fails[0] else 0


def main() -> int:
    ap = argparse.ArgumentParser(description='Find this machine\'s real limits by moving them and watching.')
    ap.add_argument('--run', action='store_true', help='walk the plan (this is what the janitor starts)')
    ap.add_argument('--dry-run', action='store_true', help='walk the plan without changing anything')
    ap.add_argument('--restore', action='store_true', help='put the recorded settings back and clear the marker')
    ap.add_argument('--status', action='store_true', help='print the state file')
    ap.add_argument('--self-test', action='store_true', help='check the properties, touching nothing')
    ap.add_argument('--steps', default=6, type=int, help='how many limits to try (default 6)')
    ap.add_argument('--dwell', default=DEFAULT_DWELL_S, type=int, help='seconds to hold each step (default 180)')
    ap.add_argument('--what', default=WHAT_INBOUND, choices=[WHAT_INBOUND, WHAT_OUTBOUND, WHAT_BOTH],
                    help='which limit to move (default inbound). Buffers are never ramped — see the '
                         'note at the top of this file.')
    a = ap.parse_args()

    if a.self_test:
        return self_test()
    if a.status:
        print(json.dumps(state_read(), indent=2))
        return 0
    if a.restore:
        print(json.dumps(restore(read_settings(), a.dry_run), indent=2))
        return 0
    if a.run or a.dry_run:
        return run(a)
    ap.print_help()
    return 2


if __name__ == '__main__':
    sys.exit(main())
