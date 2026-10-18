import re,base64,subprocess
IC='/tmp/claude-0/-home-claude/4998fdb6-94f4-52ab-bc2a-d81da6e5727a/scratchpad/icons/'
def patch(P, cfgname, title, png, port, icon_file, settings_icon_old, changes, readme, finish_anchor, remove_anchor, finish_chmod_old=None, finish_chmod_new=None):
    s=open(P,encoding='utf-8').read()
    def rep(old,new,cnt=1):
        nonlocal s
        assert s.count(old)==cnt,(P,old[:70],s.count(old))
        s=s.replace(old,new)
    s=re.sub(r'<!ENTITY version   "[^"]+">','<!ENTITY version   "2026.10.06">',s,count=1)
    rep('<!ENTITY author    "local">','<!ENTITY author    "leone007">\n<!ENTITY title     "%s">\n<!ENTITY port      "%d">'%(title,port))
    rep('<PLUGIN name="&name;"','<PLUGIN name="&title;"')
    s,n=re.subn(r'icon="[a-z\-]+"\n        pluginURL="file:///boot/config/plugins/&name;.plg">','icon="%s"\n        pluginURL="http://127.0.0.1:&port;/&name;.plg">'%icon_file,s,count=1)
    assert n==1
    s=re.sub(r'<CHANGES>.*?</CHANGES>',lambda m:'<CHANGES>\n'+changes.strip()+'\n</CHANGES>',s,count=1,flags=re.S)
    b=base64.encodebytes(open(IC+png,'rb').read()).decode().strip()
    m=re.search(r"(base64 -d > \"\$DIR/icon.png\" <<'B64'\n)(.*?)(\nB64\n)",s,re.S); assert m
    s=s[:m.start(2)]+b+s[m.end(2):]
    rep('rm -rf "$DIR"\n','rm -rf "$DIR"\nrm -rf "/usr/local/emhttp/plugins/%s"\n'%title)
    rep('\nB64\nexit 0\n]]>','\nB64\n\n# Icon for the Plugins page and the settings menu; the Plugins page looks it up under the display name\nTITLEDIR="/usr/local/emhttp/plugins/%s"\nmkdir -p "$DIR/images" "$TITLEDIR/images"\ncp "$DIR/icon.png" "$DIR/images/%s"\ncp "$DIR/icon.png" "$TITLEDIR/images/%s"\nexit 0\n]]>'%(title,icon_file,icon_file))
    rep(settings_icon_old,'Icon="%s"'%icon_file)
    router=("<?php\n// Serves this plugin's own .plg on 127.0.0.1 only, so the Plugins page can run its update check\n"
      "// (Unraid supports only http/https plugin URLs). Nothing else is served.\n"
      "$file = '/boot/config/plugins/%s.plg';\n"
      "$path = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);\n"
      "if ($path === '/%s.plg' && is_file($file)) {\n    header('Content-Type: text/xml; charset=utf-8');\n    header('Cache-Control: no-store');\n    readfile($file);\n"
      "} else {\n    http_response_code(404);\n}\n")%(cfgname,cfgname)
    assert ']]>' not in router and ']]>' not in readme
    block='<FILE Name="/usr/local/emhttp/plugins/%s/README.md">\n<INLINE><![CDATA[%s\n]]></INLINE>\n</FILE>\n\n<FILE Name="&plugdir;/repo.php">\n<INLINE><![CDATA[%s]]></INLINE>\n</FILE>\n\n'%(title,readme.strip(),router)
    rep('<!-- Permissions + done -->',block+'<!-- Permissions + done -->')
    srv=('\n# Local update server: lets the Plugins page check this plugin\'s version (see repo.php)\n'
         'PHPBIN="$(command -v php || echo /usr/bin/php)"\npkill -f "php -S 127.0.0.1:%d" 2>/dev/null\n'
         'setsid nohup "$PHPBIN" -S 127.0.0.1:%d /usr/local/emhttp/plugins/%s/repo.php >/dev/null 2>&1 </dev/null &\n')%(port,port,cfgname)
    rep(finish_anchor,srv+finish_anchor)
    if finish_chmod_old: rep(finish_chmod_old,finish_chmod_new)
    rep(remove_anchor,'rm -rf /usr/local/emhttp/plugins/%s\npkill -f "php -S 127.0.0.1:%d" 2>/dev/null\n'%(title,port)+remove_anchor)
    open(P,'w',encoding='utf-8').write(s)

