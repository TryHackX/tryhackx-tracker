#!/usr/bin/env bash
# tracker-sysctl.sh — the panel's kernel network-buffer knobs (schema v16).
#
#   sudo -n /usr/local/sbin/tracker-sysctl.sh status
#   sudo -n /usr/local/sbin/tracker-sysctl.sh check
#   sudo -n /usr/local/sbin/tracker-sysctl.sh preview  <key=value>...
#   sudo -n /usr/local/sbin/tracker-sysctl.sh arm      <seconds> <nonce> <key=value>...
#   sudo -n /usr/local/sbin/tracker-sysctl.sh confirm  <nonce>
#   sudo -n /usr/local/sbin/tracker-sysctl.sh revert
#
# Install:
#   sudo install -m 0755 tracker-sysctl.sh /usr/local/sbin/tracker-sysctl.sh
#   echo 'www-data ALL=(root) NOPASSWD: /usr/local/sbin/tracker-sysctl.sh' | sudo tee /etc/sudoers.d/tracker-sysctl
#   sudo chmod 0440 /etc/sudoers.d/tracker-sysctl && sudo visudo -c -f /etc/sudoers.d/tracker-sysctl
#
# Undo everything, by hand, without the panel:
#   sudo /usr/local/sbin/tracker-sysctl.sh revert
#   …or, if this script is gone:  sudo rm /etc/sysctl.d/99-tracker-panel.conf && sudo reboot
#
# ── why this script is shaped the way it is ──────────────────────────────────
#
# This is the first thing the panel touches that is not the tracker's own. An nftables table, a
# systemd drop-in and an accesslist file are all scoped to opentracker and undoable in isolation;
# these keys belong to the whole machine, and the mail server, the forum and the database share it.
# So the rules here are stricter than anywhere else in the project:
#
#   * EIGHT keys, named literally in a case statement. The sudoers rule pins no arguments, so the
#     validation in this file IS the security boundary. Nothing computes a /proc path from input.
#   * NOTHING is written to /etc until a human confirms. Until then the only change is in the running
#     kernel, which means a reboot is a complete undo — the cheapest escape hatch there is.
#   * `arm` schedules its own revert through systemd BEFORE it changes anything. That revert does not
#     need this script's caller, PHP, MariaDB, or an admin who can still log in. If the change makes
#     the machine unusable, the machine fixes itself.
#   * Every write is read back, and the read-back is reported per key. A change that silently did not
#     happen is the failure this project keeps meeting; here it would be a change the operator
#     believes is protecting them.
#
# The panel deals in bytes, packets and percentages; this script deals only in the kernel's own units
# (bytes for the four buffers, packets for the backlog, PAGES for udp_mem). Converting in one place,
# where the page size is read rather than assumed, is the whole reason a wrong-unit entry is hard.

set -u

VERSION=1

SYSCTL_D_DIR="${SYSCTL_D_DIR:-/etc/sysctl.d}"
CONF_FILE="${CONF_FILE:-$SYSCTL_D_DIR/99-tracker-panel.conf}"
STATE_DIR="${STATE_DIR:-/var/lib/tracker-panel}"
BASELINE_FILE="${BASELINE_FILE:-$STATE_DIR/sysctl-baseline.json}"
ARM_FILE="${ARM_FILE:-$STATE_DIR/sysctl-armed.json}"
PROC_SYS="${PROC_SYS:-/proc/sys}"
SYSTEMD_RUN="${SYSTEMD_RUN:-systemd-run}"
SYSTEMCTL_BIN="${SYSTEMCTL_BIN:-systemctl}"
SS_BIN="${SS_BIN:-ss}"
SELF="${SELF:-$0}"
REVERT_UNIT_PREFIX="tracker-sysctl-revert"

# ── tiny JSON helpers (same shape as the other three helpers) ────────────────
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

