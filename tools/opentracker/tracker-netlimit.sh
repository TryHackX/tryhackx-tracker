#!/bin/bash
# tracker-netlimit.sh — inbound UDP rate limit for the tracker port, driven by the admin panel.
#
# WHY A SECOND TABLE
#   `tools/opentracker/egress-budget/ottrack.nft` caps what the tracker *sends* (keeps the NIC's
#   transmit path — and therefore the whole VM — reachable). This helper caps what the tracker
#   *receives*: packets dropped here never reach opentracker, so they cost no CPU at all. Both
#   levers are needed and they are independent.
#
# NON-INVASIVE BY CONSTRUCTION
#   Everything lives in its own table `inet ottrack_in`, persisted as ONE file in /etc/nftables.d/.
#   The distribution's `inet filter` table (and any rule an admin added there by hand) is never
#   read, written or flushed. Undo is a single `off` — or just deleting the file.
#
# usage:
#   tracker-netlimit.sh status [--brief]           JSON: table, counters, limits, egress, manual rules
#                                                  --brief skips the foreign-rule scan (see below)
#   tracker-netlimit.sh check                      JSON: is this machine able to run the feature at all
#   tracker-netlimit.sh monitor [port] [--dry-run]  load the COUNTERS ONLY — no drop rule at all
#   tracker-netlimit.sh set <pps> [burst] [port] [--dry-run]
#                                                  write + atomically load the ruleset
#   tracker-netlimit.sh off [--dry-run]            delete the table and the file (traffic unthrottled)
#   tracker-netlimit.sh egress <pps> [--dry-run]   change the rate of the EXISTING `inet ottrack` budget
#
# Every action prints one JSON object on stdout and exits 0 on success, non-zero on failure (the
# JSON then carries "ok":false and "error"). Arguments are validated here, never interpolated into
# a shell command, so the sudoers rule can safely be NOPASSWD on the script itself:
#
#   www-data ALL=(root) NOPASSWD: /usr/local/sbin/tracker-netlimit.sh
#
# Test hooks (used by tests/netlimit_test.php): NFT_BIN overrides the nft binary, NFT_DIR the
# persistence directory, NFT_CONF the main nftables.conf that is checked for the include line.
set -u

NFT="${NFT_BIN:-}"
[ -n "$NFT" ] || NFT="$(command -v nft 2>/dev/null || true)"
[ -n "$NFT" ] || for c in /usr/sbin/nft /sbin/nft /usr/bin/nft; do [ -x "$c" ] && NFT="$c" && break; done

NFT_DIR="${NFT_DIR:-/etc/nftables.d}"
NFT_CONF="${NFT_CONF:-/etc/nftables.conf}"
RULES_FILE="$NFT_DIR/ottrack-in.nft"
EGRESS_FILE="$NFT_DIR/ottrack.nft"
TABLE="ottrack_in"

PPS_MIN=1000
PPS_MAX=1000000
BURST_MIN=1
BURST_MAX=65535
tmp=""          # temp ruleset file; global so the EXIT trap can still see it after the function returns

# ── tiny JSON writer (no jq dependency) ──────────────────────────────────────
# Escapes in order: backslash, quote, tab, CR; then folds the remaining newlines into \n (the
# ruleset preview is multi-line) and drops the control characters JSON cannot carry.
jesc() {
    printf '%s' "${1-}" \
        | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g' -e 's/\t/\\t/g' -e 's/\r//g' \
        | sed -e ':a' -e 'N' -e '$!ba' -e 's/\n/\\n/g' \
        | tr -d '\000-\010\013\014\016-\037'
}
jstr() { printf '"%s"' "$(jesc "${1-}")"; }
fail() { printf '{"ok":false,"error":%s}\n' "$(jstr "$1")"; printf '%s\n' "$1" >&2; exit "${2:-2}"; }

# ── argument validation ──────────────────────────────────────────────────────
is_uint() { case "${1-}" in ''|*[!0-9]*) return 1 ;; *) return 0 ;; esac; }
want_range() {  # want_range <value> <min> <max> <label>
    is_uint "$1" || fail "$4 must be a whole number (got '$1')"
    [ "$1" -ge "$2" ] && [ "$1" -le "$3" ] || fail "$4 must be between $2 and $3 (got $1)"
}

have_nft() { [ -n "$NFT" ] && [ -x "$NFT" ]; }
is_root()  { [ "$(id -u 2>/dev/null || echo 1)" = "0" ]; }

# ── state readers ────────────────────────────────────────────────────────────

