<?php
// Started by cron every 15 minutes (and by the page's Search now in the background): Hungarian subtitles (item 162), see subs_lib.php.
if (PHP_SAPI !== 'cli') { exit(1); } // cron only, not a web page
require_once __DIR__ . '/subs_lib.php';
wk_tz();
$lk = fopen('/tmp/streambox_subs.lock', 'c');
if (!$lk || !flock($lk, LOCK_EX | LOCK_NB)) { exit(0); }   // one run at a time (aligning reads whole videos)
subs_tick(subs_cfg(), time());
