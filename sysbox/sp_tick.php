<?php
// Stream priority (item 98): cron runs this every minute. It asks Jellyfin which streams play outside the house and
// switches qBittorrent's alternative speed limit on or off (only that switch, and only one Sysbox switched on itself).
if (PHP_SAPI !== 'cli') { exit; }
require_once __DIR__ . '/net_lib.php';
$cfg = sxnet_cfg(); $S = sxsp_state();
if ($cfg['sp_mode'] === 'off' && empty($S['held'])) { @unlink(sxsp_live_file()); exit(0); }   // nothing to do and nothing to give back
if (sxsp_jf_cfg()['key'] === '' && empty($S['held'])) { exit(0); }                             // no Jellyfin in the Streambox settings
sxsp_run('tick');
