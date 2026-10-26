<?php
// Background worker of Safe shutdown (started by shutdown.php):
//   php shutdown_run.php stop    stop the running containers (qBittorrent first, with a long wait) and the VMs
//   php shutdown_run.php start   start again the containers the stop run stopped (if you change your mind)
//   php shutdown_run.php stopqb  stop only qBittorrent (the UPS does it before the NUT plugin's shutdown, item 99)
// From the page it never shuts the server down: that is a separate button that calls Unraid's own shutdown. A stop
// started from Discord during a power outage (argument 2 = off, item 99) powers the server off when it ends, unless it
// was cancelled on the page. Argument 3 says who started it (page, discord, ups).
if (PHP_SAPI !== 'cli') { exit(1); }
require_once __DIR__ . '/shutdown_lib.php';
@set_time_limit(0);
$mode = $argv[1] ?? '';
if (!in_array($mode, ['stop', 'start', 'stopqb'], true)) { exit(1); }
$off = $mode === 'stop' && ($argv[2] ?? '') === 'off';
$by = in_array($argv[3] ?? '', ['discord', 'ups'], true) ? $argv[3] : 'page';

$lock = @fopen(SD_LOCK, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { exit(0); }
@unlink(SD_CANCEL);

$prev = sd_status();
$S = ['state' => 'running', 'mode' => $mode, 'by' => $by, 'off' => $off, 'started' => time(), 'finished' => 0, 'log' => [], 'stopped' => [], 'vms' => [], 'msg' => ''];
if ($mode === 'start') { $S['stopped'] = (array)($prev['stopped'] ?? []); }
function sd_log($m) {
    global $S;
    $S['log'][] = date('H:i:s') . ' ' . $m;
    sd_status_write($S);
}
function sd_mark_userstop($names) {   // so the Sysbox Discord alerts do not report these stops
    $f = '/tmp/sysbox_userstop.json';
    if (getenv('SX_SD_DIR')) { $f = SD_DIR . '/sysbox_userstop.json'; }
    $mk = is_file($f) ? (json_decode((string)@file_get_contents($f), true) ?: []) : [];
    foreach ($names as $n) { $mk[$n] = time(); }
    @file_put_contents($f, json_encode($mk));
}
function sd_cancelled() { return is_file(SD_CANCEL); }

$d = sx_docker();

if ($mode === 'start') {
    $names = array_values(array_filter($S['stopped'], 'sx_valid_name'));
    if ($d === '' || !$names) { $S['state'] = 'done'; $S['msg'] = sx_tr('sdr.nothing'); $S['finished'] = time(); sd_status_write($S); exit(0); }
    sd_log('starting ' . count($names) . ' container(s) again');
    $failed = [];
    foreach ($names as $n) {
        [$rc, $out] = sx_run(escapeshellarg($d) . ' start ' . escapeshellarg($n), 90);
        sd_log(($rc === 0 ? 'started ' : 'FAILED to start ') . $n . ($rc === 0 ? '' : ': ' . substr(trim($out), 0, 200)));
        if ($rc !== 0) { $failed[] = $n; }
    }
    @unlink('/tmp/sysbox_ps.json');
    $S['stopped'] = $failed;
    $S['state'] = $failed ? 'error' : 'done';
    $S['msg'] = $failed ? sx_tr('sdr.failed', ['l' => implode(', ', $failed)]) : sx_tr('sdr.started');
    $S['finished'] = time(); sd_status_write($S);
    exit(0);
}

// ---- stop -----------------------------------------------------------------------------------------
sd_log($by === 'page' ? 'start' : 'start (' . ($by === 'discord' ? 'from Discord, power outage' : 'UPS: before the NUT shutdown') . ')');
$cts = []; $ps = $d !== '' ? sx_ps(true) : [];
foreach ($ps as $c) { if ($c['state'] === 'running') { $cts[] = $c; } }
// an earlier stop run that was cancelled or failed: what it stopped and is still stopped stays on the list, so Start them again
// starts it too (item 104: a second Stop forgot qBittorrent)
foreach ((array)($prev['stopped'] ?? []) as $n) { if (sx_valid_name($n) && isset($ps[$n]) && $ps[$n]['state'] !== 'running') { $S['stopped'][] = $n; } }
if ($S['stopped']) { sd_log('still stopped from the earlier run: ' . implode(', ', $S['stopped'])); }
$qb = array_values(array_filter($cts, 'sd_is_qb'));
$rest = $mode === 'stopqb' ? [] : array_values(array_filter($cts, function ($c) { return !sd_is_qb($c); }));
$err = [];

foreach ($qb as $c) {
    if (sd_cancelled()) { break; }
    sd_log('stopping ' . $c['name'] . ' (it may take up to ' . SD_QB_WAIT . ' s to close its files)');
    sd_mark_userstop([$c['name']]);
    $t0 = time();
    [$rc, $out] = sx_run(escapeshellarg($d) . ' stop -t ' . SD_QB_WAIT . ' ' . escapeshellarg($c['name']), SD_QB_WAIT + 30);
    if ($rc === 0) { $S['stopped'][] = $c['name']; sd_log('stopped ' . $c['name'] . ' in ' . (time() - $t0) . ' s'); }
    else { $err[] = $c['name']; sd_log('FAILED to stop ' . $c['name'] . ': ' . substr(trim($out), 0, 200)); }
}
if ($rest && !sd_cancelled()) {
    $names = array_column($rest, 'name');
    sd_log('stopping ' . count($names) . ' other container(s) together: ' . implode(', ', $names));
    sd_mark_userstop($names);
    $t0 = time();
    [$rc, $out] = sx_run(escapeshellarg($d) . ' stop -t ' . SD_CT_WAIT . ' ' . implode(' ', array_map('escapeshellarg', $names)), SD_CT_WAIT + 60);
    $now = sx_ps(true);
    foreach ($names as $n) {
        if (($now[$n]['state'] ?? '') !== 'running') { $S['stopped'][] = $n; }
        else { $err[] = $n; sd_log('still running: ' . $n); }
    }
    sd_log('containers stopped in ' . (time() - $t0) . ' s');
}
@unlink('/tmp/sysbox_ps.json');

$vms = (sd_cancelled() || $mode === 'stopqb') ? [] : sd_vms();
if ($vms) {
    $v = sd_virsh();
    foreach ($vms as $vm) { sd_log('shutting down the VM ' . $vm); sx_run(escapeshellarg($v) . ' shutdown ' . escapeshellarg($vm), 20); }
    $t0 = time();
    while (time() - $t0 < SD_VM_WAIT && !sd_cancelled()) {
        $left = array_intersect($vms, sd_vms());
        if (!$left) { break; }
        sleep(5);
    }
    $left = array_values(array_intersect($vms, sd_vms()));
    foreach ($vms as $vm) { if (!in_array($vm, $left, true)) { $S['vms'][] = $vm; } }
    if ($left) { $err[] = 'VM ' . implode(', ', $left); sd_log('VM(s) still running after ' . SD_VM_WAIT . ' s: ' . implode(', ', $left) . ' (Unraid will force them off during the shutdown)'); }
    else { sd_log('VM(s) shut down: ' . implode(', ', $vms)); }
}

$S['finished'] = time();
if (sd_cancelled()) { $S['state'] = 'cancelled'; $S['msg'] = sx_tr('sdr.cancelled'); }
elseif ($err) { $S['state'] = 'error'; $S['msg'] = sx_tr('sdr.not_stopped', ['l' => implode(', ', $err)]); }
else { $S['state'] = 'done'; $S['msg'] = $S['vms'] ? sx_tr('sdr.done_vm', ['n' => count($S['stopped']), 'v' => count($S['vms'])]) : sx_tr('sdr.done', ['n' => count($S['stopped'])]); }
sd_log($S['msg']);
// a stop from Discord during an outage: the server goes off now, the way the NUT plugin does it (Unraid stops the array)
if ($off && $S['state'] !== 'cancelled') {
    sd_log('powering off');
    $S['state'] = 'off'; $S['msg'] = sx_tr('sdr.off'); sd_status_write($S);
    $po = getenv('SX_SD_POWEROFF');
    @exec(($po !== false && $po !== '' ? $po : '/sbin/poweroff') . ' >/dev/null 2>&1');
}
@unlink(SD_CANCEL);
