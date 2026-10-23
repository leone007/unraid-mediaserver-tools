<?php
require_once __DIR__ . '/lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { sx_json(['ok' => false, 'error' => 'method'], 405); }
sx_guard();

function sx_int($v, $min, $max, $name) {
    $v = trim((string)$v);
    if ($v === '' || !is_numeric($v) || $v < $min || $v > $max) { sx_json(['ok' => false, 'error' => sx_tr('save.err_number', ['name' => $name, 'min' => $min, 'max' => $max])], 400); }
    return (string)(int)$v;
}
function sx_hook($v) {
    $v = trim((string)$v);
    if ($v !== '' && !preg_match('#^https://(?:discord|discordapp)\.com/api/webhooks/\d{15,25}/[A-Za-z0-9_\-]{20,120}$#', $v)) { sx_json(['ok' => false, 'error' => sx_tr('save.err_hook')], 400); }
    return $v;
}
// the page does not get the saved webhook: empty keeps it, - removes it
function sx_hook_keep($v) { $v = trim((string)$v); if ($v === '') { return (string)(sx_cfg()['dc_hook'] ?? ''); } if ($v === '-') { return ''; } return sx_hook($v); }
function sx_choice($v, $allowed, $name) {
    $v = trim((string)$v);
    if (!in_array($v, $allowed, true)) { sx_json(['ok' => false, 'error' => sx_tr('save.err_secs', ['name' => $name, 'list' => implode(', ', $allowed)])], 400); }
    return $v;
}
function sx_bool($v) { return in_array(strtolower(trim((string)$v)), ['1', 'yes', 'true', 'on'], true) ? '1' : '0'; }

$ping = trim((string)($_POST['dc_ping'] ?? ''));
if ($ping !== '' && !preg_match('/^(\d{15,25}|role:\d{15,25}|@here|@everyone)$/', $ping)) { sx_json(['ok' => false, 'error' => sx_tr('save.err_ping')], 400); }
$mode = (string)($_POST['top_mode'] ?? 'cpu');
if (!in_array($mode, ['cpu', 'ram', 'selected'], true)) { sx_json(['ok' => false, 'error' => sx_tr('save.err_mode')], 400); }

$sel = [];
foreach ((array)($_POST['sel'] ?? []) as $n) {
    $n = (string)$n;
    if (sx_valid_name($n)) { $sel[] = $n; }
}

$host = trim((string)($_POST['socket_host'] ?? ''));
if ($host !== '' && !preg_match('/^[A-Za-z0-9.\-]+$/', $host)) { sx_json(['ok' => false, 'error' => sx_tr('save.err_sock')], 400); }
$price = trim((string)($_POST['kwh_price'] ?? ''));
if ($price !== '' && (!is_numeric($price) || $price < 0 || $price > 100000)) { sx_json(['ok' => false, 'error' => sx_tr('save.err_price')], 400); }
$cur = preg_replace('/[^\p{L}\p{N}$€£ .\-]/u', '', (string)($_POST['currency'] ?? ''));
$cur = function_exists('mb_substr') ? mb_substr($cur, 0, 6) : substr($cur, 0, 6);

