<?php
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/i18n.php';
$sbx_T = mt_i18n_t(__DIR__ . '/lang');   // texts shown on the tile and the settings page

function sb_out($ok, $extra = []) {
    echo json_encode(array_merge(['ok' => $ok], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); sb_out(false, ['error' => 'method']); }

// CSRF: Unraid's own prepend script validates csrf_token and removes it from $_POST;
// if a token is still present check it, otherwise require an AJAX request.
$var  = @parse_ini_file('/var/local/emhttp/var.ini');
$tok  = is_array($var) ? (string)($var['csrf_token'] ?? '') : '';
$sent = (string)($_POST['csrf_token'] ?? '');
if ($sent !== '') {
    if ($tok === '' || !hash_equals($tok, $sent)) { http_response_code(403); sb_out(false, ['error' => 'csrf token mismatch']); }
} elseif (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
    http_response_code(403);
    sb_out(false, ['error' => 'csrf: token removed and request is not AJAX']);
}

$dir   = '/boot/config/plugins/streambox';
$file  = $dir . '/settings.cfg';
$nfile = $dir . '/nicknames.json';

function sb_int($v, $min, $max, $def) { $i = (int)$v; return ($i < $min || $i > $max) ? $def : $i; }
function sb_bool($v) { return in_array(strtolower(trim((string)$v)), ['1', 'yes', 'true', 'on'], true) ? '1' : '0'; }

$host = trim((string)($_POST['jf_host'] ?? '127.0.0.1'));
if (!preg_match('/^[A-Za-z0-9.\-]+$/', $host)) { $host = '127.0.0.1'; }

// the page does not get the saved key and webhooks: empty keeps the saved value, - removes it
$old = is_file($file) ? @parse_ini_file($file, false, INI_SCANNER_RAW) : [];
if (!is_array($old)) { $old = []; }
function sb_keep($name, $old) { $v = trim((string)($_POST[$name] ?? '')); return $v === '' ? (string)($old[$name] ?? '') : ($v === '-' ? '' : $v); }
$key = sb_keep('jf_key', $old);
if (!preg_match('/^[A-Za-z0-9._\-]{0,128}$/', $key)) { sb_out(false, ['error' => $sbx_T('save.err_key')]); }

$url = trim(str_replace(["\r", "\n", "\0"], '', (string)($_POST['jf_url'] ?? '')));
if ($url !== '' && !preg_match('#^https?://[^\s"<>]+$#i', $url)) { sb_out(false, ['error' => $sbx_T('save.err_url')]); }

$sockHost = trim((string)($_POST['socket_host'] ?? ''));
if ($sockHost !== '' && !preg_match('/^[A-Za-z0-9.\-]+$/', $sockHost)) { sb_out(false, ['error' => $sbx_T('save.err_socket')]); }

$hookRe = '#^https://(?:discord|discordapp)\.com/api/webhooks/\d{15,25}/[A-Za-z0-9_\-]{20,120}$#';
$dcm = sb_keep('dc_movies', $old);
$dcs = sb_keep('dc_series', $old);
if ($dcm !== '' && !preg_match($hookRe, $dcm)) { sb_out(false, ['error' => $sbx_T('save.err_hook_movies')]); }
if ($dcs !== '' && !preg_match($hookRe, $dcs)) { sb_out(false, ['error' => $sbx_T('save.err_hook_series')]); }

$dcw = sb_keep('dc_weekly', $old);
if ($dcw !== '' && !preg_match($hookRe, $dcw)) { sb_out(false, ['error' => $sbx_T('save.err_hook_weekly')]); }

$sonKey = sb_keep('son_key', $old);
if (!preg_match('/^[A-Za-z0-9]{0,64}$/', $sonKey)) { sb_out(false, ['error' => $sbx_T('save.err_son_key')]); }
$sonUrl = rtrim(trim(str_replace(["\r", "\n", "\0", '"'], '', (string)($_POST['son_url'] ?? ''))), '/');
if ($sonUrl !== '' && !preg_match('#^https?://[A-Za-z0-9.\-]+(:\d{1,5})?(/[^\s"<>]*)?$#i', $sonUrl)) { sb_out(false, ['error' => $sbx_T('save.err_son_url')]); }
$wkWebDir = rtrim(trim((string)($_POST['wk_web_dir'] ?? '/mnt/cache/docker/streambox/web')), '/');
if (!preg_match('#^/mnt/[A-Za-z0-9._\-]+(/[A-Za-z0-9._\-]+)+$#', $wkWebDir)) { sb_out(false, ['error' => $sbx_T('save.err_dir')]); }
$wkDelay = (string)($_POST['wk_delay'] ?? 'series');
if (!in_array($wkDelay, ['series', 'idx', 'all'], true)) { $wkDelay = 'series'; }

$oldNotify = is_array($old) ? sb_bool($old['notify'] ?? '0') : '0';
$newNotify = sb_bool($_POST['notify'] ?? '0');

$new = [
    'jf_host'     => $host,
    'jf_port'     => (string)sb_int($_POST['jf_port'] ?? 8096, 1, 65535, 8096),
    'jf_key'      => $key,
    'jf_url'      => $url,
    'streams_max' => (string)sb_int($_POST['streams_max'] ?? 8, 1, 30, 8),
    'added_rows'  => (string)sb_int($_POST['added_rows'] ?? 5, 0, 20, 5),
    'show_load'   => sb_bool($_POST['show_load'] ?? '1'),
    'socket_host' => $sockHost,
    'socket_id'   => (string)sb_int($_POST['socket_id'] ?? 0, 0, 3, 0),
    'notify'        => $newNotify,
    'dc_movies'     => $dcm,
    'dc_series'     => $dcs,
    'notify_settle' => (string)sb_int($_POST['notify_settle'] ?? 5, 1, 60, 5),
    'notify_wait'   => (string)sb_int($_POST['notify_wait'] ?? 30, 1, 240, 30),
    'weekly'        => sb_bool($_POST['weekly'] ?? '0'),
    'dc_weekly'     => $dcw,
    'weekly_day'    => (string)sb_int($_POST['weekly_day'] ?? 5, 0, 6, 5),
    'weekly_hour'   => (string)sb_int($_POST['weekly_hour'] ?? 18, 0, 23, 18),
    'wk_show'       => (string)sb_int($_POST['wk_show'] ?? 1, 0, 2, 1),
    'wk_src'        => ((string)($_POST['wk_src'] ?? '') === 'own') ? 'own' : 'seedbox',
    'son_url'       => $sonUrl,
    'son_key'       => $sonKey,
    'wk_delay'      => $wkDelay,
    'wk_late'       => (string)sb_int($_POST['wk_late'] ?? 2, 1, 48, 2),
    'wk_dc'         => sb_bool($_POST['wk_dc'] ?? '0'),
    'wk_web'        => sb_bool($_POST['wk_web'] ?? '0'),
    'wk_web_dir'    => $wkWebDir,
    'wk_web_view'   => ((string)($_POST['wk_web_view'] ?? '') === 'row') ? 'row' : 'grid',
];

$text = '';
foreach ($new as $k => $v) { $text .= $k . '="' . $v . '"' . "\n"; }

// Nicknames: {userId: nickname}
$map = [];
$in  = $_POST['nick'] ?? [];
if (is_array($in)) {
    foreach ($in as $id => $v) {
        $id = (string)$id;
        if (!preg_match('/^[0-9a-fA-F\-]{32,36}$/', $id)) { continue; }
        $v = trim(preg_replace('/[\x00-\x1f"\\\\]/u', '', (string)$v));
        if ($v === '') { continue; }
        if (preg_match('/^.{1,24}/us', $v, $m)) { $v = $m[0]; }
        $map[$id] = $v;
    }
}

if (!is_dir($dir)) { @mkdir($dir, 0755, true); }

function sb_write($path, $data) {
    $tmp = $path . '.tmp';
    if (@file_put_contents($tmp, $data) === false) { return false; }
    @chmod($tmp, 0600);
    if (!@rename($tmp, $path)) {
        $ok = @file_put_contents($path, $data);
        @unlink($tmp);
        if ($ok === false) { return false; }
    }
    @chmod($path, 0600);
    return true;
}

if (!sb_write($file, $text)) { sb_out(false, ['error' => $sbx_T('save.err_write')]); }
// only touch the nickname list when the nickname fields were part of the form
if (isset($_POST['nick']) && !sb_write($nfile, json_encode((object)$map, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT))) {
    sb_out(false, ['error' => $sbx_T('save.err_nick')]);
}
@unlink('/tmp/streambox_added.json');
@unlink('/tmp/streambox_week.json');
@unlink('/tmp/streambox_delay.json');
// switched on again: start counting from now, do not announce what was added while it was off
if ($oldNotify !== '1' && $newNotify === '1') { @unlink($dir . '/notify_state.json'); }

$shown = $new;
unset($shown['jf_key'], $shown['son_key'], $shown['dc_movies'], $shown['dc_series'], $shown['dc_weekly']);
sb_out(true, ['saved' => $shown, 'nicknames' => count($map)]);
