#!/bin/bash
# tracker-backup.sh — database/config backups driven from the admin panel.
#
# WHAT THIS IS NOT
#   It is not a backup program. `Backup-serwera.sh` (the server toolkit that ships outside this
#   repo) already is one, with granular items, a MANIFEST, SUMY.sha256 and a restore path. This
#   helper *steers* it: it validates what the panel asked for, runs the real tool detached from the
#   web request, keeps a JSON state file the panel can poll, rotates old archives and hands one back
#   for download. Where that tool is absent there is a built-in fallback that dumps the tracker
#   database and nothing else — announced as such, never pretending to be a full backup.
#
# TWO THINGS THE REAL TOOL DELIBERATELY WILL NOT DO WITHOUT A HUMAN AT A TERMINAL, AND WHY WE DO
# NOT WORK AROUND THEM:
#   1. It refuses to overwrite a database unless somebody types its name on a TTY. That guard is
#      right, so we do not feed it a fake terminal. Restoring a database from the panel is a
#      separate action here (`restore-db`) that asks for the same thing in the panel — the exact
#      database name — and takes a dump of the current database before importing.
#   2. Its encryption is `gpg --symmetric` with an interactive passphrase, which cannot work from a
#      web request; it detects the missing TTY and silently skips encrypting. So the panel always
#      passes --no-gpg and, when a recipient key is configured, encrypts here with `gpg --encrypt
#      --recipient` — public-key, no passphrase, non-interactive by construction.
#
# usage (every action prints ONE JSON object on stdout; exit 0 on success):
#   tracker-backup.sh check [<dir>]
#   tracker-backup.sh test-path <dir>
#   tracker-backup.sh list <dir>
#   tracker-backup.sh status <dir>
#   tracker-backup.sh run <dir> <profile> [--items a,b] [--nice N] [--gpg-recipient KEY]
#                                         [--verify] [--keep N] [--keep-days N] [--max-gb N]
#   tracker-backup.sh cancel <dir>
#   tracker-backup.sh verify <dir> <id> [--deep]
#   tracker-backup.sh prune <dir> <keep> <keep-days> <max-gb> [--dry-run]
#   tracker-backup.sh delete <dir> <id>
#   tracker-backup.sh cat <dir> <id>                      (the archive on stdout — download)
#   tracker-backup.sh restore <dir> <id> --items a,b [--dry-run]
#   tracker-backup.sh restore-db <dir> <id> --db NAME --confirm NAME [--dry-run]
#
# Arguments are validated here and never interpolated into a shell command, so the sudoers rule can
# safely be NOPASSWD on the script itself:
#
#   www-data ALL=(root) NOPASSWD: /usr/local/sbin/tracker-backup.sh
#
# Test hooks (tests/backup_test.php): BACKUP_SCRIPT overrides the path to Backup-serwera.sh,
# MARIADB_DUMP_BIN / MARIADB_BIN the client binaries, BACKUP_ALLOW_ANY_DIR=1 lifts the refusal to
# work in a system directory (temporary directories only — never set it on a server).
set -u

# --script <path> (before the action) overrides where Backup-serwera.sh lives; the panel passes the
# configured path so an install that keeps the toolkit somewhere else still works. sudo scrubs the
# environment, so BACKUP_SCRIPT is only useful to the test suite.
BACKUP_SCRIPT="${BACKUP_SCRIPT:-}"
if [ "${1-}" = "--script" ]; then
    shift
    case "${1-}" in
        /*) BACKUP_SCRIPT="$1" ;;
        *)  printf '{"ok":false,"error":"--script needs an absolute path"}\n'; exit 1 ;;
    esac
    shift
fi
if [ -z "$BACKUP_SCRIPT" ]; then
    for c in /usr/local/sbin/Backup-serwera.sh /usr/local/bin/Backup-serwera.sh /opt/tryhackx/Backup-serwera.sh /root/Backup-serwera.sh; do
        [ -x "$c" ] && BACKUP_SCRIPT="$c" && break
    done
fi
[ -n "$BACKUP_SCRIPT" ] || BACKUP_SCRIPT=/usr/local/sbin/Backup-serwera.sh
MARIADB_DUMP="${MARIADB_DUMP_BIN:-}"
MARIADB="${MARIADB_BIN:-}"
[ -n "$MARIADB_DUMP" ] || MARIADB_DUMP="$(command -v mariadb-dump 2>/dev/null || command -v mysqldump 2>/dev/null || true)"
[ -n "$MARIADB" ]      || MARIADB="$(command -v mariadb 2>/dev/null || command -v mysql 2>/dev/null || true)"

STATE_NAME=".tracker-backup-state.json"
LOG_NAME=".tracker-backup.log"
LOCK_NAME=".tracker-backup.lock"
TRACKER_DB_DEFAULT="tracker"
LOG_TAIL_LINES=25
MAX_ARCHIVES=500          # a listing bound: nobody keeps more, and it caps the work `list` can do

# Profiles → the items Backup-serwera.sh is asked for. "custom" means the caller passes --items.
profile_items() {
    case "$1" in
        tracker-lekki)
            printf 'tracker-db-lekka,tracker-config,tracker-listy,tracker-opentracker,tracker-worker,tracker-janitor,tracker-siec,tracker-sudoers' ;;
        tracker-pelny)
            printf 'tracker-db,tracker-config,tracker-listy,tracker-opentracker,tracker-worker,tracker-janitor,tracker-siec,tracker-sudoers' ;;
        tracker-baza)
            printf 'tracker-db' ;;
        custom) : ;;
        *) return 1 ;;
    esac
    return 0
}

# ── tiny JSON writer (no jq dependency) ──────────────────────────────────────
jesc() {
    printf '%s' "${1-}" \
        | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g' -e 's/\t/\\t/g' -e 's/\r//g' \
        | sed -e ':a' -e 'N' -e '$!ba' -e 's/\n/\\n/g' \
        | tr -d '\000-\010\013\014\016-\037'
}
jstr() { printf '"%s"' "$(jesc "${1-}")"; }
jbool() { [ "${1:-0}" = "1" ] && printf 'true' || printf 'false'; }
fail() { printf '{"ok":false,"error":%s}\n' "$(jstr "$1")"; printf '%s\n' "$1" >&2; exit "${2:-2}"; }

is_uint() { case "${1-}" in ''|*[!0-9]*) return 1 ;; *) return 0 ;; esac; }
is_root() { [ "$(id -u 2>/dev/null || echo 1)" = "0" ]; }

# ── argument validation ──────────────────────────────────────────────────────

# An archive id is always a file basename we produced. Refuse anything that could escape the
# directory or name a file we did not write.
valid_id() {
    case "${1-}" in
        ''|*/*|*..*|.*) return 1 ;;
        backup-tryhackx-*|tracker-db-*) return 0 ;;
        *) return 1 ;;
    esac
}

