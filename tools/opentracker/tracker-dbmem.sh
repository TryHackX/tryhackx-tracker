#!/usr/bin/env bash
# tracker-dbmem.sh — the panel's database-memory knobs (MariaDB / MySQL), schema v42.
#
#   sudo -n /usr/local/sbin/tracker-dbmem.sh status
#   sudo -n /usr/local/sbin/tracker-dbmem.sh check
#   sudo -n /usr/local/sbin/tracker-dbmem.sh apply    <key=value>...   # live where the engine allows, then the drop-in
#   sudo -n /usr/local/sbin/tracker-dbmem.sh persist  <key=value>...   # the drop-in only (the janitor finishes a deferred apply)
#   sudo -n /usr/local/sbin/tracker-dbmem.sh restart                   # systemctl restart of the database service
#
# Install:
#   sudo install -m 0755 tracker-dbmem.sh /usr/local/sbin/tracker-dbmem.sh
#   echo 'www-data ALL=(root) NOPASSWD: /usr/local/sbin/tracker-dbmem.sh' | sudo tee /etc/sudoers.d/tracker-dbmem
#   sudo chmod 0440 /etc/sudoers.d/tracker-dbmem && sudo visudo -c -f /etc/sudoers.d/tracker-dbmem
#
# Undo everything, by hand, without the panel:
#   sudo rm /etc/mysql/mariadb.conf.d/70-tracker-panel.cnf   (or mysql.conf.d / conf.d)   && sudo systemctl restart mariadb
#
# ── why this script is shaped the way it is ─────────────────────────────────
#
# The database is SHARED. On the machine this was written for it also serves the mail, the forum and
# the file host, so a wrong number here is not a tracker outage, it is everybody's. Hence:
#
#   * SEVEN keys, named literally in a case statement, each with a floor and a ceiling narrower
#     than the engine accepts. The sudoers rule pins no arguments, so the validation in this file
#     IS the security boundary. Every SQL statement is assembled from a validated key name and an
#     integer; nothing that came from the caller is ever pasted into SQL as text.
#   * What the engine can change live is changed live and READ BACK; what it cannot is written to
#     the drop-in and reported as "restart required" — never restarted from here by side effect.
#     `restart` is its own action, called only when the operator pressed the button that says so.
#   * The drop-in is one file with one name, written whole every time (tmp + rename), so what the
#     panel manages is exactly what is in that file and nothing else. Other .cnf files that set the
#     same keys are reported as conflicts; they are never edited.
#   * MariaDB and MySQL differ in what is dynamic and in where the drop-ins live. What is DYNAMIC
#     comes from one table below, keyed by engine and version. Whether a key EXISTS at all is asked
#     of the running server, never guessed from the version: innodb_buffer_pool_size_max was
#     backported to 10.11.12 / 11.4.6 / 11.8.2 (MDEV-29445), and a drop-in naming a variable the
#     engine lacks stops it from starting — at the next restart, whoever does it, weeks later.
#   * Nothing that talks to the server or to systemd runs unbounded: the client is wrapped in
#     `timeout`, so a wedged server costs the panel seconds, not a hung php-fpm worker.
#   * The test hooks (DBMEM_*, SYSTEMCTL_BIN) are refused when running as root. sudo's env_reset
#     strips them today; a later `env_keep` must not turn them into "run any file as root".
#
# The panel deals in bytes and counts; so does this script. The drop-in is written in bytes with
# the human form in a comment, because "1536M" and "1.5G" are two spellings the engines round
# differently and a file that says 1610612736 cannot be misread.

set -u
# Every external program is looked up on THIS path, not on whatever the caller had. sudo's
# secure_path does the same today; this does not depend on it staying that way.
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
export PATH

VERSION=2

