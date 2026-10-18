<?php
// Test and sample messages for the Discord alerts. POST op=test|sample|weekly (uses the saved settings).
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/discord.php';
require_once __DIR__ . '/alerts.php';
require_once __DIR__ . '/i18n.php';
$dbx_T = mt_i18n_t(__DIR__ . '/lang');   // texts shown on the settings page (lang/en.json)
header('Content-Type: application/json');
header('Cache-Control: no-store');

function dbt_out($ok, $extra = []) { echo json_encode(array_merge(['ok' => $ok], $extra), JSON_UNESCAPED_UNICODE); exit; }

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); dbt_out(false, ['error' => 'method']); }
$var  = @parse_ini_file('/var/local/emhttp/var.ini');
$tok  = is_array($var) ? (string)($var['csrf_token'] ?? '') : '';
$sent = (string)($_POST['csrf_token'] ?? '');
if ($sent !== '') {
    if ($tok === '' || !hash_equals($tok, $sent)) { http_response_code(403); dbt_out(false, ['error' => 'csrf token mismatch']); }
} elseif (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
    http_response_code(403); dbt_out(false, ['error' => 'csrf: token removed and request is not AJAX']);
}

$cfg = db_cfg();
$hook = (string)$cfg['dc_hook'];
if (!dbn_hook_ok($hook)) { dbt_out(false, ['error' => $dbx_T('test.no_hook')]); }
$lang = dbn_lang($cfg);
$op = (string)($_POST['op'] ?? 'test');

if ($op === 'weekly') {
    $e = dbn_weekly_now($cfg);
    if ($e === null) { dbt_out(false, ['error' => $dbx_T('test.no_data')]); }
    $r = dbn_post($hook, [$e]);
    dbt_out($r[0] === 'ok', $r[0] === 'ok' ? [] : ['error' => $r[1]]);
}

if ($op === 'sample') {
    $tag = '[' . dbn_t($lang, 'sample') . '] ';
    $pre = function ($e) use ($tag) { $e['title'] = $tag . $e['title']; return $e; };
    $attrs = [
        [5, 'Reallocated_Sector_Ct', true, 8, 0, 98, 98, 10, false],
        [197, 'Current_Pending_Sector', false, 2, null, 100, 100, 0, false],
        [199, 'UDMA_CRC_Error_Count', false, 14, 3, 200, 200, 0, false],
    ];
    $emb = [
        dbn_e_array($lang, 'emulated', ['DISK2'], 'alert'),
        dbn_e_temp($lang, 'DISK1', 52, 45, 55, 'a', 'alert', 'ST16000NM001G-2KK103_WL2XXXXX (sdb)'),
        dbn_e_fill($lang, 'DISK1', 95.4, 15.2e12, 16e12, 'rd', 'alert'),
        dbn_e_smart($lang, 'DISK3', 'WDC_WD30EFRX-68EUZN0_WD-WCCXXXXX (sdd)', $attrs, false, [], 'a'),
        dbn_e_smart($lang, 'DISK1', 'ST16000NT001-3LV101_ZR000000 (sde)', [[187, 'Reported_Uncorrect', false, 5, 2, 95, 95, 0, false]], false, [], 'rd', 'fast', ['`187` +3 ' . dbn_t($lang, 'within') . ' 1 d 4 h (' . dbn_t($lang, 'since_ack') . ')']),
        dbn_e_smart($lang, 'DISK1', 'ST16000NT001-3LV101_ZR000000 (sde)', [[187, 'Reported_Uncorrect', false, 3, 2, 97, 97, 0, false]], false, [], 'a', 'slow', ['`187` +1 ' . dbn_t($lang, 'within') . ' 412 d (' . dbn_t($lang, 'since_ack') . ')']),
        dbn_e_pool($lang, 'CACHE', 'DEGRADED', 'alert'),
        dbn_e_resync_end($lang, 'check', ['status' => 0, 'errors' => 0, 'duration' => 71505, 'speed' => '56.1 MB/s', 'date' => '']),
        dbn_e_array($lang, 'ok', [], 'resolved'),
    ];
    foreach ($emb as $e) {
        $r = dbn_post($hook, [$pre($e)]);
        if ($r[0] !== 'ok') { dbt_out(false, ['error' => $r[1]]); }
    }
    dbt_out(true);
}

$r = dbn_post($hook, [dbn_embed('i', '✅ ' . dbn_t($lang, 'test_t'), dbn_t($lang, 'test_d'))], dbn_ping_text($cfg['dc_ping']));
dbt_out($r[0] === 'ok', $r[0] === 'ok' ? [] : ['error' => $r[1]]);
