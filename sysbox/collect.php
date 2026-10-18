<?php
// Slow readings (container statistics, GPU, smart socket), collected in the background by api_fetch.php
// so the dashboard request itself stays fast. Result: /tmp/sysbox_slow.json
if (PHP_SAPI !== 'cli') { exit(1); } // started by api_fetch.php, not a web page
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/energy.php';

$lock = @fopen('/tmp/sysbox_collect.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { exit(0); }

$cfg = sx_cfg();
$res = ['t' => time(), 'containers' => null, 'gpu' => null, 'gpu_note' => '', 'socket' => null, 'socket_note' => ''];

function sx_bytes($s) {
    if (!preg_match('/^\s*([0-9.]+)\s*([A-Za-z]*)/', (string)$s, $m)) { return null; }
    $mul = ['' => 1, 'b' => 1, 'kb' => 1e3, 'kib' => 1024, 'mb' => 1e6, 'mib' => 1048576, 'gb' => 1e9, 'gib' => 1073741824, 'tb' => 1e12, 'tib' => 1099511627776];
    $u = strtolower($m[2]);
    return isset($mul[$u]) ? (float)$m[1] * $mul[$u] : null;
}

// ---- containers: CPU and memory of the running ones ----
$d = sx_docker();
if ($d !== '') {
    list($rc, $txt) = sx_run(escapeshellarg($d) . " stats --no-stream --format '{{json .}}'", 20);
    if ($rc === 0) {
        $c = [];
        foreach (explode("\n", $txt) as $l) {
            $j = json_decode(trim($l), true);
            if (!is_array($j) || !isset($j['Name'])) { continue; }
            $n = (string)$j['Name'];
            if (!sx_valid_name($n)) { continue; }
            $used = explode('/', (string)($j['MemUsage'] ?? ''))[0];
            $c[$n] = ['cpu' => (float)rtrim((string)($j['CPUPerc'] ?? '0'), '%'), 'mem' => sx_bytes($used)];
        }
        $res['containers'] = $c;
    }
}

// ---- GPU: intel_gpu_top ----
if (sx_on($cfg['show_gpu'])) {
    $bin = sx_bin('intel_gpu_top');
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    if ($bin === '') {
        $res['gpu_note'] = 'intel_gpu_top not found';
    } elseif (!function_exists('shell_exec') || in_array('shell_exec', $disabled, true)) {
        $res['gpu_note'] = 'shell_exec is disabled in PHP';
    } else {
        $to = sx_bin('timeout');
        $errf = '/tmp/sysbox_gpu.err';
        @unlink($errf);
        $raw = (string)@shell_exec(($to !== '' ? $to . ' 4 ' : '') . escapeshellarg($bin) . ' -J -s 400 -n 2 2>' . escapeshellarg($errf));
        // not always strict JSON (nan values, a cut-off last sample): read the numbers from the last sample with engine data
        $chunks = preg_split('/"period"\s*:/', $raw);
        array_shift($chunks);
        $pick = null;
        for ($i = count($chunks) - 1; $i >= 0; $i--) {
            if (preg_match('/"Render\/3D"\s*:\s*\{[^}]*?"busy"\s*:\s*([^,}\s]+)/', $chunks[$i])) { $pick = $chunks[$i]; break; }
        }
        if ($pick !== null) {
            $num = function ($pat) use ($pick) { return (preg_match($pat, $pick, $m) && is_numeric($m[1])) ? (float)$m[1] : 0.0; };
            $res['gpu'] = [
                'video'  => max($num('/"Video"\s*:\s*\{[^}]*?"busy"\s*:\s*([^,}\s]+)/'), $num('/"VideoEnhance"\s*:\s*\{[^}]*?"busy"\s*:\s*([^,}\s]+)/')),
                'render' => $num('/"Render\/3D"\s*:\s*\{[^}]*?"busy"\s*:\s*([^,}\s]+)/'),
                'freq'   => $num('/"actual"\s*:\s*([^,}\s]+)/'),
                'power'  => $num('/"Package"\s*:\s*([^,}\s]+)/'),
            ];
        } else {
            $e = is_file($errf) ? trim((string)@file_get_contents($errf)) : '';
            $res['gpu_note'] = $raw === '' ? 'intel_gpu_top printed nothing' . ($e !== '' ? ': ' . substr($e, 0, 160) : '') : 'no engine data in the output';
        }
    }
}

// ---- smart socket ----
$host = trim((string)$cfg['socket_host']);
if ($host !== '') {
    list($sd, $se) = sx_socket_fetch($host, (int)$cfg['socket_id']);
    if ($sd !== null) {
        $res['socket'] = [
            'power' => $sd['power'], 'output' => $sd['output'], 'name' => sx_socket_name($host),
            'month_kwh' => ($sd['total_wh'] !== null) ? sx_energy_month_wh($sd['total_wh']) / 1000 : null,
            'day_kwh' => ($sd['total_wh'] !== null && ($t = sx_energy_today_wh($sd['total_wh'])) !== null) ? $t / 1000 : null,
        ];
    } else { $res['socket_note'] = $se; }
}

$tmp = '/tmp/sysbox_slow.json.tmp';
@file_put_contents($tmp, json_encode($res));
@rename($tmp, '/tmp/sysbox_slow.json');
