<?php
// Started by cron every minute (item 99): looks at the UPS; while it runs on battery, again every 15 seconds within
// the minute, so the owner's reactions and the shutdown levels are seen quickly.
if (PHP_SAPI !== 'cli') { exit(1); } // cron only, not a web page
require_once __DIR__ . '/ups_lib.php';

$cfg = sx_cfg();
$t0 = time();
while (ups_step($cfg) && time() - $t0 < 40) { sleep(15); }
