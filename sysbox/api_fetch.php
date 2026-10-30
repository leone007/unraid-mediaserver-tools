<?php
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/energy.php';
require_once __DIR__ . '/i18n.php';
$sxx_T = mt_i18n_t(__DIR__ . '/lang');   // texts shown on the tile (lang/en.json)
header('Content-Type: application/json');
header('Cache-Control: no-store');

$cfg = sx_cfg();
$attn = [];   // [level, name, text, small]

// ---- memory ------------------------------------------------------------------------------------
function sx_physical_ram() {
    $f = '/tmp/sysbox_dmi.json';
    if (is_file($f)) { $j = json_decode((string)@file_get_contents($f), true); if (is_array($j)) { return $j; } }
    $res = ['size' => null, 'type' => ''];
    $b = sx_bin('dmidecode');
    if ($b !== '') {
        list($rc, $txt) = sx_run(escapeshellarg($b) . ' -t memory', 6);
        if ($rc === 0) {
            $sum = 0.0;
            foreach (preg_split('/Memory Device/', $txt) as $blk) {
                if (preg_match('/^\s*Size:\s*(\d+)\s*(MB|GB)/m', $blk, $m)) { $sum += $m[2] === 'GB' ? (float)$m[1] : (float)$m[1] / 1024; }
                if ($res['type'] === '' && preg_match('/^\s*Type:\s*(DDR\d\w*|LPDDR\d\w*)/m', $blk, $m)) { $res['type'] = $m[1]; }
            }
            if ($sum > 0) { $res['size'] = $sum; }
        }
    }
    @file_put_contents($f, json_encode($res));
    return $res;
}

$mm = sx_mem();
$mTotal = $mm['total']; $mAvail = $mm['avail']; $mUsed = $mm['used']; $arc = $mm['arc'];

// ---- slow readings (containers' stats, GPU, socket): collected in the background ----------------
$slowFile = '/tmp/sysbox_slow.json';
$slow = is_file($slowFile) ? json_decode((string)@file_get_contents($slowFile), true) : null;
$age = is_array($slow) ? time() - (int)($slow['t'] ?? 0) : 9999;
if ($age > 8) {
    $lk = '/tmp/sysbox_collect.lock';
    if (!is_file($lk) || (time() - filemtime($lk)) > 1 || $age > 60) {
        @exec('nohup ' . escapeshellarg(sx_php()) . ' ' . escapeshellarg(__DIR__ . '/collect.php') . ' >/dev/null 2>&1 &');
    }
}
$stats = (is_array($slow) && isset($slow['containers']) && is_array($slow['containers'])) ? $slow['containers'] : null;

$dockerMem = 0.0;
if ($stats) { foreach ($stats as $s) { $dockerMem += (float)($s['mem'] ?? 0); } }
$dockerMem = min($dockerMem, max(0.0, $mUsed - $arc));
$system = max(0.0, $mUsed - $arc - $dockerMem);
$phys = sx_physical_ram();

$memPct = $mTotal > 0 ? $mUsed / $mTotal * 100 : 0;
$memAlertPct = $mTotal > 0 ? max(0.0, $mUsed - $arc) / $mTotal * 100 : 0;   // the ZFS cache gives way to applications
if ($memAlertPct >= (float)$cfg['ram_warn']) {
    $crit = $memAlertPct >= (float)$cfg['ram_crit'];
    $attn[] = [$crit ? 'rd' : 'a', 'RAM', $sxx_T('attn.used', ['p' => round($memAlertPct)]), '@FREE@' . $mAvail . ' free'];
}
$memory = [
    'total' => $mTotal, 'used' => $mUsed, 'pct' => $memPct, 'free' => $mAvail,
    'system' => $system, 'zfs' => $arc, 'docker' => $dockerMem, 'docker_known' => $stats !== null,
    'phys' => $phys['size'], 'type' => $phys['type'],
    'level' => $memAlertPct >= (float)$cfg['ram_crit'] ? 'rd' : ($memAlertPct >= (float)$cfg['ram_warn'] ? 'a' : ''),
];