# Left-to-right redirection means `: >"$f" 2>/dev/null` still prints to the real stderr; the
# redirection has to be inside a subshell whose stderr is already gone. Learned the hard way.
dir_writable() {
    local d="${1:-$SYSCTL_D_DIR}" probe
    [ -d "$d" ] || return 1
    probe="$d/.tracker-panel-probe.$$"
    if ( : >"$probe" ) 2>/dev/null; then rm -f "$probe" 2>/dev/null; return 0; fi
    return 1
}

# ── the eight keys, and nothing else ─────────────────────────────────────────
#
# Ranges are deliberately narrower than the kernel accepts. The kernel will happily take
# netdev_max_backlog=1000000; six CPUs times a million queued packets is memory the mail server does
# not get, under precisely the flood this feature exists to survive.
key_path() {
    case "${1-}" in
        rmem_max)            printf '%s' "$PROC_SYS/net/core/rmem_max" ;;
        wmem_max)            printf '%s' "$PROC_SYS/net/core/wmem_max" ;;
        rmem_default)        printf '%s' "$PROC_SYS/net/core/rmem_default" ;;
        wmem_default)        printf '%s' "$PROC_SYS/net/core/wmem_default" ;;
        netdev_max_backlog)  printf '%s' "$PROC_SYS/net/core/netdev_max_backlog" ;;
        udp_mem)             printf '%s' "$PROC_SYS/net/ipv4/udp_mem" ;;
        udp_rmem_min)        printf '%s' "$PROC_SYS/net/ipv4/udp_rmem_min" ;;
        udp_wmem_min)        printf '%s' "$PROC_SYS/net/ipv4/udp_wmem_min" ;;
        *) return 1 ;;
    esac
}
key_sysctl_name() {
    case "${1-}" in
        rmem_max|wmem_max|rmem_default|wmem_default|netdev_max_backlog) printf 'net.core.%s' "$1" ;;
        udp_mem|udp_rmem_min|udp_wmem_min) printf 'net.ipv4.%s' "$1" ;;
        *) return 1 ;;
    esac
}
ALL_KEYS="rmem_max wmem_max rmem_default wmem_default netdev_max_backlog udp_mem udp_rmem_min udp_wmem_min"

mem_total_kb() { num_or_zero "$(awk '/^MemTotal:/{print $2; exit}' /proc/meminfo 2>/dev/null)"; }
page_size()    { local p; p="$(getconf PAGESIZE 2>/dev/null)"; is_uint "$p" && printf %s "$p" || printf 4096; }
cpu_count()    { local n; n="$(nproc 2>/dev/null)"; is_uint "$n" && printf %s "$n" || printf 1; }

