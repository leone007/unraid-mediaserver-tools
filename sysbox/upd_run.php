<?php
// Update manager worker (Sysbox, item 61).
//   php upd_run.php daily            cron every 5 minutes: the day's scan once after the set time, Discord when something waits
//   php upd_run.php scan [container] the page's "Check now" (all, or one container after its update)
//   php upd_run.php ask <container>  Ask Claude (read-only tools in the claude container); the answer is kept per version
//   php upd_run.php update <container>  after a click: ZFS snapshot of its data, stop, Unraid's update_container, self-test
//   php upd_run.php updall safe|all  Update all: the green (or green and amber) ones one by one, stops at the first failure
//   php upd_run.php selftest         the read-only checks of our connections
if (PHP_SAPI !== 'cli') { exit(1); } // cron and the page's worker only, not a web page
require_once __DIR__ . '/upd_lib.php';
@set_time_limit(0);
$mode = $argv[1] ?? ''; $arg = (string)($argv[2] ?? '');
if (!in_array($mode, ['daily', 'scan', 'ask', 'update', 'updall', 'selftest'], true)) { exit(1); }
if ($arg !== '' && !sx_valid_name($arg)) { exit(1); }

// one job at a time per kind (scan and daily share a lock); Activity shows the jobs from the run file
$lk = @fopen(upd_lock_file($mode === 'daily' ? 'scan' : ($mode === 'updall' ? 'update' : $mode)), 'c');
if (!$lk || !flock($lk, LOCK_EX | LOCK_NB)) { exit(0); }

// the run file is shared by the workers: read-change-write under its own lock
function upd_run_edit($fn) {
    $l = @fopen(upd_lock_file('runfile'), 'c'); if ($l) { flock($l, LOCK_EX); }
    $R = upd_json(upd_run_file(), ['jobs' => []]); $out = $fn($R); upd_write(upd_run_file(), $R);
    if ($l) { flock($l, LOCK_UN); fclose($l); }
    return $out;
}
function upd_job_start($kind, $name, $text) {
    return upd_run_edit(function (&$R) use ($kind, $name, $text) {
    $id = $kind . ':' . $name . ':' . time();
    $R['jobs'][$id] = ['kind' => $kind, 'name' => $name, 'text' => $text, 'state' => 'running', 'started' => time(), 'log' => []];
    // keep the running ones and the finished ones of the last 24 hours
    foreach ($R['jobs'] as $k => $j) { if ($j['state'] !== 'running' && time() - (int)($j['finished'] ?? 0) > 86400) { unset($R['jobs'][$k]); } }
    return $id;
    });
}
function upd_job($id, $set = [], $line = '') {
    upd_run_edit(function (&$R) use ($id, $set, $line) {
        if (!isset($R['jobs'][$id])) { return; }
        $R['jobs'][$id] = $set + $R['jobs'][$id];
        if ($line !== '') { $R['jobs'][$id]['log'][] = date('H:i:s') . ' ' . $line; }
        if (isset($set['state']) && $set['state'] !== 'running') { $R['jobs'][$id]['finished'] = time(); }
    });
}
function upd_summary($scan) {
    $n = ['green' => [], 'amber' => [], 'red' => []];
    foreach ($scan['rows'] ?? [] as $r) { if ($r['state'] === 'update') { $n[$r['risk']][] = $r['name']; } }
    return $n;
}

if ($mode === 'daily') {
    $c = upd_cfg();
    if (empty($c['on'])) { exit(0); }
    $st = upd_json(upd_state_file(), []); $today = date('Y-m-d');
    // the result lives in /tmp: after a reboot or a Sysbox install it is gone, and the day's check runs again (seen 2026-10-19)
    $gone = ($st['day'] ?? '') === $today && !is_file(upd_scan_file());
    if (($st['day'] ?? '') === $today && !$gone) { exit(0); }
    if (!$gone && (int)date('G') * 60 + (int)date('i') < (int)$c['hour'] * 60 + (int)$c['min']) { exit(0); }
    $st['day'] = $today; upd_write(upd_state_file(), $st);
    $mode = 'scan'; $daily = true;
}