# Item names are handed to Backup-serwera.sh --items. Only its own vocabulary shape is accepted.
valid_items() {
    case "${1-}" in
        ''|*[!a-z0-9,-]*) return 1 ;;
        *) return 0 ;;
    esac
}

valid_db() { case "${1-}" in ''|*[!A-Za-z0-9_]*) return 1 ;; *) return 0 ;; esac; }

# The backup directory. Must be absolute, must not be a symlink, and must not be one of the places
# where writing 0600 archives would either break the system or expose them to the web.
REFUSED_DIRS="/ /bin /boot /dev /etc /home /lib /lib64 /proc /root /run /sbin /srv /sys /usr /var /var/www /tmp"
valid_dir() {
    local d="${1-}"
    [ -n "$d" ] || { printf 'The backup directory is not configured.'; return 1; }
    case "$d" in
        /*) : ;;
        *) printf 'The backup directory must be an absolute path (got "%s").' "$d"; return 1 ;;
    esac
    case "$d" in
        *..*) printf 'The backup directory must not contain "..".'; return 1 ;;
    esac
    [ "${BACKUP_ALLOW_ANY_DIR:-0}" = "1" ] && return 0
    local r
    for r in $REFUSED_DIRS; do
        if [ "$d" = "$r" ]; then printf 'Refusing to use %s as the backup directory — pick a directory of its own, e.g. /var/backups/tracker.' "$d"; return 1; fi
    done
    case "$d" in
        /var/www/*|/srv/www/*|/usr/share/nginx/*)
            printf 'The backup directory must be OUTSIDE the web root — archives contain database passwords. Pick something like /var/backups/tracker.'; return 1 ;;
    esac
    if [ -L "$d" ]; then printf 'The backup directory must not be a symlink (%s).' "$d"; return 1; fi
    return 0
}

require_dir() {
    local msg
    msg="$(valid_dir "$1")" || fail "$msg"
}

# ── paths derived from the directory ─────────────────────────────────────────
state_file() { printf '%s/%s' "$1" "$STATE_NAME"; }
log_file()   { printf '%s/%s' "$1" "$LOG_NAME"; }
lock_dir()   { printf '%s/%s.d' "$1" "$LOCK_NAME"; }

# ── one run at a time ────────────────────────────────────────────────────────
# A second dump against a live database is exactly what nobody wants. mkdir is atomic everywhere,
# which flock is not (util-linux only) — and a lock left behind by a killed worker is detected by
# checking whether the PID inside it still exists.
LOCK_HELD=""
acquire_lock() {  # acquire_lock <dir>
    local ld; ld="$(lock_dir "$1")"
    if ! mkdir "$ld" 2>/dev/null; then
        local pid; pid="$(cat "$ld/pid" 2>/dev/null || true)"
        if is_uint "$pid" && [ "$pid" -gt 0 ] && kill -0 "$pid" 2>/dev/null; then return 1; fi
        rm -rf -- "$ld"                       # stale: the worker that held it is gone
        mkdir "$ld" 2>/dev/null || return 1
    fi
    printf '%s' "$$" >"$ld/pid"
    LOCK_HELD="$ld"
    return 0
}
release_lock() { [ -n "$LOCK_HELD" ] && rm -rf -- "$LOCK_HELD"; LOCK_HELD=""; }

# The archive belonging to an id, or empty. Extensions in order of preference.
archive_path() {  # archive_path <dir> <id>
    local d="$1" id="$2" ext
    for ext in .tar.gz.gpg .tar.gz .zip.gpg .zip .sql.gz.gpg .sql.gz; do
        [ -f "$d/$id$ext" ] && { printf '%s/%s%s' "$d" "$id" "$ext"; return 0; }
    done
    return 1
}

# ── state file ───────────────────────────────────────────────────────────────
# Written only by the worker (as root) and read by every action. The panel never writes it.
write_state() {  # write_state <dir> <state> <key=value>...
    local d="$1" st="$2"; shift 2
    local tmp; tmp="$(mktemp "${TMPDIR:-/tmp}/tbstate.XXXXXX")" || return 1
    {
        printf '{"state":%s' "$(jstr "$st")"
        local kv k v
        for kv in "$@"; do
            k="${kv%%=*}"; v="${kv#*=}"
            case "$k" in
                # numbers and booleans go in bare; everything else is a string
                started_at|finished_at|pid|bytes|progress|size) printf ',%s:%s' "$(jstr "$k")" "${v:-0}" ;;
                encrypted|verified|cancelled) printf ',%s:%s' "$(jstr "$k")" "$v" ;;
                *) printf ',%s:%s' "$(jstr "$k")" "$(jstr "$v")" ;;
            esac
        done
        printf ',"log_tail":%s' "$(jstr "$(tail -n $LOG_TAIL_LINES "$(log_file "$d")" 2>/dev/null || true)")"
        printf ',"ts":%s}\n' "$(date +%s)"
    } >"$tmp"
    install -m 0600 "$tmp" "$(state_file "$d")" 2>/dev/null || cp "$tmp" "$(state_file "$d")"
    rm -f "$tmp"
}

note() { printf '[%s] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$1" >>"$(log_file "$WORK_DIR")"; }

# ── check / test-path ────────────────────────────────────────────────────────

emit_check() {
    local d="${1-}" errs="" hints="" dirmsg=""
    [ -n "$MARIADB_DUMP" ] || { errs="No MariaDB/MySQL dump client (mariadb-dump / mysqldump) was found."
                                hints="Install it: sudo apt install mariadb-client"; }
    if [ -n "$d" ]; then
        if ! dirmsg="$(valid_dir "$d")"; then
            errs="${errs:+$errs | }$dirmsg"
        elif [ ! -d "$d" ]; then
            hints="${hints:+$hints | }The directory does not exist yet; it will be created 0700 root on the first run."
        fi
    fi
    printf '{"ok":%s' "$([ -z "$errs" ] && printf 'true' || printf 'false')"
    printf ',"root":%s' "$(is_root && printf 'true' || printf 'false')"
    printf ',"script":%s,"script_path":%s' \
        "$([ -x "$BACKUP_SCRIPT" ] && printf 'true' || printf 'false')" "$(jstr "$BACKUP_SCRIPT")"
    printf ',"mariadb_dump":%s,"mariadb_dump_path":%s' \
        "$([ -n "$MARIADB_DUMP" ] && printf 'true' || printf 'false')" "$(jstr "$MARIADB_DUMP")"
    printf ',"mariadb":%s' "$([ -n "$MARIADB" ] && printf 'true' || printf 'false')"
    printf ',"gpg":%s' "$(command -v gpg >/dev/null 2>&1 && printf 'true' || printf 'false')"
    printf ',"systemd_run":%s' "$(command -v systemd-run >/dev/null 2>&1 && printf 'true' || printf 'false')"
    printf ',"nice":%s' "$(command -v ionice >/dev/null 2>&1 && printf 'true' || printf 'false')"
    printf ',"mode":%s' "$([ -x "$BACKUP_SCRIPT" ] && jstr 'script' || jstr 'builtin')"
    printf ',"error":%s,"hint":%s}\n' "$(jstr "$errs")" "$(jstr "$hints")"
    [ -z "$errs" ]
}

emit_test_path() {
    local d="$1" errs="" hints="" free=0 used=0 mode="" owner="" exists=0 writable=0 count=0 msg
    if ! msg="$(valid_dir "$d")"; then
        printf '{"ok":false,"path":%s,"errors":[%s],"suggestions":[%s]}\n' "$(jstr "$d")" "$(jstr "$msg")" \
            "$(jstr "Pick a directory of its own outside the web root, e.g. /var/backups/tracker.")"
        return 1
    fi
    if [ -d "$d" ]; then
        exists=1
        [ -w "$d" ] && writable=1
        mode="$(stat -c '%a' "$d" 2>/dev/null || true)"
        owner="$(stat -c '%U:%G' "$d" 2>/dev/null || true)"
        count="$(find "$d" -maxdepth 1 -type f -name 'backup-tryhackx-*' -o -maxdepth 1 -type f -name 'tracker-db-*' 2>/dev/null | grep -c . || true)"
        used="$(du -sb "$d" 2>/dev/null | awk '{print $1+0}')"
        [ "$writable" = "1" ] || { errs="The directory exists but is not writable by root — check the mount and its permissions."; }
        case "$mode" in
            700|750|0700|0750) : ;;
            '') : ;;
            *) hints="${hints:+$hints | }Mode is $mode; archives contain database passwords, so 0700 is the right mode: sudo chmod 0700 $d" ;;
        esac
    else
        local parent; parent="$(dirname "$d")"
        if [ ! -d "$parent" ]; then
            errs="Neither the directory nor its parent exists: $parent"
            hints="sudo install -d -m 0700 $d"
        else
            hints="It does not exist yet — the first run creates it 0700 root."
        fi
    fi
    free="$(df -P -B1 "$([ -d "$d" ] && printf '%s' "$d" || dirname "$d")" 2>/dev/null | awk 'NR==2{print $4+0}')"
    printf '{"ok":%s,"path":%s,"exists":%s,"writable":%s,"mode":%s,"owner":%s,"archives":%s,"used_bytes":%s,"free_bytes":%s' \
        "$([ -z "$errs" ] && printf 'true' || printf 'false')" "$(jstr "$d")" "$(jbool $exists)" "$(jbool $writable)" \
        "$(jstr "$mode")" "$(jstr "$owner")" "${count:-0}" "${used:-0}" "${free:-0}"
    printf ',"errors":['
    [ -n "$errs" ] && jstr "$errs"
    printf '],"suggestions":['
    [ -n "$hints" ] && jstr "$hints"
    printf ']}\n'
    [ -z "$errs" ]
}

# ── listing ──────────────────────────────────────────────────────────────────

# One archive as a JSON object, using its sidecar meta when we wrote one.
emit_archive() {  # emit_archive <path>
    local p="$1" base id size ts meta profile items mode enc sha verified
    base="$(basename "$p")"
    id="$base"
    for ext in .tar.gz.gpg .tar.gz .zip.gpg .zip .sql.gz.gpg .sql.gz; do
        case "$base" in *"$ext") id="${base%"$ext"}"; break ;; esac
    done
    size="$(stat -c '%s' "$p" 2>/dev/null || echo 0)"
    ts="$(stat -c '%Y' "$p" 2>/dev/null || echo 0)"
    meta="$(dirname "$p")/$id.meta.json"
    profile=""; items=""; mode=""; enc=false; sha=""; verified=null
    case "$base" in *.gpg) enc=true ;; esac
    if [ -r "$meta" ]; then
        profile="$(awk -F'"' '/"profile"/ {print $4; exit}' "$meta" 2>/dev/null || true)"
        items="$(awk -F'"' '/"items"/ {print $4; exit}' "$meta" 2>/dev/null || true)"
        mode="$(awk -F'"' '/"mode"/ {print $4; exit}' "$meta" 2>/dev/null || true)"
        sha="$(awk -F'"' '/"sha256"/ {print $4; exit}' "$meta" 2>/dev/null || true)"
        verified="$(awk -F'[:,]' '/"verified"/ {gsub(/[ "}]/,"",$2); print $2; exit}' "$meta" 2>/dev/null || true)"
        case "$verified" in true|false) : ;; *) verified=null ;; esac
    fi
    printf '{"id":%s,"file":%s,"size":%s,"ts":%s,"profile":%s,"items":%s,"mode":%s,"encrypted":%s,"sha256":%s,"verified":%s}' \
        "$(jstr "$id")" "$(jstr "$base")" "$size" "$ts" "$(jstr "$profile")" "$(jstr "$items")" \
        "$(jstr "$mode")" "$enc" "$(jstr "$sha")" "$verified"
}

emit_list() {
    local d="$1" first=1 total=0 p
    [ -d "$d" ] || { printf '{"ok":true,"dir":%s,"archives":[],"total_bytes":0,"free_bytes":0}\n' "$(jstr "$d")"; return 0; }
    printf '{"ok":true,"dir":%s,"archives":[' "$(jstr "$d")"
    while IFS= read -r p; do
        [ -n "$p" ] || continue
        [ $first -eq 1 ] || printf ','
        first=0
        emit_archive "$p"
        total=$(( total + $(stat -c '%s' "$p" 2>/dev/null || echo 0) ))
    done <<EOF
$(find "$d" -maxdepth 1 -type f \( -name 'backup-tryhackx-*' -o -name 'tracker-db-*' \) ! -name '*.meta.json' -printf '%T@\t%p\n' 2>/dev/null \
   | sort -rn | head -n $MAX_ARCHIVES | cut -f2-)
EOF
    printf '],"total_bytes":%s,"free_bytes":%s}\n' "$total" \
        "$(df -P -B1 "$d" 2>/dev/null | awk 'NR==2{print $4+0}')"
}

# One value out of the state file. The file is a single JSON line with many keys, so the match has
# to name the key — splitting the whole line on ":" would return whatever happens to sit second.
read_state_num() { sed -n 's/.*"'"$2"'":\([0-9][0-9]*\).*/\1/p' "$1" 2>/dev/null | head -1; }
read_state_str() { sed -n 's/.*"'"$2"'":"\([^"]*\)".*/\1/p' "$1" 2>/dev/null | head -1; }

emit_status() {
    local d="$1" sf; sf="$(state_file "$d")"
    if [ -r "$sf" ]; then
        # the state file is already a JSON object; hand it back with a liveness flag appended
        local pid state running=false
        pid="$(read_state_num "$sf" pid)"
        state="$(read_state_str "$sf" state)"
        if is_uint "$pid" && [ "$pid" -gt 0 ] && kill -0 "$pid" 2>/dev/null; then running=true; fi
        # A worker that was OOM-killed, or lost to a reboot, never wrote its final state. A card that
        # says "running" for ever is worse than one that says what actually happened.
        if [ "$state" = "running" ] && [ "$running" = "false" ]; then
            write_state "$d" failed id="$(read_state_str "$sf" id)" mode="$(read_state_str "$sf" mode)" \
                profile="$(read_state_str "$sf" profile)" items="$(read_state_str "$sf" items)" \
                started_at="$(read_state_num "$sf" started_at)" finished_at="$(date +%s)" pid=0 step="lost" \
                bytes=0 archive="" encrypted=false verified=null \
                error="The backup process is gone without finishing — it was killed, ran out of memory, or the machine restarted."
        fi
        sed -e 's/}[[:space:]]*$//' "$sf" | tr -d '\n'
        printf ',"running":%s,"now":%s}\n' "$running" "$(date +%s)"
    else
        printf '{"state":"idle","running":false,"now":%s}\n' "$(date +%s)"
    fi
}

# ── the run ──────────────────────────────────────────────────────────────────

WORK_DIR=""      # set by run/_worker so note() knows where the log is

action_run() {
    local d="$1" profile="$2"; shift 2
    require_dir "$d"
    local items="" nice=15 recipient="" verify=0 keep=0 keepdays=0 maxgb=0 dbname="$TRACKER_DB_DEFAULT"
    while [ $# -gt 0 ]; do
        case "$1" in
            --items)         shift; items="${1-}" ;;
            --nice)          shift; nice="${1-}" ;;
            --gpg-recipient) shift; recipient="${1-}" ;;
            --verify)        verify=1 ;;
            --keep)          shift; keep="${1-}" ;;
            --keep-days)     shift; keepdays="${1-}" ;;
            --max-gb)        shift; maxgb="${1-}" ;;
            --db)            shift; dbname="${1-}" ;;
            *) fail "unknown option for run: $1" 1 ;;
        esac
        shift || true
    done

    local pitems
    if [ "$profile" = "custom" ]; then
        valid_items "$items" || fail "Invalid item list: only lowercase letters, digits, '-' and ',' are allowed."
        [ -n "$items" ] || fail "The custom profile needs at least one item."
        pitems="$items"
    else
        pitems="$(profile_items "$profile")" || fail "Unknown profile '$profile' (known: tracker-lekki, tracker-pelny, tracker-baza, custom)."
    fi
    is_uint "$nice" || nice=15
    [ "$nice" -gt 19 ] && nice=19
    for v in "$keep" "$keepdays" "$maxgb"; do is_uint "$v" || fail "keep / keep-days / max-gb must be whole numbers."; done
    valid_db "$dbname" || fail "Invalid database name."
    case "$recipient" in *[!A-Za-z0-9@._+-]*) fail "Invalid GPG recipient (letters, digits and @ . _ + - only)." ;; esac
    is_root || fail "must run as root"

    [ -d "$d" ] || install -d -m 0700 "$d" || fail "cannot create $d"
    chmod 0700 "$d" 2>/dev/null || true

    # refuse early, before forking anything, when a run is already in flight
    if ! acquire_lock "$d"; then
        fail "A backup is already running in $d. Wait for it to finish, or cancel it." 5
    fi
    release_lock                                # the detached worker takes it for real

    local stamp id mode
    stamp="$(date '+%Y%m%d-%H%M%S')"
    if [ -x "$BACKUP_SCRIPT" ]; then mode=script; id="backup-tryhackx-$stamp"; else mode=builtin; id="tracker-db-$stamp"; fi
    : >"$(log_file "$d")"
    chmod 0600 "$(log_file "$d")" 2>/dev/null || true
    WORK_DIR="$d"

    # Detach: the panel's request must return immediately, and the dump must survive php-fpm
    # recycling the worker. systemd-run also gets us Nice/IO priority as real resource control.
    local self="$0"
    if command -v systemd-run >/dev/null 2>&1; then
        systemd-run --quiet --collect --unit="tracker-backup-$stamp" \
            --property="Nice=$nice" --property="IOSchedulingClass=idle" \
            -- "$self" _worker "$d" "$id" "$mode" "$pitems" "$profile" "$nice" "$recipient" "$verify" "$keep" "$keepdays" "$maxgb" "$dbname" \
            >>"$(log_file "$d")" 2>&1 \
            || fail "systemd-run refused to start the backup — see $(log_file "$d")" 3
    elif command -v setsid >/dev/null 2>&1; then
        setsid nice -n "$nice" "$self" _worker "$d" "$id" "$mode" "$pitems" "$profile" "$nice" "$recipient" "$verify" "$keep" "$keepdays" "$maxgb" "$dbname" \
            >>"$(log_file "$d")" 2>&1 &
        disown 2>/dev/null || true
    else
        # no systemd-run and no setsid (util-linux missing, or a non-Linux test box): a plain
        # background job still outlives the request, it just does not get its own session
        nice -n "$nice" "$self" _worker "$d" "$id" "$mode" "$pitems" "$profile" "$nice" "$recipient" "$verify" "$keep" "$keepdays" "$maxgb" "$dbname" \
            >>"$(log_file "$d")" 2>&1 &
        disown 2>/dev/null || true
    fi

    printf '{"ok":true,"started":true,"id":%s,"mode":%s,"profile":%s,"items":%s,"dir":%s}\n' \
        "$(jstr "$id")" "$(jstr "$mode")" "$(jstr "$profile")" "$(jstr "$pitems")" "$(jstr "$d")"
}