# Counters of a table as "name packets bytes" lines. Empty when the table is absent.
read_counters() {
    have_nft || return 0
    "$NFT" list counters table inet "$1" 2>/dev/null | awk '
        /^[[:space:]]*counter [A-Za-z0-9_]+ [{]/ { name = $2; next }
        name != "" && /packets/ { for (i = 1; i < NF; i++) { if ($i == "packets") p = $(i+1); if ($i == "bytes") b = $(i+1) }
                                  print name, p+0, b+0; name = "" }'
}

# The rate currently programmed in our input chain, or empty.
read_limit() {
    have_nft || return 0
    "$NFT" list chain inet "$TABLE" input 2>/dev/null | awk '
        /limit rate over/ { for (i = 1; i < NF; i++) if ($i == "rate" && $(i+1) == "over") { split($(i+2), a, "/"); print a[1]; exit } }'
}
read_burst() {
    have_nft || return 0
    "$NFT" list chain inet "$TABLE" input 2>/dev/null | awk '
        /limit rate over/ { for (i = 1; i < NF; i++) if ($i == "burst") { print $(i+1); exit } }'
}
read_port() {
    have_nft || return 0
    "$NFT" list chain inet "$TABLE" input 2>/dev/null | awk '
        /udp dport != / { for (i = 1; i < NF; i++) if ($i == "dport" && $(i+1) == "!=") { print $(i+2); exit } }'
}
read_egress_limit() {
    have_nft || return 0
    "$NFT" list chain inet ottrack output 2>/dev/null | awk '
        /limit rate over/ { for (i = 1; i < NF; i++) if ($i == "rate" && $(i+1) == "over") { split($(i+2), a, "/"); print a[1]; exit } }'
}

# Handle of the rate-limit rule in a chain, for a targeted `nft replace` (empty when not found).
read_rule_handle() {  # read_rule_handle <table> <chain>
    have_nft || return 0
    "$NFT" -a list chain inet "$1" "$2" 2>/dev/null | awk '
        /limit rate over/ { for (i = 1; i < NF; i++) if ($i == "handle") { print $(i+1); exit } }'
}
# Does a table exist? Asked by listing table NAMES, never by dumping the table.
#
# `nft list table inet ottrack` serialises every element of every set in it, and the egress budget
# keeps a dynamic set of up to 262 144 client addresses with a 3 h timeout. Measured on production
# with that set full: the existence test alone cost **5.5 s of one core** — and `status` runs it on
# every poll of the Traffic page, so a core sat at 100 % answering a yes/no question. Listing the
# names costs 26 ms and answers the same question exactly.
#
# The match is anchored (`grep -qx`) because `ottrack` is a prefix of `ottrack_in`: a substring match
# would report the egress table as loaded whenever the inbound one was.
table_exists() {
    have_nft || return 1
    "$NFT" list tables 2>/dev/null | grep -qx "table inet $1"
}

# The values we last wrote, read back from the "# tracker-netlimit:" header of the generated file.
# nft omits `burst N packets` from its own output when the burst is its default (5), so the live
# ruleset alone cannot always tell us what was asked for.
read_header() {  # read_header pps|burst|port
    [ -r "$RULES_FILE" ] || return 0
    awk -v k="$1=" '/^# tracker-netlimit:/ { for (i = 1; i <= NF; i++) if (index($i, k) == 1) { print substr($i, length(k) + 1); exit } }' "$RULES_FILE"
}

# What the egress FILE says, as opposed to what is loaded. The two drifting apart is exactly the
# failure the panel is meant to catch: a budget changed from the panel is live immediately and gone
# at the next reboot if nobody wrote the file.
read_egress_file_limit() {
    [ -r "$EGRESS_FILE" ] || return 0
    sed -n 's/.*limit rate over \([0-9]\{1,\}\)\/second.*/\1/p' "$EGRESS_FILE" | head -1
}

# Rules in OTHER tables that also rate-limit this port — an admin's hand-made rule, typically in
# `inet filter` and typically only in RAM. We never touch them; the panel just needs to say so.
#
# This is the expensive part of `status`: it dumps every table in the ruleset, and on a box with
# fail2ban that can be thousands of rules. The panel therefore polls with `status --brief` and only
# asks for the full picture every couple of minutes.
read_manual_rules() {
    have_nft || return 0
    local port="$1" t
    for t in $("$NFT" list tables 2>/dev/null | awk '{print $2 " " $3}' | tr ' ' '/' | grep -v "/$TABLE\$" | grep -v '/ottrack$'); do
        local fam="${t%%/*}" name="${t##*/}"
        "$NFT" -a list table "$fam" "$name" 2>/dev/null | awk -v fam="$fam" -v nm="$name" -v port="$port" '
            /^[[:space:]]*chain [A-Za-z0-9_.-]+ [{]/ { ch = $2 }
            /limit rate over/ && /dport/ && $0 ~ ("dport " port) {
                h = ""; for (i = 1; i < NF; i++) if ($i == "handle") h = $(i+1)
                line = $0; sub(/^[[:space:]]+/, "", line); sub(/[[:space:]]*#.*$/, "", line)
                print fam "\t" nm "\t" ch "\t" h "\t" line }'
    done
}

include_ok() {
    [ -r "$NFT_CONF" ] || return 1
    grep -Eq '^[[:space:]]*include[[:space:]]+"?'"$(printf '%s' "$NFT_DIR" | sed 's/[.[\*^$]/\\&/g')"'/' "$NFT_CONF"
}

# Can we actually SAVE there? systemd's ProtectSystem=full — which php-fpm runs with on this class
# of box — mounts /etc read-only for the service AND for everything it starts, root included: it is
# a mount namespace, not a permission bit. A helper invoked from PHP can therefore load a ruleset
# into the kernel (a syscall, unaffected) and still be unable to write the file that would bring it
# back after a reboot. The janitor is a separate unit without that sandbox, so it can.
dir_writable() {
    [ -d "$NFT_DIR" ] || return 1
    local probe="$NFT_DIR/.wtest.$$"
    # The redirection must happen inside a subshell whose stderr is ALREADY /dev/null. Bash applies
    # redirections left to right, so `: >"$probe" 2>/dev/null` reports "Read-only file system" on the
    # real stderr before the 2>/dev/null is ever set up — and this runs inside a command substitution
    # in the middle of building the status JSON, so that line landed inside the JSON and made the
    # whole reply unparseable. The card then said the firewall was unavailable while it was fine.
    if ( : >"$probe" ) 2>/dev/null; then rm -f "$probe" 2>/dev/null; return 0; fi
    return 1
}

# Does the file on disk describe what is actually loaded? "A file exists" is not the question the
# admin is asking when the card says persistent — they are asking whether the limit survives a
# reboot, and a stale file answers no.
file_matches() {
    [ -f "$RULES_FILE" ] || return 1
    local live; live="$(read_mode)"
    [ "$live" = none ] && return 1
    local fmode; fmode="$(read_header mode)"; [ -n "$fmode" ] || fmode=limit
    [ "$fmode" = "$live" ] || return 1
    local fport lport; fport="$(read_header port)"; lport="$(read_port)"
    [ -n "$lport" ] && [ "$fport" = "$lport" ] || return 1
    [ "$live" = count ] && return 0
    local fpps lpps fburst lburst
    fpps="$(read_header pps)"; lpps="$(read_limit)"
    [ "$fpps" = "$lpps" ] || return 1
    # nft omits `burst N packets` from its own output when the burst is its default of 5
    fburst="$(read_header burst)"; lburst="$(read_burst)"; [ -n "$lburst" ] || lburst=5
    [ "$fburst" = "$lburst" ] || return 1
    return 0
}

# Save the generated ruleset next to the others.
#   0 = saved   1 = deferred (read-only mount namespace — the janitor finishes it)   2 = failed
save_rules() {  # save_rules <tmpfile>
    install -m 0644 "$1" "$RULES_FILE" 2>/dev/null && return 0
    dir_writable && return 2
    return 1
}
PERSIST_HINT="this process cannot write $NFT_DIR (systemd ProtectSystem); the janitor saves it within a minute"

# Is the loaded table counting only, enforcing a limit, or absent? The panel needs to be able to say
# which, because "monitoring" and "throttling" are different promises.
read_mode() {
    table_exists "$TABLE" || { printf 'none'; return 0; }
    if [ -n "$(read_limit)" ]; then printf 'limit'; else printf 'count'; fi
}

# ── trusted addresses ────────────────────────────────────────────────────────
# Addresses the rate limit must never drop: a game server on the same box, a monitoring host, the
# admin's own address. They are validated HERE as well as in the panel, because this script runs as
# root and its arguments end up inside an nft ruleset -- a caller is not a reason to skip a check.
#
# Kept as two nft sets rather than a list of rules: a set is one hash lookup whatever its size, and
# `flags interval` lets a CIDR and a single address live in the same set.
TRUSTED4=""
TRUSTED6=""
TRUSTED_MAX=256

valid_v4() {  # a.b.c.d or a.b.c.d/0..32
    case "$1" in
        *[!0-9./]*) return 1 ;;
    esac
    local addr="${1%%/*}" bits="" o1 o2 o3 o4 rest
    case "$1" in */*) bits="${1##*/}" ;; esac
    if [ -n "$bits" ]; then
        case "$bits" in ''|*[!0-9]*) return 1 ;; esac
        [ "$bits" -le 32 ] || return 1
    fi
    IFS=. read -r o1 o2 o3 o4 rest <<EOF2
$addr
EOF2
    [ -z "$rest" ] || return 1
    for o in "$o1" "$o2" "$o3" "$o4"; do
        case "$o" in ''|*[!0-9]*) return 1 ;; esac
        [ "$o" -le 255 ] || return 1
    done
    return 0
}