if ($mode === 'scan') {
    $id = upd_job_start('scan', $arg, $arg !== '' ? 'Checking ' . $arg : 'Checking containers, Unraid OS and plugins');
    $s = upd_scan($arg !== '' ? $arg : null);
    if (empty($s['ok'])) { upd_job($id, ['state' => 'error', 'text' => sx_tr('uj.check_failed', ['e' => ($s['error'] ?? '?')])]); upd_log('check failed: ' . ($s['error'] ?? '?')); exit(0); }
    $n = upd_summary($s); $tot = count($n['green']) + count($n['amber']) + count($n['red']);
    $msg = $tot ? sx_tr('uj.summary', ['n' => $tot, 'g' => count($n['green']), 'a' => count($n['amber']), 'r' => count($n['red'])]) : sx_tr('uj.current');
    if (!empty($s['os']['new'])) { $msg .= '; Unraid ' . $s['os']['new'] . ' available'; }
    upd_job($id, ['state' => 'done', 'text' => ($arg !== '' ? sx_tr('uj.checked_one', ['n' => $arg, 'm' => $msg]) : sx_tr('uj.checked', ['m' => $msg]))]);
    upd_log('check' . ($arg !== '' ? ' ' . $arg : '') . ': ' . $msg);
    // a new Unraid OS version: Claude is asked about it by itself, once per version (owner's rule, 2026-10-15)
    $o = $s['os'] ?? []; $c = upd_cfg();
    if (!empty($o['new']) && !empty($o['key']) && !empty($c['claude']) && !upd_running('ask')) {
        $cache = upd_json(upd_cache_file(), []); $st = upd_json(upd_state_file(), []);
        if (empty($cache[$o['key']]['claude']) && empty($st['os_asked'][$o['key']])) {
            $st['os_asked'] = [$o['key'] => time()]; upd_write(upd_state_file(), $st);
            upd_log('Unraid ' . $o['new'] . ': Claude is asked by itself');
            upd_spawn(['ask', 'unraid-os']);
        }
    }
    // Discord after the daily check: only something new since the last message (per container and image)
    if (!empty($daily)) {
        $c = upd_cfg(); $st = upd_json(upd_state_file(), []); $told = $st['told'] ?? []; $new = [];
        foreach ($s['rows'] as $r) {
            if ($r['state'] !== 'update' || isset($told[$r['key']])) { continue; }
            if ($c['discord'] === 'all' || ($c['discord'] === 'warn' && $r['risk'] !== 'green')) { $new[] = $r; }
            $told[$r['key']] = time();
        }
        foreach ($told as $k => $t) { if (time() - $t > 30 * 86400) { unset($told[$k]); } }
        $st['told'] = $told; upd_write(upd_state_file(), $st);
        if ($new) {
            $lines = []; $lv = 'g';
            foreach ($new as $r) { $lines[] = ['green' => '🟢', 'amber' => '🟡', 'red' => '🔴'][$r['risk']] . ' **' . $r['name'] . '** ' . ($r['ver'] ?: '?') . ' → ' . ($r['rver'] ?: '?') . ' (' . $r['kind'] . ($r['deps'] ? '; ' . implode(', ', $r['deps']) : '') . ')'; if ($r['risk'] === 'red') { $lv = 'rd'; } elseif ($r['risk'] === 'amber' && $lv !== 'rd') { $lv = 'a'; } }
            upd_discord($lv, 'Update manager: ' . count($new) . ' new update' . (count($new) > 1 ? 's' : ''), implode("\n", $lines) . "\n\nSettings → Utilities → Update manager");
        }
    }
    exit(0);
}

if ($mode === 'selftest') {
    $id = upd_job_start('selftest', '', 'Self-test of our connections');
    $t = upd_selftest(); $bad = array_filter($t['tests'], function ($x) { return !$x['ok']; });
    upd_job($id, ['state' => $bad ? 'error' : 'done', 'text' => sx_tr('uj.selftest', ['ok' => count($t['tests']) - count($bad), 'n' => count($t['tests'])]) . ($bad ? ' (' . sx_tr('uj.failed_list', ['l' => implode(', ', array_column($bad, 'name'))]) . ')' : '')]);
    exit(0);
}