# The actual work. Runs detached; the panel only ever sees the state file.
action_worker() {
    local d="$1" id="$2" mode="$3" items="$4" profile="$5" nice="$6" recipient="$7" verify="$8" keep="$9"
    shift 9
    local keepdays="$1" maxgb="$2" dbname="$3"
    WORK_DIR="$d"

    if ! acquire_lock "$d"; then note "another run holds the lock — exiting"; exit 0; fi
    trap 'release_lock' EXIT

    local started; started="$(date +%s)"
    write_state "$d" running id="$id" mode="$mode" profile="$profile" items="$items" \
        started_at="$started" pid="$$" step="starting" bytes=0 archive="" error="" encrypted=false verified=null
    note "backup start: id=$id mode=$mode profile=$profile items=$items nice=$nice"

    local archive="" rc=0
    if [ "$mode" = script ]; then
        write_state "$d" running id="$id" mode="$mode" profile="$profile" items="$items" \
            started_at="$started" pid="$$" step="running Backup-serwera.sh" bytes=0 archive="" error="" encrypted=false verified=null
        # --no-gpg on purpose: the tool's encryption needs a TTY for the passphrase and silently
        # skips itself without one. We encrypt below with a public key instead.
        "$BACKUP_SCRIPT" --backup --items "$items" --out "$d" --no-gpg --yes >>"$(log_file "$d")" 2>&1 || rc=$?
        if [ $rc -ne 0 ]; then
            write_state "$d" failed id="$id" mode="$mode" profile="$profile" items="$items" \
                started_at="$started" finished_at="$(date +%s)" pid=0 step="failed" bytes=0 archive="" \
                error="Backup-serwera.sh exited with code $rc — see the log below." encrypted=false verified=null
            note "FAILED: exit $rc"
            exit $rc
        fi
        # the tool stamps its own name; take the newest archive it just produced
        archive="$(find "$d" -maxdepth 1 -type f -name 'backup-tryhackx-*' ! -name '*.meta.json' ! -name '*.gpg' -newermt "@$((started - 5))" -printf '%T@\t%p\n' 2>/dev/null | sort -rn | head -1 | cut -f2-)"
        [ -n "$archive" ] || {
            write_state "$d" failed id="$id" mode="$mode" profile="$profile" items="$items" \
                started_at="$started" finished_at="$(date +%s)" pid=0 step="failed" bytes=0 archive="" \
                error="Backup-serwera.sh reported success but no archive appeared in $d." encrypted=false verified=null
            note "FAILED: no archive produced"; exit 4; }
        id="$(basename "$archive")"; id="${id%.tar.gz}"; id="${id%.zip}"
    else
        [ -n "$MARIADB_DUMP" ] || {
            write_state "$d" failed id="$id" mode="$mode" profile="$profile" items="$items" \
                started_at="$started" finished_at="$(date +%s)" pid=0 step="failed" bytes=0 archive="" \
                error="No mariadb-dump/mysqldump on this machine and no Backup-serwera.sh either." encrypted=false verified=null
            exit 2; }
        archive="$d/$id.sql.gz"
        write_state "$d" running id="$id" mode="$mode" profile="$profile" items="$items" \
            started_at="$started" pid="$$" step="dumping database $dbname" bytes=0 archive="" error="" encrypted=false verified=null
        note "builtin dump of database $dbname -> $archive"
        install -m 0600 /dev/null "$archive"
        # --single-transaction keeps InnoDB consistent WITHOUT locking the tables the tracker is
        # writing to; the shared MariaDB serves several other sites on this box.
        if ! ( set -o pipefail; "$MARIADB_DUMP" --single-transaction --routines --triggers --quick "$dbname" | gzip -c >"$archive" ) 2>>"$(log_file "$d")"; then
            rm -f -- "$archive"
            write_state "$d" failed id="$id" mode="$mode" profile="$profile" items="$items" \
                started_at="$started" finished_at="$(date +%s)" pid=0 step="failed" bytes=0 archive="" \
                error="mariadb-dump failed — see the log below." encrypted=false verified=null
            note "FAILED: mariadb-dump"; exit 3
        fi
    fi
    chmod 0600 "$archive" 2>/dev/null || true

    # ── encryption (public key, so no passphrase and no terminal is needed) ──
    local encrypted=false
    if [ -n "$recipient" ]; then
        if ! command -v gpg >/dev/null 2>&1; then
            note "WARNING: gpg is not installed — the archive stays unencrypted"
        else
            write_state "$d" running id="$id" mode="$mode" profile="$profile" items="$items" \
                started_at="$started" pid="$$" step="encrypting for $recipient" bytes=0 archive="$archive" error="" encrypted=false verified=null
            if gpg --batch --yes --trust-model always --encrypt --recipient "$recipient" \
                   -o "$archive.gpg" "$archive" >>"$(log_file "$d")" 2>&1; then
                chmod 0600 "$archive.gpg"
                rm -f -- "$archive"
                archive="$archive.gpg"
                encrypted=true
                note "encrypted for $recipient"
            else
                note "WARNING: gpg --encrypt failed (is the key '$recipient' imported and trusted?) — the archive stays unencrypted"
            fi
        fi
    fi

    local size sha
    size="$(stat -c '%s' "$archive" 2>/dev/null || echo 0)"
    write_state "$d" running id="$id" mode="$mode" profile="$profile" items="$items" \
        started_at="$started" pid="$$" step="checksumming" bytes="$size" archive="$archive" error="" encrypted=$encrypted verified=null
    sha="$(sha256sum "$archive" 2>/dev/null | awk '{print $1}')"

    # Sidecar: what this archive is, so `list` never has to open it. ONE KEY PER LINE on purpose —
    # both readers pull values out with `awk -F'"'`, which needs the key it matched to be the only
    # one on the line.
    cat >"$d/$id.meta.json" <<EOF
{
"id":"$(jesc "$id")",
"ts":$(date +%s),
"profile":"$(jesc "$profile")",
"items":"$(jesc "$items")",
"mode":"$(jesc "$mode")",
"size":$size,
"sha256":"$(jesc "$sha")",
"encrypted":$encrypted,
"verified":null,
"file":"$(jesc "$(basename "$archive")")"
}
EOF
    chmod 0600 "$d/$id.meta.json"

    local verified=null
    if [ "$verify" = "1" ]; then
        write_state "$d" running id="$id" mode="$mode" profile="$profile" items="$items" \
            started_at="$started" pid="$$" step="verifying" bytes="$size" archive="$archive" error="" encrypted=$encrypted verified=null
        if verify_archive "$d" "$id" 0 >/dev/null 2>&1; then verified=true; else verified=false; fi
        set_meta_verified "$d" "$id" "$verified"
        note "verify: $verified"
    fi

    local pruned=""
    if [ "$keep" -gt 0 ] || [ "$keepdays" -gt 0 ] || [ "$maxgb" -gt 0 ]; then
        pruned="$(prune_archives "$d" "$keep" "$keepdays" "$maxgb" 0)"
        [ -n "$pruned" ] && note "pruned: $pruned"
    fi

    write_state "$d" done id="$id" mode="$mode" profile="$profile" items="$items" \
        started_at="$started" finished_at="$(date +%s)" pid=0 step="finished" bytes="$size" \
        archive="$archive" error="" encrypted=$encrypted verified="$verified" pruned="$pruned"
    note "backup done: $archive ($size bytes)"
    exit 0
}

