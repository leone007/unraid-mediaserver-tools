#!/bin/sh
# Sysbox Router part (item 132): the only command the Sysbox key may run on the router (command= in its key line).
# It reads counters and status, prints them in @sections and changes nothing. Works on any Linux router; the
# Entware, ASUS and add-on parts are printed only where they exist.
PATH=/opt/bin:/opt/sbin:/usr/sbin:/usr/bin:/sbin:/bin
case "$SSH_ORIGINAL_COMMAND" in
snap) ;;
sky) [ -x /jffs/scripts/firewall ] && /jffs/scripts/firewall stats 2>/dev/null | head -c 100000; exit 0 ;;
*) echo "sysbox_ro: not allowed" >&2; exit 1 ;;
esac
h() { command -v "$1" >/dev/null 2>&1; }
s() { echo "@$1"; }
s ver; echo 1
s host; cat /proc/sys/kernel/hostname; uname -r
s stat; grep '^cpu' /proc/stat
s mem; grep -E '^(MemTotal|MemFree|MemAvailable|Buffers|Cached|SwapTotal|SwapFree):' /proc/meminfo
s load; cat /proc/loadavg
s up; cat /proc/uptime
s dev; tail -n +3 /proc/net/dev
s route; ip route show default 2>/dev/null | head -n 2
s temp; for z in /sys/class/thermal/thermal_zone*/temp; do t=$(cat "$z" 2>/dev/null) && [ -n "$t" ] && echo "$t"; done
s arp; awk 'NR>1 && $3=="0x2"' /proc/net/arp | wc -l
if h nvram; then
  s nvram
  for k in productid odmpid firmver buildno extendno wan0_ifname wan0_proto wan0_state_t lan_ipaddr; do echo "$k=$(nvram get $k 2>/dev/null)"; done
  s mesh; nvram get cfg_device_list 2>/dev/null
fi
s radio
if h wl; then
  for n in 0 1 2 3; do
    i=$(nvram get wl${n}_ifname 2>/dev/null); [ -n "$i" ] || continue
    echo "$i $(nvram get wl${n}_nband 2>/dev/null) $(wl -i "$i" assoclist 2>/dev/null | grep -c assoclist) $(wl -i "$i" phy_tempsense 2>/dev/null | cut -d' ' -f1)"
  done
elif h iw; then
  for i in $(iw dev 2>/dev/null | awk '/Interface/{print $2}'); do echo "$i ? $(iw dev "$i" station dump 2>/dev/null | grep -c ^Station) -"; done
fi
s svc
for p in AdGuardHome tailscaled vnstatd; do pidof $p >/dev/null 2>&1 && echo "$p=1" || echo "$p=0"; done
{ [ -d /jffs/addons/AdGuardHome.d ] || [ -d /opt/etc/AdGuardHome ]; } && echo "agh_inst=1"
{ [ -d /jffs/addons/tailmon.d ] || h tailscaled; } && echo "ts_inst=1"
{ [ -d /jffs/addons/dn-vnstat.d ] || h vnstat; } && echo "vn_inst=1"
[ -x /jffs/scripts/firewall ] && echo "sky_inst=1"
h ipset && echo "sky_run=$(ipset -L -n 2>/dev/null | grep -c '^Skynet')"
grep -qs -i entware /jffs/scripts/post-mount && echo "ent_exp=1"
[ -f /opt/etc/entware_release ] && echo "ent_ok=1"
if h vnstat; then s vnm; vnstat --json m 2>/dev/null; echo; fi
[ -r /jffs/addons/connmon.d/lastx.csv ] && { s ping; tail -n 1 /jffs/addons/connmon.d/lastx.csv; }
exit 0