valid_v6() {  # hex groups, ::, optional /0..128 -- deliberately permissive on shape, strict on charset
    case "$1" in
        *:*) : ;;
        *) return 1 ;;
    esac
    case "$1" in
        *[!0-9A-Fa-f:/]*) return 1 ;;
    esac
    local bits=""
    case "$1" in */*) bits="${1##*/}" ;; esac
    if [ -n "$bits" ]; then
        case "$bits" in ''|*[!0-9]*) return 1 ;; esac
        [ "$bits" -le 128 ] || return 1
    fi
    return 0
}

# Fill TRUSTED4 / TRUSTED6 from a comma-separated argument. Anything unrecognised is DROPPED with a
# note on stderr rather than failing the whole apply: one fat-fingered address must not be able to
# leave the tracker unprotected, and it must not silently become a rule either.
parse_trusted() {  # parse_trusted <comma-separated>
    local raw="$1" item n=0
    TRUSTED4=""; TRUSTED6=""
    [ -n "$raw" ] || return 0
    local oldifs="$IFS"
    IFS=','
    for item in $raw; do
        IFS="$oldifs"
        item="$(printf '%s' "$item" | tr -d '[:space:]')"
        [ -n "$item" ] || { IFS=','; continue; }
        n=$((n + 1))
        if [ "$n" -gt "$TRUSTED_MAX" ]; then
            echo "tracker-netlimit: more than $TRUSTED_MAX trusted addresses, ignoring the rest" >&2
            break
        fi
        if valid_v4 "$item"; then
            TRUSTED4="${TRUSTED4:+$TRUSTED4, }$item"
        elif valid_v6 "$item"; then
            TRUSTED6="${TRUSTED6:+$TRUSTED6, }$item"
        else
            echo "tracker-netlimit: ignoring trusted entry that is not an address: $item" >&2
        fi
        IFS=','
    done
    IFS="$oldifs"
    return 0
}

