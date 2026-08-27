#!/usr/bin/env bash
# tracker-cluster.sh — extra opentracker instances beside the one the installer made (schema v17).
#
#   sudo -n /usr/local/sbin/tracker-cluster.sh status
#   sudo -n /usr/local/sbin/tracker-cluster.sh check
#   sudo -n /usr/local/sbin/tracker-cluster.sh plan    <name> <udp_port> <tcp_port>
#   sudo -n /usr/local/sbin/tracker-cluster.sh create  <name> <udp_port> <tcp_port> [affinity] [workers]
#   sudo -n /usr/local/sbin/tracker-cluster.sh remove  <name>
#   sudo -n /usr/local/sbin/tracker-cluster.sh reload   --all | <name>
#   sudo -n /usr/local/sbin/tracker-cluster.sh restart  <name>
#
# Install:
#   sudo install -m 0755 tracker-cluster.sh /usr/local/sbin/tracker-cluster.sh
#   echo 'www-data ALL=(root) NOPASSWD: /usr/local/sbin/tracker-cluster.sh' | sudo tee /etc/sudoers.d/tracker-cluster
#   sudo chmod 0440 /etc/sudoers.d/tracker-cluster && sudo visudo -c -f /etc/sudoers.d/tracker-cluster
#
# Remove every trace, by hand:
#   for i in /home/tracker/instances/*/; do sudo /usr/local/sbin/tracker-cluster.sh remove "$(basename "$i")"; done
#   sudo rm -f /etc/systemd/system/opentracker@.service && sudo systemctl daemon-reload
#
# ── the two decisions that make this cheap instead of dangerous ──────────────
#
# 1. THE INSTALLER'S UNIT IS NEVER TOUCHED. Extra instances are added BESIDE `opentracker.service`,
#    never by adopting it. Adopting would mean stopping and renaming the one unit whose failure takes
#    the tracker down, rewriting a file the installer placed, and migrating the stats URL, the
#    announce URL, the firewall port and the performance drop-in all at once — on a working box. The
#    price of the asymmetry is one extra branch in a loop; the price of adoption is the outage.
#
# 2. ONE SHARED MODE, AND ONE SHARED BINARY SYMLINK. Every instance executes /home/tracker/opentracker
#    and reads the SAME accesslist the janitor already generates. They therefore cannot disagree about
#    which build they are running, and there is still exactly one `tracker_mode` in the database. Only
#    the per-instance CONFIG symlink can drift, and `status` reports drift as a fact rather than
#    assuming it away.
#
# An instance name is `^[a-z0-9][a-z0-9-]{0,15}$`. That is narrower than systemd would accept because
# the name is interpolated into paths this script writes as root, and `primary` is reserved for the
# installer's unit so the two can never be confused in a roster.

set -u

VERSION=1

TRACKER_HOME="${TRACKER_HOME:-/home/tracker}"
INSTANCES_DIR="${INSTANCES_DIR:-$TRACKER_HOME/instances}"
TEMPLATE_UNIT="${TEMPLATE_UNIT:-/etc/systemd/system/opentracker@.service}"
PRIMARY_UNIT="${PRIMARY_UNIT:-opentracker}"
SYSTEMCTL_BIN="${SYSTEMCTL_BIN:-systemctl}"
SS_BIN="${SS_BIN:-ss}"
TRACKER_USER="${TRACKER_USER:-tracker}"
PROC_DIR="${PROC_DIR:-/proc}"

jesc() {
    printf '%s' "${1-}" \
        | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g' -e 's/\t/\\t/g' -e 's/\r//g' \
        | sed -e ':a' -e 'N' -e '$!ba' -e 's/\n/\\n/g' \
        | tr -d '\000-\010\013\014\016-\037'
}
jstr() { printf '"%s"' "$(jesc "${1-}")"; }
jbool() { [ "${1:-0}" = "1" ] && printf 'true' || printf 'false'; }
fail() { printf '{"ok":false,"error":%s}\n' "$(jstr "${1:-error}")"; exit "${2:-1}"; }
is_uint() { case "${1-}" in ''|*[!0-9]*) return 1 ;; *) return 0 ;; esac; }
num_or_zero() { local v="${1-}"; is_uint "$v" && printf %s "$v" || printf 0; }
is_root() { [ "$(id -u 2>/dev/null || echo 1)" = "0" ]; }
have_systemctl() { command -v "$SYSTEMCTL_BIN" >/dev/null 2>&1; }

