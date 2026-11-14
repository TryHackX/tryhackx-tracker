#!/bin/bash
# tracker-livesync.sh — the panel's narrow root helper for opentracker's live peer sync (E7).
#
# ── what livesync is, and what it is not ─────────────────────────────────────
#
# opentracker can gossip LIVE PEERS to other opentrackers: who is in which swarm, right now. It is
# not federation. Federation moves metadata (names, file lists) between panels over HTTPS with
# authentication; this moves the swarm itself between trackers, and has neither.
#
# "Neither" is the whole design constraint. The protocol has no authentication and no encryption:
# anything that can reach the port can inject peers into every swarm this tracker serves. So the
# helper REFUSES to arm unless the port is bound to a tunnel address. That is not a warning with an
# override — there is no override, because an override is the only feature anybody would ever
# regret adding here.
#
# ── what it writes ──────────────────────────────────────────────────────────
#
# One file:  /etc/systemd/system/opentracker.service.d/91-tracker-livesync.conf
#
# It has to override ExecStart, because opentracker takes livesync ONLY from the command line: its
# config parser knows listen.*, access.* and tracker.*, and nothing else (checked against the shipped
# binary, not assumed). Overriding somebody else's ExecStart is the most invasive thing the panel
# does anywhere, so:
#
#   * it is a SEPARATE drop-in from 90-tracker-panel.conf, and never touches that file;
#   * it records the ExecStart it was built from, and `status` reports when the unit's own has
#     changed underneath it — a stale copied command line is the failure mode of this technique and
#     it must be visible rather than mysterious;
#   * `revert` deletes that one file and the original command line is back.
#
# ── what it does NOT do ─────────────────────────────────────────────────────
#
# It does not configure WireGuard. Generating private keys and writing /etc/wireguard from a web
# panel would be a larger claim on the machine than this project makes anywhere else, and a tunnel
# is not something to be half-set-up by something that cannot see the other end. `check` says
# exactly what is missing and the panel prints the commands, the same as for sudoers.
#
# Actions:
#   status                          what is in force, and whether the tunnel really is one
#   check                           can this machine do it at all — for the panel's Test button
#   plan <bind_ip> <port> <peer>    what would change, without changing it
#   apply <bind_ip> <port> <peer>   write the drop-in and restart
#   revert                          delete the drop-in and restart
#
# sudoers:
#   www-data ALL=(root) NOPASSWD: /usr/local/sbin/tracker-livesync.sh
#
# Test hooks: OT_UNIT, OT_DROPIN_DIR, SYSTEMCTL_BIN, IP_BIN, SS_BIN, WG_BIN.
set -u

UNIT="${OT_UNIT:-opentracker}"
DROPIN_DIR="${OT_DROPIN_DIR:-/etc/systemd/system/${UNIT}.service.d}"
DROPIN="$DROPIN_DIR/91-tracker-livesync.conf"
SYSTEMCTL="${SYSTEMCTL_BIN:-}"
[ -n "$SYSTEMCTL" ] || SYSTEMCTL="$(command -v systemctl 2>/dev/null || echo /usr/bin/systemctl)"
IP_BIN="${IP_BIN:-}"
[ -n "$IP_BIN" ] || IP_BIN="$(command -v ip 2>/dev/null || echo /usr/sbin/ip)"
SS_BIN="${SS_BIN:-}"
[ -n "$SS_BIN" ] || SS_BIN="$(command -v ss 2>/dev/null || echo /usr/bin/ss)"
WG_BIN="${WG_BIN:-}"
[ -n "$WG_BIN" ] || WG_BIN="$(command -v wg 2>/dev/null || true)"

PORT_MIN=1024
PORT_MAX=65535

# ── JSON without jq — same discipline as the other helpers in this directory ─
jesc() {
    printf '%s' "${1-}" \
        | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g' -e 's/\t/\\t/g' -e 's/\r//g' \
        | sed -e ':a' -e 'N' -e '$!ba' -e 's/\n/\\n/g' \
        | tr -d '\000-\010\013\014\016-\037'
}
jstr() { printf '"%s"' "$(jesc "${1-}")"; }
jbool() { [ "${1:-0}" = "1" ] && printf 'true' || printf 'false'; }
fail() { printf '{"ok":false,"error":%s}\n' "$(jstr "$1")"; exit 1; }

