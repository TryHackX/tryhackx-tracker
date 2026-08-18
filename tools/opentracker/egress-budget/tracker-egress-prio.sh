#!/bin/bash
# Egress priority for the tracker host: everything except OpenTracker's UDP replies (sport 6969)
# leaves the box first. Companion of ottrack.nft (the nft table caps the volume, this qdisc keeps
# the web / SSH / game packets ahead of whatever tracker replies remain).
#   apply:    sudo ./tracker-egress-prio.sh [iface]
#   rollback: sudo tc qdisc replace dev <iface> root fq_codel
set -e
DEV=${1:-$(ip -o -4 route show to default | awk '{print $5; exit}')}
TC=$(command -v tc || echo /usr/sbin/tc)
# 2 bands; every TOS class -> band 0 (1:1, fq_codel); tracker replies -> band 1 (1:2, short pfifo)
$TC qdisc replace dev "$DEV" root handle 1: prio bands 2 priomap 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0
$TC qdisc replace dev "$DEV" parent 1:1 handle 10: fq_codel
$TC qdisc replace dev "$DEV" parent 1:2 handle 20: pfifo limit 2000
$TC filter del dev "$DEV" parent 1: 2>/dev/null || true
$TC filter add dev "$DEV" parent 1: protocol ip   prio 1 u32 match ip  protocol 17 0xff match ip  sport 6969 0xffff flowid 1:2
$TC filter add dev "$DEV" parent 1: protocol ipv6 prio 2 u32 match ip6 protocol 17 0xff match ip6 sport 6969 0xffff flowid 1:2
echo "tracker egress prio applied on $DEV"