action_cancel() {
    local d="$1"
    require_dir "$d"
    is_root || fail "must run as root"
    local sf pid; sf="$(state_file "$d")"
    [ -r "$sf" ] || fail "There is no backup to cancel."
    pid="$(read_state_num "$sf" pid)"
    is_uint "$pid" && [ "$pid" -gt 0 ] || fail "There is no backup running."
    kill -0 "$pid" 2>/dev/null || fail "There is no backup running (the recorded process is gone)."
    kill -TERM "$pid" 2>/dev/null || true
    sleep 1
    kill -0 "$pid" 2>/dev/null && kill -KILL "$pid" 2>/dev/null || true
    WORK_DIR="$d"; note "cancelled by the panel"
    write_state "$d" failed step="cancelled" pid=0 finished_at="$(date +%s)" \
        error="Cancelled from the panel." cancelled=true
    printf '{"ok":true,"cancelled":true,"pid":%s}\n' "$pid"
}

# ── verify ───────────────────────────────────────────────────────────────────

set_meta_verified() {  # set_meta_verified <dir> <id> true|false
    local m="$1/$2.meta.json"
    [ -f "$m" ] || return 0
    sed -i -E "s/\"verified\":[a-z]+/\"verified\":$3/" "$m" 2>/dev/null || true
}

