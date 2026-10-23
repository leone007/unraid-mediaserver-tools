<?php
// Long-running network sampler (item 94; cron starts it every minute, one instance only, it exits when the Network part
// is switched off). Every 5 s the wire interface's counters, every 15 s the containers' own counters, every 30 s a ping
// to the router and outside. Live data in /tmp (SXNET_LIVE), the history on the flash every 30 minutes (SXNET_HIST).
// It only reads counters and sends pings; nothing on the server is changed.
if (PHP_SAPI !== 'cli') { exit; }
require_once __DIR__ . '/net_lib.php';

function sxnet_enabled() { return sx_on(sx_cfg()['show_net'] ?? '1'); }
if (!sxnet_enabled()) { exit(0); }

// tests: --rounds=N stops after N rounds, SX_NET_STEP sets the seconds between rounds
$rounds = 0; foreach ($argv as $a) { if (preg_match('/^--rounds=(\d+)$/', $a, $m)) { $rounds = (int)$m[1]; } }
$step = max(1, (int)(getenv('SX_NET_STEP') ?: 5));
$H = json_decode((string)@file_get_contents(sxnet_hist_file()), true);
if (!is_array($H)) { $H = []; }
$H += ['v' => 1, 'days' => [], 'h24' => [], 'd7' => [], 'ctday' => ['d' => date('Y-m-d'), 'ct' => []], 'events' => []];
$L0 = sxnet_live();
if (is_array($L0) && !empty($L0['live']) && time() - (int)($L0['t'] ?? 0) < 600) { $live = $L0['live']; } else { $live = []; }

$cfg = sxnet_cfg(); $iface = sxnet_iface($cfg);
$prev = null; $prevT = 0; $prevTs = null;
$b5 = [0, 0.0, 0.0]; $b30 = [0, 0.0, 0.0];
$pids = []; $pidsT = 0; $ctPrev = []; $ctT = 0; $ctRate = []; $hostCt = []; $hostRate = null; $wireAt = null;
$inet = ['state' => 'wait', 'since' => time()]; $pingT = 0; $slowN = 0; $failN = 0; $failSince = 0;
$cfgT = time(); $saveT = time(); $link = sxnet_link($iface);

function sxnet_save_hist(&$H) {
    $f = sxnet_hist_file(); @mkdir(dirname($f), 0755, true);
    $cut = date('Y-m-d', strtotime('-400 days'));
    foreach (array_keys($H['days']) as $d) { if ($d < $cut) { unset($H['days'][$d]); } }
    $H['events'] = array_slice($H['events'], -50);
    @file_put_contents($f . '.tmp', json_encode($H)); @rename($f . '.tmp', $f);
}
function sxnet_bucket(&$b, &$list, $now, $len, $keep, $drx, $dtx) {
    $k = intdiv($now, $len) * $len;
    if ($b[0] && $b[0] !== $k) { $list[] = [$b[0], round($b[1] / $len), round($b[2] / $len)]; $b = [0, 0.0, 0.0]; }
    if (!$b[0]) { $b[0] = $k; }
    $b[1] += $drx; $b[2] += $dtx;
    while ($list && $list[0][0] < $now - $keep) { array_shift($list); }
}

// a plugin update stops the sampler (pkill): keep the history of the last minutes
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    foreach ([SIGTERM, SIGINT] as $sg) { pcntl_signal($sg, function () use (&$H) { sxnet_save_hist($H); exit(0); }); }
}