if ($mode === 'ask') {
    $id = upd_job_start('ask', $arg, sx_tr('uj.claude_ask', ['n' => $arg]));
    $e = upd_ask($arg);
    upd_job($id, ['state' => $e === '' ? 'done' : 'error', 'text' => $e === '' ? sx_tr('uj.claude_ok', ['n' => $arg]) : sx_tr('uj.claude_err', ['n' => $arg, 'e' => $e])]);
    exit(0);
}

// one container: snapshot, stop, Unraid's update, start, self-test, fresh check; [state, message]. $dc: a Discord message
// (the single update from its row); Update all sends one only when it stops.
function upd_update_one($name, $dc = true) {

    $cfg = upd_cfg();
    $scan = upd_json(upd_scan_file(), []); $row = null;
    foreach ($scan['rows'] ?? [] as $r) { if ($r['name'] === $name) { $row = $r; } }
    $id = upd_job_start('update', $name, sx_tr('uj.updating', ['n' => $name]));
    $end = function ($state, $msg) use ($id, $name, $dc) {
        upd_job($id, ['state' => $state, 'text' => $msg], $msg); upd_log($msg);
        if ($dc) { upd_discord($state === 'done' ? 'g' : 'rd', 'Update manager: ' . $name . ($state === 'done' ? ' updated' : ' update failed'), $msg); }
        return [$state, $msg];
    };
    if (!$row || $row['state'] !== 'update') { return $end('error', $name . ': no waiting update (check again first)'); }
    $d = sx_docker(); if ($d === '') { return $end('error', $name . ': Docker is not running'); }
    $tpl = '/boot/config/plugins/dockerMan/templates-user/my-' . $name . '.xml';
    if (!is_file($tpl)) { return $end('error', $name . ': no Unraid template (my-' . $name . '.xml), update it on the Docker page'); }
    $script = '/usr/local/emhttp/plugins/dynamix.docker.manager/scripts/update_container';
    if (!is_file($script)) { return $end('error', $name . ': Unraid\'s update_container script is missing'); }
    // 1. a ZFS snapshot of the dataset that holds its folders (Snapshots page: restore this container from it)
    $snap = '';
    if (!empty($cfg['backup']) && is_file(__DIR__ . '/snap_lib.php')) {
        require_once __DIR__ . '/snap_lib.php';
        foreach (snp_datasets() as $ds) {
            $have = false; foreach (snp_containers($ds) as $x) { if ($x['name'] === $name) { $have = true; } }
            if (!$have) { continue; }
            $sn = 'before-update-' . $name . '-' . date('Y-m-d-Hi');
            $e = snp_make($ds['name'], $sn);
            if ($e !== '') { return $end('error', $name . ': update stopped before anything changed, the snapshot failed (' . $e . ')'); }
            $snap = $ds['name'] . '@' . $sn; upd_job($id, [], 'snapshot ' . $snap . ' made'); snp_log($snap . ' made before the update');
        }
        if ($snap === '') { upd_job($id, [], 'no ZFS dataset holds its folders: no snapshot'); }
    }
    // 2. stop it with enough time (qBittorrent writes its resume data; the Unraid script would give 10 seconds)
    $was = trim(upd_sh(escapeshellarg($d) . ' inspect -f {{.State.Running}} ' . escapeshellarg($name), 10)) === 'true';
    if ($was) {
        $wait = preg_match('/qbittorrent/i', $row['image']) ? 300 : 60;
        upd_job($id, [], 'stopping ' . $name . ' (up to ' . $wait . ' s)');
        [$rc] = sx_run(escapeshellarg($d) . ' stop -t ' . $wait . ' ' . escapeshellarg($name), $wait + 30);
        if ($rc !== 0) { return $end('error', $name . ': did not stop, nothing was changed' . ($snap ? ' (snapshot ' . $snap . ' kept)' : '')); }
    }
    // 3. Unraid's own update: pull, recreate from the template, start again when it was running
    upd_job($id, [], 'pulling ' . $row['image'] . ' and recreating ' . $name);
    [$rc, $o] = sx_run(escapeshellarg(sx_php()) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg(rawurlencode($name)) . ($was ? ' ca_docker_run_override' : ''), 1800);
    $now = upd_containers(); $c = null; foreach ((array)$now as $x) { if ($x['name'] === $name) { $c = $x; } }
    if (!$c) { return $end('error', $name . ': the container is missing after the update; recreate it from the Docker page (Add container, its template)' . ($snap ? '; data snapshot ' . $snap : '')); }
    if ($was && !$c['running']) { sx_run(escapeshellarg($d) . ' start ' . escapeshellarg($name), 60); }
    $new = in_array($c['id'], $row['rids'] ?? [$row['rid']], true) || $c['id'] !== $row['id'];
    upd_job($id, [], $new ? 'now on ' . ($c['ver'] ?: substr($c['id'], 7, 12)) : 'the image did not change (' . ($c['ver'] ?: '?') . ')');
    // 4. self-test of our connections, then a fresh check of this container
    $tmsg = '';
    if (!empty($cfg['selftest'])) {
        if ($was) { sleep(20); }   // the program needs a moment after the start
        $t = upd_selftest(); $bad = array_filter($t['tests'], function ($x) { return !$x['ok']; });
        $tmsg = '; self-test ' . (count($t['tests']) - count($bad)) . '/' . count($t['tests']) . ($bad ? ' (' . implode(', ', array_column($bad, 'name')) . ' FAILED)' : ' passed');
    }
    upd_scan($name);
    $msg = $name . ' ' . ($new ? 'updated: ' . ($row['ver'] ?: '?') . ' → ' . ($c['ver'] ?: '?') : 'update ran but the image is the same') . $tmsg . ($snap ? '; data snapshot ' . $snap : '');
    return $end($new && strpos($tmsg, 'FAILED') === false ? 'done' : 'error', $msg);
}