# Quick: the archive's own sha256 against the sidecar — catches truncation and bit rot for the price
# of one read. Deep: unpack through Backup-serwera.sh --restore --dry-run, which checks the
# SUMY.sha256 of every file INSIDE. Returns 0 when the archive is sound.
verify_archive() {  # verify_archive <dir> <id> <deep>
    local d="$1" id="$2" deep="$3" p sha want
    p="$(archive_path "$d" "$id")" || { printf 'no such archive'; return 2; }
    want="$(awk -F'"' '/"sha256"/ {print $4; exit}' "$d/$id.meta.json" 2>/dev/null || true)"
    sha="$(sha256sum "$p" 2>/dev/null | awk '{print $1}')"
    if [ -n "$want" ] && [ "$sha" != "$want" ]; then
        printf 'checksum mismatch: the archive changed since it was written'
        return 1
    fi
    case "$p" in
        *.gpg) printf 'checksum ok (encrypted archives are not unpacked here)'; return 0 ;;
        *.sql.gz) gzip -t "$p" 2>/dev/null || { printf 'gzip reports the dump is corrupt'; return 1; }
                  printf 'checksum ok, gzip stream intact'; return 0 ;;
        *.zip) command -v unzip >/dev/null 2>&1 && { unzip -tq "$p" >/dev/null 2>&1 || { printf 'zip reports the archive is corrupt'; return 1; }; }
               printf 'checksum ok, zip readable'; return 0 ;;
    esac
    tar -tzf "$p" >/dev/null 2>&1 || { printf 'tar cannot read the archive'; return 1; }
    if [ "$deep" = "1" ]; then
        [ -x "$BACKUP_SCRIPT" ] || { printf 'checksum ok, tar readable (deep check needs Backup-serwera.sh)'; return 0; }
        "$BACKUP_SCRIPT" --restore "$p" --dry-run --yes >>"$(log_file "$d")" 2>&1 \
            || { printf 'the deep check failed — see the log'; return 1; }
        printf 'checksum ok, and every file inside matches SUMY.sha256'
        return 0
    fi
    printf 'checksum ok, tar readable'
    return 0
}

