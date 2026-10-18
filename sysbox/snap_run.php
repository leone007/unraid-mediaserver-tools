<?php
// Snapshots worker (Sysbox). php snap_run.php daily   (cron every 5 minutes: makes the day's snapshots once, after the set time)
//                           php snap_run.php restore <dataset> <snapshot> <container>   (started by snap.php after a click)
if (PHP_SAPI !== 'cli') { exit(1); } // cron and the page's worker only, not a web page
require_once __DIR__ . '/snap_lib.php';
require_once __DIR__ . '/discord.php';
@set_time_limit(0);

$lk = @fopen(snp_lock_file(), 'c');
if (!$lk || !flock($lk, LOCK_EX | LOCK_NB)) { exit(0); }
$mode = $argv[1] ?? '';

if ($mode === 'daily') {
    $c = snp_cfg();
    if (empty($c['on']) || !$c['datasets']) { exit(0); }
    $st = json_decode((string)@file_get_contents(snp_state_file()), true) ?: [];
    $today = date('Y-m-d');
    if (($st['day'] ?? '') === $today) { exit(0); }
    if ((int)date('G') * 60 + (int)date('i') < (int)$c['hour'] * 60 + (int)$c['min']) { exit(0); }
    $st['day'] = $today; snp_json_write(snp_state_file(), $st); // once a day, also when something below fails
    $name = 'auto-' . $today; $bad = [];
    foreach ($c['datasets'] as $ds) {
        if (!snp_dataset($ds)) { $bad[] = $ds . ': not found'; snp_log($ds . ': not found, no snapshot'); continue; }
        $fill = snp_pool_fill($ds);
        if ($fill >= 0 && $fill > (int)$c['maxfill']) { $bad[] = $ds . ': pool ' . $fill . ' % full'; snp_log($ds . ': pool ' . $fill . ' % full (limit ' . (int)$c['maxfill'] . ' %), no snapshot today'); }
        else {
            $e = snp_make($ds, $name);
            if ($e === '' || $e === 'already exists') { snp_log($ds . '@' . $name . ($e === '' ? ' made' : ' already exists')); } else { $bad[] = $ds . ': ' . $e; snp_log($ds . '@' . $name . ' FAILED: ' . $e); }
        }
        // the daily ones beyond "keep" go; a manual snapshot (any other name) is never removed here
        $autos = array_values(array_filter(snp_snaps($ds), function ($s) { return $s['auto']; }));
        for ($i = 0; $i < count($autos) - max(1, (int)$c['keep']); $i++) {
            $e = snp_destroy($ds, $autos[$i]['name']);
            snp_log($ds . '@' . $autos[$i]['name'] . ($e === '' ? ' removed (older than ' . (int)$c['keep'] . ' days)' : ' could not be removed: ' . $e));
        }
    }
    if ($bad) { snp_discord('rd', 'Sysbox: daily snapshot failed', implode("\n", $bad)); }
    exit(0);
}

if ($mode === 'restore') {
    [$dsn, $snap, $ctr] = [$argv[2] ?? '', $argv[3] ?? '', $argv[4] ?? ''];
    $S = ['state' => 'running', 'ds' => $dsn, 'snap' => $snap, 'ctr' => $ctr, 'started' => time(), 'log' => [], 'msg' => ''];
    $say = function ($m) use (&$S) { $S['log'][] = date('H:i:s') . ' ' . $m; snp_json_write(snp_run_file(), $S); };
    $end = function ($state, $msg) use (&$S, $say) { $S['state'] = $state; $S['msg'] = $msg; $S['finished'] = time(); $say($msg); snp_log($msg); if ($state !== 'done') { snp_discord('a', 'Sysbox: container restore stopped', $msg); } exit(0); };
    $ds = snp_dataset($dsn);
    if (!$ds || !snp_name_ok($snap) || !sx_valid_name($ctr)) { $end('error', 'restore refused: bad dataset, snapshot or container name'); }
    $have = false; foreach (snp_snaps($dsn) as $s) { if ($s['name'] === $snap) { $have = true; } }
    if (!$have) { $end('error', 'restore refused: ' . $dsn . '@' . $snap . ' does not exist'); }
    $ct = null; foreach (snp_containers($ds) as $x) { if ($x['name'] === $ctr) { $ct = $x; } }
    if (!$ct) { $end('error', 'restore refused: ' . $ctr . ' has no folder in ' . $dsn); }
    // 1. a safety snapshot of now, so the restore can be undone the same way
    $safe = 'before-restore-' . $ctr . '-' . date('Y-m-d-Hi');
    $e = snp_make($dsn, $safe);
    if ($e !== '') { $end('error', 'restore of ' . $ctr . ' stopped before anything changed: the safety snapshot failed (' . $e . ')'); }
    $say('safety snapshot ' . $dsn . '@' . $safe . ' made');
    // 2. stop only this container
    $d = sx_docker(); $wasRunning = $ct['state'] === 'running';
    if ($wasRunning) {
        snp_run(escapeshellarg($d) . ' stop -t 60 ' . escapeshellarg($ctr), $rc);
        if ($rc !== 0) { $end('error', 'restore of ' . $ctr . ' stopped: the container did not stop; nothing was changed (safety snapshot ' . $safe . ' kept)'); }
        $say($ctr . ' stopped');
    }
    // 3. each folder exactly as in the snapshot (newer files removed)
    $r = snp_bin('rsync'); $fail = [];
    foreach ($ct['dirs'] as $dir) {
        $from = snp_snapdir($ds, $snap, $dir);
        if (!is_dir($from)) { $say('not in the snapshot, left as it is: ' . $dir); continue; }
        $o = snp_run(escapeshellarg($r) . ' -aHAX --checksum --delete ' . // checksum: a file changed within the same second at the same size is caught too
             escapeshellarg($from . '/') . ' ' . escapeshellarg($dir . '/'), $rc);
        if ($rc === 0) { $say('restored ' . $dir); } else { $fail[] = $dir; $say('FAILED ' . $dir . ': ' . substr(trim(implode(' ', $o)), 0, 200)); }
    }
    // 4. start it again
    if ($wasRunning) { snp_run(escapeshellarg($d) . ' start ' . escapeshellarg($ctr), $rc); $say($rc === 0 ? $ctr . ' started' : $ctr . ' did NOT start'); }
    if ($fail) { $end('error', $ctr . ' restored from ' . $snap . ' only in part (' . implode(', ', $fail) . ' failed); undo: restore ' . $ctr . ' from ' . $safe); }
    $end('done', $ctr . ' restored from ' . $dsn . '@' . $snap . '; to undo: restore ' . $ctr . ' from ' . $safe);
}
exit(1);
