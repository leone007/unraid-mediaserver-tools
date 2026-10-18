<?php
header('Content-Type: application/json');
header('Cache-Control: no-store');

function qbt_out($ok, $extra = []) {
    echo json_encode(array_merge(['ok' => $ok], $extra));
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); qbt_out(false, ['error' => 'method']); }

// CSRF: Unraid's own prepend script already validates the csrf_token of every POST and then removes it
// from $_POST. If a token is still present, check it; otherwise require an AJAX request, which a
// cross-site form post cannot fake.
$var  = @parse_ini_file('/var/local/emhttp/var.ini');
$tok  = is_array($var) ? (string)($var['csrf_token'] ?? '') : '';
$sent = (string)($_POST['csrf_token'] ?? '');
if ($sent !== '') {
    if ($tok === '' || !hash_equals($tok, $sent)) {
        http_response_code(403);
        qbt_out(false, ['error' => 'csrf token mismatch (var.ini token ' . ($tok !== '' ? 'present' : 'missing') . ')']);
    }
} elseif (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
    http_response_code(403);
    qbt_out(false, ['error' => 'csrf: token removed and request is not AJAX']);
}

$dir  = '/boot/config/plugins/qbittorrent_widget';
$file = $dir . '/settings.cfg';
$old  = is_file($file) ? @parse_ini_file($file, false, INI_SCANNER_RAW) : [];
if (!is_array($old)) { $old = []; }

function qbt_int($v, $min, $max, $def) { $i = (int)$v; return ($i < $min || $i > $max) ? $def : $i; }
function qbt_bool($v) { return in_array(strtolower(trim((string)$v)), ['1', 'yes', 'true', 'on'], true) ? '1' : '0'; }
function qbt_clean($v) { return substr(str_replace(["\r", "\n", "\0"], '', (string)$v), 0, 200); }

$user = qbt_clean($_POST['user'] ?? '');
$pass = qbt_clean($_POST['pass'] ?? '');
// the page does not get the saved password: empty keeps it, - removes it
if ($pass === '') { $pass = (string)(is_array($old) ? ($old['pass'] ?? '') : ''); } elseif ($pass === '-') { $pass = ''; }
if (strpos($user, '"') !== false || strpos($pass, '"') !== false) { qbt_out(false, ['error' => 'quote_not_allowed']); }

$host = trim((string)($_POST['host'] ?? '127.0.0.1'));
if (!preg_match('/^[A-Za-z0-9.\-]+$/', $host)) { $host = '127.0.0.1'; }

$url = trim(qbt_clean($_POST['webui_url'] ?? ''));
if ($url !== '' && !preg_match('#^https?://[^\s"<>]+$#i', $url)) { qbt_out(false, ['error' => 'invalid Web UI link URL']); }

$new = [
    'host'          => $host,
    'port'          => (string)qbt_int($_POST['port'] ?? 8080, 1, 65535, 8080),
    'user'          => $user,
    'pass'          => $pass,
    'webui_url'     => $url,
    'rows'          => (string)qbt_int($_POST['rows'] ?? 6, 1, 50, 6),
    'max_attention' => (string)qbt_int($_POST['max_attention'] ?? 15, 1, 50, 15),
    'show_bar'      => qbt_bool($_POST['show_bar'] ?? '1'),
    'col_dl'        => qbt_bool($_POST['col_dl'] ?? '1'),
    'col_ul'        => qbt_bool($_POST['col_ul'] ?? '1'),
    'col_eta'       => qbt_bool($_POST['col_eta'] ?? '1'),
];

$text = '';
foreach ($new as $k => $v) { $text .= $k . '="' . $v . '"' . "\n"; }

if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
$tmp = $file . '.tmp';
if (@file_put_contents($tmp, $text) === false) { qbt_out(false, ['error' => 'could not write the settings file']); }
@chmod($tmp, 0600);
if (!@rename($tmp, $file)) {
    // some filesystems refuse to replace an existing file: write in place instead
    $ok = @file_put_contents($file, $text);
    @unlink($tmp);
    if ($ok === false) { qbt_out(false, ['error' => 'could not replace the settings file']); }
}
@chmod($file, 0600);
@unlink('/tmp/qbt_widget_authfail');   // credentials may have changed: allow a fresh login attempt

$shown = $new;
unset($shown['pass']);
qbt_out(true, ['saved' => $shown]);