valid_name() {
    case "${1-}" in
        primary) return 1 ;;                     # reserved for the installer's own unit
        *) printf '%s' "${1-}" | grep -qE '^[a-z0-9][a-z0-9-]{0,15}$' ;;
    esac
}

# ── the roster ───────────────────────────────────────────────────────────────
#
# The FILESYSTEM is the source of truth, not `systemctl list-units`. Without --all that command lists
# loaded units only, so an instance that exists on disk but is stopped and unloaded — after a reboot
# without `enable`, say — would silently vanish from the roster, never be switched with the others,
# and then come back weeks later serving whatever mode it was left in. Systemd state is an annotation
# here, and an instance systemd does not know about is reported as a problem rather than hidden.
instance_names() {
    [ -d "$INSTANCES_DIR" ] || return 0
    local d n
    for d in "$INSTANCES_DIR"/*/; do
        [ -d "$d" ] || continue
        n="$(basename "$d")"
        valid_name "$n" || continue
        [ -f "$d/opentracker.conf.white" ] || [ -f "$d/opentracker.conf.black" ] || continue
        printf '%s\n' "$n"
    done
}

unit_of() { printf 'opentracker@%s.service' "$1"; }

unit_state() {
    have_systemctl || { printf 'unknown'; return; }
    local s; s="$("$SYSTEMCTL_BIN" is-active "$(unit_of "$1")" 2>/dev/null)"
    [ -n "$s" ] && printf '%s' "$s" || printf 'unknown'
}
unit_known() {
    have_systemctl || return 1
    "$SYSTEMCTL_BIN" list-unit-files 'opentracker@.service' --no-legend 2>/dev/null | grep -q . || return 1
    return 0
}
main_pid_of() {
    have_systemctl || { printf 0; return; }
    num_or_zero "$("$SYSTEMCTL_BIN" show "$(unit_of "$1")" -p MainPID --value 2>/dev/null)"
}

# What a running process is ACTUALLY executing, which is not the same question as what the symlink
# points at. See the long note in tracker-mode.sh.
running_build() {
    local pid t
    pid="$(main_pid_of "$1")"
    [ "$pid" -gt 0 ] 2>/dev/null || { printf 'stopped'; return; }
    t="$(readlink -f "$PROC_DIR/$pid/exe" 2>/dev/null)"
    case "$t" in *.white) printf 'white' ;; *.black) printf 'black' ;; *) printf 'unknown' ;; esac
}
conf_mode() {
    local t; t="$(readlink -f "$INSTANCES_DIR/$1/opentracker.conf" 2>/dev/null)"
    case "$t" in *.white) printf 'white' ;; *.black) printf 'black' ;; *) printf 'unknown' ;; esac
}
conf_port() {   # $1 name, $2 = udp|tcp
    local f="$INSTANCES_DIR/$1/opentracker.conf.white"
    [ -f "$f" ] || f="$INSTANCES_DIR/$1/opentracker.conf.black"
    [ -f "$f" ] || { printf 0; return; }
    num_or_zero "$(grep -E "^[[:space:]]*listen\.$2([[:space:]]|$)" "$f" 2>/dev/null | head -1 | sed 's/.*://' | tr -dc '0-9')"
}
conf_workers() {
    local f="$INSTANCES_DIR/$1/opentracker.conf.white"
    [ -f "$f" ] || f="$INSTANCES_DIR/$1/opentracker.conf.black"
    [ -f "$f" ] || { printf 0; return; }
    num_or_zero "$(grep -E '^[[:space:]]*listen\.udp\.workers' "$f" 2>/dev/null | head -1 | awk '{print $2}')"
}

primary_conf() {
    local m; m="$1"
    printf '%s' "$TRACKER_HOME/opentracker.conf.$m"
}
primary_port() {   # $1 = udp|tcp
    local f; f="$(primary_conf white)"
    [ -f "$f" ] || f="$(primary_conf black)"
    [ -f "$f" ] || { printf 0; return; }
    num_or_zero "$(grep -E "^[[:space:]]*listen\.$1([[:space:]]|$)" "$f" 2>/dev/null | head -1 | sed 's/.*://' | tr -dc '0-9')"
}

# ── port checks: two of them, each individually insufficient ────────────────
#
# `ss` cannot see the port of an instance that is stopped, or of any third-party daemon that happens
# to be restarting. Grepping the config files cannot see anything that is not an opentracker config.
# Neither is enough on its own and together they are still not proof — which is why `plan` says so in
# its own output instead of pretending the check is complete.
port_in_use_live() {
    "$SS_BIN" -lnu 2>/dev/null | grep -qE "[:.]$1[[:space:]]" && return 0
    "$SS_BIN" -lnt 2>/dev/null | grep -qE "[:.]$1[[:space:]]" && return 0
    return 1
}
port_in_use_conf() {
    local p="$1" f
    for f in "$TRACKER_HOME"/opentracker.conf.* "$INSTANCES_DIR"/*/opentracker.conf.*; do
        [ -f "$f" ] || continue
        grep -qE "^[[:space:]]*listen\.(udp|tcp)[[:space:]].*:$p([[:space:]]|$)" "$f" 2>/dev/null && { printf '%s' "$f"; return 0; }
    done
    return 1
}
port_in_socket_unit() {
    have_systemctl || return 1
    "$SYSTEMCTL_BIN" show '*.socket' -p ListenStream -p ListenDatagram --value 2>/dev/null \
        | grep -qE "(^|:)$1$"
}

