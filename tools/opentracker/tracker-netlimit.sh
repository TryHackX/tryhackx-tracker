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
table_exists() { have_nft && "$NFT" list table inet "$1" >/dev/null 2>&1; }

# The values we last wrote, read back from the "# tracker-netlimit:" header of the generated file.
# nft omits `burst N packets` from its own output when the burst is its default (5), so the live
# ruleset alone cannot always tell us what was asked for.
read_header() {  # read_header pps|burst|port
    [ -r "$RULES_FILE" ] || return 0
    awk -v k="$1=" '/^# tracker-netlimit:/ { for (i = 1; i <= NF; i++) if (index($i, k) == 1) { print substr($i, length(k) + 1); exit } }' "$RULES_FILE"
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

# ── ruleset rendering ────────────────────────────────────────────────────────
# One `nft -f` transaction: create-if-missing, delete, recreate. nftables applies the whole file
# atomically, so the port is never left unprotected between the delete and the new rules.
render() {  # render <pps> <burst> <port>
    cat <<EOF
#!/usr/sbin/nft -f
# $RULES_FILE — generated by tracker-netlimit.sh, do not edit by hand.
# Inbound rate limit for the tracker's UDP port: packets over the budget are dropped BEFORE
# opentracker sees them. Own table, own file — nothing else in the ruleset is touched.
# tracker-netlimit: pps=$1 burst=$2 port=$3 generated=$(date -u '+%Y-%m-%dT%H:%M:%SZ')
# Undo: tracker-netlimit.sh off   (or: nft delete table inet $TABLE && rm $RULES_FILE)
table inet $TABLE {}
delete table inet $TABLE
table inet $TABLE {
    counter in_total  {}
    counter in_passed {}
    counter in_capped {}
    chain input {
        type filter hook input priority filter - 5; policy accept;
        # Only the tracker's UDP port is in scope. Both guards are required: on a non-UDP packet the
        # \`udp dport\` match simply fails, so without the l4proto line TCP would fall through to the
        # budget below and get dropped.
        meta l4proto != udp accept
        udp dport != $3 accept
        counter name in_total
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
    printf ',"persistent":%s' "$([ -f "$RULES_FILE" ] && include_ok && echo true || echo false)"
    printf ',"include_ok":%s' "$(include_ok && echo true || echo false)"
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
    printf ',"egress":{"table":%s,"pps":%s,"file":%s,"counters":{' \
        "$(table_exists ottrack && echo true || echo false)" \
        "$elimit" \
        "$([ -f "$EGRESS_FILE" ] && echo true || echo false)"
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
    fi
    printf '{"ok":%s,"nft":%s,"nft_path":%s,"root":%s,"dir":%s,"include_ok":%s,"conf":%s,"error":%s,"hint":%s}\n' \
        "$([ -z "$errs" ] && echo true || echo false)" \
        "$(have_nft && echo true || echo false)" "$(jstr "$NFT")" \
        "$(is_root && echo true || echo false)" \
        "$([ -d "$NFT_DIR" ] && echo true || echo false)" \
        "$(include_ok && echo true || echo false)" "$(jstr "$NFT_CONF")" \
        "$(jstr "$errs")" "$(jstr "$hints")"
    [ -z "$errs" ]
}

action_set() {
    local pps="${1-}" burst="${2-100}" port="${3-6969}" dry="${4-}"
    want_range "$pps"   "$PPS_MIN"   "$PPS_MAX"   "Rate (packets/second)"
    want_range "$burst" "$BURST_MIN" "$BURST_MAX" "Burst (packets)"
    want_range "$port"  1            65535        "Port"
    have_nft || fail "nftables (nft) is not installed — cannot apply a rate limit."

    tmp="$(mktemp "${TMPDIR:-/tmp}/ottrack-in.XXXXXX")" || fail "cannot create a temporary file"
    trap 'rm -f "${tmp:-}"' EXIT
    render "$pps" "$burst" "$port" >"$tmp"

    local chk; chk="$("$NFT" -c -f "$tmp" 2>&1)" || fail "nft rejected the generated ruleset: $chk" 3

    if [ "$dry" = "--dry-run" ]; then
        printf '{"ok":true,"dry_run":true,"pps":%s,"burst":%s,"port":%s,"file":%s,"ruleset":%s}\n' \
            "$pps" "$burst" "$port" "$(jstr "$RULES_FILE")" "$(jstr "$(cat "$tmp")")"
        return 0
    fi

    is_root || fail "must run as root to change the firewall"
    [ -d "$NFT_DIR" ] || mkdir -p "$NFT_DIR" || fail "cannot create $NFT_DIR"

    # Preferred path: the table is already there for the same port and only the rate changes, so
    # swap that ONE rule by handle. Rebuilding the table would restart the three counters, and the
    # monitor reads rates as differences between two readings — every automatic ±10 % move would
    # punch a hole in the chart. A targeted replace leaves the counters running.
    local mode=reload out handle
    if table_exists "$TABLE" && [ "$(read_port)" = "$port" ]; then
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
    install -m 0644 "$tmp" "$RULES_FILE" || fail "ruleset is live but could not be saved to $RULES_FILE (it will be lost on reboot)" 4

    printf '{"ok":true,"applied":true,"mode":%s,"pps":%s,"burst":%s,"port":%s,"file":%s,"persistent":%s}\n' \
        "$(jstr "$mode")" "$pps" "$burst" "$port" "$(jstr "$RULES_FILE")" "$(include_ok && echo true || echo false)"
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
    # keep the persisted file in step (best effort — the live rule is what matters)
    local saved=false
    if [ -f "$EGRESS_FILE" ] && [ -w "$EGRESS_FILE" ]; then
        sed -i -E "s#(limit rate over )[0-9]+(/second)#\\1$pps\\2#" "$EGRESS_FILE" && saved=true
    fi
    printf '{"ok":true,"applied":true,"pps":%s,"handle":%s,"file_updated":%s}\n' "$pps" "$handle" "$saved"
}

case "${1:-status}" in
    status)  shift || true; emit_status "${1-}" ;;
    check)   emit_check ;;
    set)     shift; action_set "${1-}" "${2-100}" "${3-6969}" "${4-}" ;;
    off)     shift; action_off "${1-}" ;;
    egress)  shift; action_egress "${1-}" "${2-}" ;;
    -h|--help|help)
        sed -n '2,32p' "$0" | sed 's/^# \{0,1\}//' ; exit 0 ;;
    *) fail "unknown action '${1:-}' — use: status | check | set <pps> [burst] [port] | off | egress <pps>" 1 ;;
esac
