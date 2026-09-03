#!/bin/bash
# tracker-instance.sh — the panel's narrow root helper for OpenTracker's performance knobs.
#
# Everything it writes lives in ONE file it owns:
#     /etc/systemd/system/opentracker.service.d/90-tracker-panel.conf
# It never touches override.conf or limits.conf — those were placed by the installer or by hand, and
# a panel that edits somebody else's file is a panel you cannot trust with your server. Undoing
# every change this helper has ever made is `reset`, which deletes that one file.
#
# The UDP worker count is different: it is not a unit property but a line in opentracker's own
# config, and both mode files (white/black) must agree or switching modes would silently change it.
# That change needs a restart, and the helper says so rather than pretending otherwise.
#
# Actions:
#   status                       what is in force, what we set, and the receive-buffer diagnosis
#   check                        can this machine do any of it — for the panel's Test button
#   apply <nice> <weight> <affinity> <nofile> [--dry-run]
#   workers <n> [--dry-run]      listen.udp.workers in both conf files
#   reset [--dry-run]            delete our drop-in (unit settings only; workers are left alone)
#   restart                      restart the service (the caller has already asked the admin)
#
# sudoers (one line, no arguments pinned — every argument is validated below):
#   www-data ALL=(root) NOPASSWD: /usr/local/sbin/tracker-instance.sh
#
# Test hooks: OT_UNIT, OT_DROPIN_DIR, OT_CONFS, SYSTEMCTL_BIN.
set -u

UNIT="${OT_UNIT:-opentracker}"
DROPIN_DIR="${OT_DROPIN_DIR:-/etc/systemd/system/${UNIT}.service.d}"
DROPIN="$DROPIN_DIR/90-tracker-panel.conf"
# Both mode files, space separated. The panel keeps them identical: a mode switch flips a symlink,
# and a worker count that changed with the mode would be a genuinely baffling bug to chase.
CONFS="${OT_CONFS:-/home/tracker/opentracker.conf.white /home/tracker/opentracker.conf.black}"
SYSTEMCTL="${SYSTEMCTL_BIN:-}"
[ -n "$SYSTEMCTL" ] || SYSTEMCTL="$(command -v systemctl 2>/dev/null || echo /usr/bin/systemctl)"

NICE_MIN=-20; NICE_MAX=19
WEIGHT_MIN=1; WEIGHT_MAX=10000
NOFILE_MIN=1024; NOFILE_MAX=1048576
WORKERS_MAX=64
tmp=""

# ── JSON (no jq) — same discipline as tracker-netlimit.sh ────────────────────
jesc() {
    printf '%s' "${1-}" \
        | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g' -e 's/\t/\\t/g' -e 's/\r//g' \
        | sed -e ':a' -e 'N' -e '$!ba' -e 's/\n/\\n/g' \
        | tr -d '\000-\010\013\014\016-\037'
}
jstr() { printf '"%s"' "$(jesc "${1-}")"; }
fail() { printf '{"ok":false,"error":%s}\n' "$(jstr "$1")"; printf '%s\n' "$1" >&2; exit "${2:-2}"; }
is_int()  { case "${1-}" in ''|*[!0-9-]*) return 1 ;; *) return 0 ;; esac; }
is_uint() { case "${1-}" in ''|*[!0-9]*) return 1 ;; *) return 0 ;; esac; }
is_root() { [ "$(id -u 2>/dev/null || echo 1)" = "0" ]; }
have_systemctl() { [ -n "$SYSTEMCTL" ] && [ -x "$SYSTEMCTL" ]; }

want_range() {  # want_range <value> <min> <max> <label>
    is_int "${1-}" || fail "$4 must be a whole number."
    [ "$1" -ge "$2" ] && [ "$1" -le "$3" ] || fail "$4 must be between $2 and $3."
}