SB_CH='''###&version;
- New logo: monochrome Streambox icon on the dashboard tile, the Plugins page and the settings menu
- Plugin details: display name Streambox, author leone007, description and full release notes
- Update status: the plugin file is served on 127.0.0.1 only, so the Plugins page can run its update check and report "up-to-date"
- Discord notifier: Hungarian cards for new movies and new seasons (poster, backdrop, overview, quality, audio and subtitles, genres, cast, Jellyfin link); separate webhooks for movies and series; test and preview buttons
###2026.10.05b - 2026.10.05k
- Smart socket (Shelly Gen2+): wall power and monthly kWh next to Server load; 10-minute cron sampler keeps the monthly figure exact
- Live TV: progress bar and time left for the current program (from the guide), program times in the hover text
- Direct stream rows say why (transcode reasons, container change, or the file's container/codecs); hover shows the full details
- Server load: current CPU package power in watts (from intel_gpu_top)
- Jellyfin icon (30px, embedded, inverted on light themes)
- Interlaced sources: 1080i label, 1080i -> 1080p / Deinterlace detail for transcodes (from TranscodeReasons / IsInterlaced)
- Live TV: LIVE label instead of a 0% bar, channel + program title, target resolution for transcodes
- GPU load: read the numbers directly from intel_gpu_top output instead of strict JSON parsing
- Container remux shows resolution and bitrate; device names lose the trailing (IP) part
- Audio-only transcodes are shown as Direct stream; GPU load reports why it is unavailable (gpu_note)
###2026.10.05
- First release: Jellyfin now playing (user, device, direct play / direct stream / transcode), GPU and CPU load, recently added
- Short user names: nicknames on the settings page, automatic shortening otherwise'''
SB_README='''**Streambox** puts Jellyfin on the Unraid dashboard as a native-looking tile: who is watching right now (direct play, direct stream or transcode, with progress and live TV guide data), GPU and CPU load with the current power draw, optional smart-socket wall power and monthly kWh, and the recently added titles. It can also announce new movies and seasons in Discord with Hungarian cards.

Settings: Settings, Utilities, Streambox (or the gear icon on the tile). A Jellyfin API key is required.'''

QB_CH='''###&version;
- New logo: monochrome Seedbox icon on the dashboard tile, the Plugins page and the settings menu
- Plugin details: display name Seedbox, author leone007, description and full release notes
- Update status: the plugin file is served on 127.0.0.1 only, so the Plugins page can run its update check and report "up-to-date"
###2026.10.05b - 2026.10.05k
- Speeds below 50 B/s count as idle (no more rows or header values showing 0.00 kB/s)
- Icon 30px; summary line closer to the title
- Fix: save.php rejected every save because Unraid removes csrf_token from $_POST after validating it; now accepts AJAX requests
- Settings page now saves through its own endpoint (save.php) with validation and visible feedback; Unraid's generic update.php did not write the file
- Moving / checking / allocating torrents are shown in In transfer (with a status label) instead of Needs attention
- Fix: red rows were drawn with Unraid's global .red text colour (classes renamed); header speeds use the larger of qBittorrent's global value and the sum of active rows; spacing between the two speeds
- Stalled status is ignored completely (idle seeds are normal); removed tracker checks and the stall setting
- Fix: text clipped on the right (nowrap inherited from the dashboard) and scene-style names now wrap (dots shown as spaces)
###2026.10.05
- Renamed the tile to Seedbox, with two tables: Needs attention and In transfer
- Connection globe (connected / firewalled / disconnected) from qBittorrent
- Stalled torrents are flagged while connected; when firewalled or disconnected only one connection row is shown
- Tracker hint for flagged stalled torrents
- Auto-scaled speed units, ETA, progress bar under the name, link to the Web UI
- qBittorrent icon embedded in the plugin (inverted automatically on light themes)
- Settings page: Settings -> Utilities -> Seedbox
###2026.10.04b - 2026.10.04e
- New: ETA column, separate download/upload speed columns
- New: only downloading torrents shown by default (optional: anything transferring), row limit
- New: header home icon (opens qBittorrent Web UI) and gear icon (settings page)
- New: Settings -> Utilities -> qBittorrent Widget (columns, filter, rows, connection)
- Tweak: smaller header icon
- Fix: remove nested table/tbody that broke the Unraid 7 dashboard; use grid rows
- Fix: register tile via $mytiles so Unraid 7.x renders it as a native dashboard tile
###2026.10.04
- Initial release: qBittorrent dashboard tile (Web UI API via local PHP proxy)'''
QB_README='''**Seedbox** puts qBittorrent on the Unraid dashboard as a native-looking tile: the connection state (connected, firewalled or disconnected), torrents that need attention, and what is in transfer right now with speeds, ETA and progress bars, plus a link to the Web UI. Idle seeds are never listed, so the tile stays short even with hundreds of torrents.

Settings: Settings, Utilities, Seedbox (or the gear icon on the tile). The qBittorrent Web UI must be enabled.'''

patch('/mnt/user-data/outputs/streambox.plg','streambox','Streambox','streambox_mono.png',18182,'streambox.png','Icon="play-circle"',SB_CH,SB_README,
  'rm -f /tmp/streambox_setup.sh /tmp/streambox_finish.sh','rm -f /tmp/streambox_*\nrm -f /boot/config/plugins/streambox/streambox.cron')
patch('/mnt/user-data/outputs/qbittorrent_widget.plg','qbittorrent_widget','Seedbox','seedbox_mono.png',18181,'seedbox.png','Icon="download"',QB_CH,QB_README,
  'rm -f /tmp/qbittorrent_widget_setup.sh /tmp/qbittorrent_widget_finish.sh','rm -rf /usr/local/emhttp/plugins/qbittorrent_widget\n',
  '"$DIR"/api_fetch.php "$DIR"/save.php "$DIR"/icon.png','"$DIR"/api_fetch.php "$DIR"/save.php "$DIR"/repo.php "$DIR"/icon.png')
print('patched')
