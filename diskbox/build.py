import base64
import os
D=os.path.normpath(os.path.join(os.path.dirname(os.path.abspath(__file__)),'..'))+'/'
# a page may carry /*@@TILE_CSS@@*/: the shared tile look (common/tile.css) goes there
def rd(p): return open(D+p,encoding='utf-8').read().replace('/*@@TILE_CSS@@*/',open(D+'common/tile.css',encoding='utf-8').read().strip()).replace('/*@@SETTINGS_CSS@@*/',open(D+'common/settings.css',encoding='utf-8').read().strip())
icon=base64.encodebytes(open(D+'icons/diskbox_mono.png','rb').read()).decode().strip()
files=[('&plugdir;/diskbox.page','diskbox/diskbox.page'),('&plugdir;/DiskboxSettings.page','diskbox/DiskboxSettings.page'),
 ('&plugdir;/lib.php','diskbox/lib.php'),('&plugdir;/api_fetch.php','diskbox/api_fetch.php'),('&plugdir;/smart.php','diskbox/smart.php'),('&plugdir;/unassigned.php','diskbox/unassigned.php'),
 ('&plugdir;/save.php','diskbox/save.php'),('&plugdir;/discord.php','diskbox/discord.php'),('&plugdir;/alerts.php','diskbox/alerts.php'),('&plugdir;/notify.php','diskbox/notify.php'),('&plugdir;/weekly.php','diskbox/weekly.php'),('&plugdir;/test.php','diskbox/test.php'),('&plugdir;/ack.php','diskbox/ack.php'),('/usr/local/emhttp/plugins/Diskbox/README.md','diskbox/README.md'),('&plugdir;/lang.php','common/lang.php'),('&plugdir;/lang_block.php','common/lang_block.php'),('&plugdir;/lang_save.php','common/lang_save.php'),('&plugdir;/i18n.php','common/i18n.php'),('&plugdir;/lang/en.json','diskbox/lang/en.json'),('&plugdir;/DiskboxBalance.page','diskbox/DiskboxBalance.page'),('&plugdir;/balance_lib.php','diskbox/balance_lib.php'),('&plugdir;/balance.php','diskbox/balance.php'),('&plugdir;/remove.php','diskbox/remove.php'),('&plugdir;/balance_run.php','diskbox/balance_run.php')]
