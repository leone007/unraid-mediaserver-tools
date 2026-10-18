<?php
// Light and fast: only the CPU numbers, polled every second by the dashboard tile.
require_once __DIR__ . '/lib.php';
if (($_GET['only'] ?? '') === 'clock') { sx_json(['ok' => true, 'clock' => sx_clock()]); }   // the clock has its own, slower refresh
if (($_GET['only'] ?? '') === 'hist') {                // history for the graph, as ages (ms), independent of any clock difference
    $now = time(); $min = max(1, min(60, (int)($_GET['min'] ?? 2))); $out = [];
    $d = is_file('/tmp/sysbox_hist.json') ? json_decode((string)@file_get_contents('/tmp/sysbox_hist.json'), true) : [];
    foreach ((array)$d as $p) { if (is_array($p) && $p[0] >= $now - $min * 60) { $out[] = [($now - $p[0]) * 1000, $p[1]]; } }
    sx_json(['ok' => true, 'pts' => $out]);
}
list($load, $per) = sx_cpu_rates();
list($tPkg, $tCore) = sx_temps();
$cfg = sx_cfg();
$tl = '';
if ($tPkg !== null) { $tl = $tPkg >= (float)$cfg['temp_crit'] ? 'rd' : ($tPkg >= (float)$cfg['temp_warn'] ? 'a' : ''); }
sx_json(['ok' => true, 'load' => $load, 'per' => $per, 'temp' => $tPkg, 'tlevel' => $tl, 'hot_core' => $tCore]);