# ── the systemd template ─────────────────────────────────────────────────────
#
# Derived from the primary's own unit rather than invented: whatever User, Group and binary the
# installer chose are what the extras get too. Only the config path differs. A template written from
# a guess is how an instance ends up running as the wrong user, or executing a binary that is not the
# one the mode switch flips.
write_template() {
    local user group execstart bin
    user="$("$SYSTEMCTL_BIN" show "$PRIMARY_UNIT" -p User --value 2>/dev/null)"
    group="$("$SYSTEMCTL_BIN" show "$PRIMARY_UNIT" -p Group --value 2>/dev/null)"
    execstart="$("$SYSTEMCTL_BIN" show "$PRIMARY_UNIT" -p ExecStart --value 2>/dev/null)"
    [ -n "$user" ] || user="$TRACKER_USER"
    [ -n "$group" ] || group="$user"
    bin="$(printf '%s' "$execstart" | sed -n 's/.*argv\[\]=\([^ ]*\).*/\1/p' | head -1)"
    [ -n "$bin" ] || bin="$TRACKER_HOME/opentracker"
    cat >"$TEMPLATE_UNIT" <<UNIT
# opentracker@.service — written by tracker-cluster.sh, do not edit by hand.
#
# One extra opentracker instance per systemd template instance. Every one of them runs the SAME
# binary symlink as the installer's own unit ($bin), so a mode switch cannot leave them disagreeing
# about which build they execute, and reads the SAME accesslist the janitor already generates.
#
# The installer's opentracker.service is not touched by any of this. Removing every trace:
#   tracker-cluster.sh remove <name>   (per instance)
#   rm $TEMPLATE_UNIT && systemctl daemon-reload
[Unit]
Description=OpenTracker instance %i (added by the tracker panel)
After=network.target
PartOf=opentracker.service

[Service]
Type=simple
User=$user
Group=$group
ExecStart=$bin -f $INSTANCES_DIR/%i/opentracker.conf
ExecReload=/bin/kill -HUP \$MAINPID
Restart=on-failure
RestartSec=2

[Install]
WantedBy=multi-user.target
UNIT
    chmod 0644 "$TEMPLATE_UNIT" 2>/dev/null
    "$SYSTEMCTL_BIN" daemon-reload >/dev/null 2>&1
}

# ── actions ──────────────────────────────────────────────────────────────────

emit_instance() {
    local n="$1" f
    printf '{'
    printf '"name":%s' "$(jstr "$n")"
    printf ',"unit":%s' "$(jstr "$(unit_of "$n")")"
    printf ',"state":%s' "$(jstr "$(unit_state "$n")")"
    printf ',"pid":%s' "$(main_pid_of "$n")"
    printf ',"udp_port":%s' "$(conf_port "$n" udp)"
    printf ',"tcp_port":%s' "$(conf_port "$n" tcp)"
    printf ',"workers":%s' "$(conf_workers "$n")"
    printf ',"conf_mode":%s' "$(jstr "$(conf_mode "$n")")"
    printf ',"running_build":%s' "$(jstr "$(running_build "$n")")"
    printf ',"dir":%s' "$(jstr "$INSTANCES_DIR/$n")"
    printf '}'
}