# Validate ONE key=value pair. Prints nothing; returns 0 or sets $VERR.
VERR=""
valid_value() {
    local k="$1" v="$2" ps mt lo hi a b c
    VERR=""
    ps="$(page_size)"; mt="$(mem_total_kb)"
    case "$k" in
        rmem_max|wmem_max|rmem_default|wmem_default|udp_rmem_min|udp_wmem_min)
            is_uint "$v" || { VERR="$k: not a whole number of bytes"; return 1; }
            lo=4096; hi=268435456
            [ "$v" -ge "$lo" ] || { VERR="$k: below $lo bytes"; return 1; }
            [ "$v" -le "$hi" ] || { VERR="$k: above $hi bytes (256 MiB) — that is not a buffer, that is a leak"; return 1; }
            # A single socket buffer larger than an eighth of RAM is a typo, not a decision.
            if [ "$mt" -gt 0 ] && [ "$v" -gt $(( mt * 1024 / 8 )) ]; then
                VERR="$k: more than an eighth of this machine's RAM in one socket buffer — did you mean KiB?"; return 1
            fi
            ;;
        netdev_max_backlog)
            is_uint "$v" || { VERR="$k: not a whole number of packets"; return 1; }
            [ "$v" -ge 100 ] || { VERR="netdev_max_backlog: below 100 packets"; return 1; }
            # Per CPU. The number the operator types is multiplied by the core count before it
            # becomes memory, which is why the ceiling is lower than it looks.
            [ "$v" -le 32768 ] || { VERR="netdev_max_backlog: above 32768 — this is PER CPU, so on $(cpu_count) cores that is $(( 32768 * $(cpu_count) )) packets queued ahead of the firewall"; return 1; }
            ;;
        udp_mem)
            # three page counts, strictly increasing
            set -- $v
            [ "$#" = "3" ] || { VERR="udp_mem: needs exactly three values (min pressure max), in pages"; return 1; }
            a="$1"; b="$2"; c="$3"
            is_uint "$a" && is_uint "$b" && is_uint "$c" || { VERR="udp_mem: values must be whole page counts"; return 1; }
            [ "$a" -lt "$b" ] && [ "$b" -lt "$c" ] || { VERR="udp_mem: must be strictly increasing (min < pressure < max)"; return 1; }
            if [ "$mt" -gt 0 ]; then
                local pages_total=$(( mt * 1024 / ps ))
                # The limit cannot be a flat fraction of RAM: the kernel's OWN defaults are a large
                # one (on the reference machine it chose min 9.3% and max 18.6% at boot), so a flat
                # rule would refuse the factory setting. Each bound is "twice what is in force, or
                # this fraction of RAM, whichever is more generous" — room for a considered doubling,
                # and still a refusal for the numbers people copy from tuning guides.
                local cur ra rb rc
                cur="$(read_key udp_mem 2>/dev/null)"
                ra=0; rb=0; rc=0
                set -- $cur
                if [ "$#" = "3" ]; then ra="$1"; rb="$2"; rc="$3"; fi
                is_uint "$ra" || ra=0; is_uint "$rb" || rb=0; is_uint "$rc" || rc=0
                set -- $v
                a="$1"; b="$2"; c="$3"
                local capa=$(( pages_total / 100 )) capb=$(( pages_total / 10 )) capc=$(( pages_total / 4 ))
                [ $(( ra * 2 )) -gt "$capa" ] && capa=$(( ra * 2 ))
                [ $(( rb * 2 )) -gt "$capb" ] && capb=$(( rb * 2 ))
                [ $(( rc * 2 )) -gt "$capc" ] && capc=$(( rc * 2 ))
                [ "$a" -le "$capa" ] || { VERR="udp_mem: min too high for this machine (max $capa pages) — below min the kernel never reclaims UDP memory, so this is memory promised away, not a limit"; return 1; }
                [ "$b" -le "$capb" ] || { VERR="udp_mem: pressure too high for this machine (max $capb pages)"; return 1; }
                [ "$c" -le "$capc" ] || { VERR="udp_mem: max too high for this machine (max $capc pages) — under a flood the kernel really will take it"; return 1; }
            fi
            ;;
        *) VERR="unknown key: $k"; return 1 ;;
    esac
    return 0
}

read_key() {
    local p; p="$(key_path "$1")" || return 1
    [ -r "$p" ] || return 1
    tr -s ' \t' ' ' <"$p" 2>/dev/null | tr -d '\n' | sed 's/^ *//; s/ *$//'
}

write_key() {
    local k="$1" v="$2" p
    p="$(key_path "$k")" || return 1
    printf '%s\n' "$v" >"$p" 2>/dev/null
}

# ── namespaces: the reason a write can succeed and mean nothing ──────────────
#
# These keys are per network namespace. A caller inside one (php-fpm with PrivateNetwork, a
# container) can write them, read its own write back, and be completely correct about a change the
# rest of the machine will never see. Reading the value back therefore verifies the write and NOT
# that it matters — so the namespace is compared explicitly, and an arm from inside a private one is
# refused rather than reported as a success.
netns_self() { readlink /proc/self/ns/net 2>/dev/null || printf ''; }
netns_init() { readlink /proc/1/ns/net 2>/dev/null || printf ''; }
netns_ok() {
    local a b
    a="$(netns_self)"; b="$(netns_init)"
    [ -z "$a" ] || [ -z "$b" ] || [ "$a" = "$b" ]
}

