<?php
// Web endpoint of the Snapshots page (Sysbox). POST op=state|make|delete|preview|restore|save
require_once __DIR__ . '/snap_lib.php';
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { sx_json(['ok' => false, 'error' => 'method'], 405); }
sx_guard();
$op = (string)($_POST['op'] ?? 'state');

function snp_running() { $f = @fopen(snp_lock_file(), 'c'); if (!$f) { return false; } if (flock($f, LOCK_EX | LOCK_NB)) { flock($f, LOCK_UN); fclose($f); return false; } fclose($f); return true; }
function snp_ds_arg() { $d = (string)($_POST['ds'] ?? ''); if (!snp_ds_ok($d) || !snp_dataset($d)) { sx_json(['ok' => false, 'error' => 'unknown dataset']); } return $d; }

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
    sx_json(['ok' => true, 'cfg' => $c, 'datasets' => $out, 'log' => $log, 'run' => $run, 'running' => snp_running(), 'zfs' => snp_bin('zfs') !== '']);
}
if ($op === 'make') {
    $ds = snp_ds_arg(); $n = trim((string)($_POST['name'] ?? '')); if ($n === '') { $n = 'manual-' . date('Y-m-d-Hi'); }
    if (!snp_name_ok($n) || preg_match('/^auto-/', $n)) { sx_json(['ok' => false, 'error' => 'use letters, digits, - _ . : (and not auto-, which is the daily ones)']); }
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
    if (!snp_name_ok($snap) || !sx_valid_name($ctr)) { sx_json(['ok' => false, 'error' => 'bad name']); }
    $d = snp_dataset($ds); $ct = null; foreach (snp_containers($d) as $x) { if ($x['name'] === $ctr) { $ct = $x; } }
    if (!$ct) { sx_json(['ok' => false, 'error' => 'this container has no folder in ' . $ds]); }
    sx_json(['ok' => true, 'dirs' => $ct['dirs'], 'state' => $ct['state'], 'changed' => snp_diff_count($d, $snap, $ct['dirs'])]);
}
if ($op === 'restore') {
    if (snp_running()) { sx_json(['ok' => false, 'error' => 'a snapshot or restore is running, try again in a minute']); }
    $ds = snp_ds_arg(); $snap = (string)($_POST['snap'] ?? ''); $ctr = (string)($_POST['ctr_name'] ?? '');
    if (!snp_name_ok($snap) || !sx_valid_name($ctr)) { sx_json(['ok' => false, 'error' => 'bad name']); }
    snp_json_write(snp_run_file(), ['state' => 'running', 'ds' => $ds, 'snap' => $snap, 'ctr' => $ctr, 'started' => time(), 'log' => ['starting...'], 'msg' => '']);
    @exec('setsid nohup ' . escapeshellarg(sx_php()) . ' ' . escapeshellarg(__DIR__ . '/snap_run.php') . ' restore ' . escapeshellarg($ds) . ' ' . escapeshellarg($snap) . ' ' . escapeshellarg($ctr) . ' >/dev/null 2>&1 &');
    sx_json(['ok' => true]);
}
if ($op === 'save') {
    $c = snp_cfg(); $known = array_column(snp_datasets(), 'name');
    $c['datasets'] = array_values(array_intersect(array_filter((array)($_POST['datasets'] ?? []), 'snp_ds_ok'), $known));
    foreach (['hour' => [0, 23], 'min' => [0, 59], 'keep' => [1, 90], 'maxfill' => [10, 99]] as $k => [$lo, $hi]) {
        $v = (int)($_POST[$k] ?? $c[$k]); if ($v < $lo || $v > $hi) { sx_json(['ok' => false, 'error' => $k . ' must be between ' . $lo . ' and ' . $hi]); } $c[$k] = $v;
    }
    $c['on'] = !empty($_POST['on']) && $_POST['on'] !== '0' ? 1 : 0;
    $c['discord'] = !empty($_POST['discord']) && $_POST['discord'] !== '0' ? 1 : 0;
    sx_json(['ok' => snp_json_write(snp_cfg_file(), $c), 'cfg' => $c]);
}
sx_json(['ok' => false, 'error' => 'unknown action'], 400);