action_status() {
    local n first=1 count=0
    printf '{"ok":true'
    printf ',"version":%s' "$VERSION"
    printf ',"instances_dir":%s' "$(jstr "$INSTANCES_DIR")"
    printf ',"template":%s' "$(jstr "$TEMPLATE_UNIT")"
    printf ',"template_present":%s' "$([ -f "$TEMPLATE_UNIT" ] && echo true || echo false)"
    printf ',"primary":{"unit":%s,"state":%s,"udp_port":%s,"tcp_port":%s}' \
        "$(jstr "$PRIMARY_UNIT")" \
        "$(jstr "$(have_systemctl && "$SYSTEMCTL_BIN" is-active "$PRIMARY_UNIT" 2>/dev/null || echo unknown)")" \
        "$(primary_port udp)" "$(primary_port tcp)"
    printf ',"instances":['
    for n in $(instance_names); do
        [ "$first" = 1 ] || printf ','
        first=0
        count=$(( count + 1 ))
        emit_instance "$n"
    done
    printf ']'
    printf ',"count":%s' "$count"
    printf ',"cpus":%s' "$(num_or_zero "$(nproc 2>/dev/null)")"
    printf '}\n'
}

action_check() {
    local ok=1 notes=""
    is_root || { ok=0; notes="$notes must run as root (sudo);"; }
    have_systemctl || { ok=0; notes="$notes systemctl not found;"; }
    [ -d "$TRACKER_HOME" ] || { ok=0; notes="$notes $TRACKER_HOME does not exist;"; }
    [ -e "$TRACKER_HOME/opentracker" ] || { ok=0; notes="$notes the shared binary symlink $TRACKER_HOME/opentracker is missing — every instance runs it, so nothing can be created without it;"; }
    [ "$(primary_port udp)" -gt 0 ] 2>/dev/null || notes="$notes could not read the primary's UDP port from its config, so ports cannot be proposed;"
    [ -w "$(dirname "$TEMPLATE_UNIT")" ] || notes="$notes $(dirname "$TEMPLATE_UNIT") is read-only for this process — creating an instance will be deferred to the janitor;"
    printf '{"ok":%s' "$(jbool "$ok")"
    printf ',"version":%s' "$VERSION"
    printf ',"root":%s' "$(is_root && echo true || echo false)"
    printf ',"systemctl":%s' "$(have_systemctl && echo true || echo false)"
    printf ',"shared_binary":%s' "$([ -e "$TRACKER_HOME/opentracker" ] && echo true || echo false)"
    printf ',"template_present":%s' "$([ -f "$TEMPLATE_UNIT" ] && echo true || echo false)"
    printf ',"dir_writable":%s' "$([ -w "$(dirname "$TEMPLATE_UNIT")" ] && echo true || echo false)"
    printf ',"primary_udp":%s' "$(primary_port udp)"
    printf ',"primary_tcp":%s' "$(primary_port tcp)"
    printf ',"count":%s' "$(instance_names | grep -c . | tr -d ' \n')"
    printf ',"notes":%s' "$(jstr "$(printf '%s' "$notes" | sed 's/^ *//')")"
    printf '}\n'
    [ "$ok" = 1 ] || exit 1
}

# Everything `create` would refuse, without creating anything.
action_plan() {
    local name="$1" udp="$2" tcp="$3" problems="" warnings="" conf
    valid_name "$name" || problems="$problems name must be 1-16 chars of a-z 0-9 and -, and 'primary' is reserved for the installer's unit;"
    [ -d "$INSTANCES_DIR/$name" ] && problems="$problems an instance called $name already exists;"
    for p in "$udp" "$tcp"; do
        is_uint "$p" || { problems="$problems port '$p' is not a number;"; continue; }
        # Below 1024 is refused outright: those belong to things that were here first, and a tracker
        # has no business there.
        [ "$p" -ge 1024 ] || problems="$problems port $p is privileged (below 1024) and is refused;"
        [ "$p" -le 65535 ] || problems="$problems port $p is out of range;"
        if port_in_use_live "$p"; then problems="$problems port $p is already bound by something running;"; fi
        conf="$(port_in_use_conf "$p")" && problems="$problems port $p is already named in $conf;"
        if port_in_socket_unit "$p"; then problems="$problems port $p belongs to a systemd .socket unit;"; fi
    done
    # The honest caveat, stated rather than implied: a daemon that happens to be stopped right now is
    # invisible to both checks.
    warnings="a third-party daemon that is stopped at this moment cannot be detected by either check, so a port that looks free may not be;"
    printf '{"ok":%s' "$([ -z "$problems" ] && echo true || echo false)"
    printf ',"name":%s,"udp_port":%s,"tcp_port":%s' "$(jstr "$name")" "$(num_or_zero "$udp")" "$(num_or_zero "$tcp")"
    printf ',"problems":%s' "$(jstr "$(printf '%s' "$problems" | sed 's/^ *//')")"
    printf ',"warnings":%s' "$(jstr "$warnings")"
    printf '}\n'
    [ -z "$problems" ] || exit 2
}

