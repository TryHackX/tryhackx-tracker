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
# usage: tracker-mode.sh white|black|status
# Prints the active mode ("white" / "black") on stdout, exit 0 on success. Meant to be allowed for the
# web user via sudoers (NOPASSWD) so the tracker web app's schedule can call it:
#   www-data ALL=(root) NOPASSWD: /usr/local/sbin/tracker-mode.sh
set -u
HOME_DIR=/home/tracker
UNIT=opentracker
BIN=$HOME_DIR/opentracker
CONF=$HOME_DIR/opentracker.conf

current() {
  local t; t=$(readlink -f "$BIN" 2>/dev/null || true)
  case "$t" in *.white) echo white ;; *.black) echo black ;; *) echo unknown ;; esac
}

case "${1:-status}" in
  status) current; exit 0 ;;
  white|black)
    want=$1
    for f in "$HOME_DIR/opentracker.$want" "$HOME_DIR/opentracker.conf.$want"; do
      [ -e "$f" ] || { echo "missing $f" >&2; exit 2; }
    done
    if [ "$(current)" = "$want" ] && systemctl is-active --quiet "$UNIT"; then echo "$want"; exit 0; fi
    ln -sfn "opentracker.$want" "$BIN"            # relative symlinks, atomic replace
    ln -sfn "opentracker.conf.$want" "$CONF"
    chown -h tracker:tracker "$BIN" "$CONF" 2>/dev/null || true
    systemctl restart "$UNIT" || { echo "restart failed" >&2; exit 3; }
    sleep 1
    systemctl is-active --quiet "$UNIT" || { echo "unit not active after restart" >&2; journalctl -u "$UNIT" -n 5 --no-pager >&2; exit 4; }
    current; exit 0 ;;
  *) echo "usage: $0 white|black|status" >&2; exit 1 ;;
esac