# The test hooks. Remembered here so that, as root, their mere presence is a refusal (see below).
HOOKS_SET="${DBMEM_CONF_DIR:-}${DBMEM_CONF_NAME:-}${DBMEM_CLIENT:-}${DBMEM_DEBIAN_CNF:-}${SYSTEMCTL_BIN:-}${DBMEM_MEMINFO:-}${DBMEM_RESTART_WAIT:-}${DBMEM_RESTART_STAMP:-}${DBMEM_DATADIR_FREE:-}"
CONF_DIR="${DBMEM_CONF_DIR:-}"          # empty = pick by engine
CONF_NAME="${DBMEM_CONF_NAME:-70-tracker-panel.cnf}"
CLIENT_BIN="${DBMEM_CLIENT:-}"          # a test can point this at a stub
DEBIAN_CNF="${DBMEM_DEBIAN_CNF:-/etc/mysql/debian.cnf}"
SYSTEMCTL_BIN="${SYSTEMCTL_BIN:-systemctl}"
MEMINFO="${DBMEM_MEMINFO:-/proc/meminfo}"
RESTART_WAIT="${DBMEM_RESTART_WAIT:-90}"
RESTART_STAMP="${DBMEM_RESTART_STAMP:-/run/tracker-dbmem.last-restart}"
RESTART_MIN_GAP=120                     # seconds between two restarts from the panel
DATADIR_FREE="${DBMEM_DATADIR_FREE:-}"  # a test can state the free bytes on the data disk
DB_TIMEOUT=20                           # seconds one client call may take
SYSTEMCTL_TIMEOUT=300                   # seconds `systemctl restart` may take

# ── tiny JSON helpers (same shape as the other helpers) ─────────────────────
jesc() {
    printf '%s' "${1-}" \
        | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g' -e 's/\t/\\t/g' -e 's/\r//g' \
        | sed -e ':a' -e 'N' -e '$!ba' -e 's/\n/\\n/g' \
        | tr -d '\000-\010\013\014\016-\037'
}
jstr() { printf '"%s"' "$(jesc "${1-}")"; }
jbool() { [ "${1:-0}" = "1" ] && printf 'true' || printf 'false'; }
fail() { printf '{"ok":false,"error":%s}\n' "$(jstr "${1:-error}")"; exit "${2:-1}"; }
# The same, on stderr: parse_pairs runs inside $(...), so a refusal printed to stdout would be
# captured as if it were the parsed list. The callers merge stderr into what they read (2>&1).
failerr() { printf '{"ok":false,"error":%s}\n' "$(jstr "${1:-error}")" >&2; exit "${2:-1}"; }

is_uint() { case "${1-}" in ''|*[!0-9]*) return 1 ;; *) return 0 ;; esac; }
num_or_zero() { local v="${1-}"; is_uint "$v" && printf %s "$v" || printf 0; }
is_root() { [ "$(id -u 2>/dev/null || echo 1)" = "0" ]; }
if [ -n "$HOOKS_SET" ] && is_root; then
    fail "the DBMEM_*/SYSTEMCTL_BIN hooks are for the test suite and are not accepted as root" 3
fi
# `timeout` bounds every call to the client and to systemctl; without it (not a Debian) the call runs bare.
bounded() {   # $1 = seconds, then the command
    local secs="$1"; shift
    if command -v timeout >/dev/null 2>&1; then timeout -k 3 "$secs" "$@"; else "$@"; fi
}

dir_writable() {
    local d="${1-}" probe
    [ -d "$d" ] || return 1
    probe="$d/.tracker-panel-probe.$$"
    if ( : >"$probe" ) 2>/dev/null; then rm -f "$probe" 2>/dev/null; return 0; fi
    return 1
}

mem_total_kb()     { awk '/^MemTotal:/ {print $2; exit}' "$MEMINFO" 2>/dev/null || printf 0; }
mem_available_kb() { awk '/^MemAvailable:/ {print $2; exit}' "$MEMINFO" 2>/dev/null || printf 0; }