# Build one instance's config from the PRIMARY's, changing only the three lines that must differ.
# Copying rather than generating is what keeps the accesslist paths, the rootdir and everything else
# the operator configured identical — which is the entire point of a shared accesslist.
render_conf() {
    local src="$1" dst="$2" udp="$3" tcp="$4" workers="$5"
    sed -e "s|^[[:space:]]*listen\.udp[[:space:]].*|listen.udp 0.0.0.0:$udp|" \
        -e "s|^[[:space:]]*listen\.tcp[[:space:]].*|listen.tcp 0.0.0.0:$tcp|" \
        "$src" >"$dst" || return 1
    if [ "$workers" -gt 0 ] 2>/dev/null; then
        if grep -qE '^[[:space:]]*listen\.udp\.workers' "$dst"; then
            sed -i -e "s|^[[:space:]]*listen\.udp\.workers.*|listen.udp.workers $workers|" "$dst"
        else
            printf 'listen.udp.workers %s\n' "$workers" >>"$dst"
        fi
    fi
    return 0
}

action_create() {
    local name="$1" udp="$2" tcp="$3" affinity="${4:-}" workers="${5:-0}"
    is_root || fail "create: must run as root" 2
    have_systemctl || fail "create: systemctl not found" 2
    action_plan "$name" "$udp" "$tcp" >/dev/null || fail "create: refused by the pre-flight — run plan to see why" 2
    [ -e "$TRACKER_HOME/opentracker" ] || fail "create: the shared binary symlink is missing" 3

    mkdir -p "$INSTANCES_DIR/$name" || fail "create: could not make $INSTANCES_DIR/$name" 4
    local m made=0
    for m in white black; do
        [ -f "$(primary_conf "$m")" ] || continue
        render_conf "$(primary_conf "$m")" "$INSTANCES_DIR/$name/opentracker.conf.$m" "$udp" "$tcp" "$workers" \
            || fail "create: could not write the $m config" 4
        made=1
    done
    [ "$made" = 1 ] || fail "create: the primary has no config to copy from" 4

    # Point at whatever the primary is currently running, so a new instance never joins in the wrong
    # mode even for a moment.
    local cur; cur="$(readlink -f "$TRACKER_HOME/opentracker" 2>/dev/null)"
    case "$cur" in *.white) cur=white ;; *) cur=black ;; esac
    [ -f "$INSTANCES_DIR/$name/opentracker.conf.$cur" ] || cur=$( [ -f "$INSTANCES_DIR/$name/opentracker.conf.white" ] && echo white || echo black )
    ln -sfn "opentracker.conf.$cur" "$INSTANCES_DIR/$name/opentracker.conf"
    chown -R "$TRACKER_USER":"$TRACKER_USER" "$INSTANCES_DIR/$name" 2>/dev/null
    chown -h "$TRACKER_USER":"$TRACKER_USER" "$INSTANCES_DIR/$name/opentracker.conf" 2>/dev/null

    [ -f "$TEMPLATE_UNIT" ] || write_template
    if [ -n "$affinity" ]; then
        mkdir -p "/etc/systemd/system/$(unit_of "$name").d" 2>/dev/null
        printf '# written by tracker-cluster.sh\n[Service]\nCPUAffinity=%s\n' "$affinity" \
            >"/etc/systemd/system/$(unit_of "$name").d/90-tracker-panel.conf"
    fi
    "$SYSTEMCTL_BIN" daemon-reload >/dev/null 2>&1
    "$SYSTEMCTL_BIN" enable --now "$(unit_of "$name")" >/dev/null 2>&1
    sleep 1
    local st; st="$(unit_state "$name")"
    if [ "$st" != "active" ]; then
        # Do not leave a half-made instance behind that somebody has to find later.
        "$SYSTEMCTL_BIN" disable --now "$(unit_of "$name")" >/dev/null 2>&1
        printf '{"ok":false,"error":%s,"journal":%s}\n' \
            "$(jstr "instance $name did not start; it has been removed again")" \
            "$(jstr "$("$SYSTEMCTL_BIN" status "$(unit_of "$name")" --no-pager -n 8 2>&1 | tail -8)")"
        rm -rf "$INSTANCES_DIR/$name"
        exit 5
    fi
    printf '{"ok":true,"created":true,"name":%s,"unit":%s,"mode":%s,"udp_port":%s,"tcp_port":%s}\n' \
        "$(jstr "$name")" "$(jstr "$(unit_of "$name")")" "$(jstr "$cur")" "$(num_or_zero "$udp")" "$(num_or_zero "$tcp")"
}

