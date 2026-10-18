<?php
// Sysbox helpers: settings, Docker access, small utilities.
const SX_CFG = '/boot/config/plugins/sysbox/settings.cfg';

function sx_defaults() {
    return [
        'top_mode' => 'cpu', 'top_n' => '3', 'sel' => '',
        'controls' => '1', 'logs' => '1', 'log_lines' => '100',
        'show_gpu' => '1', 'show_containers' => '1', 'hist_min' => '2', 'cpu_sec' => '1', 'clk_sec' => '2', 'show_clock' => '1',
        // Dashboard tile (item 53): blocks on or off, their order, and what the RAM, CPU and Power blocks show
        'show_ram' => '1', 'show_sto' => '1', 'show_cpu' => '1', 'show_pow' => '1', 'order' => 'ram,sto,cpu,gpu,pow,con', 'ram_leg' => '1',
        'cpu_load' => '1', 'cpu_temp' => '1', 'cpu_pow' => '1', 'cpu_hist' => '1', 'cpu_per' => '1', 'cpu_la' => '0',
        'pow_w' => '1', 'pow_day' => '1', 'pow_mon' => '1', 'pow_cost' => '1', 'pow_name' => '1',
        'ram_warn' => '80', 'ram_crit' => '92', 'log_warn' => '80', 'log_crit' => '92',
        'vd_warn' => '80', 'vd_crit' => '92', 'temp_warn' => '75', 'temp_crit' => '90',
        'socket_host' => '', 'socket_id' => '0', 'kwh_price' => '', 'currency' => 'Ft',
        'dc_hook' => '', 'dc_lang' => 'hu', 'dc_ping' => '', 'n_containers' => '1', 'n_ram' => '1', 'n_storage' => '1', 'n_cpu' => '1', 'n_boot' => '1',
        'n_remind' => '24', 'wk_on' => '1', 'wk_day' => '7', 'wk_hour' => '19',
    ];
}

function sx_cfg() {
    $c = sx_defaults();
    if (is_file(SX_CFG)) {
        $x = @parse_ini_file(SX_CFG);
        if (is_array($x)) { $c = array_merge($c, $x); }
    }
    return $c;
}

// The tile's block order: the saved list, unknown names dropped, missing ones added at the end
const SX_BLOCKS = ['ram', 'sto', 'cpu', 'gpu', 'pow', 'con'];
function sx_order($cfg) {
    $o = array_values(array_unique(array_intersect(array_map('trim', explode(',', (string)($cfg['order'] ?? ''))), SX_BLOCKS)));
    foreach (SX_BLOCKS as $b) { if (!in_array($b, $o, true)) { $o[] = $b; } }
    return $o;
}

function sx_on($v) { return !in_array(strtolower(trim((string)$v)), ['0', 'no', 'false', 'off', ''], true); }

function sx_php() { return is_executable('/usr/bin/php') ? '/usr/bin/php' : 'php'; }

function sx_valid_name($n) { return (bool)preg_match('/^[A-Za-z0-9][A-Za-z0-9_.\-]{0,127}$/', (string)$n); }

function sx_bin($names, $dirs = ['/usr/bin', '/usr/local/bin', '/usr/sbin', '/bin', '/sbin']) {
    foreach ((array)$names as $n) { foreach ($dirs as $d) { if (is_executable("$d/$n")) { return "$d/$n"; } } }
    return '';
}

// Runs a command line with a time limit; returns [exit code, output text]
function sx_run($cmd, $secs = 10) {
    $to = sx_bin('timeout');
    $out = [];
    $rc = 0;
    @exec(($to !== '' ? $to . ' ' . (int)$secs . ' ' : '') . $cmd . ' 2>&1', $out, $rc);
    return [$rc, implode("\n", $out)];
}

function sx_docker() {
    $t = getenv('SX_SD_DOCKER');   // tests only: a fake docker
    if ($t !== false && $t !== '') { return $t; }
    if (!file_exists('/var/run/docker.sock')) { return ''; }
    return sx_bin('docker');
}

