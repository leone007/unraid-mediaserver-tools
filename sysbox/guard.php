<?php
// Web endpoint of the Claude Guard page (Sysbox, items 44 and 84). POST op=state|run|save|dismiss|known|unknown|note
// state only reads files; run starts guard_run.php in the background after a click; save writes guard.json.
require_once __DIR__ . '/guard_lib.php';
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { sx_json(['ok' => false, 'error' => 'method'], 405); }
sx_guard();
$op = (string)($_POST['op'] ?? 'state');

if ($op === 'state') {
    $run = upd_json(gd_run_file(), ['jobs' => []]);
    foreach ($run['jobs'] as $k => $j) { if ($j['state'] === 'running' && time() - (int)$j['started'] > 3600) { $run['jobs'][$k]['state'] = 'error'; $run['jobs'][$k]['text'] .= ' (' . sx_tr('gd.e_stuck') . ')'; } }
    $st = upd_json(gd_state_file(), []);
    $log = is_file(gd_log_file()) ? array_slice(file(gd_log_file(), FILE_IGNORE_NEW_LINES), -40) : [];
    $reps = gd_reports(); usort($reps, function ($a, $b) { return $b['t'] <=> $a['t']; });
    sx_json(['ok' => true, 'cfg' => gd_cfg(), 'ct' => is_file(gd_flag_file()), 'usage' => gd_usage(), 'reports' => $reps, 'jobs' => array_values($run['jobs']),
             'night' => count($st['night'] ?? []), 'ev_today' => ($st['ev_day']['d'] ?? '') === date('Y-m-d') ? (int)$st['ev_day']['n'] : 0,
             'quiet' => gd_quiet(gd_cfg()), 'ctx_path' => gd_ctx_path(gd_cfg()), 'lang_now' => gd_lang_code(gd_cfg()), 'busy' => gd_running('claude'), 'log' => $log, 'known' => gd_known()]);
}
if ($op === 'run') {
    if (!is_file(gd_flag_file()) && !gd_ct_exists()) { sx_json(['ok' => false, 'error' => sx_tr('gd.e_noct')]); }
    if (gd_running('claude')) { sx_json(['ok' => false, 'error' => sx_tr('gd.e_busy')]); }
    gd_spawn(['run', 'manual']); sx_json(['ok' => true, 'now' => time()]);
}
// the owner's answers to a finding (item 84): a report id, the item's index, and short texts without control characters
function gd_arg_item() {
    $rid = (string)($_POST['rid'] ?? ''); $idx = (int)($_POST['idx'] ?? -1);
    if (!preg_match('/^[a-f0-9]{10}$/', $rid) || $idx < 0 || $idx > 50) { sx_json(['ok' => false, 'error' => 'item']); }
    foreach (gd_reports() as $r) { if ($r['id'] === $rid && isset($r['items'][$idx])) { return [$rid, $idx, $r, $r['items'][$idx]]; } }
    sx_json(['ok' => false, 'error' => sx_tr('gd.e_gone')]);
}
function gd_arg_text($k, $max) { return trim(mb_substr(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string)($_POST[$k] ?? '')), 0, $max)); }
if ($op === 'dismiss') {   // only from the tile; it stays on the page
    [$rid, $idx] = gd_arg_item(); $on = !empty($_POST['on']) && $_POST['on'] !== '0';
    $it = gd_item_edit($rid, $idx, function (&$x) use ($on) { if ($on) { $x['dismissed'] = time(); } else { unset($x['dismissed']); } });
    sx_json(['ok' => $it !== false]);
}
if ($op === 'known') {
    [$rid, $idx, $r, $it] = gd_arg_item(); $why = gd_arg_text('why', 300); $again = gd_arg_text('again', 200);
    if ($why === '') { sx_json(['ok' => false, 'error' => sx_tr('gd.e_why')]); }
    $k = ['id' => substr(md5($rid . $idx . microtime()), 0, 10), 'area' => $it['area'], 'title' => $it['title'], 'why' => $why, 'again' => $again, 't' => time()];
    if (!gd_known_add($k)) { sx_json(['ok' => false, 'error' => 'write']); }
    gd_item_edit($rid, $idx, function (&$x) use ($k) { $x['known'] = $k['id']; if (empty($x['dismissed'])) { $x['dismissed'] = time(); } });   // known: off the tile too
    gd_todo_append("\n## " . date('Y-m-d H:i') . ' · Claude Guard · ' . $it['area'] . " · ISMERT (a tulajdonos jelölte az oldalon)\n\n" . $it['title'] . "\n\nIndoklás: " . $why . "\n" .
        ($again !== '' ? "\nCsak akkor jelezze újra, ha: " . $again . "\n" : '') . "\nTeendő: vezesd be az ATADAS.md „Tudatos döntések és ismert állapotok” szakaszába.\n");
    sx_json(['ok' => true]);
}
if ($op === 'unknown') {
    $id = (string)($_POST['kid'] ?? ''); if (!preg_match('/^[a-f0-9]{10}$/', $id)) { sx_json(['ok' => false, 'error' => 'id']); }
    sx_json(['ok' => gd_known_del($id)]);
}
if ($op === 'note') {
    [$rid, $idx, $r, $it] = gd_arg_item(); $txt = gd_arg_text('text', 600);
    if ($txt === '') { sx_json(['ok' => false, 'error' => sx_tr('gd.e_note')]); }
    $ok = gd_todo_append("\n## " . date('Y-m-d H:i') . ' · Claude Guard · ' . $it['area'] . ' (' . ($it['level'] === 'red' ? 'PIROS' : 'SÁRGA') . ") · MEGJEGYZÉS A TULAJDONOSTÓL\n\n" . $it['title'] . ' ' . $it['text'] . "\n\nA tulajdonos: " . $txt . "\n" .
        ($it['cmd'] !== '' ? "\nEllenőrzés (csak olvas): `" . $it['cmd'] . "`\n" : ''));
    if ($ok) { gd_item_edit($rid, $idx, function (&$x) { $x['noted'] = time(); }); }
    sx_json($ok ? ['ok' => true] : ['ok' => false, 'error' => sx_tr('gd.e_todo')]);
}
if ($op === 'save') {
    $c = gd_cfg();
    foreach (['hour' => [0, 23], 'min' => [0, 59], 'q_from' => [0, 23], 'q_to' => [0, 23], 'ev_max' => [0, 10], 'limit' => [10, 100]] as $k => [$lo, $hi]) {
        $v = (int)($_POST[$k] ?? $c[$k]); if ($v < $lo || $v > $hi) { sx_json(['ok' => false, 'error' => sx_tr('snpe.range', ['name' => $k, 'min' => $lo, 'max' => $hi])]); } $c[$k] = $v;
    }
    foreach (['on', 'ev_sys', 'ev_dsk', 'ev_sbx', 't_disk', 't_ct', 't_log', 't_arr', 'todo'] as $k) { $c[$k] = !empty($_POST[$k]) && $_POST[$k] !== '0' ? 1 : 0; }
    $lg = (string)($_POST['lang'] ?? 'auto'); $c['lang'] = in_array($lg, ['auto', 'en', 'hu'], true) ? $lg : 'auto';
    $cx = trim((string)($_POST['ctx'] ?? 'auto')); if ($cx !== 'auto' && $cx !== '' && !preg_match('#^/[A-Za-z0-9_./ -]{1,200}$#', $cx)) { sx_json(['ok' => false, 'error' => sx_tr('gd.e_ctx')]); } $c['ctx'] = $cx;
    $dc = (string)($_POST['discord'] ?? 'issues'); $c['discord'] = in_array($dc, ['issues', 'short', 'full', 'off'], true) ? $dc : 'issues';
    sx_json(['ok' => upd_write(gd_cfg_file(), $c), 'cfg' => $c]);
}
sx_json(['ok' => false, 'error' => 'unknown action'], 400);
