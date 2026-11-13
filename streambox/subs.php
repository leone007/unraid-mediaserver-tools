<?php
// Hungarian subtitles (item 162) for the tool page: GET = the state as rows; POST op=copy (ids, comma separated) | skip | search
// (id) | save (the settings). Copy is the owner's click (the click rule): never over an existing subtitle; Skip and Search
// go to the worker as op files; Search also starts a run now in the background.
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/subs_lib.php';
wk_tz();
function subs_out($a) { echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

$cfg = subs_cfg();
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    // CSRF as save.php: Unraid's prepend checks csrf_token and removes it; without it only an AJAX request is accepted
    $var = @parse_ini_file('/var/local/emhttp/var.ini'); $tok = is_array($var) ? (string)($var['csrf_token'] ?? '') : '';
    $sent = (string)($_POST['csrf_token'] ?? '');
    if ($sent !== '' ? ($tok === '' || !hash_equals($tok, $sent)) : (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest')) { http_response_code(403); subs_out(['ok' => false, 'error' => 'csrf']); }
    $op = (string)($_POST['op'] ?? '');
    if ($op === 'save') { $e = subs_cfg_save($_POST); subs_out(['ok' => $e === '', 'error' => $e]); }
    $ids = array_values(array_filter(explode(',', (string)($_POST['id'] ?? '')), function ($i) { return (bool)preg_match('/^[0-9a-f]{32}$/', $i); }));
    if (!$ids || !in_array($op, ['copy', 'skip', 'search'], true)) { subs_out(['ok' => false, 'error' => 'bad']); }
    $st = subs_state($cfg); $errs = []; $done = 0;
    foreach ($ids as $id) {
        $x = $st['items'][$id] ?? null;
        if (!$x) { $errs[] = 'gone'; continue; }
        if ($op === 'copy') { $e = subs_copy($cfg, $x, false); if ($e === '') { $done++; } else { $errs[] = $e; } }
        else { if (subs_op($cfg, $op, $id)) { $done++; } else { $errs[] = 'write'; } }
    }
    if ($op === 'search' && $done) { exec('/usr/bin/php ' . escapeshellarg(__DIR__ . '/subs_run.php') . ' > /dev/null 2>&1 &'); }
    subs_out(['ok' => !$errs, 'done' => $done, 'error' => $errs ? $errs[0] : '', 'errors' => count($errs)]);
}

$st = subs_state($cfg); $pend = subs_pending($cfg); $now = time(); $rows = [];
$run = json_decode((string)@file_get_contents(subs_run_file()), true);
foreach ($st['items'] as $x) {
    $s = (string)($x['st'] ?? '');
    $p = $pend[$x['id']] ?? '';
    if ($p === 'skip') { $s = 'skip'; } elseif ($p === 'copied') { $s = 'copied'; } elseif ($p === 'search' && $s !== 'ready') { $s = 'search'; }
    if (in_array($s, ['has_hu', 'copied', 'skip', 'gone', 'givenup'], true) && (int)($x['t'] ?? 0) < $now - 7 * 86400) { continue; }
    $src = (array)($x['src'] ?? []);
    $rows[] = ['id' => $x['id'], 'st' => $s, 'label' => (string)($x['label'] ?? ''), 'title' => (string)($x['title'] ?? ''), 'kind' => (string)($x['kind'] ?? ''),
               'season' => (int)($x['season'] ?? 0), 'episode' => (int)($x['episode'] ?? 0), 'added' => (int)($x['added'] ?? 0), 't' => (int)($x['t'] ?? 0),
               'score' => (int)($x['score'] ?? 0), 'best' => (int)($x['best'] ?? 0), 'tries' => (int)($x['tries'] ?? 0), 'next' => (int)($x['next'] ?? 0),
               'ratio' => (float)($x['ratio'] ?? 1), 'offset' => (float)($x['offset'] ?? 0), 'auto' => !empty($x['auto']),
               'site' => (string)($src['site'] ?? ''), 'pack' => (string)($src['name'] ?? ''), 'translator' => (string)($src['translator'] ?? ''),
               'url' => preg_match('#^https://#', (string)($src['url'] ?? '')) ? (string)$src['url'] : '', 'err' => (string)($x['err'] ?? '')];
}
usort($rows, function ($a, $b) { return [$a['title'], $a['season'], $a['episode']] <=> [$b['title'], $b['season'], $b['episode']]; });
subs_out(['ok' => true, 'on' => nf_on_hu($cfg['subs_on']), 'cfg' => array_intersect_key($cfg, array_flip(['subs_on', 'subs_lang', 'subs_copy', 'subs_auto', 'subs_grace', 'subs_src', 'subs_min', 'subs_osmax'])),
          'run' => $st['run'], 'last' => (int)($run['t'] ?? 0), 'last_err' => (string)($run['err'] ?? ''), 'jf' => (int)($run['jf'] ?? 1), 'state_t' => (int)$st['t'],
          'err' => array_keys((array)$st['err']), 'os_used' => (int)$st['os_used'], 'rows' => $rows, 'now' => $now, 'langs' => array_keys(SUBS_LANGS)]);
