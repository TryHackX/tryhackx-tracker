#!/bin/bash
# tracker-mode.sh — switch OpenTracker between the WHITE (whitelist) and BLACK (blacklist) build.
#
# Layout (see README "Whitelist mode" → build both binaries):
#   /home/tracker/opentracker.white  /home/tracker/opentracker.black       (binaries)
#   /home/tracker/opentracker.conf.white  /home/tracker/opentracker.conf.black  (configs)
#   /home/tracker/opentracker -> symlink to the active binary
#   /home/tracker/opentracker.conf -> symlink to the active config
# The systemd unit keeps running "/home/tracker/opentracker -f /home/tracker/opentracker.conf".
#
# usage: tracker-mode.sh [--all | --instance NAME] white|black|status
#        tracker-mode.sh --version
#
# Prints the active mode ("white" / "black") on stdout, exit 0 on success. Meant to be allowed for the
# web user via sudoers (NOPASSWD) so the tracker web app's schedule can call it:
#   www-data ALL=(root) NOPASSWD: /usr/local/sbin/tracker-mode.sh
#
# ── the output contract, which the panel depends on ──────────────────────────
#
# includes/schedule.php reads the LAST line of stdout and accepts it only when it is exactly "white"
# or "black". So per-instance detail is printed as "name: white" lines ABOVE it and the last line is
# always a bare aggregate word. Adding --all therefore needed no PHP change at all.
#
# ── what the aggregate word means when instances exist ───────────────────────
#
# It is the PRIMARY's mode, and that is deliberate. The database's single `tracker_mode` row gates
# whitelist regeneration and every public sentence about what the tracker accepts, and the primary is
# the port every client's announce URL actually names. Leaving that row disagreeing with the primary
# because a secondary instance failed would stop the whitelist being regenerated at all — for
# everyone, including the primary — which is far worse than the failure that caused it.
#
# An instance that could NOT be switched is STOPPED rather than left running. A tracker serving the
# blacklist build while the panel and the public pages say "whitelist only" is not a degraded state,
# it is a wrong one; a stopped instance is merely a smaller swarm. Those are reported as "warn:" lines
# so the panel can raise them, and they land in schedule_last_output either way.

set -u

VERSION=2

HOME_DIR="${TRACKER_HOME:-/home/tracker}"
UNIT="${PRIMARY_UNIT:-opentracker}"
BIN=$HOME_DIR/opentracker
CONF=$HOME_DIR/opentracker.conf
INSTANCES_DIR="${INSTANCES_DIR:-$HOME_DIR/instances}"
SYSTEMCTL="${SYSTEMCTL_BIN:-systemctl}"
# A seam for the test suite only: the real value is /proc and nothing configures it.
PROC_DIR="${PROC_DIR:-/proc}"

mode_of_path() {
  case "${1-}" in *.white) echo white ;; *.black) echo black ;; *) echo unknown ;; esac
}

current() { mode_of_path "$(readlink -f "$BIN" 2>/dev/null || true)"; }

# What the RUNNING process is executing, which is a different question from what the symlink says.
#
# The old fast path asked the symlink and skipped the restart when it already pointed the right way.
# With several instances the gap between flipping the symlink and the last restart is seconds, not
# milliseconds, so an interrupted switch — an overlapping tick, a reboot, a killed sudo — could leave
# the link saying "white" while every process still executed the black binary from its open inode.
# The next run then took the fast path, printed "white", restarted nothing, and the panel recorded
# whitelist mode as fact, permanently, on a tracker that was serving black.
running_mode() {
  local unit="$1" pid
  pid="$("$SYSTEMCTL" show "$unit" -p MainPID --value 2>/dev/null)"
  case "$pid" in ''|*[!0-9]*) echo stopped; return ;; esac
  [ "$pid" -gt 0 ] || { echo stopped; return; }
  mode_of_path "$(readlink -f "$PROC_DIR/$pid/exe" 2>/dev/null)"
}