# ── the client, and how root gets in ─────────────────────────────────────────
#
# Debian's MariaDB lets root@localhost in over the unix socket; Debian's and Ubuntu's MySQL do the
# same through auth_socket. Where neither works there is /etc/mysql/debian.cnf, the maintenance
# account the packages keep for exactly this. The first way that answers is remembered.
DB_AUTH=""
find_client() {
    if [ -n "$CLIENT_BIN" ]; then printf '%s' "$CLIENT_BIN"; return 0; fi
    local c
    for c in mariadb mysql; do
        if command -v "$c" >/dev/null 2>&1; then command -v "$c"; return 0; fi
    done
    return 1
}
db_try() {   # $1 = auth mode, $2 = sql
    local client; client="$(find_client)" || return 1
    case "$1" in
        socket) bounded "$DB_TIMEOUT" "$client" --protocol=socket -uroot --connect-timeout=5 -N -B -e "$2" 2>/dev/null ;;
        debian) [ -r "$DEBIAN_CNF" ] || return 1; bounded "$DB_TIMEOUT" "$client" --defaults-file="$DEBIAN_CNF" --connect-timeout=5 -N -B -e "$2" 2>/dev/null ;;
        stub)   bounded "$DB_TIMEOUT" "$client" -N -B -e "$2" 2>/dev/null ;;
        *) return 1 ;;
    esac
}
# Probe the auth modes once, in the calling shell, so DB_AUTH survives: db_sql is mostly called
# inside $(...), where a variable set there dies with the subshell and every call would probe again.
db_connect() {
    local m
    [ -n "$DB_AUTH" ] && return 0
    if [ -n "$CLIENT_BIN" ]; then
        db_try stub "SELECT 1" >/dev/null 2>&1 && { DB_AUTH=stub; return 0; }
        return 1
    fi
    for m in socket debian; do
        if db_try "$m" "SELECT 1" >/dev/null 2>&1; then DB_AUTH="$m"; return 0; fi
    done
    return 1
}
db_sql() {
    local sql="$1"
    [ -n "$DB_AUTH" ] || db_connect || return 1
    db_try "$DB_AUTH" "$sql"
}

# ── engine and version ───────────────────────────────────────────────────────
ENGINE=""; VER=""; VER_NUM=0
detect_engine() {
    local v
    db_connect || return 1
    v="$(db_sql "SELECT VERSION()" | head -n1)" || return 1
    [ -n "$v" ] || return 1
    VER="$v"
    case "$v" in *MariaDB*|*mariadb*) ENGINE=mariadb ;; *) ENGINE=mysql ;; esac
    # 11.8.6-MariaDB-0+deb13u1 -> 011008006 ; 8.0.36-0ubuntu -> 008000036
    VER_NUM="$(printf '%s' "$v" | sed -E 's/^([0-9]+)\.([0-9]+)\.([0-9]+).*/\1 \2 \3/' | awk '{printf "%03d%03d%03d", $1, $2, $3}')"
    is_uint "$VER_NUM" || VER_NUM=0
    refresh_vars
    return 0
}
ver_at_least() { [ "$(num_or_zero "$VER_NUM")" -ge "$(printf '%03d%03d%03d' "$1" "$2" "$3")" ]; }