# Read the exemptions back out of the LOADED table.
#
# `persist` re-renders the ruleset from what is live and saves it, and it is the NORMAL path on this
# machine: php-fpm cannot write /etc, so an apply defers and the janitor calls persist a minute
# later. Re-rendering without reading the sets back would save a file with the exemptions stripped —
# the live rules keep them, the reboot does not. These sets are capped at 256 elements, so listing
# them is cheap; this is not the 262 144-element egress set.
read_trusted_live() {
    TRUSTED4=""; TRUSTED6=""
    have_nft || return 0
    local setname els
    for setname in trusted4 trusted6; do
        els="$("$NFT" list set inet "$TABLE" "$setname" 2>/dev/null | tr -d '\n' \
               | sed -n 's/.*elements[[:space:]]*=[[:space:]]*{\([^}]*\)}.*/\1/p' | tr -d ' ')"
        [ -n "$els" ] || continue
        els="$(printf '%s' "$els" | sed 's/,/, /g')"
        if [ "$setname" = trusted4 ]; then TRUSTED4="$els"; else TRUSTED6="$els"; fi
    done
}

# The two sets, always emitted so the chain can reference them even when empty.
render_trusted_sets() {
    printf '    set trusted4 { type ipv4_addr; flags interval;%s }\n' \
        "$([ -n "$TRUSTED4" ] && printf ' elements = { %s };' "$TRUSTED4")"
    printf '    set trusted6 { type ipv6_addr; flags interval;%s }\n' \
        "$([ -n "$TRUSTED6" ] && printf ' elements = { %s };' "$TRUSTED6")"
}

# The bypass, placed AFTER in_total and BEFORE the limit: a trusted packet is still counted as
# arriving (or the arrival rate on the card would understate reality) and still counted as passed,
# it simply never reaches the budget.
render_trusted_rules() {
    printf '        ip  saddr @trusted4 counter name in_passed accept\n'
    printf '        ip6 saddr @trusted6 counter name in_passed accept\n'
}

# ── ruleset rendering ────────────────────────────────────────────────────────
# One `nft -f` transaction: create-if-missing, delete, recreate. nftables applies the whole file
# atomically, so the port is never left unprotected between the delete and the new rules.

# Counters WITHOUT a drop rule. This exists because the whole "measure first, then pick a threshold"
# workflow needs numbers before there is a limit — and the counters live in the firewall, so with no
# table at all there is nothing to count. The chain accepts by default and contains no `drop`, so it
# cannot discard a packet: it is a meter, not a valve.
render_monitor() {  # render_monitor <port>
    cat <<EOF
#!/usr/sbin/nft -f
# $RULES_FILE — generated by tracker-netlimit.sh, do not edit by hand.
# COUNTING ONLY: this ruleset contains no drop rule and cannot discard a packet. It exists so the
# panel can measure the arrival rate on the tracker's UDP port before anyone picks a limit.
# tracker-netlimit: pps=0 burst=0 port=$1 mode=count generated=$(date -u '+%Y-%m-%dT%H:%M:%SZ')
# Undo: tracker-netlimit.sh off   (or: nft delete table inet $TABLE && rm $RULES_FILE)
table inet $TABLE {}
delete table inet $TABLE
table inet $TABLE {
    counter in_total  {}
    counter in_passed {}
    counter in_capped {}
    chain input {
        type filter hook input priority filter - 5; policy accept;
        meta l4proto != udp accept
        udp dport != $1 accept
        counter name in_total
        counter name in_passed
    }
}
EOF
}

render() {  # render <pps> <burst> <port>
    cat <<EOF
#!/usr/sbin/nft -f
# $RULES_FILE — generated by tracker-netlimit.sh, do not edit by hand.
# Inbound rate limit for the tracker's UDP port: packets over the budget are dropped BEFORE
# opentracker sees them. Own table, own file — nothing else in the ruleset is touched.
# tracker-netlimit: pps=$1 burst=$2 port=$3 trusted=$(printf '%s' "$TRUSTED4$([ -n "$TRUSTED4" ] && [ -n "$TRUSTED6" ] && printf ', ')$TRUSTED6" | tr -d ' ' | tr ',' '|') generated=$(date -u '+%Y-%m-%dT%H:%M:%SZ')
# Undo: tracker-netlimit.sh off   (or: nft delete table inet $TABLE && rm $RULES_FILE)
table inet $TABLE {}
delete table inet $TABLE
table inet $TABLE {
    counter in_total  {}
    counter in_passed {}
    counter in_capped {}
$(render_trusted_sets)
    chain input {
        type filter hook input priority filter - 5; policy accept;
        # Only the tracker's UDP port is in scope. Both guards are required: on a non-UDP packet the
        # \`udp dport\` match simply fails, so without the l4proto line TCP would fall through to the
        # budget below and get dropped.
        meta l4proto != udp accept
        udp dport != $3 accept
        counter name in_total
$(render_trusted_rules)
        limit rate over $1/second burst $2 packets counter name in_capped drop
        counter name in_passed
    }
}
EOF
}

# ── actions ──────────────────────────────────────────────────────────────────

