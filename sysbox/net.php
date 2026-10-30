<?php
// Network page endpoint (item 94). GET: the page data. POST op=save (the page's settings; with stream priority on, the
// upload and download limits are written into qBittorrent's alternative limits), op=check (one internet check now),
// op=ip (the public address, asked from api.ipify.org only on this click), op=sp_hold / sp_release (the tile's stream
// priority button: qBittorrent's alternative limit on or off), op=sp_test (asks Jellyfin and qBittorrent once).
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
        'events' => is_array($hist) ? array_slice((array)($hist['events'] ?? []), -50) : [],
        'sp' => sxsp_live(), 'sp_have' => ['srv' => array_keys(sxsp_servers()), 'qb' => sxsp_qb_cfg()['host'] !== '' && is_file(getenv('SX_SP_QB_CFG') ?: '/boot/config/plugins/qbittorrent_widget/settings.cfg')]]);
}

if ($op === 'save') {
    $c = sxnet_cfg();
    $if = trim((string)($_POST['iface'] ?? 'auto')); if ($if !== 'auto' && !sxnet_if_ok($if)) { sx_json(['ok' => false, 'error' => sx_tr('netp.err_if')]); }
    $rt = trim((string)($_POST['router'] ?? 'auto')); if ($rt === '') { $rt = 'auto'; } if ($rt !== 'auto' && !sxnet_host_ok($rt)) { sx_json(['ok' => false, 'error' => sx_tr('netp.err_host', ['h' => $rt])]); }
    $out = [];
    foreach (explode(',', (string)($_POST['outside'] ?? '')) as $h) { $h = trim($h); if ($h === '') { continue; } if (!sxnet_host_ok($h)) { sx_json(['ok' => false, 'error' => sx_tr('netp.err_host', ['h' => $h])]); } $out[] = $h; }
    if (!$out || count($out) > 3) { sx_json(['ok' => false, 'error' => sx_tr('netp.err_out')]); }
    $ms = (int)($_POST['slow_ms'] ?? 150); if ($ms < 20 || $ms > 5000) { sx_json(['ok' => false, 'error' => sx_tr('save.err_number', ['name' => sx_tr('netp.s_slow'), 'min' => 20, 'max' => 5000])]); }
    $on = function ($k) { return in_array((string)($_POST[$k] ?? '0'), ['1', 'on', 'yes'], true) ? '1' : '0'; };
    $num = function ($k, $min, $max, $label) {
        $v = str_replace(',', '.', trim((string)($_POST[$k] ?? '')));
        if (!is_numeric($v) || (float)$v < $min || (float)$v > $max) { sx_json(['ok' => false, 'error' => sx_tr('save.err_number', ['name' => sx_tr($label), 'min' => $min, 'max' => $max])]); }
        return rtrim(rtrim(number_format((float)$v, 1, '.', ''), '0'), '.');
    };
    $mode = in_array((string)($_POST['sp_mode'] ?? ''), ['off', 'auto', 'button'], true) ? (string)$_POST['sp_mode'] : $c['sp_mode'];
    $c = array_merge($c, ['iface' => $if, 'router' => $rt, 'outside' => implode(', ', $out), 'slow_ms' => (string)$ms,
          'unit' => (($_POST['unit'] ?? 'MB') === 'Mbit') ? 'Mbit' : 'MB', 'dc' => $on('dc'), 'host_row' => $on('host_row'),
          'sp_mode' => $mode, 'sp_up' => $num('sp_up', 0.1, 1000, 'netp.sp_s_up'), 'sp_dn' => $num('sp_dn', 0, 1000, 'netp.sp_s_dn'),
          'sp_out' => ($_POST['sp_out'] ?? '') === 'all' ? 'all' : 'outside', 'sp_back' => (string)(int)$num('sp_back', 1, 120, 'netp.sp_s_back'), 'sp_own' => $on('sp_own')]);
    if (!sxnet_cfg_save($c)) { sx_json(['ok' => false, 'error' => sx_tr('save.err_write')]); }
    // the limits go into qBittorrent's alternative limits (the only qBittorrent setting Sysbox writes, on this click)
    $warn = '';
    if ($mode !== 'off' && !sxsp_qb_set_alt((float)$c['sp_up'], (float)$c['sp_dn'])) { $warn = sx_tr('netp.sp_qb_not_set'); }
    sx_json(['ok' => true, 'warn' => $warn]);
}

if ($op === 'sp_hold' || $op === 'sp_release') {
    $L = sxsp_run($op === 'sp_hold' ? 'hold' : 'release');
    if ($L['err'] !== '') { sx_json(['ok' => false, 'error' => sx_tr('netp.sp_err_qb')]); }
    sx_json(['ok' => true, 'sp' => $L]);
}
if ($op === 'sp_test') {
    $s = sxsp_sessions($st); $q = sxsp_qb_mode(); $a = $q === null ? null : sxsp_qb_alt();
    // per server: answered, playing, outside
    $per = [];
    foreach ($st as $id => $ok) {
        $mine = array_filter((array)$s, function ($x) use ($id) { return $x[4] === $id; });
        $per[] = ['id' => $id, 'ok' => $ok, 'n' => count($mine), 'out' => count(array_filter($mine, function ($x) { return $x[3]; }))];
    }
    sx_json(['ok' => true, 'jf' => $s !== null, 'srv' => $per, 'n' => $s === null ? 0 : count($s), 'out' => $s === null ? 0 : count(array_filter($s, function ($x) { return $x[3]; })), 'qb' => $q !== null, 'q' => $q, 'alt' => $a]);
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