# ── the seven keys, and nothing else ─────────────────────────────────────────
#
# Floors and ceilings are deliberately narrower than the engines accept. A buffer pool of a few MB
# makes every query a disk read; one the size of the machine takes the memory the mail server and
# php-fpm are using right now, and the kernel answers with the OOM killer — on the shared machine
# this ships to, not on the tracker.
ALL_KEYS="innodb_buffer_pool_size innodb_buffer_pool_size_max innodb_log_file_size max_connections tmp_table_size max_heap_table_size table_open_cache"
key_known() { case "${1-}" in innodb_buffer_pool_size|innodb_buffer_pool_size_max|innodb_log_file_size|max_connections|tmp_table_size|max_heap_table_size|table_open_cache) return 0 ;; *) return 1 ;; esac; }
key_unit() { case "$1" in max_connections|table_open_cache) printf count ;; *) printf bytes ;; esac; }
key_min() {
    case "$1" in
        innodb_buffer_pool_size)     printf 67108864 ;;     # 64 MiB
        innodb_buffer_pool_size_max) printf 67108864 ;;
        innodb_log_file_size)        printf 16777216 ;;     # 16 MiB
        max_connections)             printf 10 ;;
        tmp_table_size)              printf 1048576 ;;      # 1 MiB
        max_heap_table_size)         printf 1048576 ;;
        table_open_cache)            printf 100 ;;
    esac
}
# Free bytes on the disk that holds the data directory (the redo log lives there and, on MariaDB
# >= 10.9, is allocated the moment it is set live).
datadir_free_bytes() {
    if [ -n "$DATADIR_FREE" ]; then num_or_zero "$DATADIR_FREE"; return; fi
    local d; d="$(cached_value datadir)"; [ -d "$d" ] || d=/var/lib/mysql; [ -d "$d" ] || d=/
    num_or_zero "$(df -P -B1 "$d" 2>/dev/null | awk 'NR==2 {print $4; exit}')"
}
key_max() {
    local mem free cap; mem="$(( $(num_or_zero "$(mem_total_kb)") * 1024 ))"
    case "$1" in
        # the pool may never take the whole machine: 512 MiB stays for the kernel and the rest
        innodb_buffer_pool_size)     [ "$mem" -gt 536870912 ] && printf '%s' "$(( mem - 536870912 ))" || printf 536870912 ;;
        innodb_buffer_pool_size_max) [ "$mem" -gt 0 ] && printf '%s' "$mem" || printf 4294967296 ;;
        innodb_log_file_size)
            # 8 GiB, and never more than a quarter of the free space on the data disk (MySQL keeps
            # two files of this size; a full data disk is an outage for every database on it)
            cap=8589934592; free="$(datadir_free_bytes)"
            if [ "$free" -gt 0 ] && [ "$(( free / 4 ))" -lt "$cap" ]; then cap="$(( free / 4 ))"; fi
            [ "$cap" -lt "$(key_min innodb_log_file_size)" ] && cap="$(key_min innodb_log_file_size)"
            printf '%s' "$cap" ;;
        max_connections)             printf 10000 ;;
        tmp_table_size)              printf 4294967296 ;;   # 4 GiB
        max_heap_table_size)         printf 4294967296 ;;
        table_open_cache)            printf 1000000 ;;
    esac
}
# Is this key one the running engine can change without a restart?
key_dynamic() {
    case "$1" in
        innodb_buffer_pool_size)
            if [ "$ENGINE" = mariadb ]; then ver_at_least 10 2 2; else ver_at_least 5 7 5; fi ;;
        innodb_buffer_pool_size_max) return 1 ;;                     # MariaDB 11.x: startup only
        innodb_log_file_size)
            if [ "$ENGINE" = mariadb ]; then ver_at_least 10 9 0; else return 1; fi ;;
        max_connections|tmp_table_size|max_heap_table_size|table_open_cache) return 0 ;;
        *) return 1 ;;
    esac
}
# Does this engine have the key at all? The running server says — a version table got this wrong
# once (innodb_buffer_pool_size_max exists on 10.11.12+, 11.4.6+, 11.8.2+, not "11.0+") and a
# drop-in with an unknown variable is a database that does not start.
key_supported() { [ -n "$(cached_value "$1")" ]; }

# ── where the drop-in lives ──────────────────────────────────────────────────
conf_dir() {
    if [ -n "$CONF_DIR" ]; then printf '%s' "$CONF_DIR"; return; fi
    if [ "$ENGINE" = mariadb ] && [ -d /etc/mysql/mariadb.conf.d ]; then printf /etc/mysql/mariadb.conf.d; return; fi
    if [ "$ENGINE" = mysql ] && [ -d /etc/mysql/mysql.conf.d ]; then printf /etc/mysql/mysql.conf.d; return; fi
    if [ -d /etc/mysql/conf.d ]; then printf /etc/mysql/conf.d; return; fi
    if [ -d /etc/my.cnf.d ]; then printf /etc/my.cnf.d; return; fi
    printf /etc/mysql/conf.d
}
conf_file() { printf '%s/%s' "$(conf_dir)" "$CONF_NAME"; }

# 1536M / 1.5G / 1610612736 -> bytes (what an operator or an older file may have written)
to_bytes() {
    local v="${1-}" n u
    v="$(printf '%s' "$v" | tr -d '\r"'"'" | sed -E 's/^[[:space:]]+//; s/[[:space:]]+$//')"
    n="$(printf '%s' "$v" | sed -E 's/^([0-9]+(\.[0-9]+)?)[[:space:]]*([KkMmGgTt]?)([Ii]?[Bb])?$/\1/')"
    u="$(printf '%s' "$v" | sed -E 's/^([0-9]+(\.[0-9]+)?)[[:space:]]*([KkMmGgTt]?)([Ii]?[Bb])?$/\3/' | tr 'kmgt' 'KMGT')"
    case "$n" in ''|*[!0-9.]*) printf 0; return ;; esac
    case "$u" in
        K) awk -v n="$n" 'BEGIN{printf "%d", n*1024}' ;;
        M) awk -v n="$n" 'BEGIN{printf "%d", n*1024*1024}' ;;
        G) awk -v n="$n" 'BEGIN{printf "%d", n*1024*1024*1024}' ;;
        T) awk -v n="$n" 'BEGIN{printf "%d", n*1024*1024*1024*1024}' ;;
        *) awk -v n="$n" 'BEGIN{printf "%d", n}' ;;
    esac
}
human_bytes() {
    awk -v b="$(num_or_zero "${1-}")" 'BEGIN{ u="B KiB MiB GiB TiB"; split(u,a," "); i=1; v=b; while (v>=1024 && i<5) {v/=1024; i++}; if (i==1) printf "%d %s", v, a[i]; else printf "%.2f %s", v, a[i] }'
}

