<?php
// Web endpoint of Diskbox Balance. POST op=state|saveconfig|plan|getplan|start|stop, rm_state|rm_check|rm_clear|rm_log|rm_reset (remove.php)
require_once __DIR__ . '/balance_lib.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
function bl_out($ok, $extra = []) { echo json_encode(array_merge(['ok' => $ok], $extra), JSON_UNESCAPED_UNICODE); exit; }

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); bl_out(false, ['error' => 'method']); }
$var  = @parse_ini_file('/var/local/emhttp/var.ini');
$tok  = is_array($var) ? (string)($var['csrf_token'] ?? '') : '';
$sent = (string)($_POST['csrf_token'] ?? '');
if ($sent !== '') {
    if ($tok === '' || !hash_equals($tok, $sent)) { http_response_code(403); bl_out(false, ['error' => 'csrf token mismatch']); }
} elseif (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
    http_response_code(403); bl_out(false, ['error' => 'csrf: token removed and request is not AJAX']);
}

function bl_spawn($mode) {
    $php = db_php();
    @exec('setsid nohup ' . escapeshellarg($php) . ' ' . escapeshellarg(__DIR__ . '/balance_run.php') . ' ' . $mode . ' >/dev/null 2>&1 &');
}

$op = (string)($_POST['op'] ?? 'state');

if ($op === 'state') {
    $plan = bl_json_read(BL_PLAN, ['state' => 'none']);
    unset($plan['units'], $plan['skipped']);
    $st = bl_json_read(BL_STATUS, ['state' => 'idle']);
    $running = bl_running();
    if (!$running && ($st['state'] ?? '') === 'running' && time() - (int)($st['started'] ?? 0) > 20) { $st['state'] = 'error'; $st['msg'] = bl_tr('run.died'); }
    bl_out(true, ['cfg' => bl_cfg(), 'disks' => array_values(bl_disks()), 'plan' => $plan, 'run' => $st, 'running' => $running, 'busy' => bl_busy_reason(),
                  'history' => is_file(BL_LOG) ? array_slice(preg_split('/\r?\n/', trim((string)@file_get_contents(BL_LOG))), -8) : []]);
}

if ($op === 'saveconfig') {
    $pairs = [];
    foreach (preg_split('/\r?\n/', (string)($_POST['pairs'] ?? '')) as $line) {
        $line = trim($line);
        if ($line === '') { continue; }
        $x = array_map('trim', explode('=', $line, 2));
        $dl = bl_path_ok($x[0]); $md = (($x[1] ?? '') === '') ? '' : bl_path_ok($x[1]);
        if ($dl === false || $md === false) { bl_out(false, ['error' => bl_tr('err.bad_line', ['line' => $line])]); }
        $pairs[] = ['dl' => $dl, 'media' => $md];
    }
    if (!$pairs) { bl_out(false, ['error' => bl_tr('err.no_folder')]); }
    $t = trim((string)($_POST['target'] ?? ''));
    if ($t !== '' && (!is_numeric($t) || $t < 5 || $t > 95)) { bl_out(false, ['error' => bl_tr('err.target')]); }
    $all = bl_disks();
    $dis = [];
    foreach ((array)($_POST['disks'] ?? []) as $d) { if (is_string($d) && isset($all[$d])) { $dis[] = $d; } }
    $mode = in_array($_POST['mode'] ?? '', ['drain', 'remove'], true) ? (string)$_POST['mode'] : 'balance';
    $drn = [];
    foreach ((array)($_POST['drain'] ?? []) as $d) { if (is_string($d) && isset($all[$d]) && (!$dis || in_array($d, $dis, true))) { $drn[] = $d; } }
    if ($mode !== 'balance' && !$drn) { bl_out(false, ['error' => $mode === 'remove' ? bl_tr('err.tick_remove') : bl_tr('err.tick_drain')]); }
    if ($mode !== 'balance' && count($drn) >= count($dis ?: $all)) { bl_out(false, ['error' => bl_tr('plan.one_stays')]); }
    $c = ['pairs' => $pairs, 'target' => $t === '' ? '' : (string)(int)$t, 'strategy' => (($_POST['strategy'] ?? '') === 'big') ? 'big' : 'old', 'disks' => $dis, 'mode' => $mode, 'drain' => $drn];
    if (!bl_json_write(BL_CFG, $c, 0600)) { bl_out(false, ['error' => bl_tr('err.write')]); }
    bl_out(true);
}

