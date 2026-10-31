<?php
// Long-running router sampler (item 132; cron starts it every minute, one instance only, it exits when the Router part is
// switched off or not set up). Every few seconds one ssh call to the router's read-only script (counters, status), every
// 30 s a ping to the AiMesh nodes, every 10 minutes Skynet's statistics. Live data in /tmp (rtr_live_file). Only reads.
if (PHP_SAPI !== 'cli') { exit; }
require_once __DIR__ . '/rtr_lib.php';

function rtr_on() { return sx_on(sx_cfg()['show_rtr'] ?? '0'); }
if (!rtr_on()) { exit(0); }

$rounds = 0; foreach ($argv as $a) { if (preg_match('/^--rounds=(\d+)$/', $a, $m)) { $rounds = (int)$m[1]; } }
$R = rtr_cfg(); $cfgT = time();
$L0 = rtr_live(); $pts = (is_array($L0) && !empty($L0['pts']) && time() - (int)($L0['t'] ?? 0) < 600) ? $L0['pts'] : [];
$prevCpu = null; $prevWan = null; $prevT = 0; $D = null; $lastOk = (int)($L0['last_ok'] ?? 0); $err = ''; $errSince = 0;
$cpu = null; $cores = []; $rate = [0, 0]; $sky = $L0['sky'] ?? null; $skyT = 0; $mesh = []; $meshT = 0;

function rtr_write($L) { $f = rtr_live_file(); @file_put_contents($f . '.tmp', json_encode($L)); @rename($f . '.tmp', $f); }

while (true) {
    $now = time();
    if (!is_dir(__DIR__)) { exit; }   // plugin removed
    if ($now - $cfgT >= 60) {
        $cfgT = $now;
        if (!rtr_on()) { @unlink(rtr_live_file()); exit(0); }
        $R2 = rtr_cfg(); if ($R2 !== $R) { $R = $R2; $prevCpu = null; $prevWan = null; $pts = []; $D = null; $sky = null; $skyT = 0; }
    }
    if (!rtr_ready($R)) { rtr_write(['t' => $now, 'state' => 'setup']); exit(0); }
    $step = max(2, (int)$R['sec']);

    $s = rtr_snap($R);
    if ($s['ok']) {
        $D = $s['D']; $err = ''; $errSince = 0; $lastOk = $now;
        if ($prevCpu && $now > $prevT) {
            $cores = [];
            foreach ($D['cpu'] as $k => $v) {
                $p = $prevCpu[$k] ?? null; if (!$p) { continue; }
                $dt = $v[0] - $p[0]; $pct = $dt > 0 ? max(0, min(100, round((1 - ($v[1] - $p[1]) / $dt) * 100))) : 0;
                if ($k === 'cpu') { $cpu = $pct; } else { $cores[] = $pct; }
            }
            $w = $D['wan'] !== '' ? $D['dev'][$D['wan']] : null;
            if ($w && $prevWan) {
                $dt = $now - $prevT;
                $rate = [round(($w[0] >= $prevWan[0] ? $w[0] - $prevWan[0] : $w[0]) / $dt), round(($w[1] >= $prevWan[1] ? $w[1] - $prevWan[1] : $w[1]) / $dt)];
                $pts[] = [$now, $rate[0], $rate[1]];
                while ($pts && $pts[0][0] < $now - 1800) { array_shift($pts); }
            }
        }
        $prevCpu = $D['cpu']; $prevWan = $D['wan'] !== '' ? $D['dev'][$D['wan']] : null; $prevT = $now;
        // Skynet's statistics are slow to make: only for the Full view with the Skynet bar on, or for the Claude Guard (item 131)
        $c = sx_cfg(); $hasSky = false; foreach ($D['svc'] as $x) { if ($x[0] === 'skynet' && $x[1]) { $hasSky = true; } }
        $G = json_decode((string)@file_get_contents(rtr_dir() . '/guard.json'), true); $forGuard = is_array($G) && !empty($G['on']) && ($G['t_rtr'] ?? 1);
        if ($hasSky && ((($c['rtr_view'] ?? 'b') === 'c' && sx_on($c['rtr_sky'] ?? '1')) || $forGuard) && $now - $skyT >= 600) {
            $skyT = $now; list($ok, $out) = rtr_ssh($R, 'sky', 60);
            if ($ok) { $sky = rtr_sky_parse($out) + ['t' => $now]; }
        }
        // AiMesh nodes: a ping from the server (they are on the LAN)
        if ($D['mesh'] && $now - $meshT >= 30) {
            $meshT = $now; $mesh = [];
            foreach ($D['mesh'] as $n) { list($rc) = sx_run('ping -c 1 -W 1 ' . escapeshellarg($n[1]), 3); $mesh[] = [$n[0], $n[1], $rc === 0]; }
        }
    } else {
        if ($err !== $s['err'] || !$errSince) { $errSince = $now; }
        $err = $s['err']; $prevCpu = null; $prevWan = null;
        if ($err === 'hostkey' || $err === 'auth' || $err === 'noscript' || $err === 'bad') { $step = max($step, 30); }   // only the owner can fix these: ask less often
    }
    if ($s['ok']) { $D['cpu'] = []; $D['dev'] = []; }   // the raw counters stay here; on an error the last figures are shown as they were
    rtr_write(['t' => $now, 'state' => $err === '' ? 'ok' : 'err', 'err' => $err, 'err_since' => $errSince, 'last_ok' => $lastOk,
               'D' => $D, 'cpu' => $cpu, 'cores' => $cores, 'rate' => $err === '' ? $rate : [0, 0], 'pts' => $pts, 'sky' => $sky, 'mesh' => $mesh]);
    if ($rounds && --$rounds === 0) { exit(0); }
    sleep($step);
}
