<?php
// Hungarian descriptions (item 147) for the settings page: GET = the list; POST op=write|skip|undo (id) or retry. Skip also
// takes a failed item off the list (finished, it is not looked at again).
// Write and Undo are the owner's clicks (the click rule); Retry starts a run now in the background.
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/hu_lib.php';
wk_tz();
function hu_out($a) { echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    // CSRF as save.php: Unraid's prepend checks csrf_token and removes it; without it only an AJAX request is accepted
    $var = @parse_ini_file('/var/local/emhttp/var.ini'); $tok = is_array($var) ? (string)($var['csrf_token'] ?? '') : '';
    $sent = (string)($_POST['csrf_token'] ?? '');
    if ($sent !== '' ? ($tok === '' || !hash_equals($tok, $sent)) : (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest')) { http_response_code(403); hu_out(['ok' => false, 'error' => 'csrf']); }
    $op = (string)($_POST['op'] ?? ''); $id = (string)($_POST['id'] ?? '');
    $cfg = hu_cfg();
    if ($op === 'retry') {
        $lk = hu_lock(); $st = hu_state(); $st['err'] = ''; $st['err_detail'] = '';
        foreach ($st['items'] as $k => $x) { if ($x['st'] === 'fail') { $st['items'][$k]['st'] = 'new'; $st['items'][$k]['tries'] = 0; } }
        hu_state_save($st); hu_unlock($lk);
        exec('/usr/bin/php ' . escapeshellarg(__DIR__ . '/hu_run.php') . ' now > /dev/null 2>&1 &');
        hu_out(['ok' => true]);
    }
    if (!preg_match('/^[0-9a-f]{32}$/', $id) || !in_array($op, ['write', 'skip', 'undo'], true)) { hu_out(['ok' => false, 'error' => 'bad request']); }
    $lk = hu_lock();
    $st = hu_state();
    if (!isset($st['items'][$id])) { hu_out(['ok' => false, 'error' => 'gone']); }
    $x = &$st['items'][$id]; $e = '';
    if ($op === 'skip' && in_array($x['st'], ['ready', 'fail'], true)) { $x['st'] = 'skip'; $x['t'] = time(); }
    elseif ($op === 'write' && $x['st'] === 'ready') { $e = hu_write($cfg, $x); $x['st'] = $e === '' ? 'done' : (in_array($e, ['changed', 'gone'], true) ? 'gone' : 'ready'); $x['t'] = time(); if ($e === '') { unset($x['werr']); } }
    elseif ($op === 'undo' && $x['st'] === 'done') { $e = hu_undo($cfg, $x); if ($e === '') { $x['st'] = 'undone'; $x['t'] = time(); } }
    else { $e = 'state'; }
    unset($x);
    hu_state_save($st); hu_unlock($lk);
    hu_out(['ok' => $e === '', 'error' => $e]);
}

$cfg = hu_cfg(); $st = hu_state(); $now = time(); $rows = [];
foreach ($st['items'] as $x) {
    if (in_array($x['st'], ['done', 'skip', 'undone', 'gone'], true) && ($x['t'] ?? 0) < $now - 7 * 86400) { continue; }
    $rows[] = ['id' => $x['id'], 'st' => $x['st'], 'label' => hu_label($x), 'kind' => $x['kind'], 't' => (int)($x['t'] ?? $x['found']),
               'hu' => (string)($x['hu'] ?? ''), 'hu_name' => $x['want_ti'] ? (string)($x['hu_name'] ?? '') : '', 'name_now' => $x['name_now'],
               'en' => mb_substr((string)$x['src'], 0, 300), 'what' => ($x['want_ov'] ? 'd' : '') . ($x['want_ti'] ? 't' : ''), 'auto' => !empty($x['auto']), 'tries' => (int)($x['tries'] ?? 0),
               'werr' => $x['st'] === 'ready' ? (string)($x['werr'] ?? '') : ''];
}
usort($rows, function ($a, $b) { return $b['t'] <=> $a['t']; });
hu_out(['ok' => true, 'on' => nf_on_hu($cfg['hu_on']), 'mode' => $cfg['hu_mode'], 'err' => $st['err'], 'err_t' => (int)$st['err_t'],
        'count' => (int)$st['count'], 'lim' => (int)$cfg['hu_lim'], 'quiet' => hu_quiet($now), 'rows' => $rows]);
