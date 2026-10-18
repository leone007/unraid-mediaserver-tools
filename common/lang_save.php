<?php
// Saves the shared language setting (see lang.php). POST ui, discord, csrf_token.
require_once __DIR__ . '/lang.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
function mt_out($ok, $extra = []) { echo json_encode(array_merge(['ok' => $ok], $extra)); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); mt_out(false, ['error' => 'method']); }
$var = @parse_ini_file('/var/local/emhttp/var.ini');
$tok = is_array($var) ? (string)($var['csrf_token'] ?? '') : '';
// Unraid's own prepend checks the token of every POST and then removes it from $_POST: no token here means it was
// checked already, which is accepted for an AJAX request only (like the other endpoints of these plugins)
$sent = (string)($_POST['csrf_token'] ?? '');
if ($sent !== '') {
    if ($tok === '' || !hash_equals($tok, $sent)) { http_response_code(403); mt_out(false, ['error' => 'csrf token mismatch']); }
} elseif (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
    http_response_code(403); mt_out(false, ['error' => 'csrf: token removed and request is not AJAX']);
}
if (!mt_lang_save((string)($_POST['ui'] ?? ''), (string)($_POST['discord'] ?? ''))) { mt_out(false, ['error' => 'could not save the language setting']); }
mt_out(true);
