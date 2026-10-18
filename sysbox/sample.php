<?php
// Started by cron every 10 minutes: remember the smart socket's energy counter, so the monthly
// consumption stays correct even when nobody has the dashboard open.
if (PHP_SAPI !== 'cli') { exit(1); } // cron only, not a web page
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/energy.php';

$cfg = sx_cfg();
$host = trim((string)$cfg['socket_host']);
if ($host === '') { exit(0); }

list($s, $err) = sx_socket_fetch($host, (int)$cfg['socket_id']);
if ($s !== null && $s['total_wh'] !== null) { sx_energy_month_wh($s['total_wh']); sx_energy_days_update($s['total_wh']); }