// All containers (running or not), cached for 3 seconds. [] when Docker is not available.
function sx_ps($fresh = false) {
    $f = '/tmp/sysbox_ps.json';
    if (!$fresh && is_file($f) && (time() - filemtime($f)) < 3) {
        $j = json_decode((string)@file_get_contents($f), true);
        if (is_array($j)) { return $j; }
    }
    $d = sx_docker();
    if ($d === '') { return []; }
    list($rc, $txt) = sx_run(escapeshellarg($d) . " ps -a --format '{{json .}}'", 8);
    if ($rc !== 0) { return is_file($f) ? (json_decode((string)@file_get_contents($f), true) ?: []) : []; }
    $out = [];
    foreach (explode("\n", $txt) as $l) {
        $j = json_decode(trim($l), true);
        if (!is_array($j) || !isset($j['Names'])) { continue; }
        $n = (string)$j['Names'];
        if (!sx_valid_name($n)) { continue; }
        $st = (string)($j['Status'] ?? '');
        $out[$n] = [
            'name' => $n, 'state' => strtolower((string)($j['State'] ?? '')), 'status' => $st,
            'image' => (string)($j['Image'] ?? ''), 'unhealthy' => (stripos($st, 'unhealthy') !== false),
        ];
    }
    ksort($out, SORT_NATURAL | SORT_FLAG_CASE);
    @file_put_contents($f, json_encode($out));
    return $out;
}