action_verify() {
    local d="$1" id="$2" deep="${3-}"
    require_dir "$d"
    valid_id "$id" || fail "Invalid archive id."
    WORK_DIR="$d"
    local msg rc=0
    msg="$(verify_archive "$d" "$id" "$([ "$deep" = "--deep" ] && echo 1 || echo 0)")" || rc=$?
    [ "$rc" = "0" ] && set_meta_verified "$d" "$id" true || set_meta_verified "$d" "$id" false
    printf '{"ok":%s,"id":%s,"deep":%s,"message":%s}\n' \
        "$([ "$rc" = "0" ] && printf 'true' || printf 'false')" "$(jstr "$id")" \
        "$([ "$deep" = "--deep" ] && printf 'true' || printf 'false')" "$(jstr "$msg")"
    [ "$rc" = "0" ]
}

# ── rotation ─────────────────────────────────────────────────────────────────

# Oldest first, until every rule is satisfied. Prints the removed ids, space-separated.
prune_archives() {  # prune_archives <dir> <keep> <keep-days> <max-gb> <dry-run>
    local d="$1" keep="$2" days="$3" maxgb="$4" dry="$5"
    local removed="" list n i p id ts now cutoff total maxbytes
    now="$(date +%s)"
    list="$(find "$d" -maxdepth 1 -type f \( -name 'backup-tryhackx-*' -o -name 'tracker-db-*' \) ! -name '*.meta.json' -printf '%T@\t%p\n' 2>/dev/null | sort -n | cut -f2-)"
    [ -n "$list" ] || { printf ''; return 0; }
    n="$(printf '%s\n' "$list" | grep -c .)"

    # 1. age
    if [ "$days" -gt 0 ]; then
        cutoff=$(( now - days * 86400 ))
        while IFS= read -r p; do
            [ -n "$p" ] || continue
            ts="$(stat -c '%Y' "$p" 2>/dev/null || echo "$now")"
            [ "$ts" -lt "$cutoff" ] || continue
            [ "$n" -gt 1 ] || break                     # never delete the last archive on age alone
            id="$(drop_archive "$d" "$p" "$dry")"; removed="$removed $id"; n=$(( n - 1 ))
        done <<EOF
$list
EOF
        list="$(find "$d" -maxdepth 1 -type f \( -name 'backup-tryhackx-*' -o -name 'tracker-db-*' \) ! -name '*.meta.json' -printf '%T@\t%p\n' 2>/dev/null | sort -n | cut -f2-)"
    fi

    # 2. count
    if [ "$keep" -gt 0 ]; then
        n="$(printf '%s\n' "$list" | grep -c . || true)"
        i=0
        while IFS= read -r p; do
            [ -n "$p" ] || continue
            [ $(( n - i )) -gt "$keep" ] || break
            id="$(drop_archive "$d" "$p" "$dry")"; removed="$removed $id"; i=$(( i + 1 ))
        done <<EOF
$list
EOF
        list="$(find "$d" -maxdepth 1 -type f \( -name 'backup-tryhackx-*' -o -name 'tracker-db-*' \) ! -name '*.meta.json' -printf '%T@\t%p\n' 2>/dev/null | sort -n | cut -f2-)"
    fi

    # 3. total size — oldest go until the archives fit, but never the last one standing.
    #    The budget counts ARCHIVES, not the directory: the pre-restore safety dumps also live here
    #    and are never rotated away, so charging them against the ceiling could delete every archive
    #    and still not satisfy the rule.
    if [ "$maxgb" -gt 0 ]; then
        maxbytes=$(( maxgb * 1024 * 1024 * 1024 ))
        total=0
        while IFS= read -r p; do
            [ -n "$p" ] || continue
            total=$(( total + $(stat -c '%s' "$p" 2>/dev/null || echo 0) ))
        done <<EOF
$list
EOF
        n="$(printf '%s\n' "$list" | grep -c . || true)"
        while [ "${total:-0}" -gt "$maxbytes" ] && [ "$n" -gt 1 ]; do
            p="$(printf '%s\n' "$list" | head -1)"
            [ -n "$p" ] || break
            total=$(( total - $(stat -c '%s' "$p" 2>/dev/null || echo 0) ))
            id="$(drop_archive "$d" "$p" "$dry")"; removed="$removed $id"; n=$(( n - 1 ))
            list="$(printf '%s\n' "$list" | tail -n +2)"
        done
    fi
    printf '%s' "${removed# }"
}

