#!/bin/sh
# Renders the README screenshots from the real .page files with the made-up data in mock/.
# Needs php, node and Playwright (chromium); the page loads jQuery and Font Awesome from cdnjs.
# Usage: sh tools/screenshots/shoot_all.sh   (writes screenshots/*.png)
cd "$(dirname "$0")" || exit 1
R=../..; T=$(mktemp -d)
for p in sysbox/sysbox diskbox/diskbox streambox/streambox seedbox/qbittorrent_widget seedbox/SeedboxTools; do php prep.php "$R/$p.page" > "$T/$(basename $p).html"; done
export NODE_PATH="${NODE_PATH:-/usr/local/lib/node_modules}"
node shot.js "$T/sysbox.html" "$R/screenshots/sysbox.png" 520 '{"api_fetch.php":"sysbox_api.json","cpu.php":"sysbox_cpu.json","cpu.php?hist":"sysbox_hist.json","cpu.php?clock":"sysbox_clock.json"}'
node shot.js "$T/diskbox.html" "$R/screenshots/diskbox.png" 600 '{"api_fetch.php":"diskbox_api.json"}'
node shot.js "$T/streambox.html" "$R/screenshots/streambox.png" 600 '{"api_fetch.php":"streambox_api.json","load.php":"streambox_load.json","week.php":"streambox_week.json"}'
node shot.js "$T/qbittorrent_widget.html" "$R/screenshots/seedbox.png" 600 '{"api_fetch.php":"seedbox_api.json"}'
HIDE='#stt-form, #stt-t-set, details' CLICK='.stt-gtg[data-g="3"]|.stt-gbar[data-g="must"]|.stt-utg' node shot.js "$T/SeedboxTools.html" "$R/screenshots/seedbox_tools.png" 1000 '{}'
rm -rf "$T"