$new = [
    'top_mode' => $mode, 'top_n' => sx_int($_POST['top_n'] ?? '3', 1, 10, 'Number of rows'),
    'sel' => implode(',', array_values(array_unique($sel))),
    'controls' => sx_bool($_POST['controls'] ?? '1'), 'logs' => sx_bool($_POST['logs'] ?? '1'),
    'log_lines' => sx_int($_POST['log_lines'] ?? '100', 10, 500, 'Log lines'),
    'hist_min' => sx_int($_POST['hist_min'] ?? '2', 1, 60, 'CPU history'),
    'cpu_sec' => sx_choice($_POST['cpu_sec'] ?? '1', ['1', '2', '5', '10'], 'CPU refresh'), 'clk_sec' => sx_choice($_POST['clk_sec'] ?? '2', ['1', '2', '5', '10'], 'Clock refresh'),
    'show_clock' => sx_bool($_POST['show_clock'] ?? '1'),
    'show_gpu' => sx_bool($_POST['show_gpu'] ?? '1'), 'show_containers' => sx_bool($_POST['show_containers'] ?? '1'),
    'show_ram' => sx_bool($_POST['show_ram'] ?? '1'), 'show_sto' => sx_bool($_POST['show_sto'] ?? '1'), 'show_cpu' => sx_bool($_POST['show_cpu'] ?? '1'), 'show_pow' => sx_bool($_POST['show_pow'] ?? '1'),
    'order' => implode(',', sx_order(['order' => (string)($_POST['order'] ?? '')])), 'ram_leg' => sx_bool($_POST['ram_leg'] ?? '1'),
    'cpu_load' => sx_bool($_POST['cpu_load'] ?? '1'), 'cpu_temp' => sx_bool($_POST['cpu_temp'] ?? '1'), 'cpu_pow' => sx_bool($_POST['cpu_pow'] ?? '1'),
    'cpu_hist' => sx_bool($_POST['cpu_hist'] ?? '1'), 'cpu_per' => sx_bool($_POST['cpu_per'] ?? '1'), 'cpu_la' => sx_bool($_POST['cpu_la'] ?? '0'),
    'pow_w' => sx_bool($_POST['pow_w'] ?? '1'), 'pow_day' => sx_bool($_POST['pow_day'] ?? '1'), 'pow_mon' => sx_bool($_POST['pow_mon'] ?? '1'),
    'pow_cost' => sx_bool($_POST['pow_cost'] ?? '1'), 'pow_name' => sx_bool($_POST['pow_name'] ?? '1'),
    'show_net' => sx_bool($_POST['show_net'] ?? '1'), 'net_speed' => sx_bool($_POST['net_speed'] ?? '1'), 'net_today' => sx_bool($_POST['net_today'] ?? '1'),
    'net_hist' => sx_bool($_POST['net_hist'] ?? '1'), 'net_inet' => sx_bool($_POST['net_inet'] ?? '1'), 'net_top' => sx_bool($_POST['net_top'] ?? '1'),
    'ram_warn' => sx_int($_POST['ram_warn'] ?? '80', 1, 100, 'RAM warning'), 'ram_crit' => sx_int($_POST['ram_crit'] ?? '92', 1, 100, 'RAM critical'),
    'log_warn' => sx_int($_POST['log_warn'] ?? '80', 1, 100, 'Log warning'), 'log_crit' => sx_int($_POST['log_crit'] ?? '92', 1, 100, 'Log critical'),
    'vd_warn' => sx_int($_POST['vd_warn'] ?? '80', 1, 100, 'Docker vdisk warning'), 'vd_crit' => sx_int($_POST['vd_crit'] ?? '92', 1, 100, 'Docker vdisk critical'),
    'temp_warn' => sx_int($_POST['temp_warn'] ?? '75', 20, 120, 'CPU temperature warning'), 'temp_crit' => sx_int($_POST['temp_crit'] ?? '90', 20, 120, 'CPU temperature critical'),
    'socket_host' => $host, 'socket_id' => sx_int($_POST['socket_id'] ?? '0', 0, 3, 'Socket channel'),
    'kwh_price' => $price, 'currency' => $cur,
    'dc_hook' => sx_hook_keep($_POST['dc_hook'] ?? ''), 'dc_ping' => $ping, 'dc_lang' => (($_POST['dc_lang'] ?? 'hu') === 'en') ? 'en' : 'hu',
    'n_containers' => sx_bool($_POST['n_containers'] ?? '1'), 'n_ram' => sx_bool($_POST['n_ram'] ?? '1'), 'n_storage' => sx_bool($_POST['n_storage'] ?? '1'),
    'n_cpu' => sx_bool($_POST['n_cpu'] ?? '1'), 'n_boot' => sx_bool($_POST['n_boot'] ?? '1'),
    'n_remind' => sx_int($_POST['n_remind'] ?? '24', 0, 168, 'Repeat interval'),
    'wk_on' => sx_bool($_POST['wk_on'] ?? '1'), 'wk_day' => sx_int($_POST['wk_day'] ?? '7', 1, 7, 'Weekly day'), 'wk_hour' => sx_int($_POST['wk_hour'] ?? '19', 0, 23, 'Weekly hour'),
];
foreach (['ram', 'log', 'vd', 'temp'] as $k) {
    if ((int)$new[$k . '_warn'] > (int)$new[$k . '_crit']) { sx_json(['ok' => false, 'error' => sx_tr('save.err_limits')], 400); }
}

$text = '';
foreach ($new as $k => $v) { $text .= $k . '="' . str_replace('"', '', $v) . '"' . "\n"; }

$dir = dirname(SX_CFG);
if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
$tmp = SX_CFG . '.tmp';
if (@file_put_contents($tmp, $text) === false) { sx_json(['ok' => false, 'error' => sx_tr('save.err_write')], 500); }
if (!@rename($tmp, SX_CFG)) { @file_put_contents(SX_CFG, $text); @unlink($tmp); }
@chmod(SX_CFG, 0600);
@unlink('/tmp/sysbox_slow.json');
sx_json(['ok' => true]);
