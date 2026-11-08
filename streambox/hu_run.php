<?php
// Started by cron every 15 minutes (and by the Retry button with "now"): Hungarian descriptions (item 147), see hu_lib.php.
if (PHP_SAPI !== 'cli') { exit(1); } // cron only, not a web page
require_once __DIR__ . '/hu_lib.php';
wk_tz();
$lk = fopen('/tmp/streambox_hu.lock', 'c');
if (!$lk || !flock($lk, LOCK_EX | LOCK_NB)) { exit(0); }   // one run at a time (a Claude run can take minutes)
$cfg = hu_cfg();
$now = time();
hu_tick($cfg, $now, ($argv[1] ?? '') === 'now');
hu_daily($cfg, $now);