if ($mode === 'update') { upd_update_one($arg); exit(0); }

// Update all (item 83): the safe ones (green), or the safe and the look-first ones (amber), one after the other; never the
// red ones (they go from their own row) and never the claude container. Stops at the first failure (owner, 2026-10-19).
if ($mode === 'updall') {
    $scan = upd_json(upd_scan_file(), []); $want = $arg === 'all' ? ['green', 'amber'] : ['green'];
    $list = array_values(array_filter($scan['rows'] ?? [], function ($r) use ($want) { return $r['state'] === 'update' && in_array($r['risk'], $want, true) && $r['name'] !== 'claude'; }));
    usort($list, function ($a, $b) { return [$a['risk'] === 'green' ? 0 : 1, strtolower($a['name'])] <=> [$b['risk'] === 'green' ? 0 : 1, strtolower($b['name'])]; });
    $names = array_column($list, 'name'); $n = count($names);
    $id = upd_job_start('updall', $arg, sx_tr('uj.all_start', ['n' => $n]));
    if (!$n) { upd_job($id, ['state' => 'done', 'text' => sx_tr('uj.all_none')]); exit(0); }
    upd_job($id, ['queue' => $names]);
    $done = [];
    foreach ($names as $k => $name) {
        upd_job($id, ['text' => sx_tr('uj.all_now', ['n' => $n, 'i' => $k + 1, 'c' => $name]), 'now' => $name, 'ok' => $done]);
        [$state, $msg] = upd_update_one($name, false);
        if ($state !== 'done') {
            $left = array_slice($names, $k + 1);
            $txt = sx_tr('uj.all_stopped', ['c' => $name, 'i' => $k + 1, 'n' => $n, 'ok' => count($done), 'left' => count($left)]) . ' ' . $msg;
            upd_job($id, ['state' => 'error', 'text' => $txt, 'now' => '', 'ok' => $done, 'failed' => $name, 'left' => $left], $txt); upd_log($txt);
            upd_discord('rd', 'Update manager: Update all stopped at ' . $name, $txt . ($left ? "\nNot started: " . implode(', ', $left) : ''));
            exit(0);
        }
        $done[] = $name;
    }
    $txt = sx_tr('uj.all_done', ['n' => $n]);
    upd_job($id, ['state' => 'done', 'text' => $txt, 'now' => '', 'ok' => $done], $txt); upd_log($txt . ': ' . implode(', ', $done));
}
exit(0);