need_root() { [ "$(id -u)" = "0" ] || fail "must run as root (via sudo)"; }

# ── validation ──────────────────────────────────────────────────────────────

valid_ipv4() {
    case "${1-}" in
        *[!0-9.]*|'') return 1 ;;
    esac
    local IFS=. a b c d extra
    read -r a b c d extra <<<"$1"
    [ -z "${extra:-}" ] || return 1
    for o in "$a" "$b" "$c" "$d"; do
        [ -n "$o" ] || return 1
        [ "$o" -ge 0 ] 2>/dev/null && [ "$o" -le 255 ] || return 1
    done
    return 0
}

valid_port() {
    case "${1-}" in ''|*[!0-9]*) return 1 ;; esac
    [ "$1" -ge "$PORT_MIN" ] && [ "$1" -le "$PORT_MAX" ]
}

# Is this address one of the private ranges a tunnel actually uses? A public address here would mean
# the sync port is on the open internet, which for a protocol with no authentication is the entire
# thing this helper exists to prevent.
is_private_v4() {
    local ip="$1" a b
    a="${ip%%.*}"
    b="${ip#*.}"; b="${b%%.*}"
    case "$a" in
        10) return 0 ;;
        127) return 0 ;;
        172) [ "$b" -ge 16 ] && [ "$b" -le 31 ] && return 0 ;;
        192) [ "$b" = "168" ] && return 0 ;;
        100) [ "$b" -ge 64 ] && [ "$b" -le 127 ] && return 0 ;;   # CGNAT, what Tailscale uses
    esac
    return 1
}

# Which interface carries this address, if any.
iface_for_ip() {
    "$IP_BIN" -o -4 addr show 2>/dev/null | awk -v want="$1" '{
        split($4, a, "/"); if (a[1] == want) { print $2; exit }
    }'
}

# Every WireGuard interface on the box (empty when the module or the tool is absent).
wg_ifaces() {
    "$IP_BIN" -o link show type wireguard 2>/dev/null | awk -F': ' '{print $2}' | awk '{print $1}'
}

is_tunnel_iface() {
    local want="$1" i
    for i in $(wg_ifaces); do [ "$i" = "$want" ] && return 0; done
    # A tunnel that is not WireGuard is still a tunnel. Anything point-to-point counts.
    "$IP_BIN" -o link show "$want" 2>/dev/null | grep -qE 'POINTOPOINT|wireguard|tun|tap' && return 0
    case "$want" in wg*|tun*|tap*|tail*) return 0 ;; esac
    return 1
}

# ── reading the unit ────────────────────────────────────────────────────────

# The ExecStart the unit would use WITHOUT our drop-in. Read by asking systemd for the whole
# ExecStart list and taking the last entry: our own override, when present, is that last entry, so
# the base is what came before it.
current_execstart() {
    "$SYSTEMCTL" show "$UNIT" -p ExecStart --value 2>/dev/null \
        | sed -n 's/.*argv\[\]=\([^;]*\);.*/\1/p' | sed -e 's/[[:space:]]*$//'
}

# What we recorded when we wrote the drop-in, so drift can be reported rather than discovered.
recorded_base() {
    [ -f "$DROPIN" ] || return 0
    sed -n 's/^# base-execstart: //p' "$DROPIN" | head -1
}

dropin_args() {
    [ -f "$DROPIN" ] || return 0
    sed -n 's/^# livesync-args: //p' "$DROPIN" | head -1
}

service_active() { "$SYSTEMCTL" is-active --quiet "$UNIT" 2>/dev/null && echo 1 || echo 0; }

# Is the sync port bound, and to what?
bound_to() {
    local port="$1"
    "$SS_BIN" -lnu 2>/dev/null | awk -v p=":$port" '$5 ~ p {print $5; exit}'
}

# ── status ──────────────────────────────────────────────────────────────────

