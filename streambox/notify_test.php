<?php
// Settings page buttons: "Send test message" and "Send preview" (newest movie and season, nothing is marked as sent).
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/notify_lib.php';
require_once __DIR__ . '/i18n.php';
$sbx_T = mt_i18n_t(__DIR__ . '/lang');   // texts shown on the tile and the settings page

function nt_out($ok, $msg) { echo json_encode(['ok' => $ok, 'message' => $msg], JSON_UNESCAPED_UNICODE); exit; }

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); nt_out(false, 'method'); }
$var  = @parse_ini_file('/var/local/emhttp/var.ini');
$tok  = is_array($var) ? (string)($var['csrf_token'] ?? '') : '';
$sent = (string)($_POST['csrf_token'] ?? '');
if ($sent !== '') {
    if ($tok === '' || !hash_equals($tok, $sent)) { http_response_code(403); nt_out(false, 'csrf token mismatch'); }
} elseif (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
    http_response_code(403); nt_out(false, 'csrf: token removed and request is not AJAX');
}

$cfg  = nf_cfg();
$hm   = nf_hook($cfg['dc_movies']);
$hs   = nf_hook($cfg['dc_series']);
$hw   = nf_hook($cfg['dc_weekly']);
$mode = (string)($_POST['mode'] ?? 'test');
if ($hm === '' && $hs === '' && $hw === '') { nt_out(false, $sbx_T('ntest.no_hook')); }

$report = [];
if ($mode === 'test') {
    foreach (['movies' => $hm, 'series' => $hs, 'weekly' => $hw] as $label => $h) {
        if ($h === '') { continue; }
        list($r, $m) = nf_send($h, ['username' => 'Streambox', 'content' => nf_t('✅ Streambox: tesztüzenet (' . $label . ' csatorna)', '✅ Streambox: test message (' . $label . ' channel)')]);
        $report[] = $sbx_T('ntest.ch_' . $label) . ': ' . ($r === 'ok' ? $sbx_T('ntest.sent') : $sbx_T('ntest.failed', ['e' => $m]));
    }
    nt_out(true, implode('; ', $report));
}

if ((string)$cfg['jf_key'] === '' || !preg_match('/^[A-Za-z0-9.\-]+$/', (string)$cfg['jf_host'])) { nt_out(false, $sbx_T('ntest.no_jf')); }

if ($mode === 'weekly') {
    if ($hw === '') { nt_out(false, $sbx_T('ntest.no_weekly')); }
    nf_tz();
    $p = nf_weekly($cfg, time() - 7 * 86400, time(), true);
    if ($p === false) { nt_out(false, $sbx_T('ntest.jf_down')); }
    if ($p === null) { nt_out(true, $sbx_T('ntest.nothing')); }
    list($r, $msg) = nf_send($hw, $p);
    nt_out($r === 'ok', $sbx_T('ntest.weekly') . ': ' . ($r === 'ok' ? $sbx_T('ntest.sent') : $sbx_T('ntest.failed', ['e' => $msg])));
}

if ($hm !== '') {
    $d = nf_json($cfg, '/Items?IncludeItemTypes=Movie&Recursive=true&SortBy=DateCreated&SortOrder=Descending&Limit=1&Fields=' . NF_FIELDS);
    $m = $d['Items'][0] ?? null;
    if ($m) {
        $c = nf_card_movie($cfg, $m, true);
        list($r, $msg) = nf_send($hm, $c['payload'], $c['poster'], $c['backdrop']);
        $report[] = $sbx_T('ntest.movie', ['t' => ($m['Name'] ?? '?')]) . ': ' . ($r === 'ok' ? $sbx_T('ntest.sent') : $sbx_T('ntest.failed', ['e' => $msg]));
    } else { $report[] = $sbx_T('ntest.no_movie'); }
}
if ($hs !== '') {
    $d = nf_json($cfg, '/Items?IncludeItemTypes=Episode&Recursive=true&SortBy=DateCreated&SortOrder=Descending&Limit=1&Fields=' . NF_FIELDS);
    $e = $d['Items'][0] ?? null;
    if ($e && !empty($e['SeasonId'])) {
        $all = nf_json($cfg, '/Items?ParentId=' . rawurlencode((string)$e['SeasonId']) . '&IncludeItemTypes=Episode&Fields=' . NF_FIELDS);
        $eps = (array)($all['Items'] ?? []);
        if (!$eps) { $eps = [$e]; }
        $series = nf_item($cfg, (string)($e['SeriesId'] ?? ''));
        $season = nf_item($cfg, (string)$e['SeasonId']);
        $c = nf_card_season($cfg, $series ?: [], $season ?: [], $eps, true);
        list($r, $msg) = nf_send($hs, $c['payload'], $c['poster'], $c['backdrop']);
        $report[] = $sbx_T('ntest.season', ['t' => ($e['SeriesName'] ?? '?')]) . ': ' . ($r === 'ok' ? $sbx_T('ntest.sent') : $sbx_T('ntest.failed', ['e' => $msg]));
    } else { $report[] = $sbx_T('ntest.no_episode'); }
}
nt_out(true, implode('; ', $report));
