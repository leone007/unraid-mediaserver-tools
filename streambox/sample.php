<?php
// Started by cron every 10 minutes: remember the smart socket's energy counter, so the monthly
// consumption stays correct even when nobody has the dashboard open.
if (PHP_SAPI !== 'cli') { exit(1); } // cron only, not a web page
require_once __DIR__ . '/energy.php';

$cfg = ['socket_host' => '', 'socket_id' => '0'];
$f = '/boot/config/plugins/streambox/settings.cfg';
if (is_file($f)) {
    $c = @parse_ini_file($f);
    if (is_array($c)) { $cfg = array_merge($cfg, $c); }
}
$host = trim((string)$cfg['socket_host']);
if ($host === '') { exit(0); }

list($s, $err) = sb_socket_fetch($host, (int)$cfg['socket_id']);
if ($s !== null && $s['total_wh'] !== null) { sb_energy_month_wh($s['total_wh']); }