instance_names() {
  [ -d "$INSTANCES_DIR" ] || return 0
  local d n
  for d in "$INSTANCES_DIR"/*/; do
    [ -d "$d" ] || continue
    n="$(basename "$d")"
    printf '%s' "$n" | grep -qE '^[a-z0-9][a-z0-9-]{0,15}$' || continue
    [ -f "$d/opentracker.conf.white" ] || [ -f "$d/opentracker.conf.black" ] || continue
    printf '%s\n' "$n"
  done
}

usage() { echo "usage: $0 [--all | --instance NAME] white|black|status" >&2; exit 1; }

SCOPE=primary
ONLY=""
while [ "$#" -gt 0 ]; do
  case "$1" in
    --all) SCOPE=all; shift ;;
    --instance) SCOPE=one; ONLY="${2:-}"; [ -n "$ONLY" ] || usage; shift 2 ;;
    --version) echo "tracker-mode.sh $VERSION"; exit 0 ;;
    --) shift; break ;;
    -*) usage ;;
    *) break ;;
  esac
done

ACTION="${1:-status}"

if [ "$ACTION" = "status" ]; then
  if [ "$SCOPE" = "primary" ]; then current; exit 0; fi
  # Detail above, aggregate last — the same contract as a switch.
  echo "primary: $(current) (running: $(running_mode "$UNIT"))"
  for n in $(instance_names); do
    echo "$n: $(mode_of_path "$(readlink -f "$INSTANCES_DIR/$n/opentracker.conf" 2>/dev/null)") (running: $(running_mode "opentracker@$n.service"))"
  done
  current
  exit 0
fi

case "$ACTION" in white|black) ;; *) usage ;; esac
want="$ACTION"

# ── phase 1: pre-flight everything, change nothing ───────────────────────────
#
# A switch that fails halfway leaves the swarm split across two builds. Anything systemic — a missing
# binary, a missing config — is found here, and the whole operation is refused having touched nothing.
for f in "$HOME_DIR/opentracker.$want" "$HOME_DIR/opentracker.conf.$want"; do
  [ -e "$f" ] || { echo "missing $f" >&2; exit 2; }
done
targets=""
if [ "$SCOPE" = "all" ]; then
  targets="$(instance_names)"
elif [ "$SCOPE" = "one" ]; then
  instance_names | grep -qx "$ONLY" || { echo "no such instance: $ONLY" >&2; exit 2; }
  targets="$ONLY"
fi
for n in $targets; do
  [ -f "$INSTANCES_DIR/$n/opentracker.conf.$want" ] || { echo "missing $INSTANCES_DIR/$n/opentracker.conf.$want" >&2; exit 2; }
done

# Nothing to do at all? Only when what is RUNNING already matches, everywhere.
if [ "$SCOPE" = "primary" ]; then
  if [ "$(running_mode "$UNIT")" = "$want" ] && "$SYSTEMCTL" is-active --quiet "$UNIT"; then echo "$want"; exit 0; fi
fi

# ── phase 2: the shared binary symlink, once ─────────────────────────────────
#
# Every instance executes this one path, so flipping it here is what makes it impossible for two
# instances to run different builds. It happens immediately before the first restart, not earlier.
if [ "$SCOPE" != "one" ]; then
  ln -sfn "opentracker.$want" "$BIN"
  ln -sfn "opentracker.conf.$want" "$CONF"
  chown -h tracker:tracker "$BIN" "$CONF" 2>/dev/null || true
fi

# ── phase 3: the extras, then the primary LAST ───────────────────────────────
#
# Primary last because it is the port every client announces to: it should be the last thing to
# bounce, and by then the extras are already serving the new build.
warned=0
for n in $targets; do
  ln -sfn "opentracker.conf.$want" "$INSTANCES_DIR/$n/opentracker.conf"
  chown -h tracker:tracker "$INSTANCES_DIR/$n/opentracker.conf" 2>/dev/null || true
  if "$SYSTEMCTL" restart "opentracker@$n.service" >/dev/null 2>&1; then
    sleep 1
    if [ "$(running_mode "opentracker@$n.service")" = "$want" ]; then
      echo "$n: $want"
      continue
    fi
  fi
  # It could not be switched, so it must not keep serving the old build.
  "$SYSTEMCTL" stop "opentracker@$n.service" >/dev/null 2>&1
  echo "warn: $n could not switch to $want and was stopped"
  warned=$(( warned + 1 ))
done

if [ "$SCOPE" != "one" ]; then
  "$SYSTEMCTL" restart "$UNIT" || { echo "restart failed" >&2; exit 3; }
  sleep 1
  "$SYSTEMCTL" is-active --quiet "$UNIT" || { echo "unit not active after restart" >&2; "$SYSTEMCTL" status "$UNIT" -n 5 --no-pager >&2; exit 4; }
  echo "primary: $(running_mode "$UNIT")"
fi

[ "$warned" = 0 ] || echo "warn: $warned instance(s) were stopped because they could not be switched"

# The aggregate, last, bare. When only one instance was touched the primary was not, so report what
# the primary is actually running rather than what was asked for.
current
exit 0
