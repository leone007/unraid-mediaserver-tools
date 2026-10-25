<?php
// Started by cron every 10 minutes: remember the smart socket's energy counter, so the monthly
// consumption stays correct even when nobody has the dashboard open; and one UPS input voltage point.
if (PHP_SAPI !== 'cli') { exit(1); } // cron only, not a web page
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/energy.php';

$cfg = sx_cfg();
$host = trim((string)$cfg['socket_host']);
if ($host !== '') {
    list($s, $err) = sx_socket_fetch($host, (int)$cfg['socket_id']);
    if ($s !== null && $s['total_wh'] !== null) { sx_energy_month_wh($s['total_wh']); sx_energy_days_update($s['total_wh']); }
}

// UPS input voltage: one point for the 24 h curve on the tile (item 99)
if (sx_on($cfg['pow_ups'] ?? '1')) {
    list($u, $ue) = sx_ups_fetch((string)$cfg['ups_host'], (int)$cfg['ups_port'], (string)$cfg['ups_name']);
    if ($u !== null) { sx_upsv_note($u['vin'], true); }
}
