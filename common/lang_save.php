<?php
// Saves the shared language setting (see lang.php). POST ui, discord, csrf_token.
require_once __DIR__ . '/lang.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
function mt_out($ok, $extra = []) { echo json_encode(array_merge(['ok' => $ok], $extra)); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); mt_out(false, ['error' => 'method']); }
$var = @parse_ini_file('/var/local/emhttp/var.ini');
$tok = is_array($var) ? (string)($var['csrf_token'] ?? '') : '';
if ($tok === '' || !hash_equals($tok, (string)($_POST['csrf_token'] ?? ''))) { http_response_code(403); mt_out(false, ['error' => 'csrf token mismatch']); }
if (!mt_lang_save((string)($_POST['ui'] ?? ''), (string)($_POST['discord'] ?? ''))) { mt_out(false, ['error' => 'could not save the language setting']); }
mt_out(true);