// ---- storage -------------------------------------------------------------------------------------
function sx_fs($label, $path, $warn, $crit, &$attn) {
    global $sxx_T;
    $u = sx_fs_use($path);
    if (!$u) { return null; }
    $t = $u['size']; $f = $u['free']; $used = $u['used']; $pct = $u['pct'];
    $lv = $pct >= $crit ? 'rd' : ($pct >= $warn ? 'a' : '');
    if ($lv !== '') { $attn[] = [$lv, $label, $sxx_T('attn.full', ['p' => number_format($pct, 0)]), '@FREE@' . $f . ' free']; }
    return ['label' => $label, 'size' => $t, 'used' => $used, 'pct' => $pct, 'level' => $lv];
}
$storage = [];
$s = sx_fs('BOOT', '/boot', 90, 97, $attn); if ($s) { $storage[] = $s; }
$s = sx_fs('LOG', '/var/log', (float)$cfg['log_warn'], (float)$cfg['log_crit'], $attn); if ($s) { $storage[] = $s; }
if (sx_is_mounted('/var/lib/docker')) {
    $s = sx_fs($sxx_T('sto.vdisk'), '/var/lib/docker', (float)$cfg['vd_warn'], (float)$cfg['vd_crit'], $attn); if ($s) { $storage[] = $s; }
}

// ---- CPU -----------------------------------------------------------------------------------------
list($load, $threads) = sx_cpu_rates();
$info = sx_cpu_info();
list($tPkg, $tCore) = sx_temps();
$clock = sx_on($cfg['show_clock']) ? sx_clock() : null;
$gpuRaw = (is_array($slow) && isset($slow['gpu']) && is_array($slow['gpu'])) ? $slow['gpu'] : null;
$tlevel = '';
if ($tPkg !== null) {
    $tlevel = $tPkg >= (float)$cfg['temp_crit'] ? 'rd' : ($tPkg >= (float)$cfg['temp_warn'] ? 'a' : '');
    if ($tlevel !== '') { $attn[] = [$tlevel, 'CPU', $tlevel === 'rd' ? $sxx_T('attn.too_hot') : $sxx_T('attn.hot'), $tPkg . '°C']; }
}
$la = @sys_getloadavg();
$cpu = [
    'model' => $info['model'], 'cores' => $info['cores'], 'threads' => $info['threads'],
    'load' => $load, 'per' => $threads, 'temp' => $tPkg, 'tlevel' => $tlevel, 'hot_core' => $tCore,
    'clock' => $clock, 'power' => ($gpuRaw && $gpuRaw['power'] > 0) ? $gpuRaw['power'] : null,
    'loadavg' => $la ? round($la[0], 2) : null,
];

// ---- GPU -----------------------------------------------------------------------------------------
$gpu = null;
if (sx_on($cfg['show_gpu'])) {
    $gpu = ['name' => sx_gpu_name(), 'data' => $gpuRaw, 'note' => (is_array($slow) ? (string)($slow['gpu_note'] ?? '') : ''), 'pending' => !is_array($slow)];
}

// ---- smart socket --------------------------------------------------------------------------------
$power = null;
$upsOn = sx_on($cfg['pow_ups'] ?? '1');
if (trim((string)$cfg['socket_host']) !== '' || $upsOn) {
    $sk = (is_array($slow) && isset($slow['socket']) && is_array($slow['socket'])) ? $slow['socket'] : null;
    $price = is_numeric($cfg['kwh_price']) ? (float)$cfg['kwh_price'] : null;
    $power = [
        'has_socket' => trim((string)$cfg['socket_host']) !== '',
        'data' => $sk, 'note' => is_array($slow) ? (string)($slow['socket_note'] ?? '') : '', 'pending' => !is_array($slow),
        'cost' => ($sk && $price !== null && $sk['month_kwh'] !== null) ? $sk['month_kwh'] * $price : null,
        'currency' => (string)$cfg['currency'], 'ups' => null,
    ];
    // UPS row (item 99): shown only when NUT answers; the curve is [seconds ago, voltage, low, high]
    $ud = (is_array($slow) && isset($slow['ups']) && is_array($slow['ups'])) ? $slow['ups'] : null;
    if ($upsOn && $ud) {
        $now = time(); $vh = [];
        foreach (sx_upsv_hist() as $p) { $vh[] = [$now - (int)$p[0], (float)$p[1], (float)$p[2], (float)$p[3]]; }
        // the curve ends at the voltage now, so it shows from the first saved point on (not only after two 10-minute points)
        if ($vh && $ud['vin'] !== null && end($vh)[0] > 60) { $vh[] = [0, (float)$ud['vin'], (float)$ud['vin'], (float)$ud['vin']]; }
        $ud['hist'] = $vh;
        $ud['loss'] = ($sk && $ud['watts'] !== null && $sk['power'] > $ud['watts']) ? $sk['power'] - $ud['watts'] : null;
        require_once __DIR__ . '/ups_lib.php';
        $ud['ev'] = ups_tile();   // power outage line (item 99)
        $power['ups'] = $ud;
    }
}

