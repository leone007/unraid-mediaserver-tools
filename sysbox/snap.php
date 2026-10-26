<?php
// Web endpoint of the Snapshots page (Sysbox). POST op=state|make|delete|preview|restore|save
//   copies (item 105): copy_now|off_now|off_test|off_list|off_fetch|off_pw|fetched_del
require_once __DIR__ . '/snap_lib.php';
require_once __DIR__ . '/copy_lib.php';
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { sx_json(['ok' => false, 'error' => 'method'], 405); }
sx_guard();
$op = (string)($_POST['op'] ?? 'state');

function snp_running() { $f = @fopen(snp_lock_file(), 'c'); if (!$f) { return false; } if (flock($f, LOCK_EX | LOCK_NB)) { flock($f, LOCK_UN); fclose($f); return false; } fclose($f); return true; }
function snp_ds_arg() { $d = (string)($_POST['ds'] ?? ''); if (!snp_ds_ok($d) || !snp_dataset($d)) { sx_json(['ok' => false, 'error' => sx_tr('snpe.dataset')]); } return $d; }

if ($op === 'state') {
    $c = snp_cfg(); $out = [];
    foreach (snp_datasets() as $d) {
        $row = $d + ['on' => in_array($d['name'], $c['datasets'], true), 'snaps' => snp_snaps($d['name']), 'fill' => snp_pool_fill($d['name'])];
        $row['held'] = array_sum(array_column($row['snaps'], 'used'));
        if (!empty($_POST['ctr']) && $_POST['ctr'] === $d['name']) { $row['containers'] = snp_containers($d); }
        $out[] = $row;
    }
    $log = is_file(snp_log_file()) ? array_slice(file(snp_log_file(), FILE_IGNORE_NEW_LINES), -60) : [];
    $run = json_decode((string)@file_get_contents(snp_run_file()), true) ?: ['state' => 'idle'];
    sx_json(['ok' => true, 'cfg' => $c, 'datasets' => $out, 'log' => $log, 'run' => $run, 'running' => snp_running(), 'zfs' => snp_bin('zfs') !== '', 'copies' => cp_view($c)]);
}
if ($op === 'make') {
    $ds = snp_ds_arg(); $n = trim((string)($_POST['name'] ?? '')); if ($n === '') { $n = 'manual-' . date('Y-m-d-Hi'); }
    if (!snp_name_ok($n) || preg_match('/^auto-/', $n)) { sx_json(['ok' => false, 'error' => sx_tr('snpe.name_rule')]); }
    $e = snp_make($ds, $n); if ($e === '') { snp_log($ds . '@' . $n . ' made by hand'); }
    sx_json(['ok' => $e === '', 'error' => $e, 'name' => $n]);
}
if ($op === 'delete') {
    $ds = snp_ds_arg(); $n = (string)($_POST['name'] ?? '');
    $e = snp_destroy($ds, $n); if ($e === '') { snp_log($ds . '@' . $n . ' deleted by hand'); }
    sx_json(['ok' => $e === '', 'error' => $e]);
}
if ($op === 'preview') {
    $ds = snp_ds_arg(); $snap = (string)($_POST['snap'] ?? ''); $ctr = (string)($_POST['ctr_name'] ?? '');
    if (!snp_name_ok($snap) || !sx_valid_name($ctr)) { sx_json(['ok' => false, 'error' => sx_tr('snpe.bad_name')]); }
    $d = snp_dataset($ds); $ct = null; foreach (snp_containers($d) as $x) { if ($x['name'] === $ctr) { $ct = $x; } }
    if (!$ct) { sx_json(['ok' => false, 'error' => sx_tr('snpe.no_folder', ['ds' => $ds])]); }
    sx_json(['ok' => true, 'dirs' => $ct['dirs'], 'state' => $ct['state'], 'changed' => snp_diff_count($d, $snap, $ct['dirs'])]);
}
if ($op === 'restore') {
    if (snp_running()) { sx_json(['ok' => false, 'error' => sx_tr('snpe.busy')]); }
    $ds = snp_ds_arg(); $snap = (string)($_POST['snap'] ?? ''); $ctr = (string)($_POST['ctr_name'] ?? '');
    if (!snp_name_ok($snap) || !sx_valid_name($ctr)) { sx_json(['ok' => false, 'error' => sx_tr('snpe.bad_name')]); }
    snp_json_write(snp_run_file(), ['state' => 'running', 'ds' => $ds, 'snap' => $snap, 'ctr' => $ctr, 'started' => time(), 'log' => ['starting...'], 'msg' => '']);
    @exec('setsid nohup ' . escapeshellarg(sx_php()) . ' ' . escapeshellarg(__DIR__ . '/snap_run.php') . ' restore ' . escapeshellarg($ds) . ' ' . escapeshellarg($snap) . ' ' . escapeshellarg($ctr) . ' >/dev/null 2>&1 &');
    sx_json(['ok' => true]);
}
// the background jobs of the copies (one at a time, with the daily run and a restore)
function cp_spawn($args) {
    if (snp_running()) { sx_json(['ok' => false, 'error' => sx_tr('snpe.busy')]); }
    cp_job(['kind' => $args[0] === 'offsite' ? 'off' : $args[0], 'state' => 'running', 'started' => time(), 'finished' => 0, 'pct' => 0, 'msg' => '', 'title' => sx_tr('cpj.starting')]);
    @exec('setsid nohup ' . escapeshellarg(sx_php()) . ' ' . escapeshellarg(__DIR__ . '/snap_run.php') . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' >/dev/null 2>&1 &');
    sx_json(['ok' => true]);
}
if ($op === 'copy_now') { cp_spawn(['copy']); }
if ($op === 'off_now') { if (!cp_off_ok(snp_cfg())) { sx_json(['ok' => false, 'error' => sx_tr('cpe.off_addr')]); } cp_spawn(['offsite']); }
if ($op === 'off_fetch') { $id = (string)($_POST['id'] ?? ''); if (!preg_match('/^[0-9a-f]{8}$/', $id)) { sx_json(['ok' => false, 'error' => sx_tr('cpe.no_version')]); } cp_spawn(['fetch', $id]); }
if ($op === 'off_test') { if (snp_running()) { sx_json(['ok' => false, 'error' => sx_tr('snpe.busy')]); } $r = cp_off_test(snp_cfg()); foreach (['msg', 'error'] as $k) { if (isset($r[$k])) { $r[$k] = cp_t($r[$k]); } } sx_json($r); }
if ($op === 'off_list') { $r = cp_off_list(snp_cfg()); if (isset($r['error'])) { $r['error'] = cp_t($r['error']); } sx_json($r); }
// the password is shown only on this click, so it can be written down
if ($op === 'off_pw') { snp_log('off-site password shown on the page'); sx_json(['ok' => true, 'pw' => cp_password()]); }
// removes one downloaded off-site version (a click with a confirm); nothing else can be named here
if ($op === 'fetched_del') {
    $ds = snp_ds_arg(); $n = (string)($_POST['name'] ?? ''); $c = snp_cfg();
    if (!in_array($n, cp_fetched($c, $ds), true)) { sx_json(['ok' => false, 'error' => sx_tr('cpe.no_version')]); }
    $t = cp_target($c, $ds); snp_run('rm -rf ' . escapeshellarg(dirname($t['dir']) . '/restore/' . cp_tag($ds) . '/' . $n), $rc);
    snp_log("off-site download $ds/$n deleted by hand");
    sx_json(['ok' => $rc === 0, 'error' => $rc === 0 ? '' : 'rm ' . $rc]);
}
if ($op === 'save') {
    $c = snp_cfg(); $known = array_column(snp_datasets(), 'name');
    $c['datasets'] = array_values(array_intersect(array_filter((array)($_POST['datasets'] ?? []), 'snp_ds_ok'), $known));
    foreach (['hour' => [0, 23], 'min' => [0, 59], 'keep' => [1, 90], 'maxfill' => [10, 99]] as $k => [$lo, $hi]) {
        $v = (int)($_POST[$k] ?? $c[$k]); if ($v < $lo || $v > $hi) { sx_json(['ok' => false, 'error' => sx_tr('snpe.range', ['name' => $k, 'min' => $lo, 'max' => $hi])]); } $c[$k] = $v;
    }
    foreach (['keep_d' => [1, 90], 'keep_w' => [0, 104], 'stale' => [0, 30], 'off_port' => [1, 65535], 'off_day' => [1, 7], 'off_hour' => [0, 23], 'off_min' => [0, 59], 'off_keep' => [1, 520]] as $k => [$lo, $hi]) {
        $v = (int)($_POST[$k] ?? $c[$k]); if ($v < $lo || $v > $hi) { sx_json(['ok' => false, 'error' => sx_tr('snpe.range', ['name' => $k, 'min' => $lo, 'max' => $hi])]); } $c[$k] = $v;
    }
    $c['copy'] = !empty($_POST['copy']) && $_POST['copy'] !== '0' ? 1 : 0;
    $dir = rtrim(trim((string)($_POST['copy_dir'] ?? $c['copy_dir'])), '/'); if (!cp_dir_ok($dir)) { sx_json(['ok' => false, 'error' => sx_tr('cpe.dir_bad')]); } $c['copy_dir'] = $dir;
    $c['skip'] = implode(', ', cp_skip_list((string)($_POST['skip'] ?? $c['skip'])));
    $c['off'] = in_array($_POST['off'] ?? '', ['off', 'sftp', 's3'], true) ? $_POST['off'] : 'off';
    $c['off_sftp'] = trim((string)($_POST['off_sftp'] ?? $c['off_sftp'])); $c['off_s3'] = rtrim(trim((string)($_POST['off_s3'] ?? $c['off_s3'])), '/');
    if ($c['off'] !== 'off' && !cp_off_ok($c)) { sx_json(['ok' => false, 'error' => sx_tr('cpe.off_addr')]); }
    // S3 keys: empty keeps the saved ones (the page never gets them back)
    $sec = cp_secret(); $id = trim((string)($_POST['s3_id'] ?? '')); $key = trim((string)($_POST['s3_key'] ?? ''));
    if ($id !== '' || $key !== '') {
        if (!preg_match('/^[A-Za-z0-9+\/=_\-]{8,128}$/', $id) || !preg_match('/^[A-Za-z0-9+\/=_\-]{8,128}$/', $key)) { sx_json(['ok' => false, 'error' => sx_tr('cpe.s3_keys')]); }
        $sec['s3_id'] = $id; $sec['s3_key'] = $key; cp_secret_write($sec);
    }
    if ($c['off'] !== 'off') { cp_password(); if ($c['off'] === 'sftp') { cp_ssh_key(); } }
    $c['on'] = !empty($_POST['on']) && $_POST['on'] !== '0' ? 1 : 0;
    $c['discord'] = !empty($_POST['discord']) && $_POST['discord'] !== '0' ? 1 : 0;
    sx_json(['ok' => snp_json_write(snp_cfg_file(), $c), 'cfg' => $c]);
}
sx_json(['ok' => false, 'error' => 'unknown action'], 400);