emit_status() {
    local brief="${1-}"
    local port limit burst tbl
    tbl=no; table_exists "$TABLE" && tbl=yes
    limit="$(read_limit)"; burst="$(read_burst)"; port="$(read_port)"
    # a counting-only table genuinely has no rate; its header says pps=0, which is the truth
    [ -n "$limit" ] || limit="$(read_header pps)"
    [ -n "$burst" ] || burst="$(read_header burst)"
    [ -n "$port" ]  || port="$(read_header port)"
    # These three are printed as JSON *numbers*, and one of the sources is a file an admin could have
    # edited by hand — anything that is not a plain integer becomes 0 rather than broken JSON.
    is_uint "$limit" || limit=0
    is_uint "$burst" || burst=0
    is_uint "$port"  || port=6969

    printf '{"ok":true'
    printf ',"nft":%s' "$(have_nft && echo true || echo false)"
    printf ',"nft_path":%s' "$(jstr "$NFT")"
    printf ',"root":%s' "$(is_root && echo true || echo false)"
    printf ',"table":%s' "$([ "$tbl" = yes ] && echo true || echo false)"
    printf ',"file":%s' "$([ -f "$RULES_FILE" ] && echo true || echo false)"
    printf ',"file_path":%s' "$(jstr "$RULES_FILE")"
    # `persistent` answers "will what is loaded still be here after a reboot?", so a file holding a
    # DIFFERENT ruleset — applied live but never saved — has to make it false, not true.
    printf ',"persistent":%s' "$([ -f "$RULES_FILE" ] && include_ok && file_matches && echo true || echo false)"
    printf ',"file_present":%s' "$([ -f "$RULES_FILE" ] && echo true || echo false)"
    printf ',"file_matches":%s' "$(file_matches && echo true || echo false)"
    printf ',"file_mode":%s' "$(jstr "$(fm="$(read_header mode)"; [ -f "$RULES_FILE" ] && { [ -n "$fm" ] && printf '%s' "$fm" || printf 'limit'; })")"
    printf ',"file_pps":%s' "$(fp="$(read_header pps)"; is_uint "$fp" && printf '%s' "$fp" || printf '0')"
    printf ',"dir_writable":%s' "$(dir_writable && echo true || echo false)"
    printf ',"include_ok":%s' "$(include_ok && echo true || echo false)"
    printf ',"mode":%s' "$(jstr "$(read_mode)")"
    printf ',"pps":%s' "$limit"
    printf ',"burst":%s' "$burst"
    printf ',"port":%s' "$port"

    printf ',"counters":{'
    local first=1
    while read -r n p b; do
        [ -n "$n" ] || continue
        [ $first -eq 1 ] || printf ','
        first=0
        printf '%s:{"packets":%s,"bytes":%s}' "$(jstr "$n")" "$p" "$b"
    done <<EOF
$(read_counters "$TABLE")
EOF
    printf '}'

    local elimit; elimit="$(read_egress_limit)"; is_uint "$elimit" || elimit=0
    local egfile; egfile="$(read_egress_file_limit)"; is_uint "$egfile" || egfile=0
    # A budget that is live but absent from the file is gone at the next reboot — the exact
    # thing an admin would only discover by rebooting, so the card gets told about it.
    printf ',"egress":{"table":%s,"pps":%s,"file":%s,"file_pps":%s,"file_matches":%s,"counters":{' \
        "$(table_exists ottrack && echo true || echo false)" \
        "$elimit" \
        "$([ -f "$EGRESS_FILE" ] && echo true || echo false)" \
        "$egfile" \
        "$([ "$egfile" = "$elimit" ] && [ "$elimit" != 0 ] && echo true || echo false)"
    first=1
    while read -r n p b; do
        [ -n "$n" ] || continue
        [ $first -eq 1 ] || printf ','
        first=0
        printf '%s:{"packets":%s,"bytes":%s}' "$(jstr "$n")" "$p" "$b"
    done <<EOF
$(read_counters ottrack)
EOF
    printf '}}'

    if [ "$brief" = "--brief" ]; then
        printf ',"brief":true'
    else
        printf ',"brief":false,"manual_rules":['
        first=1
        while IFS="$(printf '\t')" read -r fam nm ch h line; do
            [ -n "${fam:-}" ] || continue
            [ $first -eq 1 ] || printf ','
            first=0
            printf '{"family":%s,"table":%s,"chain":%s,"handle":%s,"rule":%s,"undo":%s}' \
                "$(jstr "$fam")" "$(jstr "$nm")" "$(jstr "$ch")" "$(jstr "$h")" "$(jstr "$line")" \
                "$(jstr "nft delete rule $fam $nm $ch handle $h")"
        done <<EOF
$(read_manual_rules "$port")
EOF
        printf ']'
    fi
    printf ',"ts":%s}\n' "$(date +%s)"
}