function sx_json($d, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

// State-changing and log requests: Unraid's prepend script validates csrf_token and removes it from $_POST;
// if a token is still present check it, otherwise require an AJAX request.
function sx_guard() {
    $var  = @parse_ini_file('/var/local/emhttp/var.ini');
    $tok  = is_array($var) ? (string)($var['csrf_token'] ?? '') : '';
    $sent = (string)($_POST['csrf_token'] ?? '');
    if ($sent !== '') {
        if ($tok === '' || !hash_equals($tok, $sent)) { sx_json(['ok' => false, 'error' => 'csrf token mismatch'], 403); }
    } elseif (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
        sx_json(['ok' => false, 'error' => 'csrf: token removed and request is not AJAX'], 403);
    }
}

// ---- CPU helpers (shared by api_fetch.php and cpu.php) ----------------------------------------
function sx_stat() {
    $out = [];
    foreach ((array)@file('/proc/stat', FILE_IGNORE_NEW_LINES) as $l) {
        if (!preg_match('/^cpu(\d*)\s+(.*)$/', $l, $m)) { continue; }
        $v = array_map('intval', preg_split('/\s+/', trim($m[2])));
        $out[$m[1] === '' ? 'all' : (int)$m[1]] = [array_sum(array_slice($v, 0, 8)), ($v[3] ?? 0) + ($v[4] ?? 0)];
    }
    return $out;
}
function sx_cpu_rates() {
    $f = '/tmp/sysbox_cpu.json';
    $now = microtime(true);
    $cur = sx_stat();
    if (!$cur) { return [null, []]; }
    $prev = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
    if (!is_array($prev) || ($now - (float)($prev['t'] ?? 0)) > 60) {
        $prev = ['t' => $now, 'c' => $cur, 'rates' => null];
        usleep(300000);
        $now = microtime(true);
        $cur = sx_stat();
    }
    $rates = $prev['rates'] ?? null;
    if (($now - (float)$prev['t']) >= 0.8 || !is_array($rates)) {
        $r = [];
        foreach ($cur as $k => $c) {
            if (!isset($prev['c'][$k])) { continue; }
            $dt = $c[0] - $prev['c'][$k][0];
            $di = $c[1] - $prev['c'][$k][1];
            $r[$k] = $dt > 0 ? max(0, min(100, (1 - $di / $dt) * 100)) : 0;
        }
        $rates = $r;
        @file_put_contents($f, json_encode(['t' => $now, 'c' => $cur, 'rates' => $rates]));
    }
    $all = $rates['all'] ?? null;
    $threads = [];
    foreach ($rates as $k => $v) { if ($k !== 'all') { $threads[(int)$k] = round($v); } }
    ksort($threads);
    return [$all, array_values($threads)];
}

function sx_cpu_info() {
    $model = ''; $cores = 0; $threads = 0;
    foreach ((array)@file('/proc/cpuinfo', FILE_IGNORE_NEW_LINES) as $l) {
        if ($model === '' && preg_match('/^model name\s*:\s*(.*)$/', $l, $m)) { $model = trim($m[1]); }
        if ($cores === 0 && preg_match('/^cpu cores\s*:\s*(\d+)/', $l, $m)) { $cores = (int)$m[1]; }
        if (preg_match('/^processor\s*:/', $l)) { $threads++; }
    }
    $short = trim(preg_replace(['/\((R|TM)\)/', '/\s*@.*$/', '/^\d+(st|nd|rd|th) Gen\s+/i', '/\s+CPU\b/', '/\s+/'], ['', '', '', '', ' '], $model));
    if (preg_match('/\b(i[3579]-[0-9A-Za-z]+|Ryzen [0-9A-Za-z ]+?)(?:\s+\d+-Core.*)?$/', $short, $m)) { $short = trim($m[1]); }
    return ['model' => $short !== '' ? $short : 'CPU', 'cores' => $cores > 0 ? $cores : $threads, 'threads' => $threads];
}

function sx_temps() {
    $pkg = null; $core = null; $any = null;
    foreach ((array)glob('/sys/class/hwmon/hwmon*') as $h) {
        $n = trim((string)@file_get_contents("$h/name"));
        if (!in_array($n, ['coretemp', 'k10temp', 'zenpower', 'cpu_thermal'], true)) { continue; }
        foreach ((array)glob("$h/temp*_input") as $in) {
            $v = (float)@file_get_contents($in) / 1000;
            if ($v <= 0) { continue; }
            $lab = trim((string)@file_get_contents(str_replace('_input', '_label', $in)));
            $any = ($any === null) ? $v : max($any, $v);
            if (preg_match('/^(Package|Tctl|Tdie)/i', $lab)) { $pkg = ($pkg === null) ? $v : max($pkg, $v); }
            elseif (preg_match('/^Core/i', $lab)) { $core = ($core === null) ? $v : max($core, $v); }
        }
    }
    $pkg = $pkg ?? $any;
    return [$pkg !== null ? round($pkg) : null, $core !== null ? round($core) : null];
}

function sx_clock() {
    $s = 0; $n = 0;
    foreach ((array)glob('/sys/devices/system/cpu/cpu[0-9]*/cpufreq/scaling_cur_freq') as $f) { $s += (float)@file_get_contents($f); $n++; }
    if ($n > 0) { return $s / $n / 1e6; }   // kHz -> GHz
    foreach ((array)@file('/proc/cpuinfo', FILE_IGNORE_NEW_LINES) as $l) {
        if (preg_match('/^cpu MHz\s*:\s*([0-9.]+)/', $l, $m)) { $s += (float)$m[1]; $n++; }
    }
    return $n > 0 ? $s / $n / 1000 : null;
}


// Name of the Intel graphics device, e.g. "UHD Graphics 770" (from lspci, else the PCI id); '' when unknown. Cached, it never changes.
function sx_gpu_name() {
    $f = '/tmp/sysbox_gpuname.json';
    if (is_file($f)) { $j = json_decode((string)@file_get_contents($f), true); if (is_array($j) && isset($j['n'])) { return (string)$j['n']; } }
    $name = '';
    $b = sx_bin('lspci');
    if ($b !== '') {
        list($rc, $txt) = sx_run(escapeshellarg($b) . ' -mm', 6);
        foreach (explode("\n", (string)$txt) as $l) {
            if (!preg_match('/"(?:VGA compatible controller|Display controller|3D controller)"\s+"([^"]*)"\s+"([^"]*)"/', $l, $m)) { continue; }
            if (stripos($m[1], 'Intel') === false) { continue; }
            $d = $m[2];
            $name = preg_match('/\[([^\]]+)\]/', $d, $x) ? $x[1] : trim($d);
            break;
        }
    }
    if ($name === '') {
        $map = ['4680' => 'UHD Graphics 770', '4688' => 'UHD Graphics 770', '4690' => 'UHD Graphics 770', 'a780' => 'UHD Graphics 770',
                '4682' => 'UHD Graphics 730', '4692' => 'UHD Graphics 730', 'a782' => 'UHD Graphics 730', '468b' => 'UHD Graphics 710', '4693' => 'UHD Graphics 710',
                '4c8a' => 'UHD Graphics 750', '4c8b' => 'UHD Graphics 730', '9bc5' => 'UHD Graphics 630', '3e92' => 'UHD Graphics 630', '3e98' => 'UHD Graphics 630', '5912' => 'HD Graphics 630'];
        foreach ((array)glob('/sys/class/drm/card*/device/vendor') as $v) {
            if (trim((string)@file_get_contents($v)) !== '0x8086') { continue; }
            $id = strtolower(substr(trim((string)@file_get_contents(dirname($v) . '/device')), 2));
            if (isset($map[$id])) { $name = $map[$id]; }
            break;
        }
    }
    @file_put_contents($f, json_encode(['n' => $name]));
    return $name;
}
