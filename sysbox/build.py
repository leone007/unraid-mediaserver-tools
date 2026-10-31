import base64
import os
D=os.path.normpath(os.path.join(os.path.dirname(os.path.abspath(__file__)),'..'))+'/'
def rd(p): return open(D+p,encoding='utf-8').read()
icon=base64.encodebytes(open(D+'icons/sysbox_mono.png','rb').read()).decode().strip()
files=[('&plugdir;/sysbox.page','sysbox/sysbox.page','DASHBOARD TILE'),('&plugdir;/SysboxSettings.page','sysbox/SysboxSettings.page','SETTINGS PAGE'),
 ('&plugdir;/lib.php','sysbox/lib.php','LIBRARY'),('&plugdir;/api_fetch.php','sysbox/api_fetch.php','DATA ENDPOINT'),('&plugdir;/collect.php','sysbox/collect.php','BACKGROUND COLLECTOR'),
 ('&plugdir;/action.php','sysbox/action.php','CONTAINER ACTIONS'),('&plugdir;/logs.php','sysbox/logs.php','CONTAINER LOGS'),('&plugdir;/save.php','sysbox/save.php','SAVE'),
 ('&plugdir;/energy.php','sysbox/energy.php','SMART SOCKET ENERGY'),('&plugdir;/sample.php','sysbox/sample.php','ENERGY SAMPLER'),('&plugdir;/cpu.php','sysbox/cpu.php','CPU ENDPOINT'),('&plugdir;/cpuhist.php','sysbox/cpuhist.php','CPU HISTORY SAMPLER'),('&plugdir;/discord.php','sysbox/discord.php','DISCORD HELPERS'),('&plugdir;/alerts.php','sysbox/alerts.php','DISCORD ALERT TEXTS'),('&plugdir;/notify.php','sysbox/notify.php','DISCORD ALERTS'),('&plugdir;/weekly.php','sysbox/weekly.php','DISCORD WEEKLY'),('&plugdir;/test.php','sysbox/test.php','DISCORD TEST'),
 ('/usr/local/emhttp/plugins/Sysbox/README.md','sysbox/README.md','README'),
 ('&plugdir;/lang.php','common/lang.php','SHARED LANGUAGE SETTING'),('&plugdir;/lang_block.php','common/lang_block.php','LANGUAGE BLOCK OF THE SETTINGS PAGE'),('&plugdir;/lang_save.php','common/lang_save.php','LANGUAGE SAVE'),('&plugdir;/i18n.php','common/i18n.php','DICTIONARIES'),('&plugdir;/lang/en.json','sysbox/lang/en.json','ENGLISH DICTIONARY (the source of translations)'),('&plugdir;/lang/hu.json','sysbox/lang/hu.json','HUNGARIAN DICTIONARY'),
 ('&plugdir;/SysboxShutdown.page','sysbox/SysboxShutdown.page','SAFE SHUTDOWN PAGE'),('&plugdir;/shutdown_lib.php','sysbox/shutdown_lib.php','SAFE SHUTDOWN LIBRARY'),('&plugdir;/shutdown.php','sysbox/shutdown.php','SAFE SHUTDOWN ENDPOINT'),('&plugdir;/shutdown_run.php','sysbox/shutdown_run.php','SAFE SHUTDOWN WORKER'),
 ('&plugdir;/ClaudeButton.page','sysbox/ClaudeButton.page','CLAUDE HEADER BUTTON'),('&plugdir;/claude.php','sysbox/claude.php','CLAUDE BUTTON ENDPOINT'),
 ('&plugdir;/SysboxSnapshots.page','sysbox/SysboxSnapshots.page','SNAPSHOTS PAGE'),('&plugdir;/snap_lib.php','sysbox/snap_lib.php','SNAPSHOTS LIBRARY'),('&plugdir;/snap.php','sysbox/snap.php','SNAPSHOTS ENDPOINT'),('&plugdir;/snap_run.php','sysbox/snap_run.php','SNAPSHOTS WORKER'),('&plugdir;/copy_lib.php','sysbox/copy_lib.php','SNAPSHOTS COPIES LIBRARY'),
 ('&plugdir;/SysboxUpdates.page','sysbox/SysboxUpdates.page','UPDATE MANAGER PAGE'),('&plugdir;/upd_lib.php','sysbox/upd_lib.php','UPDATE MANAGER LIBRARY'),('&plugdir;/upd.php','sysbox/upd.php','UPDATE MANAGER ENDPOINT'),('&plugdir;/upd_run.php','sysbox/upd_run.php','UPDATE MANAGER WORKER'),
 ('&plugdir;/SysboxGuard.page','sysbox/SysboxGuard.page','CLAUDE GUARD PAGE'),('&plugdir;/guard_lib.php','sysbox/guard_lib.php','CLAUDE GUARD LIBRARY'),('&plugdir;/guard.php','sysbox/guard.php','CLAUDE GUARD ENDPOINT'),('&plugdir;/guard_run.php','sysbox/guard_run.php','CLAUDE GUARD WORKER'),('&plugdir;/brief_lib.php','sysbox/brief_lib.php','CLAUDE GUARD MORNING BRIEF'),('&plugdir;/sugg_lib.php','sysbox/sugg_lib.php','CLAUDE GUARD SUGGESTIONS'),
 ('&plugdir;/SysboxNetwork.page','sysbox/SysboxNetwork.page','NETWORK PAGE'),('&plugdir;/net_lib.php','sysbox/net_lib.php','NETWORK LIBRARY'),('&plugdir;/net.php','sysbox/net.php','NETWORK ENDPOINT'),('&plugdir;/netmon.php','sysbox/netmon.php','NETWORK SAMPLER'),('&plugdir;/sp_tick.php','sysbox/sp_tick.php','STREAM PRIORITY CHECK'),('&plugdir;/ups_lib.php','sysbox/ups_lib.php','POWER OUTAGE LIBRARY'),('&plugdir;/ups_tick.php','sysbox/ups_tick.php','POWER OUTAGE CHECK'),
 ('&plugdir;/rtr_lib.php','sysbox/rtr_lib.php','ROUTER LIBRARY'),('&plugdir;/rtr.php','sysbox/rtr.php','ROUTER SETTINGS ENDPOINT'),('&plugdir;/rtrmon.php','sysbox/rtrmon.php','ROUTER SAMPLER'),('&plugdir;/sysbox_ro.sh','sysbox/sysbox_ro.sh','ROUTER READ SCRIPT (copied to the router by its install line)'),
 ('&plugdir;/bot.php','common/bot.php','SHARED DISCORD BOT SETTING'),('&plugdir;/bot_block.php','common/bot_block.php','DISCORD BOT BLOCK'),('&plugdir;/bot_save.php','common/bot_save.php','DISCORD BOT SAVE AND TEST'),('&plugdir;/mtlang/en.json','common/lang/en.json','SHARED BLOCKS ENGLISH DICTIONARY'),('&plugdir;/mtlang/hu.json','common/lang/hu.json','SHARED BLOCKS HUNGARIAN DICTIONARY')]