emit_check() {
    local errs="" hints=""
    have_nft || { errs="nftables (nft) was not found on this machine."; hints="Install it: sudo apt install nftables"; }
    if have_nft && ! is_root; then
        errs="${errs:+$errs | }This helper must run as root (nft needs it)."
        hints="${hints:+$hints | }Call it through sudo: sudo -n $0 status"
    fi
    if have_nft && is_root; then
        [ -d "$NFT_DIR" ] || { errs="${errs:+$errs | }$NFT_DIR does not exist."; hints="${hints:+$hints | }sudo install -d -m 0755 $NFT_DIR"; }
        include_ok || { errs="${errs:+$errs | }$NFT_CONF does not include $NFT_DIR — the limit would be lost on reboot."
                        hints="${hints:+$hints | }Append one line to $NFT_CONF:  include \"$NFT_DIR/*.nft\""; }
        # Not an error: the janitor runs outside the web server's sandbox and saves the file there.
        # Worth saying out loud though — it is the difference between "saved" and "saved in a minute".
        dir_writable || hints="${hints:+$hints | }$NFT_DIR is read-only for this process (systemd ProtectSystem), so rules applied from the panel are written by the janitor instead, within a minute. To make it immediate: sudo systemctl edit php-fpm, add ReadWritePaths=-$NFT_DIR"
    fi
    printf '{"ok":%s,"nft":%s,"nft_path":%s,"root":%s,"dir":%s,"dir_writable":%s,"include_ok":%s,"conf":%s,"error":%s,"hint":%s}\n' \
        "$([ -z "$errs" ] && echo true || echo false)" \
        "$(have_nft && echo true || echo false)" "$(jstr "$NFT")" \
        "$(is_root && echo true || echo false)" \
        "$([ -d "$NFT_DIR" ] && echo true || echo false)" \
        "$(dir_writable && echo true || echo false)" \
        "$(include_ok && echo true || echo false)" "$(jstr "$NFT_CONF")" \
        "$(jstr "$errs")" "$(jstr "$hints")"
    [ -z "$errs" ]
}

action_set() {
    local pps="${1-}" burst="${2-100}" port="${3-6969}" dry="" trusted=""
    # The two optional tails may arrive in either order and either may be absent, so they are matched
    # by shape rather than by position: --dry-run is a flag, --trusted=... carries a value.
    local a
    for a in "${4-}" "${5-}"; do
        case "$a" in
            --dry-run)   dry="--dry-run" ;;
            --trusted=*) trusted="${a#--trusted=}" ;;
            "")          : ;;
            *) fail "unknown option '$a' — use --dry-run and/or --trusted=<comma-separated>" 1 ;;
        esac
    done
    want_range "$pps"   "$PPS_MIN"   "$PPS_MAX"   "Rate (packets/second)"
    want_range "$burst" "$BURST_MIN" "$BURST_MAX" "Burst (packets)"
    want_range "$port"  1            65535        "Port"
    have_nft || fail "nftables (nft) is not installed — cannot apply a rate limit."
    parse_trusted "$trusted"

    tmp="$(mktemp "${TMPDIR:-/tmp}/ottrack-in.XXXXXX")" || fail "cannot create a temporary file"
    trap 'rm -f "${tmp:-}"' EXIT
    render "$pps" "$burst" "$port" >"$tmp"

    local chk; chk="$("$NFT" -c -f "$tmp" 2>&1)" || fail "nft rejected the generated ruleset: $chk" 3

    if [ "$dry" = "--dry-run" ]; then
        printf '{"ok":true,"dry_run":true,"pps":%s,"burst":%s,"port":%s,"trusted":%s,"file":%s,"ruleset":%s}\n' \
            "$pps" "$burst" "$port" "$(jstr "$TRUSTED4$([ -n "$TRUSTED4" ] && [ -n "$TRUSTED6" ] && printf ', ')$TRUSTED6")" \
            "$(jstr "$RULES_FILE")" "$(jstr "$(cat "$tmp")")"
        return 0
    fi

    is_root || fail "must run as root to change the firewall"
    [ -d "$NFT_DIR" ] || mkdir -p "$NFT_DIR" || fail "cannot create $NFT_DIR"

    # Preferred path: the table is already there for the same port and only the rate changes, so
    # swap that ONE rule by handle. Rebuilding the table would restart the three counters, and the
    # monitor reads rates as differences between two readings — every automatic ±10 % move would
    # punch a hole in the chart. A targeted replace leaves the counters running.
    local mode=reload out handle
    # The fast path swaps ONE rule and leaves the rest of the table alone -- including the trusted
    # sets. So it is only available when the exemptions are unchanged; otherwise the table has to be
    # rebuilt, counters and all, or the panel would report a trusted list the firewall never got.
    local trusted_now trusted_was
    trusted_now="$(printf '%s' "$TRUSTED4$([ -n "$TRUSTED4" ] && [ -n "$TRUSTED6" ] && printf ', ')$TRUSTED6" | tr -d ' ' | tr ',' '|')"
    trusted_was="$(read_header trusted)"
    if [ "$trusted_now" = "$trusted_was" ] && table_exists "$TABLE" && [ "$(read_port)" = "$port" ]; then
        handle="$(read_rule_handle "$TABLE" input)"
        if [ -n "$handle" ]; then
            if out="$("$NFT" replace rule inet "$TABLE" input handle "$handle" \
                        limit rate over "$pps"/second burst "$burst" packets counter name in_capped drop 2>&1)"; then
                mode=replace
            fi
        fi
    fi
    if [ "$mode" = reload ]; then
        out="$("$NFT" -f "$tmp" 2>&1)" || fail "nft failed to load the ruleset: $out" 3
    fi
    # The rule is in the kernel from here on. Saving it can still fail for a reason that is NOT a
    # failed apply and NOT the admin's doing: see dir_writable().
    local rc=0; save_rules "$tmp" || rc=$?
    [ "$rc" = 2 ] && fail "ruleset is live but could not be saved to $RULES_FILE (it will be lost on reboot)" 4

    printf '{"ok":true,"applied":true,"mode":%s,"pps":%s,"burst":%s,"port":%s,"file":%s,"saved":%s,"persist_deferred":%s,"persist_hint":%s,"persistent":%s}\n' \
        "$(jstr "$mode")" "$pps" "$burst" "$port" "$(jstr "$RULES_FILE")" \
        "$([ "$rc" = 0 ] && echo true || echo false)" "$([ "$rc" = 1 ] && echo true || echo false)" \
        "$(jstr "$([ "$rc" = 1 ] && printf '%s' "$PERSIST_HINT")")" \
        "$([ "$rc" = 0 ] && include_ok && echo true || echo false)"
}