# CPUAffinity: systemd takes a list like "0 2 4" or "2-5". Refuse anything else outright rather than
# handing a malformed unit file to systemd, which would then refuse to start the service at all.
valid_affinity() {
    [ -z "${1-}" ] && return 0
    printf '%s' "$1" | grep -Eq '^[0-9]+(-[0-9]+)?([ ,][0-9]+(-[0-9]+)?)*$' || return 1
    local cpus; cpus=$(nproc 2>/dev/null || echo 1)
    local part lo hi
    for part in $(printf '%s' "$1" | tr ',' ' '); do
        case "$part" in
            *-*) lo="${part%%-*}"; hi="${part##*-}" ;;
            *)   lo="$part"; hi="$part" ;;
        esac
        is_uint "$lo" && is_uint "$hi" || return 1
        [ "$lo" -le "$hi" ] || return 1
        [ "$hi" -lt "$cpus" ] || return 1
    done
    return 0
}

unit_prop() { have_systemctl && "$SYSTEMCTL" show "$UNIT" -p "$1" --value 2>/dev/null; }

read_workers() {
    local f
    for f in $CONFS; do
        [ -r "$f" ] || continue
        awk '/^[[:space:]]*listen\.udp\.workers[[:space:]]+[0-9]+/ { print $2; exit }' "$f"
        return 0
    done
}

# Do the mode files agree? They must: the panel writes both, but a hand edit to one would make the
# worker count depend on which mode the tracker happens to be in.
workers_consistent() {
    local first="" cur f seen=0
    for f in $CONFS; do
        [ -r "$f" ] || continue
        cur="$(awk '/^[[:space:]]*listen\.udp\.workers[[:space:]]+[0-9]+/ { print $2; exit }' "$f")"
        seen=$((seen + 1))
        [ "$seen" = 1 ] && { first="$cur"; continue; }
        [ "$cur" = "$first" ] || return 1
    done
    return 0
}

dir_writable() {
    local d="${1:-$DROPIN_DIR}"
    [ -d "$d" ] || return 1
    local probe="$d/.wtest.$$"
    if ( : >"$probe" ) 2>/dev/null; then rm -f "$probe" 2>/dev/null; return 0; fi
    return 1
}

# ── the receive-buffer diagnosis ─────────────────────────────────────────────
# Not a knob — a measurement, and the one that actually explains lost announces. opentracker asks
# for a receive buffer; the kernel clamps it to net.core.rmem_max. When the queue fills, the packet
# is discarded AFTER the machine has paid to receive it, which is the worst place to lose it.
# The panel shows this and the command to change it; it does not write sysctls, because that is
# system-wide and belongs to whoever owns the machine.
# Each of these is printed as a JSON NUMBER and each reads a file or tool that may not exist on the
# machine — or may exist and print nothing. An empty result would produce `"key":`, which is invalid
# JSON, and the panel would report the whole helper as broken over a missing sysctl.
num_or_zero() { local v="${1-}"; is_uint "$v" && printf %s "$v" || printf 0; }
rmem_max() { num_or_zero "$(sysctl -n net.core.rmem_max 2>/dev/null)"; }
backlog()  { num_or_zero "$(sysctl -n net.core.netdev_max_backlog 2>/dev/null)"; }
udp_rcv_errors() {
    [ -r /proc/net/snmp ] || { printf 0; return 0; }
    num_or_zero "$(grep -A1 '^Udp:' /proc/net/snmp 2>/dev/null | awk 'NR==2{print $6}')"
}
socket_drops() {
    # `d<N>` in ss's skmem is this socket's own drop counter — per socket, unlike the global one.
    # awk, not sed: the field is pulled out by splitting on the literal ",d", which needs no
    # backreference at all. The sed form this replaces carried a stray control byte where its
    # backreference should have been, so it matched, substituted rubbish, failed is_uint and reported a
    # confident 0 for ever -- a broken measurement that looks exactly like a healthy one.
    num_or_zero "$(ss -ulnpm 2>/dev/null | grep -A1 ':6969' | awk -F',d' 'NF>1 {n=$2; sub(/[^0-9].*/, "", n); if (n != "") { print n; exit }}')"
}