# ── measurements the advice is allowed to be built from ─────────────────────
# The tracker socket's real receive buffer, its own drop counter, and the queue it had at the moment
# we looked. `d<N>` is per socket, unlike the global counter, and `rb` is what the kernel actually
# gave the process — which is the only way to tell whether raising a cap would do anything at all.
socket_line() {
    local port="${1:-6969}"
    "$SS_BIN" -ulnpm 2>/dev/null | grep -A1 ":$port" | tr '\n' ' '
}
socket_field() { # $1 = line, $2 = prefix (rb, d, r)
    printf '%s' "$1" | grep -oE "[,(]$2[0-9]+" | head -1 | sed "s/^[,(]$2//"
}
softnet_dropped() {
    [ -r /proc/net/softnet_stat ] || { printf 0; return; }
    # Column 1 is processed, column 2 is DROPPED, column 3 is time_squeeze. Reading the wrong one
    # produces a confident, evidence-cited suggestion to lengthen a queue that never overflowed.
    num_or_zero "$(awk '{ s += strtonum("0x" $2) } END { print s+0 }' /proc/net/softnet_stat 2>/dev/null)"
}
softnet_squeezed() {
    [ -r /proc/net/softnet_stat ] || { printf 0; return; }
    num_or_zero "$(awk '{ s += strtonum("0x" $3) } END { print s+0 }' /proc/net/softnet_stat 2>/dev/null)"
}
udp_mem_pages_in_use() {
    [ -r /proc/net/sockstat ] || { printf 0; return; }
    num_or_zero "$(awk '/^UDP:/ { for (i = 1; i < NF; i++) if ($i == "mem") { print $(i+1); exit } }' /proc/net/sockstat 2>/dev/null)"
}

# ── the file we own, and only that file ─────────────────────────────────────
render_conf() {
    local args="$*" k v
    printf '# %s — written by tracker-sysctl.sh, do not edit by hand.\n' "$CONF_FILE"
    printf '#\n'
    printf '# The panel writes ONLY this file. /etc/sysctl.conf and anything else in %s belongs to\n' "$SYSCTL_D_DIR"
    printf '# whoever put it there and is never touched. Undo everything the panel ever changed here:\n'
    printf '#     sudo %s revert\n' "$SELF"
    printf '# or, if this script is gone, delete this file and reboot.\n'
    printf '#\n'
    printf '# Keys the panel is NOT managing are absent on purpose: a form with eight boxes that\n'
    printf '# writes all eight is exactly how the expensive one gets raised by accident.\n'
    printf '#\n'
    printf '# page size at write time: %s bytes\n' "$(page_size)"
    printf '\n'
    for kv in $args; do
        k="${kv%%=*}"; v="${kv#*=}"
        v="$(printf '%s' "$v" | tr '_' ' ')"     # udp_mem arrives as a=1_2_3
        key_sysctl_name "$k" >/dev/null || continue
        printf '%s = %s\n' "$(key_sysctl_name "$k")" "$v"
    done
}

# ── baseline: what the machine looked like before the panel ever touched it ──
#
# Captured once, immediately before the first write, and then never overwritten. "Restore defaults"
# would be a lie: the operator may have tuned this box years ago, and the distro default is not what
# they want back. Stored as JSON so revert can re-validate every value instead of replaying a file
# as root, which would turn a password-free revert into "apply an arbitrary sysctl file".
capture_baseline() {
    local k v first=1
    [ -f "$BASELINE_FILE" ] && return 0
    mkdir -p "$STATE_DIR" 2>/dev/null || return 1
    chmod 0700 "$STATE_DIR" 2>/dev/null
    chown root:root "$STATE_DIR" 2>/dev/null
    {
        printf '{"captured_at":%s,"page_size":%s,"values":{' "$(date +%s)" "$(page_size)"
        for k in $ALL_KEYS; do
            v="$(read_key "$k")" || continue
            [ "$first" = 1 ] || printf ','
            first=0
            printf '%s:%s' "$(jstr "$k")" "$(jstr "$v")"
        done
        printf '}}\n'
    } >"$BASELINE_FILE" 2>/dev/null || return 1
    chmod 0600 "$BASELINE_FILE" 2>/dev/null
    return 0
}