// ---- network (item 94): netmon.php samples in the background ------------------------------------
$net = null;
if (sx_on($cfg['show_net'] ?? '1')) {
    require_once __DIR__ . '/net_lib.php';
    $NL = sxnet_live(); $fresh = $NL && time() - (int)$NL['t'] < 30;
    if (!$fresh && (!is_file('/tmp/sysbox_net.lock.run') || time() - filemtime('/tmp/sysbox_net.lock.run') > 60)) {   // the sampler is not running (just installed, or switched on): start it now, cron keeps it alive
        @touch('/tmp/sysbox_net.lock.run');
        @exec('setsid nohup flock -n /tmp/sysbox_net.lock ' . escapeshellarg(sx_php()) . ' ' . escapeshellarg(__DIR__ . '/netmon.php') . ' >/dev/null 2>&1 </dev/null &');
    }
    if ($fresh) { @unlink('/tmp/sysbox_net.lock.run'); }
    $net = ['pending' => !$fresh, 'unit' => sxnet_cfg()['unit']];
    if ($fresh) {
        $pts = []; $now = (int)$NL['t']; $acc = null;
        foreach ((array)$NL['live'] as $p) {           // the last 30 minutes, 15-second averages: [seconds ago, down, up]
            if ($p[0] < $now - 1800) { continue; }
            $k = intdiv($p[0], 15);
            if ($acc && $acc[0] !== $k) { $pts[] = [$now - $acc[0] * 15, round($acc[1] / $acc[3]), round($acc[2] / $acc[3])]; $acc = null; }
            if (!$acc) { $acc = [$k, 0, 0, 0]; }
            $acc[1] += $p[1]; $acc[2] += $p[2]; $acc[3]++;
        }
        if ($acc) { $pts[] = [$now - $acc[0] * 15, round($acc[1] / $acc[3]), round($acc[2] / $acc[3])]; }
        $top = [];
        foreach ((array)$NL['ct'] as $n => $r) { $top[] = [$n, (float)$r[0], (float)$r[1]]; }
        // the host network as one row (item 98): containers without their own network and the server itself
        $ncfg = sxnet_cfg();
        if (sx_on($ncfg['host_row']) && !empty($NL['host_ct']) && is_array($NL['host_rate'] ?? null)) { $top[] = ['@host', (float)$NL['host_rate'][0], (float)$NL['host_rate'][1], array_values((array)$NL['host_ct'])]; }
        usort($top, function ($a, $b) { return ($b[1] + $b[2]) <=> ($a[1] + $a[2]); });
        $today = $NL['days'][date('Y-m-d')] ?? [0, 0];
        $in = (array)$NL['inet'];
        $net += ['iface' => (string)$NL['iface'], 'speed' => $NL['link']['speed'] ?? null, 'up' => $NL['link']['up'] ?? true, 'rate' => $NL['rate'], 'today' => [$today[0], $today[1]], 'pts' => $pts,
                 'top' => array_slice($top, 0, 3), 'inet' => ['state' => (string)($in['state'] ?? 'wait'), 'since' => (int)($in['since'] ?? 0), 'router' => (string)($in['router'] ?? ''),
                 'router_ms' => $in['router_ms'] ?? null, 'out' => (string)($in['out'] ?? ''), 'out_ms' => $in['out_ms'] ?? null]];
        // stream priority (item 98): the last check of sp_tick.php (cron, every minute)
        $SP = sxsp_live();
        if ($SP && $ncfg['sp_mode'] !== 'off' && !empty($SP['jf']) && time() - (int)$SP['t'] < 180) {
            $net['sp'] = ['mode' => $SP['mode'], 'held' => (int)$SP['held'], 'since' => (int)$SP['since'], 'q' => $SP['q'], 'qb' => !empty($SP['qb']), 'own' => (int)$SP['own'], 'user_off' => (int)$SP['user_off'],
                          'users' => array_values(array_unique(array_map(function ($x) { return (string)$x['u']; }, (array)$SP['out']))), 'n' => count((array)$SP['out']),
                          'up' => (float)$ncfg['sp_up'], 'last' => null];
            foreach (array_reverse((array)$SP['ev']) as $e) { if (in_array($e['k'] ?? '', ['release', 'release_btn'], true)) { $net['sp']['last'] = (int)$e['t']; break; } }
        }
        if (in_array($net['inet']['state'], ['down', 'norouter'], true)) {
            $attn[] = ['rd', 'Internet', $net['inet']['state'] === 'down' ? $sxx_T('attn.no_inet') : $sxx_T('attn.no_router'), date('H:i', $net['inet']['since'])];
        }
    }
}