action_remove() {
    local name="$1"
    is_root || fail "remove: must run as root" 2
    valid_name "$name" || fail "remove: bad instance name" 2
    have_systemctl && {
        "$SYSTEMCTL_BIN" disable --now "$(unit_of "$name")" >/dev/null 2>&1
    }
    rm -rf "$INSTANCES_DIR/$name"
    rm -rf "/etc/systemd/system/$(unit_of "$name").d"
    local left; left="$(instance_names | grep -c . | tr -d ' \n')"
    # The template is ours and is only useful while an instance uses it. Leaving it behind would mean
    # "remove every trace" quietly did not.
    if [ "$left" = "0" ] && [ -f "$TEMPLATE_UNIT" ]; then rm -f "$TEMPLATE_UNIT"; fi
    have_systemctl && "$SYSTEMCTL_BIN" daemon-reload >/dev/null 2>&1
    printf '{"ok":true,"removed":true,"name":%s,"left":%s,"template_removed":%s}\n' \
        "$(jstr "$name")" "$left" "$([ "$left" = "0" ] && echo true || echo false)"
}

# SIGHUP every instance, then ask each one whether it is still there.
#
# `systemctl reload` returns 0 as soon as the signal is delivered, and on a binary without the
# SIGHUP-with-UDP-workers patch the signal can KILL the process a moment later. Reporting ok on the
# exit code alone would clear the pending-reload bookkeeping while a third of the swarm was gone.
action_reload() {
    local which="$1" n first=1 failed=0
    is_root || fail "reload: must run as root" 2
    printf '{"ok":true,"reloaded":['
    for n in $(instance_names); do
        if [ "$which" != "--all" ] && [ "$which" != "$n" ]; then continue; fi
        local rc=0 alive
        "$SYSTEMCTL_BIN" reload "$(unit_of "$n")" >/dev/null 2>&1 || rc=$?
        sleep 1
        alive="$(unit_state "$n")"
        [ "$rc" = 0 ] && [ "$alive" = "active" ] || failed=$(( failed + 1 ))
        [ "$first" = 1 ] || printf ','
        first=0
        printf '{"name":%s,"rc":%s,"state":%s,"ok":%s}' "$(jstr "$n")" "$rc" "$(jstr "$alive")" \
            "$(jbool "$([ "$rc" = 0 ] && [ "$alive" = active ] && echo 1 || echo 0)")"
    done
    printf '],"failed":%s}\n' "$failed"
    [ "$failed" = 0 ] || exit 6
}

action_restart() {
    local name="$1"
    is_root || fail "restart: must run as root" 2
    valid_name "$name" || fail "restart: bad instance name" 2
    "$SYSTEMCTL_BIN" restart "$(unit_of "$name")" >/dev/null 2>&1
    sleep 1
    local st; st="$(unit_state "$name")"
    printf '{"ok":%s,"name":%s,"state":%s}\n' "$(jbool "$([ "$st" = active ] && echo 1 || echo 0)")" "$(jstr "$name")" "$(jstr "$st")"
    [ "$st" = active ] || exit 5
}

main() {
    local action="${1:-status}"; shift || true
    local reply rc=0
    case "$action" in
        status)  reply="$(action_status)" || rc=$? ;;
        check)   reply="$(action_check)" || rc=$? ;;
        plan)    reply="$(action_plan "${1:-}" "${2:-0}" "${3:-0}")" || rc=$? ;;
        create)  reply="$(action_create "${1:-}" "${2:-0}" "${3:-0}" "${4:-}" "${5:-0}")" || rc=$? ;;
        remove)  reply="$(action_remove "${1:-}")" || rc=$? ;;
        reload)  reply="$(action_reload "${1:---all}")" || rc=$? ;;
        restart) reply="$(action_restart "${1:-}")" || rc=$? ;;
        --version) printf 'tracker-cluster.sh %s\n' "$VERSION"; exit 0 ;;
        --help|-h|help) sed -n '2,20p' "$0"; exit 0 ;;
        *) reply="$(printf '{"ok":false,"error":%s}' "$(jstr "unknown action: $action")")"; rc=2 ;;
    esac
    printf '%s\n' "$reply"
    exit "$rc"
}

main "$@"