drop_archive() {  # drop_archive <dir> <path> <dry-run> ; prints the id
    local d="$1" p="$2" dry="$3" base id
    base="$(basename "$p")"; id="$base"
    for ext in .tar.gz.gpg .tar.gz .zip.gpg .zip .sql.gz.gpg .sql.gz; do
        case "$base" in *"$ext") id="${base%"$ext"}"; break ;; esac
    done
    if [ "$dry" != "1" ]; then rm -f -- "$p" "$d/$id.meta.json"; fi
    printf '%s' "$id"
}

action_prune() {
    local d="$1" keep="$2" days="$3" maxgb="$4" dry="${5-}"
    require_dir "$d"
    for v in "$keep" "$days" "$maxgb"; do is_uint "$v" || fail "keep / keep-days / max-gb must be whole numbers."; done
    is_root || fail "must run as root"
    local removed
    removed="$(prune_archives "$d" "$keep" "$days" "$maxgb" "$([ "$dry" = "--dry-run" ] && echo 1 || echo 0)")"
    printf '{"ok":true,"dry_run":%s,"removed":[' "$([ "$dry" = "--dry-run" ] && printf 'true' || printf 'false')"
    local first=1 id
    for id in $removed; do [ $first -eq 1 ] || printf ','; first=0; jstr "$id"; done
    printf ']}\n'
}

action_delete() {
    local d="$1" id="$2"
    require_dir "$d"
    valid_id "$id" || fail "Invalid archive id."
    is_root || fail "must run as root"
    local p; p="$(archive_path "$d" "$id")" || fail "No archive with id '$id' in $d."
    rm -f -- "$p" "$d/$id.meta.json" || fail "Could not remove $p"
    WORK_DIR="$d"; note "deleted archive $id"
    printf '{"ok":true,"deleted":%s}\n' "$(jstr "$id")"
}

action_cat() {
    local d="$1" id="$2"
    require_dir "$d"
    valid_id "$id" || fail "Invalid archive id."
    local p; p="$(archive_path "$d" "$id")" || fail "No archive with id '$id' in $d."
    WORK_DIR="$d"; note "downloaded archive $id"
    cat -- "$p"
}

# ── restore ──────────────────────────────────────────────────────────────────

# Files and configuration only. Backup-serwera.sh keeps a *.bak-<stamp> of everything it overwrites
# and refuses to touch a database without a person at a terminal — we do not work around that; the
# database has its own action below.
action_restore() {
    local d="$1" id="$2"; shift 2
    require_dir "$d"
    valid_id "$id" || fail "Invalid archive id."
    local items="" dry=0
    while [ $# -gt 0 ]; do
        case "$1" in
            --items)   shift; items="${1-}" ;;
            --dry-run) dry=1 ;;
            *) fail "unknown option for restore: $1" 1 ;;
        esac
        shift || true
    done
    [ -n "$items" ] || fail "Restore needs an explicit item list — it never restores everything by accident."
    valid_items "$items" || fail "Invalid item list."
    [ -x "$BACKUP_SCRIPT" ] || fail "Restoring needs Backup-serwera.sh at $BACKUP_SCRIPT."
    is_root || fail "must run as root"
    local p; p="$(archive_path "$d" "$id")" || fail "No archive with id '$id' in $d."
    case "$p" in *.gpg) fail "This archive is encrypted. Decrypt it on the server first: gpg -o <plain> -d '$p'" ;; esac
    case "$p" in *.sql.gz) fail "This is a plain database dump, not a full archive — use restore-db." ;; esac

    WORK_DIR="$d"; note "restore $id items=$items dry=$dry"
    local out rc=0
    if [ "$dry" = "1" ]; then
        out="$("$BACKUP_SCRIPT" --restore "$p" --items "$items" --dry-run --yes 2>&1)" || rc=$?
    else
        out="$("$BACKUP_SCRIPT" --restore "$p" --items "$items" --yes 2>&1)" || rc=$?
    fi
    printf '%s\n' "$out" >>"$(log_file "$d")"
    printf '{"ok":%s,"id":%s,"items":%s,"dry_run":%s,"code":%s,"output":%s}\n' \
        "$([ $rc -eq 0 ] && printf 'true' || printf 'false')" "$(jstr "$id")" "$(jstr "$items")" \
        "$([ "$dry" = "1" ] && printf 'true' || printf 'false')" "$rc" "$(jstr "$(printf '%s' "$out" | tail -n 60)")"
    [ $rc -eq 0 ]
}