action_status() {
    local base args bind port peer iface armed
    armed=0
    [ -f "$DROPIN" ] && armed=1
    args="$(dropin_args)"
    bind=""; port=""; peer=""
    if [ -n "$args" ]; then
        bind="$(printf '%s' "$args" | awk '{print $1}')"
        port="$(printf '%s' "$args" | awk '{print $2}')"
        peer="$(printf '%s' "$args" | awk '{print $3}')"
    fi
    base="$(current_execstart)"
    iface=""
    [ -n "$bind" ] && iface="$(iface_for_ip "$bind")"

    printf '{"ok":true'
    printf ',"unit":%s' "$(jstr "$UNIT")"
    printf ',"armed":%s' "$(jbool "$armed")"
    printf ',"dropin":%s' "$(jstr "$DROPIN")"
    printf ',"bind_ip":%s' "$(jstr "$bind")"
    printf ',"port":%s' "$(jstr "$port")"
    printf ',"peer":%s' "$(jstr "$peer")"
    printf ',"iface":%s' "$(jstr "$iface")"
    printf ',"iface_is_tunnel":%s' "$([ -n "$iface" ] && is_tunnel_iface "$iface" && echo true || echo false)"
    printf ',"service_active":%s' "$(jbool "$(service_active)")"
    printf ',"listening":%s' "$(jstr "$([ -n "$port" ] && bound_to "$port")")"
    printf ',"wg_present":%s' "$([ -n "$WG_BIN" ] && echo true || echo false)"
    printf ',"wg_ifaces":['
    local first=1 i
    for i in $(wg_ifaces); do [ $first = 1 ] || printf ','; printf '%s' "$(jstr "$i")"; first=0; done
    printf ']'
    # Drift: the base command line we copied is not the one the unit has any more. This is THE
    # failure mode of overriding somebody else's ExecStart, so it is reported as data, not guessed at.
    if [ "$armed" = "1" ]; then
        local rec
        rec="$(recorded_base)"
        printf ',"base_recorded":%s' "$(jstr "$rec")"
        printf ',"base_now":%s' "$(jstr "$base")"
        printf ',"base_drifted":%s' "$([ -n "$rec" ] && [ "$rec" != "$base" ] && echo true || echo false)"
    else
        printf ',"base_now":%s' "$(jstr "$base")"
        printf ',"base_drifted":false'
    fi
    printf '}\n'
}

# ── check (the Test button) ─────────────────────────────────────────────────

