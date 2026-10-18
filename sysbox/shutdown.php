<?php
// Web endpoint of Safe shutdown. POST op=state|stop|start|cancel|kill
// The shutdown or restart itself is not here: the page posts to Unraid's own /webGui/include/Boot.php.
require_once __DIR__ . '/shutdown_lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { sx_json(['ok' => false, 'error' => 'method'], 405); }
sx_guard();
$op = (string)($_POST['op'] ?? 'state');

function sd_spawn($mode) {
    @exec('setsid nohup ' . escapeshellarg(sx_php()) . ' ' . escapeshellarg(__DIR__ . '/shutdown_run.php') . ' ' . $mode . ' >/dev/null 2>&1 &');
}

// The stopped list of the last run, compared with Docker now: a container started again outside this page (Unraid's
// autostart after an array restart, the Docker page) or removed since then is not offered for Start them again.
function sd_prune($run, $all) {
    if (!$all || ($run['state'] ?? '') === 'running' || empty($run['stopped'])) { return $run; }   // no Docker answer: keep the list
    $keep = []; $back = []; $gone = [];
    foreach ((array)$run['stopped'] as $n) {
        $st = $all[$n]['state'] ?? null;
        if ($st === null) { $gone[] = $n; } elseif ($st === 'running') { $back[] = $n; } else { $keep[] = $n; }
    }
    $run['stopped'] = $keep; $run['back'] = $back; $run['gone'] = $gone;
    return $run;
}

if ($op === 'state') {
    $run = sd_status();
    $running = sd_running();
    if (!$running && ($run['state'] ?? '') === 'running' && time() - (int)($run['started'] ?? 0) > 20) { $run['state'] = 'error'; $run['msg'] = 'The worker stopped unexpectedly.'; }
    $all = sx_ps(true);
    if (!$running) { $run = sd_prune($run, $all); }
    $cts = [];
    foreach ($all as $c) { if ($c['state'] === 'running') { $cts[] = ['name' => $c['name'], 'qb' => sd_is_qb($c)]; } }
    sx_json(['ok' => true, 'checks' => sd_checks(), 'containers' => $cts, 'vms' => sd_vms(), 'holders' => sd_holders(), 'run' => $run, 'running' => $running, 'now' => time()]);
}

if ($op === 'stop' || $op === 'start') {
    if (sd_running()) { sx_json(['ok' => false, 'error' => 'A stop or start run is already going.']); }
    $prev = $op === 'start' ? sd_prune(sd_status(), sx_ps(true)) : sd_status();
    if ($op === 'start' && !($prev['stopped'] ?? [])) { sx_json(['ok' => false, 'error' => 'There is nothing to start again.']); }
    sd_status_write(['state' => 'running', 'mode' => $op, 'started' => time(), 'log' => ['starting...'], 'stopped' => (array)($prev['stopped'] ?? []), 'vms' => [], 'msg' => '']);
    sd_spawn($op);
    sx_json(['ok' => true]);
}

if ($op === 'cancel') {
    if (sd_running()) { @file_put_contents(SD_CANCEL, '1'); }
    sx_json(['ok' => true]);
}

// End processes that still use the disks. Only processes that are in a fresh scan, with the same start time as shown on
// the page (so a reused process id is never hit) and that are not protected. TERM asks them to quit, KILL forces it.
if ($op === 'kill') {
    $sig = (string)($_POST['sig'] ?? '');
    if (!in_array($sig, ['TERM', 'KILL'], true)) { sx_json(['ok' => false, 'error' => 'unknown signal'], 400); }
    $want = json_decode((string)($_POST['pids'] ?? ''), true);
    if (!is_array($want) || !$want) { sx_json(['ok' => false, 'error' => 'Nothing is selected.'], 400); }
    $now = [];
    foreach (sd_holders() as $h) { $now[$h['pid']] = $h; }
    $done = []; $skip = [];
    foreach ($want as $w) {
        $pid = (int)($w['pid'] ?? 0); $start = (string)($w['start'] ?? '');
        $h = $now[$pid] ?? null;
        if (!$h) { $skip[] = $pid . ' (no longer uses the disks)'; continue; }
        if ($h['start'] !== $start) { $skip[] = $pid . ' (a different process now)'; continue; }
        if ($h['protected'] !== '') { $skip[] = $pid . ' (' . $h['protected'] . ')'; continue; }
        $ok = function_exists('posix_kill') ? posix_kill($pid, $sig === 'KILL' ? 9 : 15) : (sx_run('kill -' . $sig . ' ' . $pid, 5)[0] === 0);
        if ($ok) { $done[] = $pid . ' ' . $h['comm']; } else { $skip[] = $pid . ' (the signal failed)'; }
    }
    @exec('logger -t sysbox ' . escapeshellarg('Safe shutdown: ' . $sig . ' sent to ' . (implode(', ', $done) ?: 'nothing')));
    sx_json(['ok' => true, 'done' => $done, 'skipped' => $skip]);
}

sx_json(['ok' => false, 'error' => 'unknown action'], 400);