# The one action that overwrites live data. Backup-serwera.sh refuses to do this without somebody
# typing the database name at a terminal — a guard worth keeping, so we ask for the same thing
# (--db must equal --confirm, and the panel makes a human type it) and, exactly like that tool,
# dump the current database before importing anything.
action_restore_db() {
    local d="$1" id="$2"; shift 2
    require_dir "$d"
    valid_id "$id" || fail "Invalid archive id."
    local db="" confirm="" dry=0
    while [ $# -gt 0 ]; do
        case "$1" in
            --db)      shift; db="${1-}" ;;
            --confirm) shift; confirm="${1-}" ;;
            --dry-run) dry=1 ;;
            *) fail "unknown option for restore-db: $1" 1 ;;
        esac
        shift || true
    done
    valid_db "$db" || fail "Invalid database name (letters, digits and _ only)."
    [ "$db" = "$confirm" ] || fail "The typed database name does not match '$db' — nothing was touched."
    [ -n "$MARIADB" ] || fail "No MariaDB client on this machine."
    is_root || fail "must run as root"

    local p; p="$(archive_path "$d" "$id")" || fail "No archive with id '$id' in $d."
    case "$p" in *.gpg) fail "This archive is encrypted. Decrypt it on the server first: gpg -o <plain> -d '$p'" ;; esac

    WORK_DIR="$d"
    local work sqlfile rc=0
    work="$(mktemp -d "${TMPDIR:-/tmp}/tracker-restore.XXXXXX")" || fail "cannot create a work directory"
    trap 'rm -rf "${work:-}"' EXIT
    chmod 0700 "$work"

    if [ "${p%.sql.gz}" != "$p" ]; then
        sqlfile="$work/dump.sql"
        gzip -dc -- "$p" >"$sqlfile" 2>>"$(log_file "$d")" || fail "cannot decompress $p" 3
    else
        # Pull ONLY the dump of this database out of the archive — no other file is unpacked.
        local member
        member="$(tar -tzf "$p" 2>/dev/null | grep -E "(^|/)db/${db}(-bez-index)?\.sql$" | head -1)"
        [ -n "$member" ] || fail "The archive does not contain a dump of the database '$db'."
        tar -xzf "$p" -C "$work" -- "$member" 2>>"$(log_file "$d")" || fail "cannot extract $member from the archive" 3
        sqlfile="$work/$member"
        [ -f "$sqlfile" ] || fail "the extracted dump is missing"
    fi

    local size; size="$(stat -c '%s' "$sqlfile" 2>/dev/null || echo 0)"
    if [ "$dry" = "1" ]; then
        printf '{"ok":true,"dry_run":true,"id":%s,"db":%s,"dump_bytes":%s,"message":%s}\n' \
            "$(jstr "$id")" "$(jstr "$db")" "$size" \
            "$(jstr "Would import $size bytes into '$db' after dumping the current database. Nothing has been changed.")"
        return 0
    fi

    # Safety net first: the state we are about to overwrite, as a plain dump next to the archives.
    local safety=""
    if [ -n "$MARIADB_DUMP" ]; then
        safety="$d/before-restore-$db-$(date '+%Y%m%d-%H%M%S').sql.gz"
        install -m 0600 /dev/null "$safety"
        if ! ( set -o pipefail; "$MARIADB_DUMP" --single-transaction --routines --triggers --quick "$db" | gzip -c >"$safety" ) 2>>"$(log_file "$d")"; then
            rm -f -- "$safety"
            fail "Could not dump the current '$db' first — REFUSING to import. Nothing was changed." 4
        fi
        note "safety dump before restore: $safety"
    else
        fail "No mariadb-dump on this machine, so the current database cannot be saved first — REFUSING to import." 4
    fi

    note "restore-db $id -> $db ($size bytes)"
    "$MARIADB" -e "CREATE DATABASE IF NOT EXISTS \`$db\` CHARACTER SET utf8mb4" >>"$(log_file "$d")" 2>&1 || true
    if ! "$MARIADB" "$db" <"$sqlfile" >>"$(log_file "$d")" 2>&1; then
        note "FAILED: import into $db"
        fail "The import into '$db' failed. The database as it was is saved at $safety" 5
    fi
    note "restore-db done"
    printf '{"ok":true,"id":%s,"db":%s,"dump_bytes":%s,"safety_dump":%s}\n' \
        "$(jstr "$id")" "$(jstr "$db")" "$size" "$(jstr "$safety")"
}

# ── dispatch ─────────────────────────────────────────────────────────────────
case "${1:-check}" in
    check)      shift || true; emit_check "${1-}" ;;
    test-path)  shift || true; [ -n "${1-}" ] || fail "test-path needs a directory" 1; emit_test_path "$1" ;;
    list)       shift || true; require_dir "${1-}"; emit_list "$1" ;;
    status)     shift || true; require_dir "${1-}"; emit_status "$1" ;;
    run)        shift || true; [ $# -ge 2 ] || fail "run needs a directory and a profile" 1; action_run "$@" ;;
    _worker)    shift || true; action_worker "$@" ;;
    cancel)     shift || true; action_cancel "${1-}" ;;
    verify)     shift || true; [ $# -ge 2 ] || fail "verify needs a directory and an id" 1; action_verify "$@" ;;
    prune)      shift || true; [ $# -ge 4 ] || fail "prune needs <dir> <keep> <keep-days> <max-gb>" 1; action_prune "$@" ;;
    delete)     shift || true; [ $# -ge 2 ] || fail "delete needs a directory and an id" 1; action_delete "$@" ;;
    cat)        shift || true; [ $# -ge 2 ] || fail "cat needs a directory and an id" 1; action_cat "$@" ;;
    restore)    shift || true; [ $# -ge 2 ] || fail "restore needs a directory and an id" 1; action_restore "$@" ;;
    restore-db) shift || true; [ $# -ge 2 ] || fail "restore-db needs a directory and an id" 1; action_restore_db "$@" ;;
    profiles)
        printf '{"ok":true,"profiles":{"tracker-lekki":%s,"tracker-pelny":%s,"tracker-baza":%s}}\n' \
            "$(jstr "$(profile_items tracker-lekki)")" "$(jstr "$(profile_items tracker-pelny)")" "$(jstr "$(profile_items tracker-baza)")" ;;
    -h|--help|help) sed -n '2,45p' "$0" | sed 's/^# \{0,1\}//' ; exit 0 ;;
    *) fail "unknown action '${1:-}' — use: check | test-path | list | status | run | cancel | verify | prune | delete | cat | restore | restore-db | profiles" 1 ;;
esac
