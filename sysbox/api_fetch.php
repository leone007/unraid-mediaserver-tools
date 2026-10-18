<?php
require_once __DIR__ . '/lib.php';
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

function sx_arc() {
    foreach ((array)@file('/proc/spl/kstat/zfs/arcstats', FILE_IGNORE_NEW_LINES) as $l) {
        if (preg_match('/^size\s+\d+\s+(\d+)/', $l, $m)) { return (float)$m[1]; }
    }
    return 0.0;
}

$mi = [];
foreach ((array)@file('/proc/meminfo', FILE_IGNORE_NEW_LINES) as $l) {
    if (preg_match('/^(\w+):\s+(\d+)/', $l, $m)) { $mi[$m[1]] = (float)$m[2] * 1024; }
}
$mTotal = $mi['MemTotal'] ?? 0.0;
$mAvail = $mi['MemAvailable'] ?? ($mi['MemFree'] ?? 0.0);
$mUsed  = max(0.0, $mTotal - $mAvail);
$arc    = min(sx_arc(), $mUsed);

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
    $attn[] = [$crit ? 'rd' : 'a', 'RAM', 'is ' . round($memAlertPct) . '% used', '@FREE@' . $mAvail . ' free'];
}
$memory = [
    'total' => $mTotal, 'used' => $mUsed, 'pct' => $memPct, 'free' => $mAvail,
    'system' => $system, 'zfs' => $arc, 'docker' => $dockerMem, 'docker_known' => $stats !== null,
    'phys' => $phys['size'], 'type' => $phys['type'],
    'level' => $memAlertPct >= (float)$cfg['ram_crit'] ? 'rd' : ($memAlertPct >= (float)$cfg['ram_warn'] ? 'a' : ''),
];

// ---- storage -------------------------------------------------------------------------------------
function sx_mounted($path) {
    foreach ((array)@file('/proc/mounts', FILE_IGNORE_NEW_LINES) as $l) {
        $p = explode(' ', $l);
        if (isset($p[1]) && $p[1] === $path) { return true; }
    }
    return false;
}
function sx_fs($label, $path, $warn, $crit, &$attn) {
    $t = @disk_total_space($path); $f = @disk_free_space($path);
    if (!$t) { return null; }
    $used = $t - $f; $pct = $used / $t * 100;
    $lv = $pct >= $crit ? 'rd' : ($pct >= $warn ? 'a' : '');
    if ($lv !== '') { $attn[] = [$lv, $label, 'is ' . number_format($pct, 0) . '% full', '@FREE@' . $f . ' free']; }
    return ['label' => $label, 'size' => $t, 'used' => $used, 'pct' => $pct, 'level' => $lv];
}
$storage = [];
$s = sx_fs('BOOT', '/boot', 90, 97, $attn); if ($s) { $storage[] = $s; }
$s = sx_fs('LOG', '/var/log', (float)$cfg['log_warn'], (float)$cfg['log_crit'], $attn); if ($s) { $storage[] = $s; }
if (sx_mounted('/var/lib/docker')) {
    $s = sx_fs('DOCKER VDISK', '/var/lib/docker', (float)$cfg['vd_warn'], (float)$cfg['vd_crit'], $attn); if ($s) { $storage[] = $s; }
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
    if ($tlevel !== '') { $attn[] = [$tlevel, 'CPU', $tlevel === 'rd' ? 'is too hot' : 'is running hot', $tPkg . '°C']; }
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
if (trim((string)$cfg['socket_host']) !== '') {
    $sk = (is_array($slow) && isset($slow['socket']) && is_array($slow['socket'])) ? $slow['socket'] : null;
    $price = is_numeric($cfg['kwh_price']) ? (float)$cfg['kwh_price'] : null;
    $power = [
        'data' => $sk, 'note' => is_array($slow) ? (string)($slow['socket_note'] ?? '') : '', 'pending' => !is_array($slow),
        'cost' => ($sk && $price !== null && $sk['month_kwh'] !== null) ? $sk['month_kwh'] * $price : null,
        'currency' => (string)$cfg['currency'],
    ];
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
        if ($p['state'] === 'restarting') { $attn[] = ['a', $n, 'is restarting', $p['status']]; }
        elseif ($p['unhealthy']) { $attn[] = ['a', $n, 'is unhealthy', '']; }
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

// ---- summary and attention -----------------------------------------------------------------------
$up = (int)((float)@file_get_contents('/proc/uptime'));
$d = intdiv($up, 86400); $h = intdiv($up % 86400, 3600); $m = intdiv($up % 3600, 60);
$order = ['rd' => 0, 'a' => 1];
usort($attn, function ($a, $b) use ($order) { return ($order[$a[0]] ?? 2) <=> ($order[$b[0]] ?? 2); });
$attOut = [];
foreach ($attn as $a) { $attOut[] = ['level' => $a[0], 'name' => $a[1], 'text' => $a[2], 'small' => $a[3]]; }

$sxShow = [];
foreach (['ram_leg', 'cpu_load', 'cpu_temp', 'cpu_pow', 'cpu_hist', 'cpu_per', 'cpu_la', 'pow_w', 'pow_day', 'pow_mon', 'pow_cost', 'pow_name'] as $k) { $sxShow[$k] = sx_on($cfg[$k]); }

sx_json([
    'ok' => true, 'ts' => time(),
    'summary' => ['ram' => $memPct, 'cpu' => $load, 'temp' => $tPkg, 'uptime' => ($d > 0 ? $d . ' d ' . $h . ' h' : ($h > 0 ? $h . ' h ' . $m . ' m' : $m . ' m'))],
    'attention' => $attOut, 'memory' => $memory, 'storage' => $storage, 'cpu' => $cpu, 'gpu' => $gpu, 'power' => $power, 'containers' => $cont,
    'limits' => ['temp_warn' => (float)$cfg['temp_warn'], 'temp_crit' => (float)$cfg['temp_crit']],
    'ui' => ['hist_min' => max(1, min(60, (int)$cfg['hist_min'])), 'cpu_sec' => (int)$cfg['cpu_sec'], 'clk_sec' => (int)$cfg['clk_sec'], 'show_clock' => sx_on($cfg['show_clock']),
             'order' => sx_order($cfg), 'show' => ['ram' => sx_on($cfg['show_ram']), 'sto' => sx_on($cfg['show_sto']), 'cpu' => sx_on($cfg['show_cpu']),
             'gpu' => sx_on($cfg['show_gpu']), 'pow' => sx_on($cfg['show_pow']), 'con' => sx_on($cfg['show_containers'])], 'metrics' => $sxShow],
]);