# --- how hard the threads are actually working -------------------------------
#
# This is the measurement that decides whether a second tracker instance is worth building at all.
# opentracker runs its UDP workers as threads, so "is the tracker CPU-bound?" is a question about
# threads, not about the process: four threads at 25% each and one thread pinned at 100% look like
# the same 100% in top and mean completely different things.
#
# Reported as raw counters, never as a percentage. A percentage needs two samples, and taking the
# second one would mean this helper sleeping inside a web request. The panel already polls every
# fifteen seconds, so it subtracts consecutive samples itself and gets a true fifteen-second average
# for nothing.
tracker_pid() { pgrep -x opentracker 2>/dev/null | head -1; }

# utime+stime in clock ticks, per thread. A thread that exits between the listing and the read is
# ordinary rather than an error, and is simply left out.
emit_threads() {
    local pid; pid="$(tracker_pid)"
    printf '['
    if [ -z "$pid" ] || [ ! -d "/proc/$pid/task" ]; then printf ']'; return 0; fi
    local first=1 t tid line name ticks
    for t in "/proc/$pid/task"/*; do
        [ -r "$t/stat" ] || continue
        line="$(cat "$t/stat" 2>/dev/null)" || continue
        [ -n "$line" ] || continue
        tid="$(basename "$t")"
        # The comm field is parenthesised and may contain spaces and brackets of its own, so the
        # fields are counted from the LAST ')' rather than from the start of the line.
        name="$(printf %s "$line" | awk -F'[()]' '{print $2}')"
        ticks="$(printf %s "$line" | sed 's/.*)//' | awk '{print $12+$13}')"
        is_uint "$ticks" || continue
        [ "$first" = 1 ] || printf ','
        first=0
        printf '{"tid":%s,"name":%s,"ticks":%s}' "$tid" "$(jstr "$name")" "$ticks"
    done
    printf ']'
}

# Busy and idle ticks across every core, from the same clock as the thread counters, so the panel can
# express the tracker's share of the WHOLE machine -- which is the number that answers "is there
# room for another one of these", rather than its share of a single core.
cpu_busy_ticks() {
    [ -r /proc/stat ] || { printf 0; return 0; }
    num_or_zero "$(awk '/^cpu /{print $2+$3+$4+$6+$7+$8}' /proc/stat)"
}
cpu_idle_ticks() {
    [ -r /proc/stat ] || { printf 0; return 0; }
    num_or_zero "$(awk '/^cpu /{print $5+$6}' /proc/stat)"
}

emit_status() {
    local w; w="$(read_workers)"; is_uint "$w" || w=0
    local nice weight aff nofile
    nice="$(unit_prop Nice)"; is_int "$nice" || nice=0
    weight="$(unit_prop CPUWeight)"; is_uint "$weight" || weight=0
    aff="$(unit_prop CPUAffinity)"
    nofile="$(unit_prop LimitNOFILE)"; is_uint "$nofile" || nofile=0
    local drops; drops="$(socket_drops)"

    printf '{"ok":true'
    printf ',"unit":%s' "$(jstr "$UNIT")"
    printf ',"active":%s' "$(have_systemctl && [ "$("$SYSTEMCTL" is-active "$UNIT" 2>/dev/null)" = active ] && echo true || echo false)"
    printf ',"cpus":%s' "$(nproc 2>/dev/null || echo 1)"
    printf ',"workers":%s' "$w"
    printf ',"workers_consistent":%s' "$(workers_consistent && echo true || echo false)"
    printf ',"nice":%s' "$nice"
    printf ',"cpu_weight":%s' "$weight"
    printf ',"cpu_affinity":%s' "$(jstr "$aff")"
    printf ',"limit_nofile":%s' "$nofile"
    printf ',"dropin":%s' "$(jstr "$DROPIN")"
    printf ',"dropin_present":%s' "$([ -f "$DROPIN" ] && echo true || echo false)"
    printf ',"dropin_writable":%s' "$(dir_writable && echo true || echo false)"
    printf ',"other_dropins":['
    local first=1 f
    if [ -d "$DROPIN_DIR" ]; then
        for f in "$DROPIN_DIR"/*.conf; do
            [ -f "$f" ] || continue
            [ "$f" = "$DROPIN" ] && continue
            [ "$first" = 1 ] || printf ','
            first=0
            printf '%s' "$(jstr "$(basename "$f")")"
        done
    fi
    printf ']'
    printf ',"rmem_max":%s' "$(rmem_max)"
    printf ',"netdev_backlog":%s' "$(backlog)"
    printf ',"socket_drops":%s' "$drops"
    printf ',"udp_rcv_errors":%s' "$(udp_rcv_errors)"
    printf ',"clk_tck":%s' "$(num_or_zero "$(getconf CLK_TCK 2>/dev/null)")"
    printf ',"cpu_busy_ticks":%s' "$(cpu_busy_ticks)"
    printf ',"cpu_idle_ticks":%s' "$(cpu_idle_ticks)"
    printf ',"threads":%s' "$(emit_threads)"
    printf ',"confs":['
    first=1
    for f in $CONFS; do
        [ "$first" = 1 ] || printf ','
        first=0
        printf '%s' "$(jstr "$f")"
    done
    printf ']}\n'
}

emit_check() {
    local errs="" hints=""
    have_systemctl || { errs="systemctl was not found — unit settings cannot be changed."; hints="This helper is meant for a systemd machine."; }
    is_root || { errs="${errs:+$errs | }This helper must run as root."; hints="${hints:+$hints | }Call it through sudo: sudo -n $0 status"; }
    if is_root && have_systemctl; then
        "$SYSTEMCTL" cat "$UNIT" >/dev/null 2>&1 || {
            errs="${errs:+$errs | }There is no ${UNIT}.service on this machine."
            hints="${hints:+$hints | }Set the service name in Settings → Tracker, or leave the performance knobs alone."; }
        local f found=0
        for f in $CONFS; do [ -r "$f" ] && found=1; done
        [ "$found" = 1 ] || {
            errs="${errs:+$errs | }None of the opentracker config files is readable ($CONFS)."
            hints="${hints:+$hints | }The worker count lives in those files; the unit knobs still work without them."; }
        workers_consistent || {
            errs="${errs:+$errs | }The mode config files disagree about listen.udp.workers."
            hints="${hints:+$hints | }Applying a worker count from the panel writes both and settles it."; }
        dir_writable || hints="${hints:+$hints | }$DROPIN_DIR is read-only for this process (systemd ProtectSystem); apply from the janitor or add ReadWritePaths=-$DROPIN_DIR to the php-fpm drop-in."
    fi
    printf '{"ok":%s,"systemctl":%s,"root":%s,"unit":%s,"dropin_dir":%s,"dropin_writable":%s,"cpus":%s,"error":%s,"hint":%s}\n' \
        "$([ -z "$errs" ] && echo true || echo false)" \
        "$(have_systemctl && echo true || echo false)" \
        "$(is_root && echo true || echo false)" \
        "$(jstr "$UNIT")" "$(jstr "$DROPIN_DIR")" \
        "$(dir_writable && echo true || echo false)" \
        "$(nproc 2>/dev/null || echo 1)" \
        "$(jstr "$errs")" "$(jstr "$hints")"
    [ -z "$errs" ]
}

render_dropin() {  # render_dropin <nice> <weight> <affinity> <nofile>
    cat <<EOF
# $DROPIN — generated by tracker-instance.sh, do not edit by hand.
# The panel writes ONLY this file. override.conf and limits.conf belong to whoever put them there
# and are never touched; systemd merges all of them, and the highest-numbered file wins a conflict.
# Undo everything the panel has ever changed here: tracker-instance.sh reset  (deletes this file)
[Service]
Nice=$1
CPUWeight=$2
LimitNOFILE=$4
EOF
    # An empty affinity must not be written at all: `CPUAffinity=` with no value is how you RESET it
    # in a drop-in, which is right, but writing a blank line for a value the admin never set reads
    # like a decision. Absent means absent.
    [ -n "$3" ] && printf 'CPUAffinity=%s\n' "$3"
}

action_apply() {
    local nice="${1-}" weight="${2-}" aff="${3-}" nofile="${4-}" dry="${5-}"
    want_range "$nice" "$NICE_MIN" "$NICE_MAX" "Nice"
    want_range "$weight" "$WEIGHT_MIN" "$WEIGHT_MAX" "CPU weight"
    want_range "$nofile" "$NOFILE_MIN" "$NOFILE_MAX" "Open file limit"
    valid_affinity "$aff" || fail "CPU affinity must be a list systemd understands (e.g. '2-5' or '0 2 4') and every core must exist on this machine ($(nproc 2>/dev/null || echo 1) cores)."
    have_systemctl || fail "systemctl was not found — cannot change unit settings."

    if [ "$dry" = "--dry-run" ]; then
        printf '{"ok":true,"dry_run":true,"file":%s,"content":%s}\n' \
            "$(jstr "$DROPIN")" "$(jstr "$(render_dropin "$nice" "$weight" "$aff" "$nofile")")"
        return 0
    fi
    is_root || fail "must run as root to write $DROPIN"
    [ -d "$DROPIN_DIR" ] || mkdir -p "$DROPIN_DIR" || fail "cannot create $DROPIN_DIR"
    tmp="$(mktemp "${TMPDIR:-/tmp}/ot-dropin.XXXXXX")" || fail "cannot create a temporary file"
    trap 'rm -f "${tmp:-}"' EXIT
    render_dropin "$nice" "$weight" "$aff" "$nofile" >"$tmp"
    # Same distinction the firewall helper makes, and for the same reason: when the panel calls this
    # from php-fpm, /etc is read-only inside that service's mount namespace — even for root, because
    # it is a namespace and not a permission bit. That is not a failed apply, it is an apply that has
    # to be finished by the janitor, which runs outside the sandbox.
    if ! install -m 0644 "$tmp" "$DROPIN" 2>/dev/null; then
        if dir_writable; then
            fail "cannot write $DROPIN" 4
        fi
        printf '{"ok":true,"applied":false,"deferred":true,"file":%s,"hint":%s}
'             "$(jstr "$DROPIN")"             "$(jstr "this process cannot write $DROPIN_DIR (systemd ProtectSystem); the janitor writes it within a minute")"
        return 0
    fi
    local out; out="$("$SYSTEMCTL" daemon-reload 2>&1)" || fail "daemon-reload failed: $out" 3

    # Nice and CPUWeight are applied to a running unit by daemon-reload; CPUAffinity and LimitNOFILE
    # are not. Say which, instead of leaving the admin to wonder why nothing changed.
    printf '{"ok":true,"applied":true,"file":%s,"restart_needed":%s,"why":%s}\n' \
        "$(jstr "$DROPIN")" \
        "$([ -n "$aff" ] && echo true || echo true)" \
        "$(jstr "CPUAffinity and LimitNOFILE only take effect on a restart; Nice and CPUWeight are live already.")"
}

action_workers() {
    local n="${1-}" dry="${2-}"
    want_range "$n" 1 "$WORKERS_MAX" "UDP workers"
    local cpus; cpus=$(nproc 2>/dev/null || echo 1)
    [ "$n" -le $((cpus * 4)) ] || fail "That is more than four workers per core ($cpus cores) — well past the point where more threads help."

    local f found=0
    for f in $CONFS; do [ -r "$f" ] && found=1; done
    [ "$found" = 1 ] || fail "none of the opentracker config files is readable ($CONFS)"

    if [ "$dry" = "--dry-run" ]; then
        printf '{"ok":true,"dry_run":true,"workers":%s,"from":%s,"files":%s}\n' \
            "$n" "$(read_workers | head -1 | grep -E '^[0-9]+$' || echo 0)" "$(jstr "$CONFS")"
        return 0
    fi
    is_root || fail "must run as root to edit the opentracker config"
    local changed=0
    for f in $CONFS; do
        [ -f "$f" ] || continue
        tmp="$(mktemp "${TMPDIR:-/tmp}/ot-conf.XXXXXX")" || fail "cannot create a temporary file"
        if grep -Eq '^[[:space:]]*listen\.udp\.workers[[:space:]]+[0-9]+' "$f"; then
            sed -E "s/^[[:space:]]*listen\.udp\.workers[[:space:]]+[0-9]+.*/listen.udp.workers $n/" "$f" >"$tmp"
        else
            { printf 'listen.udp.workers %s\n' "$n"; cat "$f"; } >"$tmp"
        fi
        # Keep the file's owner and mode: it is read by the tracker user, not by root.
        chown --reference="$f" "$tmp" 2>/dev/null || true
        chmod --reference="$f" "$tmp" 2>/dev/null || chmod 0640 "$tmp"
        mv -f "$tmp" "$f" || fail "cannot write $f" 4
        tmp=""
        changed=$((changed + 1))
    done
    printf '{"ok":true,"applied":true,"workers":%s,"files_changed":%s,"restart_needed":true,"why":%s}\n' \
        "$n" "$changed" "$(jstr "opentracker reads this at start-up; a reload does not pick it up.")"
}