for _,p in files: assert ']]>' not in rd(p),p
assert len(files)==24
out=r'''<?xml version='1.0' standalone='yes'?>
<!DOCTYPE PLUGIN [
<!ENTITY name      "diskbox">
<!ENTITY author    "leone007">
<!ENTITY title     "Diskbox">
<!ENTITY version   "2026.10.22k">
<!ENTITY plugdir   "/usr/local/emhttp/plugins/&name;">
<!ENTITY cfgdir    "/boot/config/plugins/&name;">
]>

<PLUGIN name="&title;"
        author="&author;"
        version="&version;"
        min="7.0.0"
        icon="diskbox.png"
        pluginURL="https://raw.githubusercontent.com/leone007/unraid-mediaserver-tools/main/dist/&name;.plg">

<CHANGES>
###&version;
- The dashboard tile gets only the texts it shows from the dictionary (a smaller page); nothing changes on the screen
###2026.10.22j
- The texts of the tile, the settings page and the Diskbox Balance page come from a dictionary (lang/en.json) that can be translated (see TRANSLATING.md in the repository); they follow the shared language setting and show English where a translation is missing. Nothing changes on the screen yet
###2026.10.22i
- Language block: saving failed with csrf token mismatch (Unraid checks the token itself and then removes it from the request); fixed
###2026.10.22h
- Language block: the save button (now Save language) was greyed out, because Unraid disables Apply buttons outside a changed form; it works now
###2026.10.22g
- Language: one setting for all the plugins (Sysbox, Diskbox, Seedbox, Streambox), the Language block at the bottom of each settings page: pages and tiles (Follow Unraid or English; only English exists yet) and Discord messages (Follow Unraid, English or Magyar). A server that already sends Discord messages keeps the language it had; a new install starts in English. The old Language of the messages row is gone
###2026.10.22f
- Needs Unraid 7.0.0 or newer (tested on 7.3.2): the Plugins page no longer installs it on Unraid 6, where it was never tried
###2026.10.22e
- Updates now come from GitHub: the Plugins page checks the raw .plg on github.com/leone007/unraid-mediaserver-tools; the local update server (php -S on 127.0.0.1) and repo.php are removed
###2026.10.22d
- Dashboard tile: while Unraid clears a newly added data disk, its row shows clearing with speed and time left and a blue cleared progress bar, like a disk being built
- Clearing no longer turns the array amber: the chip stays Parity protected (the parity stays valid while a new disk is zeroed), the parity rows stay green, the header line says Disk 2 clearing NN%, and no amber array alert goes to Discord
###2026.10.22c
- Array: a parity sync (a new or replaced parity disk, e.g. Parity 2) shows on the disk being built: blue row, building, speed, time left and a built bar; the other parity disk stays valid, the header chip reads PARITY-SYNC and the disk is no longer listed as invalid under Needs attention. A data disk rebuild shows the same on that disk, with one amber attention line. Which disk is built comes from the array state (mdResyncAction)
- Discord: a parity sync is named Parity sync, not Disk rebuild
###2026.10.22b
- Unassigned: a running UD Preclear shows on the disk row: step (Pre-Read, Zeroing, Post-Read, step N of 3), percent bar, speed and the time left in the step; paused, failed (also under Needs attention) and precleared results stay until the preclear status is cleared
- Discord: one message when a preclear is over (finished, failed or stopped), with its result and duration (the SMART test end setting)
###2026.10.22a
- Discord: the parity check end message takes its result from the array state (errors, exit, start and end), which is set the moment the check stops; it reported the previous check when the history line was written late. Speed shown as MB/s
###2026.10.22
- New band on the tile: Unassigned, with each unassigned disk (model, USB or SATA, serial, power-on hours, size, temperature, SMART health) and its SMART self-test: progress while it runs, then the last result (passed, aborted, failed) with a coloured stripe; the header counts the running tests; read every 5 minutes by a new cron script that never wakes a sleeping disk
- Discord: one message when a SMART self-test of an unassigned disk is over (passed, aborted or failed), on by default (setting under Discord)
###2026.10.21b
- Discord: no fill-level alert for a disk being zeroed for removal (its percentage is the zeroing progress, not the fill level); an alert left open by such a reading is dropped without an all-clear message
###2026.10.21a
- Balance, Remove disks: no wide gaps around the links and bold words in the step texts
###2026.10.21
- Balance: third mode Remove disks, step by step: the shares (you change them, Diskbox checks), emptying (the Empty disks plan), a check that nothing is left, clearing through parity (type the disk names; both side by side), then the New Config steps. No step can be skipped
###2026.10.20d
- Update: the zeroing state is kept, so a cleared disk still shows zeroed, ready to remove after the plugin update
###2026.10.20c
- Widget, zeroing: a finished run whose last sample stopped short of the end (99 percent or more) is checked once by reading the rest at three places; all zero shows zeroed, ready to remove. A zeroing state is forgotten when another disk is in the slot
###2026.10.20b
- Widget, zeroing: the size column shows the disk, not the small stand-in image; a disk being zeroed is left out of the array usage total; the bar label no longer runs into the temperature
###2026.10.20
- Widget: an array disk being zeroed for removal (dd onto its md device) shows zeroing percent, speed and time left with a blue bar, then zeroed, ready to remove
###2026.10.19
- Balance: the Activity list of a run is short now: failed and moving titles and the next 5 waiting ones are shown, the other waiting, skipped and done titles are folded under bars with their count and size
###2026.10.18
- Settings page in the shared look of the plugin pages: a dark band per part, the label on the left, the controls of one setting on one line, the explanation quiet under them; Apply and Done below
###2026.10.17
- Settings: the saved Discord webhook is no longer sent to the page; an empty field keeps it, a single - removes it
###2026.10.16
- Diskbox Balance plan: the media-only bar is called Library files without a hardlink (a hardlink cannot cross disks, so these files have none anywhere: mostly pictures and nfo next to a film whose video is on another disk); video files among them are counted and marked as video without a download, since nothing seeds them
###2026.10.15
- Diskbox Balance plan: the media-only items are called Library files not linked to a download on this disk; their bar counts the real file types (for example jpg 912, nfo 498) and how many of the folders are also on another disk (a hardlink cannot cross disks, so the video and its download are usually there) and how many are only on this disk; each row says where else its folder is
- Diskbox Balance plan: a long list shows 25 rows, then 50 more per click; the hidden rows are still in the plan and are moved too
###2026.10.14
- Diskbox Balance plan: on a disk to empty, the space that stays used after the move but is neither a kept folder nor an unticked title is shown as the file system's own space (striped grey on the bar, for example 3.4 percent to empty plus 57 GB file system), not as data left on the disk
- Diskbox Balance plan: the bar of each source disk counts the titles and the media-only items apart (media files without a download: nfo, pictures, subtitles), and the media-only items sit in their own folded bar under the titles
###2026.10.13
- Diskbox Balance: a plan can be made during a parity check (it only reads the disks); the Move button waits until the check has finished and says why (with the percent), and is enabled again by itself within a minute after the end. Moving itself still refuses to start or goes no further while a parity check, a rebuild or the mover runs
###2026.10.12
- Diskbox Balance page in the look of the Dashboard tiles: Activity on top (the running move with a progress bar and a striped row per title, the log and the recent moves folded), then Setup and Plan, each under a dark band with its state on the right
- Plan: one row per disk with two bars, the fill now and after the ticked moves (green, amber when it grows, red when nearly full); the titles in one foldable bar per source disk, closed when the page loads, each with a checkbox to tick or untick all of that disk and a summary (ticked titles, size, target disks); what stays on a disk to empty and the titles left out in their own bars
- Setup: the controls of one setting on one line, the explanation under them. Nothing in the planning or moving changed
###2026.10.11
- The cron scripts (alerts, weekly summary, SMART reading) run only from cron, not when their address is opened in a browser
###2026.10.10
- Diskbox Balance: new mode Empty disks: tick the disk(s) to empty and everything in the configured folders is planned to the other disks, titles with their hardlinks (one copy run per title, checked, then removed from the old disk), media files without a download (also nfo, pictures, subtitles; folders that share a hardlink move together) and the items in the Seedbox trash with their hardlinked media files; the plan lists what would still stay on the disk (other folders, skipped titles)
- Diskbox Balance no longer treats the Seedbox trash folder (.seedbox_trash) as a title when spreading the data
###2026.10.09
- Parity check, rebuild and clearing show their progress: the amber chip fills from the left with the percentage, and the parity row gets a thin progress bar (checked, percent) where the other disks show their usage; both turn paler while the check is paused
###2026.10.08c
- The dashboard tile shows the Balance move: a Balance card with progress and time left while it runs, and for a day after it ends the result (finished, skipped titles, stopped, error)
###2026.10.08b
- Diskbox Balance: a select / deselect all box in front of the Title column
###2026.10.08
- New page Settings, Utilities, Diskbox Balance: spreads the data over the array disks. Set a target fill (or equal fill), get a suggested plan, tick or untick titles and change the target disk; whole titles (a downloads folder and its hardlinked media files) are copied to the other disk, checked (sizes and hardlinks) and only then removed from the old one, so the hardlinks are kept. Runs in the background, with progress, a stop button and Discord messages (start, finish, error)
###2026.10.07g
- The weekly Discord summary lists the acknowledged SMART warnings that are still on a disk (attribute, type, raw value, when acknowledged, unchanged since)
###2026.10.07f
- Fix: the weekly summary button reported "No disk data available" when pressed on the settings page
###2026.10.07e
- Discord SMART alerts after a worsening say how fast it happened: the change since the acknowledgement or the previous alert and how long it took; getting worse within 7 days (or by 10 or more) is marked RAPID DETERIORATION, is red and pings, a slower worsening is amber, marked slow deterioration and does not ping
###2026.10.07d
- Discord: red alerts can ping you (your user ID, a role, @here or @everyone); amber alerts and all-clear messages never ping; the test message pings too
###2026.10.07c
- SMART warnings can be acknowledged: the Needs attention list gets an Acknowledge button; an acknowledged warning is reported again (tile and Discord) only when its raw value rises. A failed health check cannot be acknowledged; the settings page lists acknowledgements with a Forget button
- The tile's SMART warnings now cover the same attributes as the Discord alerts (for example reported uncorrectable errors, UDMA CRC errors and spin retries), named with their raw value
###2026.10.07b
- Fix: the Discord alert files (notify, weekly, test) were missing from the package
###2026.10.07
- Discord alerts for a system channel (own webhook, Hungarian or English): array not protected, emulated or failed disk, disk temperature, SMART problems (each attribute is marked Pre-fail or Old age, with raw value, previous value, value, worst and threshold), disk and pool fill level, ZFS pool health, parity check and rebuild start and result; all-clear messages, no repeats of the same alert, optional repeat of red alerts
- Weekly summary in Discord (day and hour are settings) and buttons for a test message, sample alerts and the summary
###2026.10.06d
- The parity row stripe is now a straight bar like the disk rows (the row corners are square)
###2026.10.06c
- Array and pool header bars show the device count (ARRAY · 5 DEVICES, POOL · 1 DEVICE); array disks get a blue tint with a blue stripe, pools purple (also the file system badge); the parity row has a stripe in its status colour (green, amber, red)
###2026.10.06b
- Array and pool headings are dark header bars like the Seedbox and Streambox tables; compact row layout for narrow dashboard columns (file system badge next to the pool name)
###2026.10.06
- First release: a Disk Viewer replacement in the Seedbox and Streambox design
- Array as one card (devices, file system, usage bar) with a protection chip: parity protected, parity check or rebuild with progress, not protected, disk emulated, disk error, stopped
- Parity row under the array: green when protected, amber during a parity check or rebuild (speed and time left), red when there is no protection
- Data disks and pools with usage, temperature, activity (reading, writing, idle, spun down) and SMART health
- Needs attention list: disks that are too full, too hot, have SMART warnings, are unmountable, emulated or invalid, and degraded ZFS pools
- Colour limits follow Unraid's display settings and can be overridden on the settings page
- SMART health is read every 20 minutes and never wakes a spun down disk
</CHANGES>

<FILE Name="/tmp/&name;_setup.sh" Run="/bin/bash">
<INLINE><![CDATA[
#!/bin/bash
PLUG="diskbox"
DIR="/usr/local/emhttp/plugins/$PLUG"
CFG="/boot/config/plugins/$PLUG"

rm -rf "$DIR"
rm -rf "/usr/local/emhttp/plugins/Diskbox"
# the zeroing state is kept: a finished run must still show zeroed, ready to remove after an update
find /tmp -maxdepth 1 -name 'diskbox_*' ! -name diskbox_zero.json -exec rm -f {} + 2>/dev/null

mkdir -p "$DIR"
mkdir -p "$CFG"

# Defaults are written only if absent, so upgrades never clobber the settings.
# Empty limits mean: use Unraid's own display settings.
if [ ! -f "$CFG/settings.cfg" ]; then
cat > "$CFG/settings.cfg" <<'EOF'
warn_use=""
crit_use=""
hot_temp=""
max_temp=""
smart="1"
EOF
fi
chmod 600 "$CFG/settings.cfg"

# Monochrome icon (128x128 PNG), embedded so there is no download or CDN dependency
base64 -d > "$DIR/icon.png" <<'B64'
'''+icon+r'''
B64

# Icon for the Plugins page and the settings menu; the Plugins page looks it up under the display name
TITLEDIR="/usr/local/emhttp/plugins/Diskbox"
mkdir -p "$DIR/images" "$TITLEDIR/images"
cp "$DIR/icon.png" "$DIR/images/diskbox.png"
cp "$DIR/icon.png" "$TITLEDIR/images/diskbox.png"
exit 0
]]></INLINE>
</FILE>

'''
assert len(files)==24
sep=lambda t:'<!-- ========================================================= -->\n<!-- %s -->\n<!-- ========================================================= -->\n'%t
for (name,p),t in zip(files,['DASHBOARD TILE','SETTINGS PAGE','LIBRARY','DATA ENDPOINT','SMART COLLECTOR','SAVE','DISCORD HELPERS','DISCORD ALERT TEXTS','DISCORD ALERTS','DISCORD WEEKLY','DISCORD TEST','ACKNOWLEDGE','README','SHARED LANGUAGE SETTING','LANGUAGE BLOCK OF THE SETTINGS PAGE','LANGUAGE SAVE','DICTIONARIES','ENGLISH DICTIONARY (the source of translations)','BALANCE PAGE','BALANCE LIBRARY','BALANCE ENDPOINT','REMOVE DISKS','BALANCE WORKER']):
    out+=sep(t)+'<FILE Name="%s">\n<INLINE><![CDATA[%s]]></INLINE>\n</FILE>\n\n'%(name,rd(p) if not p.endswith('README.md') else rd(p).rstrip('\n')+'\n')