action_check() {
    local ok=1 notes="" wgn=0 i
    for i in $(wg_ifaces); do wgn=$((wgn + 1)); done

    printf '{"ok":true,"checks":['
    printf '{"name":"the helper is installed and running as root","ok":%s}' "$([ "$(id -u)" = "0" ] && echo true || echo false)"
    printf ',{"name":"systemctl is available","ok":%s}' "$([ -x "$SYSTEMCTL" ] && echo true || echo false)"

    local unit_ok
    "$SYSTEMCTL" cat "$UNIT" >/dev/null 2>&1 && unit_ok=true || unit_ok=false
    printf ',{"name":"the opentracker unit exists","ok":%s,"detail":%s}' "$unit_ok" "$(jstr "$UNIT")"
    [ "$unit_ok" = "true" ] || ok=0

    # Is livesync compiled into the binary that is actually running?
    #
    # This must PROBE the flag, not read the help text. opentracker prints one static usage string
    # regardless of what it was built with, so `-h` mentions "-s livesyncport" on a build that
    # rejects -s outright; the stats page carries a <livesync> section for the same reason. Both were
    # believed here once, and both were wrong: the binary on the machine this was written for was
    # built without -DWANT_SYNC_LIVE and refuses -s, while advertising it in two places.
    #
    # So: run it with -s and a port, for a moment, and see whether it takes the flag. A build without
    # livesync answers with its usage and exits.
    local binpath sync_ok probe
    binpath="$(current_execstart | awk '{print $1}')"
    sync_ok=false
    probe=""
    if [ -n "$binpath" ] && [ -x "$binpath" ]; then
        # A port nothing uses, an address that is always local, and a timeout: this either exits at
        # once with a usage message, or starts and is killed a second later. Neither disturbs the
        # running tracker, which has its own process and its own sockets.
        probe="$(timeout 2 "$binpath" -i 127.0.0.1 -p 65533 -P 65533 -s 65532 -u nobody 2>&1 | head -1)"
        case "$probe" in
            Usage*) sync_ok=false ;;
            *)      sync_ok=true ;;
        esac
    fi
    printf ',{"name":"this opentracker build has livesync compiled in","ok":%s,"detail":%s}' \
        "$sync_ok" "$(jstr "$([ "$sync_ok" = true ] \
            && echo "$binpath accepts -s" \
            || echo "$binpath REFUSES -s. The help text and the <livesync> section in /stats both mention livesync on builds that do not have it — this one does not. Rebuild opentracker with -DWANT_SYNC_LIVE in FEATURES.")")"
    [ "$sync_ok" = "true" ] || ok=0

    printf ',{"name":"the drop-in directory can be written","ok":%s,"detail":%s}' \
        "$([ -d "$DROPIN_DIR" ] || mkdir -p "$DROPIN_DIR" 2>/dev/null; [ -w "$DROPIN_DIR" ] && echo true || echo false)" \
        "$(jstr "$DROPIN_DIR")"

    # The tunnel. Not an error when absent — it is the next thing to do, and the panel prints how.
    printf ',{"name":"a tunnel interface exists to bind to","ok":%s,"detail":%s}' \
        "$([ "$wgn" -gt 0 ] && echo true || echo false)" \
        "$(jstr "$([ "$wgn" -gt 0 ] && echo "$wgn WireGuard interface(s)" || echo "none — livesync has no authentication, so it may only be bound inside a tunnel")")"
    [ "$wgn" -gt 0 ] || ok=0

    printf '],"ok_all":%s' "$(jbool "$ok")"
    printf ',"notes":%s' "$(jstr "$notes")"
    printf '}\n'
}

# ── plan / apply ────────────────────────────────────────────────────────────

# Everything that must be true before a port with no authentication is opened.
validate_args() {
    local bind="$1" port="$2" peer="$3"
    valid_ipv4 "$bind" || fail "the bind address is not an IPv4 address"
    valid_port "$port" || fail "the port must be between $PORT_MIN and $PORT_MAX"
    valid_ipv4 "$peer" || fail "the peer address is not an IPv4 address"

    is_private_v4 "$bind" || fail "refusing: $bind is a public address. Livesync has no authentication and no encryption — anything able to reach the port can inject peers into every swarm this tracker serves. Bind it to the tunnel address instead."
    is_private_v4 "$peer" || fail "refusing: the peer $peer is a public address. The peer must be reachable through the tunnel, not across the internet."

    local iface
    iface="$(iface_for_ip "$bind")"
    [ -n "$iface" ] || fail "no interface on this machine has the address $bind — bring the tunnel up first"
    is_tunnel_iface "$iface" || fail "refusing: $bind belongs to $iface, which is not a tunnel. Livesync must not be bound to an ordinary interface."

    [ "$bind" != "$peer" ] || fail "the peer address is this machine's own tunnel address"
}

build_execstart() {
    local base="$1" bind="$2" port="$3" peer="$4"
    # -s binds the livesync port; -A blesses the peer as permitted to talk to it. Both are appended,
    # so whatever the installer's command line does stays exactly as it was.
    printf '%s -i %s -s %s -A %s/32' "$base" "$bind" "$port" "$peer"
}

action_plan() {
    local bind="$1" port="$2" peer="$3"
    validate_args "$bind" "$port" "$peer"
    local base cmd
    base="$(current_execstart)"
    [ -n "$base" ] || fail "could not read the unit's ExecStart"
    cmd="$(build_execstart "$base" "$bind" "$port" "$peer")"
    printf '{"ok":true,"would_write":%s,"base":%s,"execstart":%s,"restart_required":true}\n' \
        "$(jstr "$DROPIN")" "$(jstr "$base")" "$(jstr "$cmd")"
}

