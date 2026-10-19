<?php
// Web endpoint of the Claude Guard page (Sysbox, item 44). POST op=state|run|save
// state only reads files; run starts guard_run.php in the background after a click; save writes guard.json.
require_once __DIR__ . '/guard_lib.php';
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { sx_json(['ok' => false, 'error' => 'method'], 405); }
sx_guard();
$op = (string)($_POST['op'] ?? 'state');

if ($op === 'state') {
    $run = upd_json(gd_run_file(), ['jobs' => []]);
    foreach ($run['jobs'] as $k => $j) { if ($j['state'] === 'running' && time() - (int)$j['started'] > 3600) { $run['jobs'][$k]['state'] = 'error'; $run['jobs'][$k]['text'] .= ' (stopped answering)'; } }
    $st = upd_json(gd_state_file(), []);
    $log = is_file(gd_log_file()) ? array_slice(file(gd_log_file(), FILE_IGNORE_NEW_LINES), -40) : [];
    $reps = gd_reports(); usort($reps, function ($a, $b) { return $b['t'] <=> $a['t']; });
    sx_json(['ok' => true, 'cfg' => gd_cfg(), 'ct' => is_file(gd_flag_file()), 'usage' => gd_usage(), 'reports' => $reps, 'jobs' => array_values($run['jobs']),
             'night' => count($st['night'] ?? []), 'ev_today' => ($st['ev_day']['d'] ?? '') === date('Y-m-d') ? (int)$st['ev_day']['n'] : 0,
             'quiet' => gd_quiet(gd_cfg()), 'busy' => gd_running('claude'), 'log' => $log]);
}
if ($op === 'run') {
    if (!is_file(gd_flag_file()) && !gd_ct_exists()) { sx_json(['ok' => false, 'error' => sx_tr('gd.e_noct')]); }
    if (gd_running('claude')) { sx_json(['ok' => false, 'error' => sx_tr('gd.e_busy')]); }
    gd_spawn(['run', 'manual']); sx_json(['ok' => true, 'now' => time()]);
}
if ($op === 'save') {
    $c = gd_cfg();
    foreach (['hour' => [0, 23], 'min' => [0, 59], 'q_from' => [0, 23], 'q_to' => [0, 23], 'ev_max' => [0, 10], 'limit' => [10, 100]] as $k => [$lo, $hi]) {
        $v = (int)($_POST[$k] ?? $c[$k]); if ($v < $lo || $v > $hi) { sx_json(['ok' => false, 'error' => sx_tr('snpe.range', ['name' => $k, 'min' => $lo, 'max' => $hi])]); } $c[$k] = $v;
    }
    foreach (['on', 'ev_sys', 'ev_dsk', 'ev_sbx', 't_disk', 't_ct', 't_log', 't_arr', 'todo'] as $k) { $c[$k] = !empty($_POST[$k]) && $_POST[$k] !== '0' ? 1 : 0; }
    $dc = (string)($_POST['discord'] ?? 'issues'); $c['discord'] = in_array($dc, ['issues', 'short', 'full', 'off'], true) ? $dc : 'issues';
    sx_json(['ok' => upd_write(gd_cfg_file(), $c), 'cfg' => $c]);
}
sx_json(['ok' => false, 'error' => 'unknown action'], 400);