while (true) {
    $now = time(); $today = date('Y-m-d');
    if (!is_dir(__DIR__)) { exit; }   // plugin removed

    // settings: every minute (the Network page or the Sysbox settings may have changed)
    if ($now - $cfgT >= 60) {
        $cfgT = $now;
        if (!sxnet_enabled()) { sxnet_save_hist($H); @unlink(sxnet_live_file()); exit(0); }
        $cfg = sxnet_cfg(); $i2 = sxnet_iface($cfg);
        if ($i2 !== $iface) { $iface = $i2; $prev = null; }
        $link = sxnet_link($iface);
    }

    // a new day: today's per-container totals start again
    if (($H['ctday']['d'] ?? '') !== $today) { $H['ctday'] = ['d' => $today, 'ct' => []]; sxnet_save_hist($H); $saveT = $now; }

    // the wire interface (and Tailscale, which is part of it)
    $dev = sxnet_dev(); $cur = $dev[$iface] ?? null; $ts = $dev['tailscale0'] ?? null; $rate = [0, 0];
    if ($cur && $prev && $now > $prevT) {
        $drx = $cur[0] >= $prev[0] ? $cur[0] - $prev[0] : $cur[0]; $dtx = $cur[1] >= $prev[1] ? $cur[1] - $prev[1] : $cur[1];   // a counter reset counts from 0
        $dt = $now - $prevT; $rate = [round($drx / $dt), round($dtx / $dt)];
        $live[] = [$now, $rate[0], $rate[1]];
        while ($live && $live[0][0] < $now - 3600) { array_shift($live); }
        sxnet_bucket($b5, $H['h24'], $now, 300, 86400, $drx, $dtx);
        sxnet_bucket($b30, $H['d7'], $now, 1800, 7 * 86400, $drx, $dtx);
        $dd = $H['days'][$today] ?? [0, 0, 0, 0];
        $dd[0] += $drx; $dd[1] += $dtx;
        if ($ts && $prevTs) { $dd[2] += $ts[0] >= $prevTs[0] ? $ts[0] - $prevTs[0] : $ts[0]; $dd[3] += $ts[1] >= $prevTs[1] ? $ts[1] - $prevTs[1] : $ts[1]; }
        $H['days'][$today] = $dd;
    }
    if ($cur) { $prev = $cur; $prevT = $now; }
    $prevTs = $ts;

    // containers: their own network counters (/proc/<pid>/net/dev); the list of containers every minute
    if ($now - $ctT >= 3 * $step) {
        if ($now - $pidsT >= 12 * $step) { $pids = sxnet_ct_pids($hostCt); $pidsT = $now; }
        $dtc = $ctT ? $now - $ctT : 0; $ctT = $now; $newPrev = []; $ctRate = [];
        foreach ($pids as $n => $pid) {
            $b = sxnet_ct_bytes($pid); if ($b === null) { continue; }
            $p = $ctPrev[$n] ?? null;
            if ($p && $dtc > 0) {
                $samePid = $p[2] === $pid;
                $drx = ($samePid && $b[0] >= $p[0]) ? $b[0] - $p[0] : $b[0]; $dtx = ($samePid && $b[1] >= $p[1]) ? $b[1] - $p[1] : $b[1];
                $ctRate[$n] = [round($drx / $dtc), round($dtx / $dtc)];
                $c = $H['ctday']['ct'][$n] ?? [0, 0]; $H['ctday']['ct'][$n] = [$c[0] + $drx, $c[1] + $dtx];
            }
            $newPrev[$n] = [$b[0], $b[1], $pid];
        }
        $ctPrev = $newPrev;
        // the host network (containers without their own network, and the server itself): the wire minus the containers
        if ($cur && $wireAt && $dtc > 0) {
            $sum = [0, 0]; foreach ($ctRate as $r) { $sum[0] += $r[0]; $sum[1] += $r[1]; }
            $hostRate = [max(0, round(($cur[0] - $wireAt[0]) / $dtc) - $sum[0]), max(0, round(($cur[1] - $wireAt[1]) / $dtc) - $sum[1])];
        }
        $wireAt = $cur;
    }

    // internet: every 30 s; slow after 3 slow checks in a row, down after 2 failed ones; an outage is logged when it ends
    if ($now - $pingT >= 6 * $step) {
        $pingT = $now; $c = sxnet_check($cfg); $raw = $c['raw'];
        if ($raw === 'ok' || $raw === 'slow') {
            $failN = 0; $slowN = $raw === 'slow' ? $slowN + 1 : 0;
            $st = $slowN >= 3 ? 'slow' : 'ok';
            $n = count($H['events']);
            if ($n && $H['events'][$n - 1]['e'] === null) { $H['events'][$n - 1]['e'] = $now; sxnet_save_hist($H); $saveT = $now; }
        } else {
            $slowN = 0; if ($failN === 0) { $failSince = $c['t']; } $failN++;
            $st = $failN >= 2 ? $raw : ($inet['state'] === 'wait' ? 'ok' : $inet['state']);
            $n = count($H['events']);
            if ($failN >= 2 && (!$n || $H['events'][$n - 1]['e'] !== null)) { $H['events'][] = ['s' => $failSince, 'e' => null, 'k' => $raw]; }
            elseif ($failN >= 2 && $n) { $H['events'][$n - 1]['k'] = $raw; }
        }
        if ($st !== $inet['state']) { $inet['since'] = ($st === 'down' || $st === 'norouter') ? $failSince : $now; }
        $inet = array_merge($inet, $c, ['state' => $st]);
    }

    // the live file for the tile and the page
    $L = ['t' => $now, 'iface' => $iface, 'link' => $link, 'rate' => $rate, 'live' => $live, 'ct' => $ctRate, 'ct_names' => array_keys($pids), 'host_ct' => $hostCt, 'host_rate' => $hostRate,
          'inet' => $inet, 'h24' => $H['h24'], 'd7' => $H['d7'], 'days' => array_slice($H['days'], -62, null, true), 'ctday' => $H['ctday'], 'events' => array_slice($H['events'], -20),
          'unit' => $cfg['unit'], 'slow_ms' => (int)$cfg['slow_ms']];
    $f = sxnet_live_file(); @file_put_contents($f . '.tmp', json_encode($L)); @rename($f . '.tmp', $f);
    if ($now - $saveT >= 1800) { sxnet_save_hist($H); $saveT = $now; }
    if ($rounds && --$rounds === 0) { sxnet_save_hist($H); exit(0); }
    sleep($step);
}