// ---- containers ----------------------------------------------------------------------------------
$cont = ['enabled' => sx_on($cfg['show_containers']), 'docker' => false, 'rows' => [], 'mode' => $cfg['top_mode'], 'n' => (int)$cfg['top_n'],
         'controls' => sx_on($cfg['controls']), 'logs' => sx_on($cfg['logs']), 'log_lines' => (int)$cfg['log_lines'], 'pending' => $stats === null];
if ($cont['enabled']) {
    $ps = sx_ps();
    $cont['docker'] = (sx_docker() !== '');
    $rows = [];
    foreach ($ps as $n => $p) {
        $st = $stats[$n] ?? null;
        $running = ($p['state'] === 'running' || $p['state'] === 'restarting' || $p['state'] === 'paused');
        $rows[$n] = [
            'name' => $n, 'state' => $p['state'], 'status' => $p['status'], 'unhealthy' => $p['unhealthy'],
            // Docker reports a share of one core; the tile shows the share of the whole CPU (all threads), like the Load figure
            'cpu' => ($st && $p['state'] === 'running') ? $st['cpu'] / max(1, $info['threads']) : null,
            'cpu_core' => ($st && $p['state'] === 'running') ? $st['cpu'] : null,
            'mem' => ($st && $p['state'] === 'running') ? $st['mem'] : null,
        ];
        if ($p['state'] === 'restarting') { $attn[] = ['a', $n, $sxx_T('attn.restarting'), $p['status']]; }
        elseif ($p['unhealthy']) { $attn[] = ['a', $n, $sxx_T('attn.unhealthy'), '']; }
    }
    $mode = in_array($cont['mode'], ['cpu', 'ram', 'selected'], true) ? $cont['mode'] : 'cpu';
    $n = max(1, min(10, (int)$cfg['top_n']));
    $pick = [];
    if ($mode === 'selected') {
        foreach (array_filter(array_map('trim', explode(',', (string)$cfg['sel']))) as $name) { if (isset($rows[$name])) { $pick[] = $rows[$name]; } }
    } else {
        $run = array_filter($rows, function ($r) { return $r['state'] === 'running'; });
        $key = $mode === 'cpu' ? 'cpu' : 'mem';
        usort($run, function ($a, $b) use ($key) { return ((float)($b[$key] ?? -1)) <=> ((float)($a[$key] ?? -1)); });
        $pick = array_slice($run, 0, $n);
    }
    foreach ($pick as &$r) {
        $r['bar'] = ($mode === 'ram')
            ? ($mTotal > 0 && $r['mem'] !== null ? min(100, $r['mem'] / $mTotal * 100) : 0)
            : ($r['cpu'] !== null ? min(100, max(0, $r['cpu'])) : 0);
    }
    unset($r);
    $cont['mode'] = $mode; $cont['n'] = $n; $cont['rows'] = $pick;
}

