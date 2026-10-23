<?php
// Saves or tests the shared Discord bot setting (see bot.php). POST op=save (token, user) | test, csrf_token.
require_once __DIR__ . '/bot.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
function mt_bot_out($ok, $extra = []) { echo json_encode(array_merge(['ok' => $ok], $extra)); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); mt_bot_out(false, ['error' => 'method']); }
$var = @parse_ini_file('/var/local/emhttp/var.ini');
$tok = is_array($var) ? (string)($var['csrf_token'] ?? '') : '';
// Unraid's own prepend checks the token of every POST and then removes it from $_POST (like lang_save.php)
$sent = (string)($_POST['csrf_token'] ?? '');
if ($sent !== '') {
    if ($tok === '' || !hash_equals($tok, $sent)) { http_response_code(403); mt_bot_out(false, ['error' => 'csrf token mismatch']); }
} elseif (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
    http_response_code(403); mt_bot_out(false, ['error' => 'csrf: token removed and request is not AJAX']);
}
$op = (string)($_POST['op'] ?? '');
if ($op === 'save') {
    $e = mt_bot_save((string)($_POST['token'] ?? ''), (string)($_POST['user'] ?? ''));
    if ($e !== '') { mt_bot_out(false, ['error' => mt_bot_t($e)]); }
    $c = mt_bot_cfg();
    mt_bot_out(true, ['msg' => mt_bot_t($c['token'] === '' ? 'bot.saved_off' : 'bot.saved'), 'token_set' => $c['token'] !== '']);
}
if ($op === 'test') {
    $p = basename(__DIR__) === 'qbittorrent_widget' ? 'Seedbox' : ucfirst(basename(__DIR__));
    [$ok, $m] = mt_bot_test($p);
    mt_bot_out($ok, $ok ? ['msg' => $m] : ['error' => $m]);
}
mt_bot_out(false, ['error' => 'op']);