baseline_value() { # $1 = key
    [ -r "$BASELINE_FILE" ] || return 1
    sed -n 's/.*"'"$1"'":"\([^"]*\)".*/\1/p' "$BASELINE_FILE" 2>/dev/null | head -1
}

# ── the watchdog: a revert nobody has to be alive to trigger ─────────────────
#
# Scheduled through systemd BEFORE anything changes, so it survives PHP, the database, the janitor
# timer and an administrator who can no longer open a session. The unit name carries the arm's nonce
# so a stale unit from a previous cycle can never be mistaken for this one's.
schedule_revert() {
    local secs="$1" nonce="$2"
    command -v "$SYSTEMD_RUN" >/dev/null 2>&1 || return 1
    cancel_reverts
    "$SYSTEMD_RUN" --quiet --collect \
        --unit="$REVERT_UNIT_PREFIX-$nonce" \
        --on-active="${secs}s" \
        --description="Undo the tracker panel's kernel buffer change unless it is confirmed" \
        "$SELF" revert >/dev/null 2>&1
}
cancel_reverts() {
    local u
    command -v "$SYSTEMCTL_BIN" >/dev/null 2>&1 || return 0
    for u in $("$SYSTEMCTL_BIN" list-units --all --no-legend "$REVERT_UNIT_PREFIX*" 2>/dev/null | awk '{print $1}'); do
        "$SYSTEMCTL_BIN" stop "$u" >/dev/null 2>&1
        "$SYSTEMCTL_BIN" reset-failed "$u" >/dev/null 2>&1
    done
    for u in $("$SYSTEMCTL_BIN" list-timers --all --no-legend "$REVERT_UNIT_PREFIX*" 2>/dev/null | awk '{print $NF}'); do
        "$SYSTEMCTL_BIN" stop "$u" >/dev/null 2>&1
    done
    return 0
}
revert_units_present() {
    command -v "$SYSTEMCTL_BIN" >/dev/null 2>&1 || { printf 0; return; }
    "$SYSTEMCTL_BIN" list-units --all --no-legend "$REVERT_UNIT_PREFIX*" 2>/dev/null | grep -c . | tr -d ' \n'
}

# ── actions ──────────────────────────────────────────────────────────────────

emit_values() {
    local k v first=1
    printf '{'
    for k in $ALL_KEYS; do
        v="$(read_key "$k")" || continue
        [ "$first" = 1 ] || printf ','
        first=0
        printf '%s:%s' "$(jstr "$k")" "$(jstr "$v")"
    done
    printf '}'
}

emit_baseline() {
    if [ -r "$BASELINE_FILE" ]; then cat "$BASELINE_FILE"; else printf 'null'; fi
}

emit_armed() {
    if [ -r "$ARM_FILE" ]; then cat "$ARM_FILE"; else printf 'null'; fi
}

