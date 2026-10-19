<?php
// Web endpoint of the Update manager page (Sysbox, item 61). POST op=state|detail|scan|ask|todo|update|updall|selftest|save
// Reading ops only read files; the others start upd_run.php in the background after a click.
require_once __DIR__ . '/upd_lib.php';
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { sx_json(['ok' => false, 'error' => 'method'], 405); }
sx_guard();
$op = (string)($_POST['op'] ?? 'state');
function upd_name_arg() { $n = (string)($_POST['name'] ?? ''); if (!sx_valid_name($n)) { sx_json(['ok' => false, 'error' => sx_tr('ue.bad_name')]); } return $n; }

if ($op === 'state') {
    $scan = upd_json(upd_scan_file(), []); $cache = upd_json(upd_cache_file(), []);
    // the result lives in /tmp: after a reboot or a plugin install it is gone, so the page starts the (read-only) check itself
    // (once in 10 minutes: a failing check is not restarted at every poll)
    $recent = false; foreach (upd_json(upd_run_file(), ['jobs' => []])['jobs'] as $j) { if (($j['kind'] ?? '') === 'scan' && time() - (int)($j['started'] ?? 0) < 600) { $recent = true; } }
    if (!$scan && !$recent && !upd_running('scan')) { upd_spawn(['scan']); $autoscan = true; }
    foreach ($scan['rows'] ?? [] as $i => $r) { if (!empty($r['key']) && !empty($cache[$r['key']]['claude'])) { $scan['rows'][$i]['claude_t'] = $cache[$r['key']]['claude']['t']; $scan['rows'][$i]['claude'] = $cache[$r['key']]['claude']; } }
    if (!empty($scan['os']['key']) && !empty($cache[$scan['os']['key']]['claude'])) { $scan['os']['claude_t'] = $cache[$scan['os']['key']]['claude']['t']; $scan['os']['claude'] = $cache[$scan['os']['key']]['claude']; }
    $run = upd_json(upd_run_file(), ['jobs' => []]);
    // a job whose worker died stays "running" for ever: after 2 hours it is shown as stuck
    foreach ($run['jobs'] as $k => $j) { if ($j['state'] === 'running' && time() - (int)$j['started'] > 7200) { $run['jobs'][$k]['state'] = 'error'; $run['jobs'][$k]['text'] .= ' (stopped answering)'; } }
    $log = is_file(upd_log_file()) ? array_slice(file(upd_log_file(), FILE_IGNORE_NEW_LINES), -40) : [];
    sx_json(['ok' => true, 'cfg' => upd_cfg(), 'scan' => $scan ?: null, 'jobs' => array_values($run['jobs']), 'test' => upd_json(upd_test_file(), []) ?: null,
             'log' => $log, 'busy' => ['scan' => !empty($autoscan) || upd_running('scan'), 'ask' => upd_running('ask'), 'update' => upd_running('update'), 'selftest' => upd_running('selftest')],
             'claude' => is_file('/tmp/sysbox_claude_ct') || trim(upd_sh(escapeshellarg(sx_docker()) . ' inspect -f {{.Name}} claude', 10)) !== '']);
}
if ($op === 'detail') {
    $n = upd_name_arg(); $scan = upd_json(upd_scan_file(), []); $cache = upd_json(upd_cache_file(), []);
    foreach ($scan['rows'] ?? [] as $r) {
        if ($r['name'] !== $n || empty($r['key'])) { continue; }
        $c = $cache[$r['key']] ?? [];
        sx_json(['ok' => true, 'notes' => (string)($c['notes'] ?? ''), 'url' => (string)($c['url'] ?? ''), 'claude' => $c['claude'] ?? null]);
    }
    if ($n === 'unraid-os' && !empty($scan['os']['key'])) { $c = $cache[$scan['os']['key']] ?? []; sx_json(['ok' => true, 'notes' => (string)($c['notes'] ?? ''), 'url' => (string)($scan['os']['url'] ?? ''), 'claude' => $c['claude'] ?? null]); }
    sx_json(['ok' => false, 'error' => sx_tr('ue.no_update', ['n' => $n])]);
}
if ($op === 'scan') {
    if (upd_running('scan')) { sx_json(['ok' => false, 'error' => sx_tr('ue.scan_busy')]); }
    upd_spawn(['scan']); sx_json(['ok' => true]);
}
if ($op === 'selftest') {
    if (upd_running('selftest')) { sx_json(['ok' => false, 'error' => sx_tr('ue.test_busy')]); }
    upd_spawn(['selftest']); sx_json(['ok' => true]);
}
if ($op === 'ask') {
    $n = upd_name_arg();
    if (empty(upd_cfg()['claude'])) { sx_json(['ok' => false, 'error' => sx_tr('ue.claude_off')]); }
    if (upd_running('ask')) { sx_json(['ok' => false, 'error' => sx_tr('ue.claude_busy')]); }
    upd_spawn(['ask', $n]); sx_json(['ok' => true, 'now' => time()]);
}
if ($op === 'update') {
    $n = upd_name_arg();
    if ($n === 'claude') { sx_json(['ok' => false, 'error' => sx_tr('ue.claude_ct')]); }
    if (upd_running('update')) { sx_json(['ok' => false, 'error' => sx_tr('ue.upd_busy')]); }
    upd_spawn(['update', $n]); sx_json(['ok' => true]);
}
if ($op === 'updall') {
    $set = (string)($_POST['set'] ?? ''); if (!in_array($set, ['safe', 'all'], true)) { sx_json(['ok' => false, 'error' => 'set']); }
    if (upd_running('update')) { sx_json(['ok' => false, 'error' => sx_tr('ue.upd_busy')]); }
    upd_spawn(['updall', $set]); sx_json(['ok' => true]);
}
if ($op === 'todo') {
    $n = upd_name_arg(); $e = upd_todo($n);
    sx_json($e === '' ? ['ok' => true] : ['ok' => false, 'error' => $e]);
}
if ($op === 'save') {
    $c = upd_cfg();
    foreach (['hour' => [0, 23], 'min' => [0, 59], 'soak' => [0, 60]] as $k => [$lo, $hi]) {
        $v = (int)($_POST[$k] ?? $c[$k]); if ($v < $lo || $v > $hi) { sx_json(['ok' => false, 'error' => sx_tr('snpe.range', ['name' => $k, 'min' => $lo, 'max' => $hi])]); } $c[$k] = $v;
    }
    foreach (['on', 'backup', 'claude', 'selftest'] as $k) { $c[$k] = !empty($_POST[$k]) && $_POST[$k] !== '0' ? 1 : 0; }
    $dc = (string)($_POST['discord'] ?? 'warn'); $c['discord'] = in_array($dc, ['warn', 'all', 'off'], true) ? $dc : 'warn';
    sx_json(['ok' => upd_write(upd_cfg_file(), $c), 'cfg' => $c]);
}
sx_json(['ok' => false, 'error' => 'unknown action'], 400);
