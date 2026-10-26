<?php
// Snapshots worker (Sysbox). php snap_run.php daily   (cron every 5 minutes: makes the day's snapshots once, after the set time)
//                           php snap_run.php restore <dataset> <snapshot|copy:date|off:date-id> <container>   (started by snap.php after a click)
//                           php snap_run.php copy | offsite | fetch <id>   (Copy now, Off-site now, Download: clicks on the page)
if (PHP_SAPI !== 'cli') { exit(1); } // cron and the page's worker only, not a web page
require_once __DIR__ . '/snap_lib.php';
require_once __DIR__ . '/copy_lib.php';
require_once __DIR__ . '/discord.php';
@set_time_limit(0);

$lk = @fopen(snp_lock_file(), 'c');
if (!$lk || !flock($lk, LOCK_EX | LOCK_NB)) { exit(0); }
$mode = $argv[1] ?? '';

// a copy, off-site copy or download as a job the page's Activity follows
function cp_jobrun($kind, $fn) {
    cp_job(['kind' => $kind, 'state' => 'running', 'started' => time(), 'finished' => 0, 'pct' => 0, 'msg' => '', 'title' => '']);
    $msg = ''; $ok = $fn($msg);
    cp_job(['state' => $ok ? 'done' : 'error', 'finished' => time(), 'pct' => 100, 'msg' => $ok ? sx_tr(['copy' => 'cpj.done_copy', 'off' => 'cpj.done_off', 'fetch' => 'cpj.done_fetch'][$kind]) : cp_t($msg)]);
    return [$ok, $msg];
}
// the array copy of every chosen dataset from its newest snapshot (daily: the one just made)
function cp_copy_all($c) {
    return cp_jobrun('copy', function (&$msg) use ($c) {
        $bad = [];
        foreach ($c['datasets'] as $ds) {
            $sn = snp_snaps($ds); $snap = $sn ? end($sn) : null;
            if (!$snap) { $bad[] = ['cpd.no_snap', ['ds' => $ds]]; continue; }
            $m = ''; if (!cp_copy_one($c, $ds, $snap, $m)) { $bad[] = $m; }
        }
        if ($bad) { $msg = $bad; cp_state_set('err', cp_t($bad)); snp_log('copy FAILED: ' . str_replace("\n", '; ', cp_t($bad))); return false; }
        return true;
    });
}
function cp_off_all($c) {
    cp_state_set('off_try', time());
    return cp_jobrun('off', function (&$msg) use ($c) { return cp_off_run($c, $msg); });
}

if ($mode === 'copy' || $mode === 'offsite') {
    $c = snp_cfg();
    [$ok, $msg] = $mode === 'copy' ? cp_copy_all($c) : cp_off_all($c);
    if (!$ok) { snp_discord('rd', sx_trd($mode === 'copy' ? 'cpd.dc_copy' : 'cpd.dc_off'), cp_t($msg, true)); }
    exit(0);
}
if ($mode === 'fetch') {
    $c = snp_cfg(); $id = (string)($argv[2] ?? '');
    if (!preg_match('/^[0-9a-f]{8}$/', $id)) { exit(1); }
    cp_jobrun('fetch', function (&$msg) use ($c, $id) { return cp_off_fetch($c, $id, $msg); });
    exit(0);
}