# Every other file that mentions one of our keys, and which of them wins at boot. Our file is
# 99-tracker-panel.conf; anything sorting after it silently overrides the panel at the next reboot,
# and the runtime change then looks permanent for weeks before evaporating.
emit_conflicts() {
    local d f first=1 pat
    pat='net\.(core|ipv4)\.(rmem_max|wmem_max|rmem_default|wmem_default|netdev_max_backlog|udp_mem|udp_rmem_min|udp_wmem_min)'
    printf '['
    for d in /usr/lib/sysctl.d /run/sysctl.d "$SYSCTL_D_DIR"; do
        [ -d "$d" ] || continue
        for f in "$d"/*.conf; do
            [ -f "$f" ] || continue
            [ "$f" = "$CONF_FILE" ] && continue
            grep -qE "^[[:space:]]*$pat" "$f" 2>/dev/null || continue
            [ "$first" = 1 ] || printf ','
            first=0
            printf '%s' "$(jstr "$f")"
        done
    done
    if [ -f /etc/sysctl.conf ] && grep -qE "^[[:space:]]*$pat" /etc/sysctl.conf 2>/dev/null; then
        [ "$first" = 1 ] || printf ','
        first=0
        printf '%s' "$(jstr /etc/sysctl.conf)"
    fi
    printf ']'
}

action_status() {
    local port="${1:-6969}" line rb d r
    line="$(socket_line "$port")"
    rb="$(num_or_zero "$(socket_field "$line" rb)")"
    d="$(num_or_zero "$(socket_field "$line" d)")"
    r="$(num_or_zero "$(socket_field "$line" r)")"

    printf '{"ok":true'
    printf ',"version":%s' "$VERSION"
    printf ',"values":%s' "$(emit_values)"
    printf ',"baseline":%s' "$(emit_baseline)"
    printf ',"armed":%s' "$(emit_armed)"
    printf ',"file":%s' "$(jstr "$CONF_FILE")"
    printf ',"file_present":%s' "$([ -f "$CONF_FILE" ] && echo true || echo false)"
    printf ',"conflicts":%s' "$(emit_conflicts)"
    printf ',"page_size":%s' "$(page_size)"
    printf ',"mem_total_kb":%s' "$(mem_total_kb)"
    printf ',"cpus":%s' "$(cpu_count)"
    printf ',"netns_self":%s' "$(jstr "$(netns_self)")"
    printf ',"netns_init":%s' "$(jstr "$(netns_init)")"
    printf ',"netns_shared":%s' "$(netns_ok && echo true || echo false)"
    printf ',"proc_writable":%s' "$([ -w "$PROC_SYS/net/core/rmem_max" ] && echo true || echo false)"
    printf ',"dir_writable":%s' "$(dir_writable && echo true || echo false)"
    printf ',"state_dir_ok":%s' "$([ ! -d "$STATE_DIR" ] || [ "$(stat -c %a "$STATE_DIR" 2>/dev/null)" = "700" ] && echo true || echo false)"
    printf ',"systemd_run":%s' "$(command -v "$SYSTEMD_RUN" >/dev/null 2>&1 && echo true || echo false)"
    printf ',"revert_units":%s' "$(num_or_zero "$(revert_units_present)")"
    printf ',"socket":{"port":%s,"rb":%s,"drops":%s,"queued":%s}' "$(num_or_zero "$port")" "$rb" "$d" "$r"
    printf ',"softnet_dropped":%s' "$(softnet_dropped)"
    printf ',"softnet_squeezed":%s' "$(softnet_squeezed)"
    printf ',"udp_pages_used":%s' "$(udp_mem_pages_in_use)"
    printf '}\n'
}

action_check() {
    local ok=1 notes=""
    is_root || { ok=0; notes="$notes must run as root (sudo);"; }
    [ -w "$PROC_SYS/net/core/rmem_max" ] || { ok=0; notes="$notes /proc/sys is read-only here — the caller is sandboxed (systemd ProtectKernelTunables), so the write has to happen from the janitor;"; }
    netns_ok || { ok=0; notes="$notes this process is in a private network namespace, so a write would be invisible to the rest of the machine;"; }
    dir_writable || notes="$notes ${SYSCTL_D_DIR} is read-only here — confirming will be deferred to the janitor;"
    command -v "$SYSTEMD_RUN" >/dev/null 2>&1 || notes="$notes systemd-run is missing, so the automatic revert would depend on the janitor timer alone;"
    printf '{"ok":%s' "$(jbool "$ok")"
    printf ',"version":%s' "$VERSION"
    printf ',"root":%s' "$(is_root && echo true || echo false)"
    printf ',"proc_writable":%s' "$([ -w "$PROC_SYS/net/core/rmem_max" ] && echo true || echo false)"
    printf ',"netns_shared":%s' "$(netns_ok && echo true || echo false)"
    printf ',"dir_writable":%s' "$(dir_writable && echo true || echo false)"
    printf ',"systemd_run":%s' "$(command -v "$SYSTEMD_RUN" >/dev/null 2>&1 && echo true || echo false)"
    printf ',"notes":%s' "$(jstr "$(printf '%s' "$notes" | sed 's/^ *//')")"
    printf '}\n'
    [ "$ok" = 1 ] || exit 1
}

