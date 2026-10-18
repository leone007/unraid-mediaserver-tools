<?php
// Start, stop or restart one container. POST name=<container>&op=start|stop|restart
require_once __DIR__ . '/lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { sx_json(['ok' => false, 'error' => 'method'], 405); }
sx_guard();
$cfg = sx_cfg();
if (!sx_on($cfg['controls'])) { sx_json(['ok' => false, 'error' => 'the container buttons are switched off in the Sysbox settings'], 403); }

$name = (string)($_POST['name'] ?? '');
$op   = (string)($_POST['op'] ?? '');
if (!in_array($op, ['start', 'stop', 'restart'], true)) { sx_json(['ok' => false, 'error' => 'unknown action'], 400); }
if (!sx_valid_name($name)) { sx_json(['ok' => false, 'error' => 'invalid container name'], 400); }

$d = sx_docker();
if ($d === '') { sx_json(['ok' => false, 'error' => 'Docker is not running'], 503); }
$ps = sx_ps(true);
if (!isset($ps[$name])) { sx_json(['ok' => false, 'error' => 'no such container'], 404); }

list($rc, $out) = sx_run(escapeshellarg($d) . ' ' . $op . ' ' . escapeshellarg($name), 90);
@unlink('/tmp/sysbox_ps.json');
if ($rc === 0 && $op === 'stop') {   // so the Discord alerts do not report a stop made from the dashboard
    $mk = is_file('/tmp/sysbox_userstop.json') ? (json_decode((string)@file_get_contents('/tmp/sysbox_userstop.json'), true) ?: []) : [];
    $mk[$name] = time();
    @file_put_contents('/tmp/sysbox_userstop.json', json_encode($mk));
}
if ($rc !== 0) { sx_json(['ok' => false, 'error' => substr(trim($out), 0, 300) ?: ('docker exited with ' . $rc)], 500); }
sx_json(['ok' => true]);