if ($mode === 'daily') {
    $c = snp_cfg();
    if (empty($c['on']) || !$c['datasets']) { exit(0); }
    $st = json_decode((string)@file_get_contents(snp_state_file()), true) ?: [];
    $today = date('Y-m-d'); $now = (int)date('G') * 60 + (int)date('i');
    if (($st['day'] ?? '') === $today) {
        // after the day's snapshot: the off-site copy when it is due (the set day and time, or more than 8 days since the last try)
        $cs = cp_state();
        if ($c['off'] !== 'off' && cp_off_ok($c) && $now >= (int)$c['off_hour'] * 60 + (int)$c['off_min'] && ($cs['off_week'] ?? '') !== date('o-W')
            && ((int)date('N') === (int)$c['off_day'] || time() - (int)($cs['off_try'] ?? 0) > 8 * 86400)) {
            cp_state_set('off_week', date('o-W'));
            [$ok, $msg] = cp_off_all($c);
            if (!$ok) { snp_discord('rd', sx_trd('cpd.dc_off'), cp_t($msg, true)); }
        }
        // a copy older than the set days: one Discord message a day
        $cs = cp_state(); $old = [];
        if (!empty($c['copy']) && (int)$c['stale'] > 0 && time() - (int)($cs['ok'] ?? time()) > (int)$c['stale'] * 86400) { $old[] = sx_trd('cpd.stale_copy', ['n' => (int)floor((time() - (int)$cs['ok']) / 86400)]); }
        if ($c['off'] !== 'off' && (int)$c['stale'] > 0 && !empty($cs['off_ok']) && time() - (int)$cs['off_ok'] > (7 + (int)$c['stale']) * 86400) { $old[] = sx_trd('cpd.stale_off', ['n' => (int)floor((time() - (int)$cs['off_ok']) / 86400)]); }
        if ($old && ($cs['stale_day'] ?? '') !== $today && $now >= (int)$c['hour'] * 60 + (int)$c['min'] + 120) { cp_state_set('stale_day', $today); snp_discord('a', sx_trd('cpd.dc_stale'), implode("\n", $old)); }
        exit(0);
    }
    if ($now < (int)$c['hour'] * 60 + (int)$c['min']) { exit(0); }
    $st['day'] = $today; snp_json_write(snp_state_file(), $st); // once a day, also when something below fails
    $name = 'auto-' . $today; $bad = [];
    foreach ($c['datasets'] as $ds) {
        if (!snp_dataset($ds)) { $bad[] = sx_trd('sr.daily_nf', ['ds' => $ds]); snp_log($ds . ': not found, no snapshot'); continue; }
        $fill = snp_pool_fill($ds);
        if ($fill >= 0 && $fill > (int)$c['maxfill']) { $bad[] = sx_trd('sr.daily_full', ['ds' => $ds, 'p' => $fill]); snp_log($ds . ': pool ' . $fill . ' % full (limit ' . (int)$c['maxfill'] . ' %), no snapshot today'); }
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
    if ($bad) { snp_discord('rd', sx_trd('sr.dc_daily'), implode("\n", $bad)); }
    // then the copy to the array (item 105)
    if (!empty($c['copy'])) { [$ok, $msg] = cp_copy_all($c); if (!$ok) { snp_discord('rd', sx_trd('cpd.dc_copy'), cp_t($msg, true)); } }
    exit(0);
}

if ($mode === 'restore') {
    [$dsn, $snap, $ctr] = [$argv[2] ?? '', $argv[3] ?? '', $argv[4] ?? ''];
    $S = ['state' => 'running', 'ds' => $dsn, 'snap' => $snap, 'ctr' => $ctr, 'started' => time(), 'log' => [], 'msg' => ''];
    // texts from the dictionary: the page in its language, Discord in the Discord language (item 104)
    $say = function ($k, $v = []) use (&$S) { $S['log'][] = date('H:i:s') . ' ' . sx_tr($k, $v); snp_json_write(snp_run_file(), $S); };
    $end = function ($state, $k, $v = []) use (&$S, $say) { $msg = sx_tr($k, $v); $S['state'] = $state; $S['msg'] = $msg; $S['finished'] = time(); $say($k, $v); snp_log($msg); if ($state !== 'done') { snp_discord('a', sx_trd('sr.dc_restore'), sx_trd($k, $v)); } exit(0); };
    $ds = snp_dataset($dsn);
    if (!$ds || !snp_name_ok($snap) || !sx_valid_name($ctr)) { $end('error', 'sr.bad_args'); }
    $isCopy = cp_is_copy($snap); $root = $isCopy ? cp_src_root($ds, $snap) : '';
    if ($isCopy) { if ($root === '' || !is_dir($root)) { $end('error', 'sr.no_snap', ['s' => $snap]); } }
    else { $have = false; foreach (snp_snaps($dsn) as $s) { if ($s['name'] === $snap) { $have = true; } } if (!$have) { $end('error', 'sr.no_snap', ['s' => $dsn . '@' . $snap]); } }
    $ct = null; foreach (snp_containers($ds) as $x) { if ($x['name'] === $ctr) { $ct = $x; } }
    if (!$ct) { $end('error', 'sr.no_dir', ['c' => $ctr, 'ds' => $dsn]); }
    // a folder that is (or holds) another mount, e.g. its own child dataset, is not in this snapshot: rsync --delete would empty it
    // and the safety snapshot would not cover it (item 104). Refused before anything changes.
    $mnts = []; foreach ((array)@file(snp_mounts_file(), FILE_IGNORE_NEW_LINES) as $l) { $f = explode(' ', $l); if (isset($f[1])) { $mnts[] = stripcslashes($f[1]); } }
    foreach ($ct['dirs'] as $dir) {
        foreach ($mnts as $m) { if ($m === $dir || strpos($m, rtrim($dir, '/') . '/') === 0) { $end('error', 'snr.nested', ['c' => $ctr, 'd' => $m, 'ds' => $dsn]); } }
    }
    // 1. a safety snapshot of now, so the restore can be undone the same way
    $safe = 'before-restore-' . $ctr . '-' . date('Y-m-d-Hi');
    $e = snp_make($dsn, $safe);
    if ($e !== '') { $end('error', 'sr.safe_fail', ['c' => $ctr, 'e' => $e]); }
    $say('sr.safe_made', ['s' => $dsn . '@' . $safe]);
    // 2. stop only this container
    $d = sx_docker(); $wasRunning = $ct['state'] === 'running';
    if ($wasRunning) {
        snp_run(escapeshellarg($d) . ' stop -t 60 ' . escapeshellarg($ctr), $rc);
        if ($rc !== 0) { $end('error', 'sr.no_stop', ['c' => $ctr, 's' => $safe]); }
        $say('sr.stopped', ['c' => $ctr]);
    }
    // 3. each folder exactly as in the snapshot or copy (newer files removed; what the copies skip is left as it is)
    $r = snp_bin('rsync'); $fail = []; $skip = $isCopy ? cp_skip_args(snp_cfg()) : '';
    foreach ($ct['dirs'] as $dir) {
        $from = cp_src_dir($ds, $snap, $dir);
        if ($from === '' || !is_dir($from)) { $say('sr.not_in', ['d' => $dir]); continue; }
        $o = snp_run(escapeshellarg($r) . ' -aHAX --checksum --delete' . $skip . ' ' . // checksum: a file changed within the same second at the same size is caught too
             escapeshellarg($from . '/') . ' ' . escapeshellarg($dir . '/'), $rc);
        if ($rc === 0) { $say('sr.restored_dir', ['d' => $dir]); } else { $fail[] = $dir; $say('sr.failed_dir', ['d' => $dir, 'e' => substr(trim(implode(' ', $o)), 0, 200)]); }
    }
    // 4. start it again
    if ($wasRunning) { snp_run(escapeshellarg($d) . ' start ' . escapeshellarg($ctr), $rc); $say($rc === 0 ? 'sr.started' : 'sr.not_started', ['c' => $ctr]); }
    if ($fail) { $end('error', 'sr.part', ['c' => $ctr, 's' => $snap, 'l' => implode(', ', $fail), 'u' => $safe]); }
    $end('done', 'sr.done', ['c' => $ctr, 's' => $dsn . '@' . $snap, 'u' => $safe]);
}
exit(1);
