<?php
// Acknowledge (or forget) the SMART warnings of one disk. POST name=<disk>&op=ack|forget
require_once __DIR__ . '/lib.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
function dba_out($ok, $extra = []) { echo json_encode(array_merge(['ok' => $ok], $extra), JSON_UNESCAPED_UNICODE); exit; }

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); dba_out(false, ['error' => 'method']); }
$var  = @parse_ini_file('/var/local/emhttp/var.ini');
$tok  = is_array($var) ? (string)($var['csrf_token'] ?? '') : '';
$sent = (string)($_POST['csrf_token'] ?? '');
if ($sent !== '') {
    if ($tok === '' || !hash_equals($tok, $sent)) { http_response_code(403); dba_out(false, ['error' => 'csrf token mismatch']); }
} elseif (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
    http_response_code(403); dba_out(false, ['error' => 'csrf: token removed and request is not AJAX']);
}

$name = (string)($_POST['name'] ?? '');
$op = (string)($_POST['op'] ?? '');
$disks = db_disks();
if (!preg_match('/^[A-Za-z0-9_]{1,32}$/', $name) || !isset($disks[$name])) { http_response_code(400); dba_out(false, ['error' => 'unknown disk']); }
$ack = db_ack();

if ($op === 'forget') {
    unset($ack[$name]);
    dba_out(db_ack_write($ack));
}
if ($op !== 'ack') { http_response_code(400); dba_out(false, ['error' => 'unknown action']); }

$s = db_smart()[$name] ?? null;
if (!is_array($s)) { dba_out(false, ['error' => 'no SMART data for this disk yet']); }
$raw = [];
foreach ($s['attrs'] ?? [] as $x) { $raw[(string)$x[0]] = (int)$x[3]; }
$ack[$name] = ['t' => time(), 'raw' => $raw, 'nvme' => [
    'warn' => (int)($s['nvme_warn'] ?? 0), 'media' => (int)($s['nvme_media'] ?? 0), 'used' => (int)($s['nvme_used'] ?? 0)]];
dba_out(db_ack_write($ack));
