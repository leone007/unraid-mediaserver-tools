import base64
import os
D=os.path.normpath(os.path.join(os.path.dirname(os.path.abspath(__file__)),'..'))+'/'
def rd(p): return open(D+p,encoding='utf-8').read()
def b64(p): return base64.encodebytes(open(D+p,'rb').read()).decode().strip()
files=[('&plugdir;/Monitor.page','monitor/Monitor.page','MONITOR PAGE'),('&plugdir;/lang.php','common/lang.php','SHARED LANGUAGE SETTING'),('&plugdir;/i18n.php','common/i18n.php','DICTIONARIES'),('&plugdir;/lang/en.json','monitor/lang/en.json','ENGLISH DICTIONARY (the source of translations)')]
for _,p,_t in files: assert ']]>' not in rd(p),p
out=r'''<?xml version='1.0' standalone='yes'?>
<!DOCTYPE PLUGIN [
<!ENTITY name      "mediaserver_monitor">
<!ENTITY author    "leone007">
<!ENTITY title     "Monitor">
<!ENTITY version   "2026.10.07i">
<!ENTITY plugdir   "/usr/local/emhttp/plugins/&name;">
]>

<PLUGIN name="&title;"
        author="&author;"
        version="&version;"
        min="7.0.0"
        icon="monitor.png"
        pluginURL="https://raw.githubusercontent.com/leone007/unraid-mediaserver-tools/main/dist/&name;.plg">

<CHANGES>
###&version;
- The dictionary also works when another plugin with an older language file was loaded first
###2026.10.07h
- The page texts come from a dictionary (lang/en.json) that can be translated; the page follows the shared language setting and shows English where a translation is missing. Nothing changes on the page yet
###2026.10.07g
- Needs Unraid 7.0.0 or newer (tested on 7.3.2): the Plugins page no longer installs it on Unraid 6, where it was never tried
###2026.10.07f
- Updates now come from GitHub: the Plugins page checks the raw .plg on github.com/leone007/unraid-mediaserver-tools; the local update server (php -S on 127.0.0.1) and repo.php are removed
###2026.10.07e
- The page now opens on its own from Settings, Utilities, Monitor (it was listed inside the Tools page)
###2026.10.07d
- Every tile request has a time limit, so a request dropped by iOS can no longer freeze a tile (the CPU numbers and the graph stopped updating on the phone)
###2026.10.07c
- Full screen mode: hides the Unraid header and menu (automatic when opened from the iPhone home screen; the button is Full screen / Unraid menu; ?full=1 in the address also turns it on)
###2026.10.07b
- Each tile has its own frame and padding, like on the dashboard
###2026.10.07
- First release: a phone-friendly page (Settings, Utilities, Monitor) that shows the Sysbox, Diskbox, Streambox and Seedbox tiles in one column; reorder and hide tiles with the Reorder button (saved on the device); can be added to the iPhone home screen
</CHANGES>

<FILE Name="/tmp/&name;_setup.sh" Run="/bin/bash">
<INLINE><![CDATA[
#!/bin/bash
DIR="/usr/local/emhttp/plugins/mediaserver_monitor"
rm -rf "$DIR"
rm -rf "/usr/local/emhttp/plugins/Monitor"
mkdir -p "$DIR/images" "/usr/local/emhttp/plugins/Monitor/images"

# Menu icon (monochrome) and the iPhone home screen icon, embedded so there is no download
base64 -d > "$DIR/icon.png" <<'B64'
'''+b64('icons/monitor_mono.png')+r'''
B64
base64 -d > "$DIR/touch.png" <<'B64'
'''+b64('icons/monitor_touch.png')+r'''
B64
cp "$DIR/icon.png" "$DIR/images/monitor.png"
cp "$DIR/icon.png" "$DIR/images/mediaserver_monitor.png"
cp "$DIR/icon.png" "/usr/local/emhttp/plugins/Monitor/images/mediaserver_monitor.png"
cp "$DIR/icon.png" "/usr/local/emhttp/plugins/Monitor/images/monitor.png"
exit 0
]]></INLINE>
</FILE>

'''
for name,p,t in files:
    out+='<FILE Name="%s">\n<INLINE><![CDATA[%s]]></INLINE>\n</FILE>\n\n'%(name,rd(p))
out+=r'''<FILE Name="/tmp/&name;_finish.sh" Run="/bin/bash">
<INLINE><![CDATA[
#!/bin/bash
DIR="/usr/local/emhttp/plugins/mediaserver_monitor"
chmod 644 "$DIR"/*.page "$DIR"/*.php "$DIR"/*.png
PHPBIN="$(command -v php || echo /usr/bin/php)"
# Updates come from GitHub (pluginURL): stop the local update server of earlier versions
pkill -f "php -S 127.0.0.1:18185" 2>/dev/null
rm -f /usr/local/emhttp/plugins/mediaserver_monitor/repo.php
rm -f /tmp/mediaserver_monitor_setup.sh /tmp/mediaserver_monitor_finish.sh
echo ""
echo "----------------------------------------------------"
echo " Monitor installed: Settings -> Utilities -> Monitor"
echo " It shows the Sysbox, Diskbox, Streambox and Seedbox"
echo " tiles, so install those plugins too."
echo "----------------------------------------------------"
exit 0
]]></INLINE>
</FILE>

<FILE Run="/bin/bash" Method="remove">
<INLINE><![CDATA[
#!/bin/bash
rm -rf /usr/local/emhttp/plugins/mediaserver_monitor
rm -rf /usr/local/emhttp/plugins/Monitor
pkill -f "php -S 127.0.0.1:18185" 2>/dev/null
echo "Monitor removed."
exit 0
]]></INLINE>
</FILE>

</PLUGIN>
'''
open(D+'dist/mediaserver_monitor.plg','w',encoding='utf-8').write(out)