out+=r'''<!-- Permissions + done -->
<FILE Name="/tmp/&name;_finish.sh" Run="/bin/bash">
<INLINE><![CDATA[
#!/bin/bash
DIR="/usr/local/emhttp/plugins/diskbox"
chmod 644 "$DIR"/*.page "$DIR"/*.php "$DIR"/icon.png

# SMART collector: Unraid collects /boot/config/plugins/<name>/*.cron with update_cron.
PHPBIN="$(command -v php || echo /usr/bin/php)"
cat > /boot/config/plugins/diskbox/diskbox.cron <<CRON
# Diskbox: read SMART health every 20 minutes (spun down disks are skipped)
*/20 * * * * $PHPBIN /usr/local/emhttp/plugins/diskbox/smart.php >/dev/null 2>&1
# Diskbox: Discord alerts, checked every minute
* * * * * $PHPBIN /usr/local/emhttp/plugins/diskbox/notify.php >/dev/null 2>&1
# Diskbox: unassigned disks (SMART, self-test progress, Discord when a test ends), every 5 minutes
*/5 * * * * $PHPBIN /usr/local/emhttp/plugins/diskbox/unassigned.php >/dev/null 2>&1
# Diskbox: weekly summary, checked hourly (day and hour are settings)
7 * * * * $PHPBIN /usr/local/emhttp/plugins/diskbox/weekly.php >/dev/null 2>&1
CRON
# update_cron reads the plugin link in /var/log/plugins, which appears only after the install scripts
# have finished, so wait for it in the background.
nohup bash -c 'for i in $(seq 1 60); do [ -e /var/log/plugins/diskbox.plg ] && break; sleep 1; done; /usr/local/sbin/update_cron' >/dev/null 2>&1 &

# First SMART reading right away (in the background, can take a minute)
nohup "$PHPBIN" "$DIR/smart.php" >/dev/null 2>&1 &

# Updates come from GitHub (pluginURL): stop the local update server of earlier versions
pkill -f "php -S 127.0.0.1:18183" 2>/dev/null
rm -f /usr/local/emhttp/plugins/diskbox/repo.php
rm -f /tmp/diskbox_setup.sh /tmp/diskbox_finish.sh
echo ""
echo "----------------------------------------------------"
echo " Diskbox installed."
echo " Settings: Settings -> Utilities -> Diskbox"
echo " (or the gear icon on the dashboard tile)."
echo "----------------------------------------------------"
exit 0
]]></INLINE>
</FILE>

<!-- Uninstall -->
<FILE Run="/bin/bash" Method="remove">
<INLINE><![CDATA[
#!/bin/bash
rm -rf /usr/local/emhttp/plugins/diskbox
rm -rf /usr/local/emhttp/plugins/Diskbox
pkill -f "php -S 127.0.0.1:18183" 2>/dev/null
rm -f /tmp/diskbox_*
rm -f /boot/config/plugins/diskbox/diskbox.cron
/usr/local/sbin/update_cron 2>/dev/null
# Settings in /boot/config/plugins/diskbox are kept intentionally.
echo "diskbox removed."
exit 0
]]></INLINE>
</FILE>

</PLUGIN>
'''
open(D+'dist/diskbox.plg','w',encoding='utf-8').write(out)