# Load the counting-only table. Same atomic transaction as `set`, and it never uses the targeted
# replace: switching between counting and limiting means adding or removing a rule, not editing one.
action_monitor() {
    local port="${1-6969}" dry="${2-}"
    [ "$port" = "--dry-run" ] && { dry=--dry-run; port=6969; }
    want_range "$port" 1 65535 "Port"
    have_nft || fail "nftables (nft) is not installed — cannot count packets."

    tmp="$(mktemp "${TMPDIR:-/tmp}/ottrack-in.XXXXXX")" || fail "cannot create a temporary file"
    trap 'rm -f "${tmp:-}"' EXIT
    render_monitor "$port" >"$tmp"
    local chk; chk="$("$NFT" -c -f "$tmp" 2>&1)" || fail "nft rejected the generated ruleset: $chk" 3

    if [ "$dry" = "--dry-run" ]; then
        printf '{"ok":true,"dry_run":true,"mode":"count","port":%s,"file":%s,"ruleset":%s}\n' \
            "$port" "$(jstr "$RULES_FILE")" "$(jstr "$(cat "$tmp")")"
        return 0
    fi
    is_root || fail "must run as root to change the firewall"
    [ -d "$NFT_DIR" ] || mkdir -p "$NFT_DIR" || fail "cannot create $NFT_DIR"
    local out; out="$("$NFT" -f "$tmp" 2>&1)" || fail "nft failed to load the ruleset: $out" 3
    local rc=0; save_rules "$tmp" || rc=$?
    [ "$rc" = 2 ] && fail "ruleset is live but could not be saved to $RULES_FILE (it will be lost on reboot)" 4

    printf '{"ok":true,"applied":true,"mode":"count","port":%s,"file":%s,"saved":%s,"persist_deferred":%s,"persist_hint":%s,"persistent":%s}\n' \
        "$port" "$(jstr "$RULES_FILE")" \
        "$([ "$rc" = 0 ] && echo true || echo false)" "$([ "$rc" = 1 ] && echo true || echo false)" \
        "$(jstr "$([ "$rc" = 1 ] && printf '%s' "$PERSIST_HINT")")" \
        "$([ "$rc" = 0 ] && include_ok && echo true || echo false)"
}

# Rewrite the egress budget in its own file.
#   0 = saved   1 = deferred (read-only mount namespace)   2 = failed / nothing to edit
save_egress_file() {  # save_egress_file <pps>
    [ -f "$EGRESS_FILE" ] || return 2
    local tmpf
    tmpf="$(mktemp "${TMPDIR:-/tmp}/ottrack-eg.XXXXXX")" || return 2
    sed -E "s#(limit rate over )[0-9]+(/second)#\\1$1\\2#" "$EGRESS_FILE" >"$tmpf" || { rm -f "$tmpf"; return 2; }
    if install -m 0644 "$tmpf" "$EGRESS_FILE" 2>/dev/null; then rm -f "$tmpf"; return 0; fi
    rm -f "$tmpf"
    dir_writable && return 2
    return 1
}

# Write the file so it describes what is CURRENTLY loaded, and nothing else. This exists because a
# limit applied from the panel travels through php-fpm, whose mount namespace makes /etc read-only:
# the rule reaches the kernel, but the file that would restore it after a reboot never gets written.
# The janitor is a plain systemd unit without that sandbox, so it can finish the job a minute later.
# Idempotent by design: it does nothing at all when the file already matches.
action_persist() {
    have_nft || fail "nftables (nft) is not installed."
    # Even with nothing to do for the inbound table, the outbound budget may still have drifted.
    local egl egf egfixed=false
    egl="$(read_egress_limit)"; egf="$(read_egress_file_limit)"
    if is_uint "$egl" && [ "$egl" != "$egf" ] && is_root; then
        save_egress_file "$egl" && egfixed=true
    fi
    table_exists "$TABLE" || { printf '{"ok":true,"saved":%s,"egress_saved":%s,"reason":"no table is loaded"}\n' "$egfixed" "$egfixed"; return 0; }
    if file_matches; then printf '{"ok":true,"saved":%s,"egress_saved":%s,"in_sync":true}\n' "$egfixed" "$egfixed"; return 0; fi
    is_root || fail "must run as root to save the ruleset"
    [ -d "$NFT_DIR" ] || mkdir -p "$NFT_DIR" || fail "cannot create $NFT_DIR"

    local m port; m="$(read_mode)"; port="$(read_port)"; is_uint "$port" || port=6969
    tmp="$(mktemp "${TMPDIR:-/tmp}/ottrack-in.XXXXXX")" || fail "cannot create a temporary file"
    trap 'rm -f "${tmp:-}"' EXIT
    if [ "$m" = count ]; then
        render_monitor "$port" >"$tmp"
    else
        local pps burst; pps="$(read_limit)"; burst="$(read_burst)"; [ -n "$burst" ] || burst=5
        is_uint "$pps" || fail "cannot read the live limit back from the ruleset"
        read_trusted_live
        render "$pps" "$burst" "$port" >"$tmp"
    fi
    # The outbound budget lives in its own file and drifts for the same reason, so the janitor
    # reconciles both in one visit — otherwise an admin fixes one and is surprised by the other.
    local eglive egfile
    eglive="$(read_egress_limit)"; egfile="$(read_egress_file_limit)"
    if is_uint "$eglive" && [ "$eglive" != "$egfile" ]; then save_egress_file "$eglive" || true; fi

    local rc=0; save_rules "$tmp" || rc=$?
    [ "$rc" = 0 ] || fail "could not save $RULES_FILE — $([ "$rc" = 1 ] && printf '%s' "$PERSIST_HINT" || printf 'the write failed')" 4

    printf '{"ok":true,"saved":true,"mode":%s,"file":%s,"persistent":%s}\n' \
        "$(jstr "$m")" "$(jstr "$RULES_FILE")" "$(include_ok && echo true || echo false)"
}