action_apply() {
    need_root
    local bind="$1" port="$2" peer="$3"
    validate_args "$bind" "$port" "$peer"

    local base cmd
    # The base must be read with our own drop-in ABSENT, or re-applying would append to a command
    # line that already has -s in it and opentracker would be handed the flag twice.
    [ -f "$DROPIN" ] && { rm -f "$DROPIN"; "$SYSTEMCTL" daemon-reload >/dev/null 2>&1; }
    base="$(current_execstart)"
    [ -n "$base" ] || fail "could not read the unit's ExecStart"
    cmd="$(build_execstart "$base" "$bind" "$port" "$peer")"

    mkdir -p "$DROPIN_DIR" 2>/dev/null || fail "cannot create $DROPIN_DIR"
    {
        printf '# Written by the tracker panel (tracker-livesync.sh). Delete this file to undo.\n'
        printf '# base-execstart: %s\n' "$base"
        printf '# livesync-args: %s %s %s\n' "$bind" "$port" "$peer"
        printf '[Service]\n'
        printf 'ExecStart=\n'
        printf 'ExecStart=%s\n' "$cmd"
    } > "$DROPIN" || fail "cannot write $DROPIN"
    chmod 0644 "$DROPIN"

    "$SYSTEMCTL" daemon-reload >/dev/null 2>&1 || fail "daemon-reload failed"
    "$SYSTEMCTL" restart "$UNIT" >/dev/null 2>&1 || {
        rm -f "$DROPIN"; "$SYSTEMCTL" daemon-reload >/dev/null 2>&1; "$SYSTEMCTL" restart "$UNIT" >/dev/null 2>&1
        fail "the service would not start with livesync enabled — the change has been undone"
    }

    # Prove it, rather than reporting success because a command returned zero. A port that is not
    # listening is the difference between a working tunnel and a tunnel-shaped belief.
    local i listening=""
    for i in 1 2 3 4 5 6; do
        listening="$(bound_to "$port")"
        [ -n "$listening" ] && break
        sleep 1
    done
    if [ -z "$listening" ]; then
        rm -f "$DROPIN"; "$SYSTEMCTL" daemon-reload >/dev/null 2>&1; "$SYSTEMCTL" restart "$UNIT" >/dev/null 2>&1
        fail "opentracker started but nothing is listening on UDP $port — the change has been undone"
    fi
    # And that it is listening where it was told to, not on everything.
    case "$listening" in
        "$bind:$port") : ;;
        *"0.0.0.0:$port"|*"*:$port")
            rm -f "$DROPIN"; "$SYSTEMCTL" daemon-reload >/dev/null 2>&1; "$SYSTEMCTL" restart "$UNIT" >/dev/null 2>&1
            fail "opentracker bound the sync port to every interface instead of $bind — that would expose an unauthenticated port, so the change has been undone"
            ;;
    esac

    printf '{"ok":true,"armed":true,"execstart":%s,"listening":%s}\n' "$(jstr "$cmd")" "$(jstr "$listening")"
}

action_revert() {
    need_root
    if [ ! -f "$DROPIN" ]; then
        printf '{"ok":true,"armed":false,"changed":false,"note":"nothing was armed"}\n'
        return 0
    fi
    rm -f "$DROPIN" || fail "cannot remove $DROPIN"
    "$SYSTEMCTL" daemon-reload >/dev/null 2>&1 || fail "daemon-reload failed"
    "$SYSTEMCTL" restart "$UNIT" >/dev/null 2>&1 || fail "the service did not restart after removing the drop-in"
    printf '{"ok":true,"armed":false,"changed":true}\n'
}

# ── dispatch ────────────────────────────────────────────────────────────────

case "${1-}" in
    status) action_status ;;
    check)  action_check ;;
    plan)   [ $# -eq 4 ] || fail "usage: plan <bind_ip> <port> <peer_ip>"; action_plan "$2" "$3" "$4" ;;
    apply)  [ $# -eq 4 ] || fail "usage: apply <bind_ip> <port> <peer_ip>"; action_apply "$2" "$3" "$4" ;;
    revert) action_revert ;;
    --version) printf '{"ok":true,"version":"1"}\n' ;;
    *) fail "unknown action: ${1-} (status|check|plan|apply|revert)" ;;
esac