action_preview() {
    local kv k v
    for kv in "$@"; do
        k="${kv%%=*}"; v="${kv#*=}"
        v="$(printf '%s' "$v" | tr '_' ' ')"
        key_path "$k" >/dev/null || fail "unknown key: $k" 2
        valid_value "$k" "$v" || fail "$VERR" 2
    done
    printf '{"ok":true,"file":%s,"content":%s}\n' "$(jstr "$CONF_FILE")" "$(jstr "$(render_conf "$@")")"
}

action_arm() {
    local secs="$1" nonce="$2"; shift 2
    is_uint "$secs" || fail "arm: seconds must be a whole number" 2
    [ "$secs" -ge 30 ] && [ "$secs" -le 900 ] || fail "arm: the confirmation window must be 30-900 seconds" 2
    case "$nonce" in ''|*[!a-f0-9]*) fail "arm: bad nonce" 2 ;; esac
    [ "$#" -gt 0 ] || fail "arm: nothing to change" 2
    is_root || fail "arm: must run as root" 2
    netns_ok || fail "arm: this process is in a private network namespace — the change would be invisible to the rest of the machine, which is worse than not making it" 3
    [ -w "$PROC_SYS/net/core/rmem_max" ] || fail "arm: /proc/sys is read-only for this process (systemd ProtectKernelTunables) — run this from the janitor, not from a web request" 3

    local kv k v
    for kv in "$@"; do
        k="${kv%%=*}"; v="${kv#*=}"
        v="$(printf '%s' "$v" | tr '_' ' ')"
        key_path "$k" >/dev/null || fail "unknown key: $k" 2
        valid_value "$k" "$v" || fail "$VERR" 2
    done

    capture_baseline || fail "arm: could not capture the baseline into $BASELINE_FILE — refusing to change anything without a recorded way back" 4

    # The way back is scheduled BEFORE the way forward.
    local watchdog="none"
    if schedule_revert "$secs" "$nonce"; then watchdog="systemd"; fi

    local landed_json="" first=1 all_landed=1 now
    now="$(date +%s)"
    for kv in "$@"; do
        k="${kv%%=*}"; v="${kv#*=}"
        v="$(printf '%s' "$v" | tr '_' ' ')"
        write_key "$k" "$v"
        local got; got="$(read_key "$k")"
        local ok=0
        [ "$(printf '%s' "$got" | tr -s ' ')" = "$(printf '%s' "$v" | tr -s ' ')" ] && ok=1
        [ "$ok" = 1 ] || all_landed=0
        [ "$first" = 1 ] || landed_json="$landed_json,"
        first=0
        landed_json="$landed_json$(jstr "$k"):{\"wanted\":$(jstr "$v"),\"got\":$(jstr "$got"),\"landed\":$(jbool "$ok")}"
    done

    mkdir -p "$STATE_DIR" 2>/dev/null; chmod 0700 "$STATE_DIR" 2>/dev/null
    printf '{"nonce":%s,"armed_at":%s,"seconds":%s,"deadline":%s,"watchdog":%s,"wanted":%s}\n' \
        "$(jstr "$nonce")" "$now" "$secs" "$(( now + secs ))" "$(jstr "$watchdog")" "$(jstr "$*")" >"$ARM_FILE" 2>/dev/null
    chmod 0600 "$ARM_FILE" 2>/dev/null

    printf '{"ok":true,"armed":true,"nonce":%s,"deadline":%s,"watchdog":%s,"all_landed":%s,"keys":{%s}}\n' \
        "$(jstr "$nonce")" "$(( now + secs ))" "$(jstr "$watchdog")" "$(jbool "$all_landed")" "$landed_json"
}

