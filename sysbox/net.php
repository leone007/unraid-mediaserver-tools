<?php
// Network page endpoint (item 94). GET: the page data. POST op=save (the page's settings), op=check (one internet check
// now), op=ip (the public address, asked from api.ipify.org only on this click). Nothing on the server is changed.
require_once __DIR__ . '/net_lib.php';

$op = (string)($_POST['op'] ?? ($_GET['op'] ?? 'state'));
if ($op !== 'state') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { sx_json(['ok' => false, 'error' => 'method'], 405); }
    sx_guard();
}

if ($op === 'state') {
    $L = sxnet_live(); $cfg = sxnet_cfg(); $sx = sx_cfg();
    $hist = json_decode((string)@file_get_contents(sxnet_hist_file()), true); $days = is_array($hist) ? (array)($hist['days'] ?? []) : [];
    if ($L) { $days = array_merge($days, (array)($L['days'] ?? [])); }
    $mon = []; foreach ($days as $d => $v) { $m = substr((string)$d, 0, 7); $mon[$m] = [($mon[$m][0] ?? 0) + $v[0], ($mon[$m][1] ?? 0) + $v[1]]; }
    ksort($days); $last7 = [];
    for ($i = 6; $i >= 0; $i--) { $d = date('Y-m-d', strtotime("-$i days")); $last7[] = ['d' => $d, 'ts' => strtotime($d), 'rx' => $days[$d][0] ?? 0, 'tx' => $days[$d][1] ?? 0]; }
    $tm = date('Y-m'); $pm = date('Y-m', strtotime(date('Y-m-01') . ' -1 month'));
    sx_json(['ok' => true, 'on' => sx_on($sx['show_net'] ?? '1'), 'running' => $L !== null && time() - (int)$L['t'] < 30, 'cfg' => $cfg, 'ifaces' => sxnet_ifaces(), 'auto_if' => sxnet_iface(['iface' => 'auto']),
        'auto_gw' => sxnet_route()[1], 'live' => $L, 'last7' => $last7, 'today' => $days[date('Y-m-d')] ?? [0, 0, 0, 0],
        'month' => ['key' => $tm, 'v' => $mon[$tm] ?? [0, 0]], 'prev' => ['key' => $pm, 'v' => $mon[$pm] ?? null],
        'events' => is_array($hist) ? array_slice((array)($hist['events'] ?? []), -50) : []]);
}

if ($op === 'save') {
    $c = sxnet_cfg();
    $if = trim((string)($_POST['iface'] ?? 'auto')); if ($if !== 'auto' && !sxnet_if_ok($if)) { sx_json(['ok' => false, 'error' => sx_tr('netp.err_if')]); }
    $rt = trim((string)($_POST['router'] ?? 'auto')); if ($rt === '') { $rt = 'auto'; } if ($rt !== 'auto' && !sxnet_host_ok($rt)) { sx_json(['ok' => false, 'error' => sx_tr('netp.err_host', ['h' => $rt])]); }
    $out = [];
    foreach (explode(',', (string)($_POST['outside'] ?? '')) as $h) { $h = trim($h); if ($h === '') { continue; } if (!sxnet_host_ok($h)) { sx_json(['ok' => false, 'error' => sx_tr('netp.err_host', ['h' => $h])]); } $out[] = $h; }
    if (!$out || count($out) > 3) { sx_json(['ok' => false, 'error' => sx_tr('netp.err_out')]); }
    $ms = (int)($_POST['slow_ms'] ?? 150); if ($ms < 20 || $ms > 5000) { sx_json(['ok' => false, 'error' => sx_tr('save.err_number', ['name' => sx_tr('netp.s_slow'), 'min' => 20, 'max' => 5000])]); }
    $c = ['iface' => $if, 'router' => $rt, 'outside' => implode(', ', $out), 'slow_ms' => (string)$ms,
          'unit' => (($_POST['unit'] ?? 'MB') === 'Mbit') ? 'Mbit' : 'MB', 'dc' => in_array((string)($_POST['dc'] ?? '0'), ['1', 'on', 'yes'], true) ? '1' : '0'];
    if (!sxnet_cfg_save($c)) { sx_json(['ok' => false, 'error' => sx_tr('save.err_write')]); }
    sx_json(['ok' => true]);
}

if ($op === 'check') { sx_json(['ok' => true, 'check' => sxnet_check(sxnet_cfg())]); }

if ($op === 'ip') {
    $ch = curl_init('https://api.ipify.org');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4]);
    $b = trim((string)curl_exec($ch)); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code !== 200 || !filter_var($b, FILTER_VALIDATE_IP)) { sx_json(['ok' => false, 'error' => sx_tr('netp.ip_fail')]); }
    sx_json(['ok' => true, 'ip' => $b]);
}
sx_json(['ok' => false, 'error' => 'op'], 400);