# key=value lines of our own file (and of any other .cnf in the dir, for conflicts)
file_values() {   # $1 = file ; prints key<TAB>bytes for managed keys found under any section
    [ -f "$1" ] || return 0
    local k v
    # `loose-` / `loose_` prefixes and dashes for underscores are the spellings the engines accept
    grep -E '^[[:space:]]*(loose[-_])?(innodb[-_]buffer[-_]pool[-_]size|innodb[-_]buffer[-_]pool[-_]size[-_]max|innodb[-_]log[-_]file[-_]size|max[-_]connections|tmp[-_]table[-_]size|max[-_]heap[-_]table[-_]size|table[-_]open[-_]cache)[[:space:]]*=' "$1" 2>/dev/null \
    | while IFS= read -r line; do
        k="$(printf '%s' "$line" | sed -E 's/^[[:space:]]*(loose[-_])?([a-z_-]+)[[:space:]]*=.*/\2/' | tr '-' '_')"
        key_known "$k" || continue
        v="$(printf '%s' "$line" | sed -E 's/^[^=]*=[[:space:]]*//; s/[[:space:]]*(#.*)?$//')"
        case "$(key_unit "$k")" in bytes) v="$(to_bytes "$v")" ;; *) v="$(num_or_zero "$v")" ;; esac
        printf '%s\t%s\n' "$k" "$v"
    done
}
emit_file_values() {
    local first=1 k v
    printf '{'
    while IFS=$'\t' read -r k v; do
        [ -n "$k" ] || continue
        [ $first = 1 ] || printf ','; first=0
        printf '%s:%s' "$(jstr "$k")" "$(num_or_zero "$v")"
    done < <(file_values "$(conf_file)")
    printf '}'
}
# Every file the engine reads that could set a managed key: the chosen drop-in dir, the shared
# /etc/mysql/conf.d that both engines read, the top-level files, and the RHEL layout.
conflict_files() {
    local dir seen="" f
    dir="$(conf_dir)"
    for f in "$dir"/*.cnf /etc/mysql/conf.d/*.cnf /etc/mysql/mariadb.conf.d/*.cnf /etc/mysql/mysql.conf.d/*.cnf \
             /etc/mysql/my.cnf /etc/mysql/mariadb.cnf /etc/my.cnf /etc/my.cnf.d/*.cnf; do
        [ -f "$f" ] || continue
        case "$seen" in *"|$f|"*) continue ;; esac
        seen="$seen|$f|"
        printf '%s\n' "$f"
    done
}
emit_conflicts() {
    local first=1 f k v mine
    mine="$(conf_file)"
    printf '['
    while IFS= read -r f; do
        [ -n "$f" ] || continue
        [ "$f" = "$mine" ] && continue
        while IFS=$'\t' read -r k v; do
            [ -n "$k" ] || continue
            [ $first = 1 ] || printf ','; first=0
            printf '{"file":%s,"key":%s,"value":%s}' "$(jstr "$f")" "$(jstr "$k")" "$(num_or_zero "$v")"
        done < <(file_values "$f")
    done < <(conflict_files)
    printf ']'
}

# ── live values and counters ────────────────────────────────────────────────
live_vars() {   # prints name<TAB>value for the managed keys plus the ones the sizing depends on
    db_sql "SHOW GLOBAL VARIABLES WHERE Variable_name IN ('innodb_buffer_pool_size','innodb_buffer_pool_size_max','innodb_buffer_pool_chunk_size','innodb_buffer_pool_instances','innodb_log_file_size','max_connections','tmp_table_size','max_heap_table_size','table_open_cache','datadir')"
}
live_status() {
    db_sql "SHOW GLOBAL STATUS WHERE Variable_name IN ('Innodb_buffer_pool_pages_total','Innodb_buffer_pool_pages_free','Innodb_buffer_pool_reads','Innodb_buffer_pool_read_requests','Innodb_buffer_pool_resize_status','Threads_connected','Max_used_connections','Created_tmp_disk_tables','Created_tmp_tables','Uptime')"
}
# One read of the variables per run (and one after every SET), kept in the calling shell so the
# per-key lookups below cost nothing and a wedged server costs one bounded call, not twenty.
LIVE_VARS=""
refresh_vars() { LIVE_VARS="$(live_vars)"; }
cached_value() { printf '%s\n' "$LIVE_VARS" | awk -F'\t' -v k="$1" '$1==k {print $2; exit}'; }
emit_kv_json() {   # stdin: name<TAB>value -> {"name":value,...} (numbers unquoted)
    local first=1 k v
    printf '{'
    while IFS=$'\t' read -r k v; do
        [ -n "$k" ] || continue
        [ $first = 1 ] || printf ','; first=0
        if is_uint "$v"; then printf '%s:%s' "$(jstr "$k")" "$v"; else printf '%s:%s' "$(jstr "$k")" "$(jstr "$v")"; fi
    done
    printf '}'
}
live_value() { refresh_vars; cached_value "$1"; }

emit_keys() {   # per key: unit, min, max, dynamic, supported
    local first=1 k
    printf '{'
    for k in $ALL_KEYS; do
        [ $first = 1 ] || printf ','; first=0
        printf '%s:{"unit":%s,"min":%s,"max":%s,"dynamic":%s,"supported":%s}' \
            "$(jstr "$k")" "$(jstr "$(key_unit "$k")")" "$(key_min "$k")" "$(key_max "$k")" \
            "$(key_dynamic "$k" && echo true || echo false)" "$(key_supported "$k" && echo true || echo false)"
    done
    printf '}'
}

service_unit() {
    local u
    for u in mariadb.service mysql.service mysqld.service; do
        if "$SYSTEMCTL_BIN" list-unit-files "$u" 2>/dev/null | grep -q "^$u"; then printf '%s' "$u"; return 0; fi
    done
    return 1
}

# ── actions ─────────────────────────────────────────────────────────────────
action_status() {
    detect_engine || fail "the database did not answer (no client found, or root cannot connect over the socket)"
    local unit out; unit="$(service_unit || printf '')"
    # built whole, printed once: a reader that takes the last JSON line never sees half a reply
    out='{"ok":true'
    out="$out,\"version\":$VERSION"
    out="$out,\"engine\":$(jstr "$ENGINE")"
    out="$out,\"server_version\":$(jstr "$VER")"
    out="$out,\"unit\":$(jstr "$unit")"
    out="$out,\"auth\":$(jstr "$DB_AUTH")"
    out="$out,\"mem_total_kb\":$(num_or_zero "$(mem_total_kb)")"
    out="$out,\"mem_available_kb\":$(num_or_zero "$(mem_available_kb)")"
    out="$out,\"datadir_free_bytes\":$(datadir_free_bytes)"
    out="$out,\"vars\":$(printf '%s\n' "$LIVE_VARS" | emit_kv_json)"
    out="$out,\"status\":$(live_status | emit_kv_json)"
    out="$out,\"keys\":$(emit_keys)"
    out="$out,\"file\":$(jstr "$(conf_file)")"
    out="$out,\"file_present\":$([ -f "$(conf_file)" ] && echo true || echo false)"
    out="$out,\"file_values\":$(emit_file_values)"
    out="$out,\"conflicts\":$(emit_conflicts)"
    out="$out,\"dir_writable\":$(dir_writable "$(conf_dir)" && echo true || echo false)"
    out="$out,\"is_root\":$(is_root && echo true || echo false)"
    out="$out,\"restart_gap_s\":$RESTART_MIN_GAP"
    printf '%s}\n' "$out"
}

action_check() {
    local notes="" ok=1 client
    is_root || { notes="$notes not running as root (sudo rule missing?);"; ok=0; }
    client="$(find_client)" || { notes="$notes no mariadb/mysql client on PATH;"; ok=0; }
    if [ $ok = 1 ] && ! detect_engine; then notes="$notes root cannot query the server over the socket (nor via $DEBIAN_CNF);"; ok=0; fi
    if [ $ok = 1 ]; then
        dir_writable "$(conf_dir)" || notes="$notes $(conf_dir) is read-only here — the drop-in write will be deferred to the janitor;"
    fi
    printf '{"ok":%s,"engine":%s,"server_version":%s,"client":%s,"file":%s,"notes":%s}\n' \
        "$(jbool "$ok")" "$(jstr "$ENGINE")" "$(jstr "$VER")" "$(jstr "${client:-}")" "$(jstr "$(conf_file)")" "$(jstr "$notes")"
    [ $ok = 1 ] || exit 1
}

# Validate every key=value on the command line; prints "key<TAB>value" lines, or fails.
parse_pairs() {
    local p k v seen=""
    for p in "$@"; do
        k="${p%%=*}"; v="${p#*=}"
        [ "$p" != "$k" ] || failerr "argument '$p' is not key=value"
        key_known "$k" || failerr "unknown key '$k'"
        key_supported "$k" || failerr "$k is not a variable of this server ($ENGINE $VER) — a drop-in naming it would stop the engine from starting"
        is_uint "$v" || failerr "$k must be a whole number of $(key_unit "$k")"
        [ "$v" -ge "$(key_min "$k")" ] || failerr "$k below the floor ($(key_min "$k"))"
        [ "$v" -le "$(key_max "$k")" ] || failerr "$k above the ceiling ($(key_max "$k")) for this machine"
        case " $seen " in *" $k "*) failerr "$k given twice" ;; esac
        seen="$seen $k"
        printf '%s\t%s\n' "$k" "$v"
    done
}

write_conf() {   # stdin: key<TAB>value ; writes the drop-in; returns 2 when the directory is read-only
    local dir file tmp k v
    dir="$(conf_dir)"; file="$(conf_file)"
    [ -d "$dir" ] || return 2
    dir_writable "$dir" || return 2
    tmp="$file.tmp.$$"
    {
        printf '# Written by the tracker panel (tracker-dbmem.sh) — do not edit by hand, the next apply rewrites it.\n'
        printf '# Values are bytes (or counts); the human form is the comment. Remove this file and restart\n'
        printf '# the service to return to the engine defaults.\n'
        printf '[mysqld]\n'
        while IFS=$'\t' read -r k v; do
            [ -n "$k" ] || continue
            # `loose-`: should the engine ever be downgraded below the version that has this key,
            # the line is a warning in the log, not a server that refuses to start.
            case "$k" in innodb_buffer_pool_size_max) k="loose-$k" ;; esac
            case "$(key_unit "${k#loose-}")" in
                bytes) printf '%-36s = %s   # %s\n' "$k" "$v" "$(human_bytes "$v")" ;;
                *)     printf '%-36s = %s\n' "$k" "$v" ;;
            esac
        done
    } >"$tmp" 2>/dev/null || { rm -f "$tmp"; return 2; }
    chmod 0644 "$tmp" 2>/dev/null
    mv -f "$tmp" "$file" 2>/dev/null || { rm -f "$tmp"; return 2; }
    return 0
}

action_persist() {
    detect_engine || fail "the database did not answer"
    local pairs; pairs="$(parse_pairs "$@")" || exit 1
    if printf '%s\n' "$pairs" | write_conf; then
        printf '{"ok":true,"persisted":true,"file":%s,"file_values":%s}\n' "$(jstr "$(conf_file)")" "$(emit_file_values)"
    else
        printf '{"ok":true,"persisted":false,"deferred":true,"file":%s,"hint":%s}\n' "$(jstr "$(conf_file)")" \
            "$(jstr "$(conf_dir) is read-only from here; the janitor writes the file on its next tick")"
    fi
}

action_apply() {
    detect_engine || fail "the database did not answer"
    local pairs; pairs="$(parse_pairs "$@")" || exit 1
    local k v first=1 live landed restart note sizemax out
    sizemax="$(num_or_zero "$(cached_value innodb_buffer_pool_size_max)")"
    out="{\"ok\":true,\"engine\":$(jstr "$ENGINE"),\"applied\":{"
    while IFS=$'\t' read -r k v; do
        [ -n "$k" ] || continue
        [ $first = 1 ] || out="$out,"; first=0
        live=0; restart=0; note=""; landed=""
        if key_dynamic "$k"; then
            if [ "$k" = innodb_buffer_pool_size ] && [ "$ENGINE" = mariadb ] && [ "$sizemax" -gt 0 ] && [ "$v" -gt "$sizemax" ]; then
                # MariaDB 11: the pool can grow live only up to innodb_buffer_pool_size_max, a startup setting.
                restart=1; note="above innodb_buffer_pool_size_max ($(human_bytes "$sizemax")); takes effect after a restart"
            else
                if db_sql "SET GLOBAL $k = $v" >/dev/null; then
                    live=1
                    refresh_vars; landed="$(cached_value "$k")"
                    # MySQL rounds the pool to chunk × instances; MariaDB < 11 to chunks. Say so
                    # rather than reporting a number the operator did not type as an error.
                    if [ "$k" = innodb_buffer_pool_size ] && [ "$(num_or_zero "$landed")" != "$v" ]; then
                        note="rounded by the engine to $(human_bytes "$landed")"
                    fi
                else
                    restart=1; note="SET GLOBAL was refused; written to the drop-in for the next restart"
                fi
            fi
        else
            restart=1; note="startup-only on $ENGINE $VER; takes effect after a restart"
        fi
        out="$out$(printf '%s:{"wanted":%s,"landed":%s,"live":%s,"restart_required":%s,"note":%s}' \
            "$(jstr "$k")" "$v" "$(num_or_zero "${landed:-$(cached_value "$k")}")" "$(jbool "$live")" "$(jbool "$restart")" "$(jstr "$note")")"
    done <<EOF
$pairs
EOF
    out="$out}"
    if printf '%s\n' "$pairs" | write_conf; then
        out="$out,\"persisted\":true,\"deferred\":false"
    else
        out="$out,\"persisted\":false,\"deferred\":true,\"hint\":$(jstr "$(conf_dir) is read-only from here; the janitor writes the file on its next tick")"
    fi
    refresh_vars
    printf '%s,"file":%s,"vars":%s,"status":%s}\n' "$out" "$(jstr "$(conf_file)")" "$(printf '%s\n' "$LIVE_VARS" | emit_kv_json)" "$(live_status | emit_kv_json)"
}

action_restart() {
    is_root || fail "restart needs root"
    local unit t=0 last now
    unit="$(service_unit)" || fail "no mariadb/mysql service unit found"
    # Two restarts within the gap are not a plan, they are a loop; the stamp lives on tmpfs so a
    # reboot clears it.
    if [ -f "$RESTART_STAMP" ]; then
        last="$(num_or_zero "$(stat -c %Y "$RESTART_STAMP" 2>/dev/null)")"; now="$(date +%s)"
        if [ "$last" -gt 0 ] && [ $(( now - last )) -lt "$RESTART_MIN_GAP" ]; then
            fail "the database was restarted $(( now - last )) s ago; wait $(( RESTART_MIN_GAP - (now - last) )) s before the next one"
        fi
    fi
    : >"$RESTART_STAMP" 2>/dev/null
    DB_AUTH=""
    bounded "$SYSTEMCTL_TIMEOUT" "$SYSTEMCTL_BIN" restart "$unit" >/dev/null 2>&1 || fail "systemctl restart $unit failed (or took more than ${SYSTEMCTL_TIMEOUT}s) — check journalctl -u $unit"
    while [ $t -lt "$RESTART_WAIT" ]; do
        if "$SYSTEMCTL_BIN" is-active --quiet "$unit" && detect_engine 2>/dev/null; then
            printf '{"ok":true,"unit":%s,"seconds":%s,"server_version":%s,"vars":%s}\n' "$(jstr "$unit")" "$t" "$(jstr "$VER")" "$(printf '%s\n' "$LIVE_VARS" | emit_kv_json)"
            return 0
        fi
        DB_AUTH=""
        sleep 1; t=$((t+1))
    done
    fail "$unit did not come back within ${RESTART_WAIT}s — check journalctl -u $unit"
}

case "${1-}" in
    status)  action_status ;;
    check)   action_check ;;
    apply)   shift; [ $# -gt 0 ] || fail "apply needs key=value arguments"; action_apply "$@" ;;
    persist) shift; [ $# -gt 0 ] || fail "persist needs key=value arguments"; action_persist "$@" ;;
    restart) action_restart ;;
    version) printf '{"ok":true,"version":%s}\n' "$VERSION" ;;
    *) fail "usage: tracker-dbmem.sh status|check|apply k=v...|persist k=v...|restart" 2 ;;
esac