for _,p,_t in files: assert ']]>' not in rd(p),p
out=r'''<?xml version='1.0' standalone='yes'?>
<!DOCTYPE PLUGIN [
<!ENTITY name      "sysbox">
<!ENTITY author    "leone007">
<!ENTITY title     "Sysbox">
<!ENTITY version   "2026.10.37a">
<!ENTITY plugdir   "/usr/local/emhttp/plugins/&name;">
<!ENTITY cfgdir    "/boot/config/plugins/&name;">
]>

<PLUGIN name="&title;"
        author="&author;"
        version="&version;"
        min="7.0.0"
        icon="sysbox.png"
        pluginURL="https://raw.githubusercontent.com/leone007/unraid-mediaserver-tools/main/dist/&name;.plg">

<CHANGES>
###2026.10.37a
- Router: the install line is now a block of short lines (the router's shell cut the long line at about 1000 characters, so nothing was written); paste the whole block in the router's SSH, it ends with Sysbox OK

###2026.10.37
- New Router part on the tile (off until switched on): any router with SSH, Asuswrt-Merlin and other Entware routers; three views chosen in the settings: Compact (CPU, RAM, temperature, ping, services), Standard (WAN speed and 30-minute graph, month from vnstat, CPU cores, RAM and swap, temperatures) and Full (Standard plus foldable Skynet, Clients and AiMesh bars)
- Only what the router has appears: Entware, AdGuard Home, Skynet, Tailscale, vnstat, connmon and the Wi-Fi radios are found by themselves; a stopped service is a red chip and an attention row, a router that does not answer is a red row
- Read only: Sysbox makes its own key; the key line for the router runs nothing but a small read script, which a one-line install command writes on the router; Test and save lists what was found

###2026.10.36
- Power: the UPS voltage curve shows from the first point on and ends at the voltage now; the first point is saved right away, so after a restart it no longer waits 20 minutes

###2026.10.35
- Updates keep the graph histories: the network, UPS voltage and CPU graphs on the tile go on where they were instead of starting from empty

###2026.10.34
- Stream priority: outside streams on Emby and Plex hold qBittorrent too, not only Jellyfin; every media server switched on in Streambox counts
- Stream priority: the tile, Activity and the events show the Streambox nicknames with the server name, for example Zoli (Plex); the Network page has one chip per media server and the connection test reports each server

###2026.10.33
- Snapshots: the before-update snapshots of the Update manager are removed by themselves in the daily round, after 14 days, the newest of each container after 30 days (both can be set, or turned off); each row shows when it goes, and Keep turns one into a manual snapshot that stays
- Snapshots: a line in Activity and on Discord when expired before-update snapshots are removed, with the space freed
- Update manager: a warning above the container updates and in the confirmation when a pool is above the Snapshots fill limit; Update all safe then asks first too
- The Guard no longer suggests deleting before-update snapshots while they are removed by themselves

###2026.10.32a
- Claude Guard: the morning brief shows each amber or red finding once (the summary only when everything is green); the summary asked from Claude does not repeat the findings

###2026.10.32
- Snapshots: copies. After the daily snapshot the containers' data is copied to a share on the array (one browsable folder per day, unchanged files hardlinked, so a copy only takes the space of what changed); keeps 14 daily and 8 weekly copies by default and removes only the date folders it made; names to skip (Docker image, Jellyfin transcodes and cache); Copy now
- Snapshots: an encrypted copy off the server once a week (restic, to another computer over SFTP or to S3 storage such as Backblaze B2); the password is made once and shown on a click so it can be written down; Test and set up makes the repository; only the changes are sent; keeps a set number of weekly versions
- Snapshots: restore a container from an array copy or from a downloaded off-site version, the same way as from a snapshot (dry run, safety snapshot, only that container stopped); the skipped names are left as they are
- Snapshots: Activity shows a running copy with its progress; Discord when a copy fails or the newest one is older than a set number of days
###2026.10.31
- Power outage on Discord: when the UPS runs on battery for a minute a message goes out (also at night) with battery, runtime and load, updated every minute
- Shutdown from Discord: the owner's stop sign reaction under it asks for a check mark, then Safe shutdown runs (qBittorrent first) and the server powers off
- qBittorrent stops by itself a few percent (default 5) before the NUT plugin's own shutdown level
- Messages when the power is back and when the server is up again after an outage (the array does not start by itself)
- Tile: a line under the UPS figures (on battery, the shutdown, the last outage); Settings: a new Power outage part with a test message

###2026.10.30f
- Update manager and Snapshots: every Activity, error and Discord text comes from the dictionary (Hungarian too); Discord messages use the Discord language, also the one of Update all

###2026.10.30e
- Inside: the memory and disk usage reading of the tile and of the alerts is one shared function (no change in what is shown)

###2026.10.30d
- Snapshots: a restore is refused before anything changes when a container folder is a separate dataset or mount, which the snapshot does not hold
- Update manager: an Ask Claude answer or a to-do mark saved while a check runs is no longer lost when the check ends
- Update manager: plugin versions compare by their parts (2025.10.01 is newer than 2025.9.30)
- Claude Guard: two findings of one area in the same report both go to the to-do file
- Discord: editing the morning brief works with a webhook that has a thread id

###2026.10.30c
- Update manager: the self-test runs before the update too; a service that already failed then (stopped, or broken for another reason) no longer makes the update fail or stops Update all
- Safe shutdown: after a cancelled or failed Stop, a second Stop keeps the containers the first one stopped on the list, so Start them again starts them too
- A redirect to another host never gets an API key

###2026.10.30b
- Claude Guard: a command in a finding is shown only when every part of it is a known read-only program (cat, grep, tail, smartctl -a, zpool status, docker logs and similar); repair, unmount, delete or test commands such as xfs_repair, fsck, find -delete or smartctl -t are dropped instead of being shown as a read-only check

###2026.10.30a
- Security: names with quote marks (container, dataset, snapshot or file names, Claude answers) can no longer break into the Update manager, Claude Guard, Snapshots, Safe shutdown and Network pages as code; they show as plain text

###2026.10.30
- Power part: a UPS row read from the NUT plugin (only reading): state (on line, charging, on battery, low battery), battery, runtime, load in W, input voltage, the UPS loss (wall power minus UPS output) and a 24 hour input voltage curve with the voltage above which the UPS goes to battery
- On battery the UPS row turns red and its state blinks
- Settings: the UPS row can be switched off in the Power part (UPS (NUT))

###2026.10.29a
- Morning summary: a waiting plugin update shows its name (for example Unraid Connect), not only the new version
- Claude Guard: the snapshot it reads lists the waiting plugin updates too, so the report no longer says nothing waits while the summary shows one
- Suggestions: a snapshot to delete shows its dataset too (for example cache/docker@before-update-autobrr-...), so two snapshots of one update are told apart

###2026.10.29
- Stream priority (Settings, Utilities, Network, and a row on the tile): while someone watches Jellyfin from outside the house, qBittorrent switches to its alternative speed limit (25 MB/s upload by default, written into qBittorrent on Save) and goes back to full speed 5 minutes after the last outside stream; modes Off, Automatic (default) and Button on the tile; a limit you switched on yourself is left alone; Jellyfin from the Streambox settings, qBittorrent from the Seedbox settings; Activity at the top of the Network page
- Network: the containers on the host network (for example Jellyfin) and the server itself show as one host network row on the tile and the Network page

###2026.10.28
- Claude Guard: suggestions. In the daily report Claude may suggest up to three actions from a fixed list built by the server: a safe or a look-first container update, deleting an old before-update snapshot, the self-test, Check now, and (off by default) hiding a stuck Seedbox season pack job. Never a red (Wait) update, never anything outside the list
- Guard page: new Suggestions band with Approve (and a confirmation) or Not now; an approved one runs with the Update manager, Snapshots or Seedbox code like their own buttons and shows in Activity; a failed one comes back; earlier ones are folded
- Morning brief: the suggestions are numbered; with the shared bot it puts 1, 2, 3 and thumbs down under the message, your reaction approves (24 hours) and the Status field of the message follows the jobs
- Settings: which actions may be suggested, Approve from Discord, and Suggestions as a part of the brief

###2026.10.27b
- Claude Guard and the morning brief: a Seedbox Tools job you closed yourself (Hide, Cancel, or replaced by a later finished job of the same season) no longer counts as failed and starts no alert report

###2026.10.27a
- Morning brief: a failed or stuck Seedbox Tools job is named (up to three, with its state and time), not only counted

###2026.10.27
- Claude Guard: morning brief. With the daily report one Discord message goes out every day, also when everything is fine: Claude's summary and findings on top, then today's episodes (Streambox), downloads (Seedbox), waiting updates, yesterday's network and outages, and on the 1st last month's power. It replaces the daily report's own message. Settings: on or off, which parts, and Send it now
- Claude Guard: a red report's Discord message is red again (it showed blue); the Hungarian quiet hours row no longer starts with an English word

###2026.10.26a
- Claude Guard page: a shared Discord bot block (bot token and your Discord user ID, the same setting as in Seedbox Tools; the saved Seedbox bot is taken over). Test the bot sends a message with the Sysbox webhook, the bot reacts under it and reads the reaction back, so a missing permission shows at once. The morning summary and the suggestions with reactions come in the next versions

###2026.10.26
- New Network part on the tile (item 94): download and upload speed, traffic today, a 30-minute graph, an internet check (router and two outside addresses: amber when slow, red when down) and the three busiest containers; each piece can be switched off in the Sysbox settings, like the other parts
- New page Settings, Utilities, Network: 1 hour, 24 hour and 7 day graph, daily bars, today, this month and last month, traffic per container, internet outages, and its settings (interface, router, outside addresses, slow limit, unit MB/s or Mbit/s, Discord); only reads counters and sends pings
- Discord: one message after an internet outage, with its start and end
###2026.10.25d
- Update manager: when the self-test after an update fails because the program is still starting (e.g. Jellyfin with a Docker mod or a database migration), it tries again every 15 seconds for up to 3 minutes before it calls the update failed
###2026.10.25c
- Update manager: the Update all confirmation has normal sized buttons (they were stretched into tall columns)
###2026.10.25b
- Update manager: after a click on Update, Update all, Check now or Run self-test the job shows up in Activity at once and the buttons lock (before, the first click seemed to do nothing until a second click)
###2026.10.25a
- Claude Guard and Update all: the remaining error and Discord texts come from the dictionary too (Hungarian translation)
###2026.10.25
- Claude Guard: answer a finding on the page. Known (why, and optionally when to report it again) stops it at once from the next report and goes to Claude Code for the notes; Note for Claude Code sends a remark to the next session; Dismiss hides it from the tile only and it stays on the page. A Marked as known list with Remove
###2026.10.24a
- The tile shows the waiting container updates (a purple row under the containers, it opens the Update manager)
- Update manager: the daily check runs again when its result is gone after a reboot or a Sysbox install (it waited until the next morning)
###2026.10.24
- Update manager: Update all safe (the green ones, at once) and Update all (green and amber, after a list to confirm); one container after the other with a snapshot before and the self-test after each; stops at the first failure and tells Discord only then. Red updates and the claude container never go in a bulk update
###2026.10.23e
- Claude Guard: your notes and the findings waiting for Claude Code go into the request as text (Claude Code may not open files outside its folder when it runs unattended, so it could not read them); a long notes file is cut to the known states, decisions, hardware and open tasks
###2026.10.23d
- Claude Guard and Claude Code know about each other: every report is also written to the claude container (~/guard/latest.md and report files next to the snapshots), and the Guard reads which findings are still waiting in guard_todo.md
###2026.10.23c
- Claude Guard: a finding that is already an open task in your notes is marked with its item number instead of shown as new; a known state is reported again only when the condition written next to it in the notes is met
###2026.10.23b
- Claude Guard: Claude first reads your notes about the server (auto: ATADAS.md of this repository in the claude container, or a path you set) and does not report again what they call intended or known; the report language is a setting (default: Unraid's language); the snapshot has the disk device names so commands need no placeholders
###2026.10.23a
- Claude Guard: Run a report now ignores the weekly Claude limit (only the scheduled and alert reports are skipped above it)
###2026.10.23
- New page Claude Guard (Settings, Utilities): Claude Code in the claude container reads a snapshot of the server every day and after Sysbox, Diskbox and Seedbox alerts, and reports what needs a look (page, Discord, and amber or red findings as a to-do for Claude Code). It only reads; quiet hours, a daily limit of alert reports and a weekly Claude limit are settings. Off until switched on
- The Claude button in the header shows only when a container named claude exists
###2026.10.22o
- Update manager: an image named after a git commit (e.g. tvheadend a87be591 to 8837853a) is no longer called a major version; it shows new build (commit) and lists the program commits between the two builds
- The CPU graph recorder no longer leaves an exit status 1 line in the syslog every minute while it is running
###2026.10.22n
- Hungarian translation (Magyar) of every page and tile: choose Magyar under Pages and tiles in the Language block of any settings page (or set Unraid itself to Hungarian and keep Follow Unraid). Update all the plugins: with an older one installed the choice may fall back to English
###2026.10.22m
- The texts of the tile, the settings page, the Snapshots, Safe shutdown and Update manager pages and the Claude button come from a dictionary (lang/en.json) that can be translated (see TRANSLATING.md in the repository); they follow the shared language setting and show English where a translation is missing. Nothing changes on the screen yet
###2026.10.22l
- Language block: saving failed with csrf token mismatch (Unraid checks the token itself and then removes it from the request); fixed
###2026.10.22k
- Language block: the save button (now Save language) was greyed out, because Unraid disables Apply buttons outside a changed form; it works now
###2026.10.22j
- Language: one setting for all the plugins (Sysbox, Diskbox, Seedbox, Streambox), the Language block at the bottom of each settings page: pages and tiles (Follow Unraid or English; only English exists yet) and Discord messages (Follow Unraid, English or Magyar). A server that already sends Discord messages keeps the language it had; a new install starts in English. The old Language of the messages row is gone
###2026.10.22i
- Needs Unraid 7.0.0 or newer (tested on 7.3.2): the Plugins page no longer installs it on Unraid 6, where it was never tried
###2026.10.22h
- Updates now come from GitHub: the Plugins page checks the raw .plg on github.com/leone007/unraid-mediaserver-tools; the local update server (php -S on 127.0.0.1) and repo.php are removed
###2026.10.22g
- Safe shutdown: the last stop run is compared with Docker now; containers started again outside the page (Unraid autostart after an array restart, the Docker page) or removed are shown grey as running again or removed, and Start them again only counts and starts the ones still stopped
###2026.10.22f
- Update manager self-test, autobrr: connected (it answers) and, with the Seedbox Tools API key, live feed available (every enabled IRC network connected, every enabled channel monitored) with the time of the last announce; a disconnected network or an unmonitored channel fails the test
###2026.10.22e
- Update manager: when the last check result is gone (it lives in /tmp: a reboot or a plugin install removes it), opening the page starts the read-only check by itself, so the waiting updates and the kept Claude answers come back
###2026.10.22d
- Update manager: a new Unraid OS version is asked about by Claude by itself after a check (daily or Check now), once per version, when Ask Claude is on
###2026.10.22c
- Update manager: Save as a to-do button under a Claude answer: the Sysbox appends the answer to updates_todo.md in the repo folder (found through the claude container /work mount), and the next Claude session moves it into the task list
###2026.10.22b
- Update manager: a Claude answer stays visible under its row (also after a reload) for as long as that version is the waiting update
- Update manager: right after Ask Claude the page keeps watching until the answer arrives (before, the background job could start too late and the page stopped watching)
###2026.10.22a
- Update manager: Unraid OS updates are found again: Unraid 7 checks at releases.unraid.net (result.json), not with a plugin file; the page reads Unraid's answer or asks the same address once a day, shows the release date, the kind of change, the PHP note and the release notes, and Ask Claude works for the OS too (it reads our five plugins against the notes)
- Update manager: an image with several amd64 builds (v2, v3, as autobrr has) is no longer shown as an update when Docker pulled one of the other builds
- Ask Claude answers show bold text, inline code and copyable command blocks
###2026.10.22
- New page Settings, Utilities, Update manager (item 61): every Docker container with a waiting update, sorted Wait (red), Look first (amber) and Safe (green): installed and new version from the image labels, the kind of change (image rebuild, patch, new features, major version), how old the release is, which of our plugins use the program, warning words in the release notes (breaking, removed, API, migration), and the notes themselves from GitHub
- Ask Claude: Claude Code in the claude container reads the notes and our code that uses the program, with read-only tools and no saved session, and answers in a few sentences; the answer is kept per version
- Update button with a confirm: a ZFS snapshot of the container's data first (restore it on the Snapshots page), a long enough stop for qBittorrent, Unraid's own update from the template, then the self-test and a Discord message with the result. The item leaves the lists while it runs and comes back if it fails
- Self-test: read-only checks of qBittorrent, Sonarr, Radarr, Prowlarr, Lidarr, autobrr, Jellyfin with the Streambox week row
- Unraid OS and plugin updates from Unraid's own last check, with the PHP note of the OS release notes
- A daily check at a set time (only reads) and a Discord message for new amber and red updates
###2026.10.21
- Claude button: its tooltip shows the Claude plan usage: this week (with the time of the weekly reset), today, the average per day against the even share of the week (100/7 percent), the 5-hour window and how old the figures are. They come from the Claude Code status line in the claude container (tools/claude_statusline.sh, written to the container home only when a value changes); the page only reads them
###2026.10.20a
- Claude button: when the container's tmux session holds only a shell (after the container was recreated), Claude Code is started in the repo folder, so --continue finds the last conversation and no folder trust question waits in the terminal
###2026.10.20
- Dashboard tile (item 53): a new settings band switches every block on or off (RAM, Storage, CPU, GPU, Power, Containers) and changes their order with arrows; the CPU block can show or hide Load, Temperature, Package power, Clock, the history graph, the per-thread meters and the load average (in the footer); the Power block Wall, Today, This month, Cost and the socket name
- Power: new figure Today (kWh), counted from midnight with the socket's energy counter, on by default
- Memory is called RAM on the tile; the Needs attention list stays on top whatever is switched off
###2026.10.19
- Power on the tile: the band shows the socket type, e.g. Shelly Plug S Gen3, instead of smart socket
- Snapshots: 1 line instead of 1 lines
###2026.10.18
- Settings page in the shared look of the plugin pages: a dark band per part, the label on the left, the controls of one setting on one line, the explanation quiet under them; Apply and Done below
###2026.10.17
- New page Settings, Utilities, Snapshots: daily ZFS snapshots of the pool datasets that hold the containers' data (by default the docker dataset), at a set time before the container updates; keeps the daily auto- snapshots for a set number of days (7) and removes only those by itself; stops above a set pool fill (85 percent); a Discord message when a daily snapshot fails (the Sysbox webhook)
- Make one now and Delete (with a confirm) for each snapshot; every snapshot can be browsed as a folder
- Restore one container from a snapshot: its folders are read from the container's settings; a dry run shows how many files differ; a safety snapshot of now is made first (so the restore can be undone), only that container is stopped, its folders become exactly as in the snapshot (compared by content), and it is started again. The whole dataset is never rolled back
###2026.10.16
- Settings: the saved Discord webhook is no longer sent to the page; an empty field keeps it, a single - removes it
###2026.10.15
- New Claude button in the Unraid header, next to the terminal: one click starts the claude container if it is stopped, starts Claude Code with Remote Control in the tmux session main if it is not running there (the last conversation goes on), and opens the session on claude.ai in a new tab (the session list when the address is not known). A running session is left as it is
###2026.10.14
- Safe shutdown: while something still runs, the buttons read Shut down anyway and Restart anyway, and the confirm lists what still runs (containers, VMs, processes on the disks); when everything has stopped they read Shut down and Restart. They stay usable at any time as a way out (for example on battery)
###2026.10.13
- Safe shutdown: qBittorrent is the first row of the containers (it is stopped first); a protected process (the share service shfs, Samba) shows two of its files and a count; the summary row counts only the processes that can be stopped here and names the protected ones apart, since Unraid stops them by itself once the containers stop reading files
###2026.10.12
- Safe shutdown page in the look of the Dashboard tiles: Activity on top (the stop or start run with a progress bar, a striped row per container, qBittorrent closing its files, the log folded), then the four steps, each under a dark band with its state on the right: the checks as green or amber rows, the containers and VMs, the processes on the disks (protected ones grey, Force quit in red), and the summary row that turns green when nothing uses the disks. Nothing in the working changed
###2026.10.11
- The background scripts (alerts, weekly summary, energy sampler, slow readings) run only from cron or the dashboard worker, not when their address is opened in a browser
###2026.10.10
- New page Settings, Utilities, Safe shutdown: shows what could hang a shutdown (parity check, mover, Diskbox Balance, running containers and VMs, and every process that has a file or its working folder on the array or a pool); a button stops the containers (qBittorrent first, with up to 5 minutes to close its files, then the others together) and the VMs, and another starts them again if you change your mind; processes that still use the disks are listed and are stopped only when you press Ask to quit (TERM) or Force quit (KILL); Shut down and Restart then use Unraid's own shutdown
###2026.10.09
- Dashboard look: the row boxes have square corners, so the stripes are straight like in Diskbox
###2026.10.08
- Dashboard look: Storage and Containers rows sit in a box with a colored stripe on the left (storage: green, amber, red by usage; containers: green running, grey stopped, amber busy or restarting, red unhealthy), the section bands come from the shared common tile.css
###2026.10.07l
- Containers: the CPU number and bar are now a share of the whole CPU (all threads), consistent with the Load figure (Docker's own number is a share of one core: hover the value to see it); the RAM bar is unchanged
###2026.10.07k
- Container CPU bar was briefly scaled to one core
###2026.10.07j
- CPU history graph: refilled from the background recording every 15 seconds and when the page comes back to the front, so a slow or sleeping phone browser no longer leaves a gap at the right edge
###2026.10.07i
- CPU history graph: a short spike no longer changes height while the graph scrolls (the samples are grouped on fixed time slots instead of slots that moved with every redraw)
###2026.10.07h
- CPU history graph: the load is now recorded in the background (RAM only, last hour, one sample every 2 seconds), so the graph is already full when the dashboard opens and also fills in after a tab was in the background
###2026.10.07g
- CPU history graph: the time axis is now real time (it was counted in samples, so slow polling, for example a background tab, stretched the graph and a 3 second spike looked minutes old); the graph really shows the last N minutes
###2026.10.07f
- CPU history graph: removed the "50% · 80%" caption (less busy)
###2026.10.07e
- The GPU band shows the graphics model (for example UHD Graphics 770), read from the PCI device list, in front of Quick Sync
###2026.10.07d
- The CPU refresh (load, per-thread meters, temperature, history graph) is a setting: 1, 2, 5 or 10 seconds; the clock has its own refresh (1, 2, 5 or 10 seconds) and can be switched off; the CPU history graph keeps its length in minutes whatever the refresh
###2026.10.07c
- The weekly Discord summary includes the average daily consumption (kWh per day, average watts and the daily cost) when the smart socket is set up; it averages the last complete days (up to 7) from the socket's counter, which is recorded every 10 minutes
###2026.10.07b
- Discord: red alerts can ping you (your user ID, a role, @here or @everyone); amber alerts and all-clear messages never ping; the test message pings too
###2026.10.07
- Discord alerts for a system channel (own webhook, Hungarian or English): a container stopped unexpectedly (exit code, out of memory), restart loop, unhealthy container, memory, boot drive, log and Docker vdisk filling up, CPU temperature, and server restart; all-clear messages, no repeats of the same alert, optional repeat of red alerts
- A stop made with the dashboard button is not reported
- Weekly summary in Discord (day and hour are settings) and buttons for a test message, sample alerts and the summary
###2026.10.06b
- Memory legend has fixed positions, so nothing moves when System drops to zero
- CPU numbers, temperature and per-thread load refresh every second
- CPU history length is a setting (minutes); the curve is green below 50%, yellow above 50% and red above 80%
- Per-thread cells are vertical LED-segment meters instead of numbers
###2026.10.06
- First release: one dashboard tile in the Seedbox, Streambox and Diskbox design that replaces the System and Processor tiles
- Memory as a single bar (system, ZFS cache, Docker, free); boot, log and Docker vdisk usage
- CPU: load history, temperature, power, clock and a per-thread heat map; Intel GPU video and render engines
- Smart socket (Shelly Gen2+): power drawn at the wall, kWh this month and an optional cost
- Containers: the busiest by CPU or RAM, or a chosen list, with start, stop (asks first), restart and a log viewer; each can be switched off
- Needs attention list: full memory, log or Docker vdisk, hot CPU, restarting or unhealthy containers; limits are settings
</CHANGES>

<FILE Name="/tmp/&name;_setup.sh" Run="/bin/bash">
<INLINE><![CDATA[
#!/bin/bash
PLUG="sysbox"
DIR="/usr/local/emhttp/plugins/$PLUG"
CFG="/boot/config/plugins/$PLUG"

rm -rf "$DIR"
rm -rf "/usr/local/emhttp/plugins/Sysbox"
# clear the caches, but keep the graph histories (CPU, network, UPS voltage, energy samples), so an update does not
# start the dashboard graphs from empty (owner, 2026-10-30)
find /tmp -maxdepth 1 -name 'sysbox_*' ! -name sysbox_hist.json ! -name sysbox_net.json ! -name sysbox_upsv.json \
  ! -name sysbox_energy_last.json ! -name sysbox_edays.json ! -name sysbox_rtr.json ! -name '*.lock' -exec rm -f {} + 2>/dev/null

mkdir -p "$DIR"
mkdir -p "$CFG"

# Defaults are written only if absent, so upgrades never clobber the settings.
if [ ! -f "$CFG/settings.cfg" ]; then
cat > "$CFG/settings.cfg" <<'EOF'
top_mode="cpu"
hist_min="2"
top_n="3"
sel=""
controls="1"
logs="1"
log_lines="100"
show_gpu="1"
show_containers="1"
ram_warn="80"
ram_crit="92"
log_warn="80"
log_crit="92"
vd_warn="80"
vd_crit="92"
temp_warn="75"
temp_crit="90"
socket_host=""
socket_id="0"
kwh_price=""
currency="Ft"
EOF
fi
chmod 600 "$CFG/settings.cfg"

# Monochrome icon (128x128 PNG), embedded so there is no download or CDN dependency
base64 -d > "$DIR/icon.png" <<'B64'
'''+icon+r'''
B64

# Icon for the Plugins page and the settings menu; the Plugins page looks it up under the display name
TITLEDIR="/usr/local/emhttp/plugins/Sysbox"
mkdir -p "$DIR/images" "$TITLEDIR/images"
cp "$DIR/icon.png" "$DIR/images/sysbox.png"
cp "$DIR/icon.png" "$TITLEDIR/images/sysbox.png"
exit 0
]]></INLINE>
</FILE>

'''
for name,p,t in files:
    body=rd(p).replace('/*@@TILE_CSS@@*/',rd('common/tile.css').strip()).replace('/*@@SETTINGS_CSS@@*/',rd('common/settings.css').strip())
    if p.endswith('README.md'): body=body.rstrip('\n')+'\n'
    out+='<!-- ========================================================= -->\n<!-- %s -->\n<!-- ========================================================= -->\n<FILE Name="%s">\n<INLINE><![CDATA[%s]]></INLINE>\n</FILE>\n\n'%(t,name,body)