action_off() {
    local dry="${1-}"
    have_nft || fail "nftables (nft) is not installed."
    if [ "$dry" = "--dry-run" ]; then
        printf '{"ok":true,"dry_run":true,"would_delete_table":%s,"would_delete_file":%s}\n' \
            "$(table_exists "$TABLE" && echo true || echo false)" \
            "$([ -f "$RULES_FILE" ] && echo true || echo false)"
        return 0
    fi
    is_root || fail "must run as root to change the firewall"
    local removed=false deleted=false
    if table_exists "$TABLE"; then
        "$NFT" delete table inet "$TABLE" 2>/dev/null && deleted=true || fail "could not delete table inet $TABLE" 3
    fi
    if [ -f "$RULES_FILE" ]; then rm -f "$RULES_FILE" && removed=true || fail "could not remove $RULES_FILE" 4; fi
    printf '{"ok":true,"table_deleted":%s,"file_removed":%s}\n' "$deleted" "$removed"
}

# Change ONLY the rate of the existing egress budget. Done with a handle-targeted `nft replace` so
# the dynamic sets (good4/good6 — up to 262 144 clients with a 3 h timeout) survive untouched; a
# reload of the whole file would flush them and un-prioritise every established client at once.
action_egress() {
    local pps="${1-}" dry="${2-}"
    want_range "$pps" "$PPS_MIN" "$PPS_MAX" "Egress rate (packets/second)"
    have_nft || fail "nftables (nft) is not installed."
    table_exists ottrack || fail "the egress budget (table inet ottrack) is not loaded — install tools/opentracker/egress-budget/ottrack.nft first"

    local handle
    handle="$("$NFT" -a list chain inet ottrack output 2>/dev/null | awk '
        /limit rate over/ { for (i = 1; i < NF; i++) if ($i == "handle") { print $(i+1); exit } }')"
    [ -n "$handle" ] || fail "could not find the rate-limit rule in table inet ottrack, chain output"

    if [ "$dry" = "--dry-run" ]; then
        printf '{"ok":true,"dry_run":true,"pps":%s,"handle":%s}\n' "$pps" "$handle"
        return 0
    fi
    is_root || fail "must run as root to change the firewall"
    local out
    out="$("$NFT" replace rule inet ottrack output handle "$handle" limit rate over "$pps"/second counter name capped drop 2>&1)" \
        || fail "nft replace failed: $out" 3
    # The live rule is changed; now make it survive a reboot. `[ -w ]` was the bug: inside php-fpm's
    # mount namespace /etc is read-only, so the test simply failed and the file was silently left
    # alone — the budget reverted at every restart and nothing said why.
    local rc=0
    save_egress_file "$pps" || rc=$?
    printf '{"ok":true,"applied":true,"pps":%s,"handle":%s,"file_updated":%s,"persist_deferred":%s,"persist_hint":%s}\n' \
        "$pps" "$handle" "$([ "$rc" = 0 ] && echo true || echo false)" \
        "$([ "$rc" = 1 ] && echo true || echo false)" \
        "$(jstr "$([ "$rc" = 1 ] && printf '%s' "$PERSIST_HINT")")"
}

# Every reply is captured first and written in a single printf. A command substitution that leaks a
# line to stderr can then only produce a SEPARATE line — never one spliced into the middle of the
# JSON, which is what made a healthy firewall report itself as unavailable.
case "${1:-status}" in
    status)  shift || true; _out="$(emit_status "${1-}")"; printf '%s
' "$_out" ;;
    check)   _out="$(emit_check)"; _rc=$?; printf '%s
' "$_out"; exit "$_rc" ;;
    set)     shift; action_set "${1-}" "${2-100}" "${3-6969}" "${4-}" "${5-}" ;;
    monitor) shift || true; action_monitor "${1-6969}" "${2-}" ;;
    off)     shift; action_off "${1-}" ;;
    persist) action_persist ;;
    egress)  shift; action_egress "${1-}" "${2-}" ;;
    -h|--help|help)
        sed -n '2,32p' "$0" | sed 's/^# \{0,1\}//' ; exit 0 ;;
    *) fail "unknown action '${1:-}' — use: status | check | monitor [port] | set <pps> [burst] [port] [--trusted=a,b] [--dry-run] | persist | off | egress <pps>" 1 ;;
esac