if ($op === 'plan') {
    if (bl_running()) { bl_out(false, ['error' => bl_tr('err.running')]); }
    $why = bl_busy_reason(true);
    if ($why !== '') { bl_out(false, ['error' => bl_tr('err.cannot_plan', ['why' => $why])]); }
    $p = bl_json_read(BL_PLAN, []);
    if (($p['state'] ?? '') === 'running' && time() - (int)($p['t'] ?? 0) < 120) { bl_out(true); }
    if ((bl_cfg()['mode'] ?? '') === 'remove') { // Remove disks: emptying (step 3) only after the shares keep new files off them (step 2)
        require_once __DIR__ . '/remove.php';
        $sh = rm_shares(rm_sel());
        if ($sh) { bl_out(false, ['error' => bl_tr('rm.err.step2_shares', ['l' => implode(', ', array_column($sh, 0))])]); }
    }
    bl_plan_status('running', 'Starting...');
    bl_spawn('plan');
    bl_out(true);
}

if ($op === 'getplan') {
    $p = bl_json_read(BL_PLAN, ['state' => 'none']);
    bl_out(true, ['plan' => $p, 'busy' => bl_busy_reason()]);
}

if ($op === 'start') {
    if (bl_running()) { bl_out(false, ['error' => bl_tr('err.already')]); }
    $why = bl_busy_reason();
    if ($why !== '') { bl_out(false, ['error' => bl_tr('err.cannot_start', ['why' => $why])]); }
    $plan = bl_json_read(BL_PLAN, []);
    if (($plan['state'] ?? '') !== 'ready' || empty($plan['units'])) { bl_out(false, ['error' => bl_tr('err.no_plan')]); }
    $sel = json_decode((string)($_POST['sel'] ?? ''), true);
    if (!is_array($sel) || !$sel) { bl_out(false, ['error' => bl_tr('err.nothing')]); }
    $disks = bl_disks();
    $byId = [];
    foreach ($plan['units'] as $u) { $byId[$u['id']] = $u; }
    $items = []; $perDst = [];
    foreach ($sel as $id => $dst) {
        if (!isset($byId[$id]) || !is_string($dst) || !isset($disks[$dst]) || $dst === $byId[$id]['src'] || in_array($dst, (array)($plan['drain'] ?? []), true)) { bl_out(false, ['error' => bl_tr('err.bad_sel')]); }
        $items[] = ['id' => (string)$id, 'dst' => $dst];
        $perDst[$dst] = ($perDst[$dst] ?? 0) + $byId[$id]['size'];
    }
    foreach ($perDst as $d => $b) {
        if ($b + max(5e9, 0.01 * $disks[$d]['size']) > $disks[$d]['free']) { bl_out(false, ['error' => strtoupper($d) . ' does not have enough free space for the selected titles.']); }
    }
    @unlink(BL_STATUS);
    bl_json_write(BL_JOB, ['items' => $items, 't' => time()], 0600);
    bl_json_write(BL_STATUS, ['state' => 'running', 'started' => time(), 'items' => [], 'log' => ['starting...'], 'total' => 0, 'done' => 0, 'cur' => -1, 'curBytes' => 0]);
    bl_spawn('run');
    bl_out(true);
}

if ($op === 'stop') {
    if (!bl_running()) { bl_out(true); }
    @file_put_contents(BL_STOP, '1');
    bl_out(true);
}

if (strpos($op, 'rm_') === 0) { require_once __DIR__ . '/remove.php'; rm_op($op); }

http_response_code(400);
bl_out(false, ['error' => 'unknown action']);