out+=r'''<!-- Permissions + done -->
<FILE Name="/tmp/&name;_finish.sh" Run="/bin/bash">
<INLINE><![CDATA[
#!/bin/bash
DIR="/usr/local/emhttp/plugins/sysbox"
chmod 644 "$DIR"/*.page "$DIR"/*.php "$DIR"/icon.png

# Energy sampler: Unraid collects /boot/config/plugins/<name>/*.cron with update_cron.
PHPBIN="$(command -v php || echo /usr/bin/php)"
cat > /boot/config/plugins/sysbox/sysbox.cron <<CRON
# Sysbox: remember the smart socket energy counter every 10 minutes (monthly consumption)
*/10 * * * * $PHPBIN /usr/local/emhttp/plugins/sysbox/sample.php >/dev/null 2>&1
# Sysbox: records the CPU load in RAM for the dashboard graph (one long-running process, restarted if it stops)
* * * * * flock -n -E 0 /tmp/sysbox_hist.lock $PHPBIN /usr/local/emhttp/plugins/sysbox/cpuhist.php >/dev/null 2>&1
# Sysbox: network meter (traffic counters every 5 s, internet ping every 30 s; one long-running process, restarted if it stops)
* * * * * flock -n -E 0 /tmp/sysbox_net.lock $PHPBIN /usr/local/emhttp/plugins/sysbox/netmon.php >/dev/null 2>&1
# Sysbox: router part (item 132; one long-running process asking the router over ssh, it exits when the part is off)
* * * * * flock -n -E 0 /tmp/sysbox_rtr.lock $PHPBIN /usr/local/emhttp/plugins/sysbox/rtrmon.php >/dev/null 2>&1
# Sysbox: stream priority, every minute (qBittorrent gives way to Jellyfin streams outside the house; off on the Network page)
* * * * * flock -n -E 0 /tmp/sysbox_sp.lock $PHPBIN /usr/local/emhttp/plugins/sysbox/sp_tick.php >/dev/null 2>&1
# Sysbox: Discord alerts, checked every minute
* * * * * $PHPBIN /usr/local/emhttp/plugins/sysbox/notify.php >/dev/null 2>&1
# Sysbox: power outage (UPS on battery), checked every minute, every 15 seconds while on battery
* * * * * flock -n -E 0 /tmp/sysbox_ups.lock $PHPBIN /usr/local/emhttp/plugins/sysbox/ups_tick.php >/dev/null 2>&1
# Sysbox: weekly summary, checked hourly (day and hour are settings)
12 * * * * $PHPBIN /usr/local/emhttp/plugins/sysbox/weekly.php >/dev/null 2>&1
# Sysbox: daily ZFS snapshots, checked every 5 minutes (made once a day after the set time; see Settings, Utilities, Snapshots)
*/5 * * * * $PHPBIN /usr/local/emhttp/plugins/sysbox/snap_run.php daily >/dev/null 2>&1
# Sysbox: Update manager daily check, checked every 5 minutes (runs once a day after the set time; only reads)
*/5 * * * * $PHPBIN /usr/local/emhttp/plugins/sysbox/upd_run.php daily >/dev/null 2>&1
# Sysbox: Claude Guard, checked every 2 minutes (alerts, the daily report once after the set time; only reads; off until switched on)
*/2 * * * * $PHPBIN /usr/local/emhttp/plugins/sysbox/guard_run.php tick >/dev/null 2>&1
CRON
# update_cron reads the plugin link in /var/log/plugins, which appears only after the install scripts
# have finished, so wait for it in the background.
nohup bash -c 'for i in $(seq 1 60); do [ -e /var/log/plugins/sysbox.plg ] && break; sleep 1; done; /usr/local/sbin/update_cron' >/dev/null 2>&1 &

# start the CPU recorder right away (cron keeps it alive afterwards)
pkill -f "plugins/sysbox/cpuhist.php" 2>/dev/null
setsid nohup flock -n /tmp/sysbox_hist.lock "$PHPBIN" /usr/local/emhttp/plugins/sysbox/cpuhist.php >/dev/null 2>&1 </dev/null &

# start the network meter right away (it exits by itself when the Network part is switched off)
pkill -f "plugins/sysbox/netmon.php" 2>/dev/null
setsid nohup flock -n /tmp/sysbox_net.lock "$PHPBIN" /usr/local/emhttp/plugins/sysbox/netmon.php >/dev/null 2>&1 </dev/null &

# start the router reader right away (it exits by itself when the Router part is off)
pkill -f "plugins/sysbox/rtrmon.php" 2>/dev/null
setsid nohup flock -n /tmp/sysbox_rtr.lock "$PHPBIN" /usr/local/emhttp/plugins/sysbox/rtrmon.php >/dev/null 2>&1 </dev/null &
# the claude container flag for the header button (Claude Guard tick; cron repeats it every 2 minutes)
setsid nohup "$PHPBIN" /usr/local/emhttp/plugins/sysbox/guard_run.php tick >/dev/null 2>&1 </dev/null &

# Updates come from GitHub (pluginURL): stop the local update server of earlier versions
pkill -f "php -S 127.0.0.1:18184" 2>/dev/null
rm -f /usr/local/emhttp/plugins/sysbox/repo.php
rm -f /tmp/sysbox_setup.sh /tmp/sysbox_finish.sh
echo ""
echo "----------------------------------------------------"
echo " Sysbox installed."
echo " Settings: Settings -> Utilities -> Sysbox"
echo " (or the gear icon on the dashboard tile)."
echo " Hide the old System and Processor tiles in the"
echo " dashboard's tile management."
echo "----------------------------------------------------"
exit 0
]]></INLINE>
</FILE>

<!-- Uninstall -->
<FILE Run="/bin/bash" Method="remove">
<INLINE><![CDATA[
#!/bin/bash
rm -rf /usr/local/emhttp/plugins/sysbox
rm -rf /usr/local/emhttp/plugins/Sysbox
pkill -f "php -S 127.0.0.1:18184" 2>/dev/null
pkill -f "plugins/sysbox/cpuhist.php" 2>/dev/null
pkill -f "plugins/sysbox/netmon.php" 2>/dev/null
pkill -f "plugins/sysbox/rtrmon.php" 2>/dev/null
rm -f /tmp/sysbox_*
rm -f /boot/config/plugins/sysbox/sysbox.cron
/usr/local/sbin/update_cron 2>/dev/null
# Settings, the energy counter and the router key in /boot/config/plugins/sysbox are kept intentionally.
echo "sysbox removed."
exit 0
]]></INLINE>
</FILE>

</PLUGIN>
'''
open(D+'dist/sysbox.plg','w',encoding='utf-8').write(out)