// waiting updates from the Update manager's last check (a purple row under the containers, link to the page)
$updScan = json_decode((string)@file_get_contents('/tmp/sysbox_upd_scan.json'), true); $updN = ['green' => 0, 'amber' => 0, 'red' => 0];
foreach ((array)($updScan['rows'] ?? []) as $r) { if (($r['state'] ?? '') === 'update' && isset($updN[$r['risk'] ?? ''])) { $updN[$r['risk']]++; } }
$updOut = is_array($updScan) ? ['n' => $updN, 'os' => (string)($updScan['os']['new'] ?? ''), 't' => (int)($updScan['t'] ?? 0)] : null;

// Claude Guard: its latest report with something to look at stays in the list for 36 hours
$gdl = json_decode((string)@file_get_contents('/tmp/sysbox_guard_last.json'), true);
if (is_array($gdl) && !empty($gdl['n']) && time() - (int)($gdl['t'] ?? 0) < 36 * 3600) {
    $attn[] = [($gdl['verdict'] ?? '') === 'red' ? 'rd' : 'a', 'Claude Guard', $sxx_T('gd.n_look', ['n' => (int)$gdl['n']]), (string)($gdl['first'] ?? '')];
}

// ---- summary and attention -----------------------------------------------------------------------
$up = (int)((float)@file_get_contents('/proc/uptime'));
$d = intdiv($up, 86400); $h = intdiv($up % 86400, 3600); $m = intdiv($up % 3600, 60);
$order = ['rd' => 0, 'a' => 1];
usort($attn, function ($a, $b) use ($order) { return ($order[$a[0]] ?? 2) <=> ($order[$b[0]] ?? 2); });
$attOut = [];
foreach ($attn as $a) { $attOut[] = ['level' => $a[0], 'name' => $a[1], 'text' => $a[2], 'small' => $a[3]]; }

$sxShow = [];
foreach (['net_speed', 'net_today', 'net_hist', 'net_inet', 'net_top', 'ram_leg', 'cpu_load', 'cpu_temp', 'cpu_pow', 'cpu_hist', 'cpu_per', 'cpu_la', 'pow_w', 'pow_day', 'pow_mon', 'pow_cost', 'pow_name', 'pow_ups'] as $k) { $sxShow[$k] = sx_on($cfg[$k]); }

sx_json([
    'ok' => true, 'ts' => time(),
    'summary' => ['ram' => $memPct, 'cpu' => $load, 'temp' => $tPkg, 'uptime' => ($d > 0 ? $d . ' d ' . $h . ' h' : ($h > 0 ? $h . ' h ' . $m . ' m' : $m . ' m'))],
    'attention' => $attOut, 'updates' => $updOut, 'memory' => $memory, 'storage' => $storage, 'cpu' => $cpu, 'gpu' => $gpu, 'power' => $power, 'net' => $net, 'containers' => $cont,
    'limits' => ['temp_warn' => (float)$cfg['temp_warn'], 'temp_crit' => (float)$cfg['temp_crit']],
    'ui' => ['hist_min' => max(1, min(60, (int)$cfg['hist_min'])), 'cpu_sec' => (int)$cfg['cpu_sec'], 'clk_sec' => (int)$cfg['clk_sec'], 'show_clock' => sx_on($cfg['show_clock']),
             'order' => sx_order($cfg), 'show' => ['ram' => sx_on($cfg['show_ram']), 'sto' => sx_on($cfg['show_sto']), 'cpu' => sx_on($cfg['show_cpu']),
             'gpu' => sx_on($cfg['show_gpu']), 'pow' => sx_on($cfg['show_pow']), 'net' => sx_on($cfg['show_net'] ?? '1'), 'con' => sx_on($cfg['show_containers'])], 'metrics' => $sxShow],
]);
