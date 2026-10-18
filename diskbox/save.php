<?php
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/i18n.php';
$dbx_T = mt_i18n_t(__DIR__ . '/lang');   // texts shown on the settings page (lang/en.json)

function db_out($ok, $extra = []) {
    echo json_encode(array_merge(['ok' => $ok], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); db_out(false, ['error' => 'method']); }

// CSRF: Unraid's prepend script validates csrf_token and removes it from $_POST;
// if a token is still present check it, otherwise require an AJAX request.
$var  = @parse_ini_file('/var/local/emhttp/var.ini');
$tok  = is_array($var) ? (string)($var['csrf_token'] ?? '') : '';
$sent = (string)($_POST['csrf_token'] ?? '');
if ($sent !== '') {
    if ($tok === '' || !hash_equals($tok, $sent)) { http_response_code(403); db_out(false, ['error' => 'csrf token mismatch']); }
} elseif (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
    http_response_code(403);
    db_out(false, ['error' => 'csrf: token removed and request is not AJAX']);
}

$dir  = '/boot/config/plugins/diskbox';
$file = $dir . '/settings.cfg';

// empty = use Unraid's own value
function db_num($v, $min, $max, $name) {
    $v = trim((string)$v);
    if ($v === '') { return ''; }
    if (!is_numeric($v) || $v < $min || $v > $max) { db_out(false, ['error' => $GLOBALS['dbx_T']('save.err_range', ['name' => $name, 'min' => $min, 'max' => $max])]); }
    return (string)(int)$v;
}

function db_bool($v) { return in_array(strtolower(trim((string)$v)), ['1', 'yes', 'true', 'on'], true) ? '1' : '0'; }
function db_req($v, $min, $max, $name) {
    $v = trim((string)$v);
    if ($v === '' || !is_numeric($v) || $v < $min || $v > $max) { db_out(false, ['error' => $GLOBALS['dbx_T']('save.err_number', ['name' => $name, 'min' => $min, 'max' => $max])]); }
    return (string)(int)$v;
}
$hook = trim((string)($_POST['dc_hook'] ?? ''));
// the page does not get the saved webhook: empty keeps it, - removes it
if ($hook === '') { $oldc = is_file($file) ? @parse_ini_file($file, false, INI_SCANNER_RAW) : []; $hook = is_array($oldc) ? (string)($oldc['dc_hook'] ?? '') : ''; }
elseif ($hook === '-') { $hook = ''; }
if ($hook !== '' && !preg_match('#^https://(?:discord|discordapp)\.com/api/webhooks/\d{15,25}/[A-Za-z0-9_\-]{20,120}$#', $hook)) {
    db_out(false, ['error' => $dbx_T('save.err_hook')]);
}
$ping = trim((string)($_POST['dc_ping'] ?? ''));
if ($ping !== '' && !preg_match('/^(\d{15,25}|role:\d{15,25}|@here|@everyone)$/', $ping)) { db_out(false, ['error' => $dbx_T('save.err_ping')]); }
$fw = db_req($_POST['n_fill_warn'] ?? '85', 1, 100, 'Fill warning');
$fc = db_req($_POST['n_fill_crit'] ?? '95', 1, 100, 'Fill critical');
if ((int)$fw > (int)$fc) { db_out(false, ['error' => $dbx_T('save.err_fill')]); }

$new = [
    'warn_use' => db_num($_POST['warn_use'] ?? '', 0, 100, 'Warning utilization'),
    'crit_use' => db_num($_POST['crit_use'] ?? '', 0, 100, 'Critical utilization'),
    'hot_temp' => db_num($_POST['hot_temp'] ?? '', 0, 100, 'Hot temperature'),
    'max_temp' => db_num($_POST['max_temp'] ?? '', 0, 100, 'Maximum temperature'),
    'smart'    => in_array(strtolower(trim((string)($_POST['smart'] ?? '1'))), ['1', 'yes', 'true', 'on'], true) ? '1' : '0',
    'dc_hook'  => $hook, 'dc_ping' => $ping,
    'dc_lang'  => (($_POST['dc_lang'] ?? 'hu') === 'en') ? 'en' : 'hu',
    'n_array' => db_bool($_POST['n_array'] ?? '1'), 'n_temp' => db_bool($_POST['n_temp'] ?? '1'), 'n_smart' => db_bool($_POST['n_smart'] ?? '1'),
    'n_fill' => db_bool($_POST['n_fill'] ?? '1'), 'n_fill_warn' => $fw, 'n_fill_crit' => $fc,
    'n_pool' => db_bool($_POST['n_pool'] ?? '1'), 'n_parity' => db_bool($_POST['n_parity'] ?? '1'), 'n_utest' => db_bool($_POST['n_utest'] ?? '1'),
    'n_remind' => db_req($_POST['n_remind'] ?? '24', 0, 168, 'Repeat interval'),
    'wk_on' => db_bool($_POST['wk_on'] ?? '1'), 'wk_day' => db_req($_POST['wk_day'] ?? '7', 1, 7, 'Weekly day'), 'wk_hour' => db_req($_POST['wk_hour'] ?? '18', 0, 23, 'Weekly hour'),
];

$text = '';
foreach ($new as $k => $v) { $text .= $k . '="' . str_replace('"', '', (string)$v) . '"' . "\n"; }

if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
$tmp = $file . '.tmp';
if (@file_put_contents($tmp, $text) === false) { db_out(false, ['error' => $dbx_T('save.err_write')]); }
if (!@rename($tmp, $file)) { @file_put_contents($file, $text); @unlink($tmp); }
@chmod($file, 0600);
@unlink('/tmp/diskbox_zpool.json');

db_out(true, ['saved' => $new]);
