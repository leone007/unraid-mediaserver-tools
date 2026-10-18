<?php
// Long-running CPU sampler (started by cron, one instance only): keeps the last hour of total CPU load
// in RAM (/tmp), so the dashboard graph is already full when the page is opened.
if (PHP_SAPI !== 'cli') { exit; }
require_once __DIR__ . '/lib.php';
const SX_HF = '/tmp/sysbox_hist.json';
$step = 2; $keep = 3600;
$pts = [];
$f0 = is_file(SX_HF) ? json_decode((string)@file_get_contents(SX_HF), true) : null;
if (is_array($f0)) { $now = time(); foreach ($f0 as $p) { if (is_array($p) && count($p) === 2 && $p[0] > $now - $keep) { $pts[] = $p; } } }
$prev = (sx_stat())['all'] ?? null; $n = 0;
while (true) {
    sleep($step);
    if (!is_dir(__DIR__)) { exit; }                       // plugin removed
    $cur = (sx_stat())['all'] ?? null;
    if ($cur && $prev) {
        $dt = $cur[0] - $prev[0]; $di = $cur[1] - $prev[1];
        $v = $dt > 0 ? max(0, min(100, (1 - $di / $dt) * 100)) : 0;
        $pts[] = [time(), round($v, 1)];
        $lim = time() - $keep;
        while ($pts && $pts[0][0] < $lim) { array_shift($pts); }
        if (++$n % 2 === 0) { @file_put_contents(SX_HF . '.tmp', json_encode($pts)); @rename(SX_HF . '.tmp', SX_HF); }
    }
    $prev = $cur;
}
