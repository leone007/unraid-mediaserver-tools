<?php
// The last lines of one container's log. GET name=<container>&lines=<n>
require_once __DIR__ . '/lib.php';

if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') { sx_json(['ok' => false, 'error' => 'not an AJAX request'], 403); }
$cfg = sx_cfg();
if (!sx_on($cfg['logs'])) { sx_json(['ok' => false, 'error' => 'the log button is switched off in the Sysbox settings'], 403); }

$name = (string)($_GET['name'] ?? '');
if (!sx_valid_name($name)) { sx_json(['ok' => false, 'error' => 'invalid container name'], 400); }
$n = max(10, min(500, (int)($_GET['lines'] ?? $cfg['log_lines'])));

$d = sx_docker();
if ($d === '') { sx_json(['ok' => false, 'error' => 'Docker is not running'], 503); }
$ps = sx_ps();
if (!isset($ps[$name])) { sx_json(['ok' => false, 'error' => 'no such container'], 404); }

list($rc, $out) = sx_run(escapeshellarg($d) . ' logs --tail ' . $n . ' --timestamps ' . escapeshellarg($name), 10);
if ($rc !== 0 && $out === '') { sx_json(['ok' => false, 'error' => 'could not read the log'], 500); }
if (strlen($out) > 200000) { $out = substr($out, -200000); }
sx_json(['ok' => true, 'text' => $out]);