action_reset() {
    local dry="${1-}"
    if [ "$dry" = "--dry-run" ]; then
        printf '{"ok":true,"dry_run":true,"would_remove":%s,"present":%s}\n' \
            "$(jstr "$DROPIN")" "$([ -f "$DROPIN" ] && echo true || echo false)"
        return 0
    fi
    is_root || fail "must run as root"
    # A failed removal was reported as ok:true, removed:false — which the panel renders as "nothing
    # to remove" about a file that is still in force and still overriding the unit. The three cases
    # are different and are now said differently: removed, deferred (this process cannot write /etc,
    # so the janitor finishes it), or a genuine failure.
    local removed=false deferred=false
    if [ -f "$DROPIN" ]; then
        if rm -f "$DROPIN" 2>/dev/null; then
            removed=true
        elif dir_writable 2>/dev/null; then
            fail "could not remove $DROPIN — it is still overriding the unit" 4
        else
            deferred=true
        fi
    fi
    have_systemctl && "$SYSTEMCTL" daemon-reload >/dev/null 2>&1
    # Deliberately NOT touching listen.udp.workers: it is opentracker's own setting and may well
    # have been what the installer chose. "Reset" means "forget what the panel did to the unit".
    printf '{"ok":true,"removed":%s,"deferred":%s,"file":%s,"note":%s}\n' \
        "$removed" "$deferred" "$(jstr "$DROPIN")" \
        "$(jstr "$([ "$deferred" = true ] \
            && printf '%s' "The drop-in is still there: this process cannot write /etc, so the janitor removes it within a minute." \
            || printf '%s' "The unit drop-in is gone; listen.udp.workers is left as it is, since it is opentracker's own setting.")")"
}

action_restart() {
    have_systemctl || fail "systemctl was not found."
    is_root || fail "must run as root to restart $UNIT"
    local out; out="$("$SYSTEMCTL" restart "$UNIT" 2>&1)" || fail "restart failed: $out" 3
    sleep 1
    printf '{"ok":true,"restarted":true,"active":%s}\n' \
        "$([ "$("$SYSTEMCTL" is-active "$UNIT" 2>/dev/null)" = active ] && echo true || echo false)"
}

case "${1:-status}" in
    status)  _out="$(emit_status)"; printf '%s\n' "$_out" ;;
    check)   _out="$(emit_check)"; _rc=$?; printf '%s\n' "$_out"; exit "$_rc" ;;
    apply)   shift; action_apply "${1-}" "${2-}" "${3-}" "${4-}" "${5-}" ;;
    workers) shift; action_workers "${1-}" "${2-}" ;;
    reset)   shift; action_reset "${1-}" ;;
    restart) action_restart ;;
    -h|--help|help)
        sed -n '2,26p' "$0" | sed 's/^# \{0,1\}//' ; exit 0 ;;
    *) fail "unknown action '${1:-}' — use: status | check | apply <nice> <weight> <affinity> <nofile> | workers <n> | reset | restart" 1 ;;
esac