action_confirm() {
    local nonce="$1" have
    [ -r "$ARM_FILE" ] || fail "confirm: nothing is armed" 2
    have="$(sed -n 's/.*"nonce":"\([^"]*\)".*/\1/p' "$ARM_FILE" | head -1)"
    [ "$have" = "$nonce" ] || fail "confirm: this is not the change that is armed" 2
    is_root || fail "confirm: must run as root" 2

    local wanted kv k v got
    wanted="$(sed -n 's/.*"wanted":"\([^"]*\)".*/\1/p' "$ARM_FILE" | head -1)"
    [ -n "$wanted" ] || fail "confirm: the armed record has no values" 4

    # Confirming makes a change survive a reboot, which is the escape hatch everything else here
    # depends on. Refuse if what is running is not what was armed.
    for kv in $wanted; do
        k="${kv%%=*}"; v="$(printf '%s' "${kv#*=}" | tr '_' ' ')"
        got="$(read_key "$k")"
        [ "$(printf '%s' "$got" | tr -s ' ')" = "$(printf '%s' "$v" | tr -s ' ')" ] \
            || fail "confirm: $k is $got, not the $v that was armed — refusing to make that permanent" 5
    done

    dir_writable || {
        printf '{"ok":true,"confirmed":false,"deferred":true,"hint":%s}\n' \
            "$(jstr "$SYSCTL_D_DIR is read-only for this process; the janitor writes the file within a minute")"
        exit 0
    }
    render_conf $wanted >"$CONF_FILE" 2>/dev/null || fail "confirm: could not write $CONF_FILE" 4
    chmod 0644 "$CONF_FILE" 2>/dev/null

    # The scheduled revert must die here, or it fires later and quietly undoes a confirmed change.
    cancel_reverts
    local left; left="$(revert_units_present)"
    rm -f "$ARM_FILE" 2>/dev/null
    printf '{"ok":true,"confirmed":true,"file":%s,"revert_units_left":%s}\n' "$(jstr "$CONF_FILE")" "$(num_or_zero "$left")"
}

action_revert() {
    is_root || fail "revert: must run as root" 2
    cancel_reverts
    local k v restored=0 first=1 out="" removed=0 deferred=0
    if [ -r "$BASELINE_FILE" ]; then
        for k in $ALL_KEYS; do
            v="$(baseline_value "$k")" || continue
            [ -n "$v" ] || continue
            # Re-validated, never replayed. The baseline file is root-owned, but a revert that
            # applies a file wholesale as root is a hole regardless of who owns it today.
            valid_value "$k" "$v" || continue
            write_key "$k" "$v" && restored=$(( restored + 1 ))
            [ "$first" = 1 ] || out="$out,"
            first=0
            out="$out$(jstr "$k"):$(jstr "$v")"
        done
    fi
    if [ -f "$CONF_FILE" ]; then
        if rm -f "$CONF_FILE" 2>/dev/null && [ ! -f "$CONF_FILE" ]; then removed=1; else deferred=1; fi
    fi
    rm -f "$ARM_FILE" 2>/dev/null
    printf '{"ok":true,"reverted":true,"restored":%s,"file_removed":%s,"unpersist_deferred":%s,"values":{%s}}\n' \
        "$restored" "$(jbool "$removed")" "$(jbool "$deferred")" "$out"
}

# ── dispatch ─────────────────────────────────────────────────────────────────
#
# One reply, built into a variable and printed with a single printf. A stray line from any command
# substitution then lands beside the JSON instead of inside it — the bug that once made a healthy
# firewall report itself as unavailable.
main() {
    local action="${1:-status}"; shift || true
    local reply rc=0
    case "$action" in
        status)  reply="$(action_status "${1:-6969}")" || rc=$? ;;
        check)   reply="$(action_check)" || rc=$? ;;
        preview) reply="$(action_preview "$@")" || rc=$? ;;
        arm)     reply="$(action_arm "$@")" || rc=$? ;;
        confirm) reply="$(action_confirm "${1:-}")" || rc=$? ;;
        revert)  reply="$(action_revert)" || rc=$? ;;
        --help|-h|help)
            sed -n '2,30p' "$0"; exit 0 ;;
        *)
            reply="$(printf '{"ok":false,"error":%s}' "$(jstr "unknown action: $action")")"; rc=2 ;;
    esac
    printf '%s\n' "$reply"
    exit "$rc"
}

main "$@"
