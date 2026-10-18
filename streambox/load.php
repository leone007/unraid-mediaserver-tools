<?php
header('Content-Type: application/json');
header('Cache-Control: no-store');

require_once __DIR__ . '/energy.php';

$sbCfg = ['socket_host' => '', 'socket_id' => '0'];
$sbCfgFile = '/boot/config/plugins/streambox/settings.cfg';
if (is_file($sbCfgFile)) {
    $sbRead = @parse_ini_file($sbCfgFile);
    if (is_array($sbRead)) { $sbCfg = array_merge($sbCfg, $sbRead); }
}

// ---- CPU: delta of /proc/stat between two readings --------------------------
function sb_cpu_read() {
    $l = @file('/proc/stat', FILE_IGNORE_NEW_LINES);
    if (!$l) { return null; }
    $p = preg_split('/\s+/', trim($l[0]));
    array_shift($p);
    $v = array_map('intval', array_slice($p, 0, 8));
    return [array_sum($v), (int)($v[3] ?? 0) + (int)($v[4] ?? 0)];
}

$cpu = null;
$cur = sb_cpu_read();
if ($cur !== null) {
    $cf   = '/tmp/streambox_cpu.json';
    $prev = is_file($cf) ? json_decode((string)@file_get_contents($cf), true) : null;
    if (!is_array($prev) || (microtime(true) - (float)($prev['t'] ?? 0)) > 60) {
        $prev = ['t' => microtime(true), 'total' => $cur[0], 'idle' => $cur[1]];
        usleep(300000);
        $cur = sb_cpu_read();
    }
    if ($cur !== null) {
        $dt = $cur[0] - (int)$prev['total'];
        $di = $cur[1] - (int)$prev['idle'];
        if ($dt > 0) { $cpu = max(0, min(100, (1 - $di / $dt) * 100)); }
        @file_put_contents($cf, json_encode(['t' => microtime(true), 'total' => $cur[0], 'idle' => $cur[1]]));
    }
}

// ---- GPU: intel_gpu_top, cached for 8 seconds so several tabs do not pile up --
$gpu     = null;
$gpuNote = '';
$gf      = '/tmp/streambox_gpu.json';
$gc      = is_file($gf) ? json_decode((string)@file_get_contents($gf), true) : null;
if (is_array($gc) && (time() - (int)($gc['t'] ?? 0)) < 8) {
    $gpu     = $gc['gpu'] ?? null;
    $gpuNote = (string)($gc['note'] ?? '');
} else {
    $bin = '';
    foreach (['/usr/bin/intel_gpu_top', '/usr/local/bin/intel_gpu_top', '/usr/sbin/intel_gpu_top'] as $cand) {
        if (is_executable($cand)) { $bin = $cand; break; }
    }
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    if ($bin === '') {
        $gpuNote = 'intel_gpu_top not found';
    } elseif (!function_exists('shell_exec') || in_array('shell_exec', $disabled, true)) {
        $gpuNote = 'shell_exec is disabled in PHP';
    } else {
        $to = '';
        foreach (['/usr/bin/timeout', '/bin/timeout'] as $tb) {
            if (is_executable($tb)) { $to = $tb . ' 4 '; break; }
        }
        $errf = '/tmp/streambox_gpu.err';
        @unlink($errf);
        $raw = (string)@shell_exec($to . escapeshellarg($bin) . ' -J -s 400 -n 2 2>' . escapeshellarg($errf));
        // intel_gpu_top output is not always strict JSON (nan values, a cut-off last sample), so the
        // numbers are read straight from the last sample that contains engine data.
        $chunks = preg_split('/"period"\s*:/', $raw);
        array_shift($chunks);
        $pick = null;
        for ($i = count($chunks) - 1; $i >= 0; $i--) {
            if (preg_match('/"Render\/3D"\s*:\s*\{[^}]*?"busy"\s*:\s*([^,}\s]+)/', $chunks[$i])) { $pick = $chunks[$i]; break; }
        }
        if ($pick !== null) {
            $num = function ($pat) use ($pick) {
                return (preg_match($pat, $pick, $m) && is_numeric($m[1])) ? (float)$m[1] : 0.0;
            };
            $gpu = [
                'video'  => max($num('/"Video"\s*:\s*\{[^}]*?"busy"\s*:\s*([^,}\s]+)/'),
                                $num('/"VideoEnhance"\s*:\s*\{[^}]*?"busy"\s*:\s*([^,}\s]+)/')),
                'render' => $num('/"Render\/3D"\s*:\s*\{[^}]*?"busy"\s*:\s*([^,}\s]+)/'),
                'freq'   => $num('/"actual"\s*:\s*([^,}\s]+)/'),
                'power'  => $num('/"Package"\s*:\s*([^,}\s]+)/'),
            ];
        } else {
            $e = is_file($errf) ? trim((string)@file_get_contents($errf)) : '';
            if ($raw === '') { $gpuNote = 'intel_gpu_top printed nothing' . ($e !== '' ? ': ' . substr($e, 0, 160) : ''); }
            else { $gpuNote = 'no engine data in the output: ' . substr($raw, -200); }
        }
    }
    @file_put_contents($gf, json_encode(['t' => time(), 'gpu' => $gpu, 'note' => $gpuNote]));
}

// ---- Smart socket (power drawn at the wall + monthly energy), cached for 8 seconds ---------
$socket     = null;
$socketNote = '';
$sockHost   = trim((string)$sbCfg['socket_host']);
if ($sockHost !== '') {
    $skf = '/tmp/streambox_socket.json';
    $skc = is_file($skf) ? json_decode((string)@file_get_contents($skf), true) : null;
    if (is_array($skc) && (time() - (int)($skc['t'] ?? 0)) < 8 && (string)($skc['host'] ?? '') === $sockHost) {
        $socket     = $skc['socket'] ?? null;
        $socketNote = (string)($skc['note'] ?? '');
    } else {
        list($sd, $se) = sb_socket_fetch($sockHost, (int)$sbCfg['socket_id']);
        if ($sd !== null) {
            $monthKwh = ($sd['total_wh'] !== null) ? sb_energy_month_wh($sd['total_wh']) / 1000 : null;
            $socket = ['power' => $sd['power'], 'month_kwh' => $monthKwh, 'output' => $sd['output']];
        } else {
            $socketNote = $se;
        }
        @file_put_contents($skf, json_encode(['t' => time(), 'host' => $sockHost, 'socket' => $socket, 'note' => $socketNote]));
    }
}

echo json_encode(['ok' => true, 'gpu' => $gpu, 'cpu' => $cpu, 'gpu_note' => $gpuNote, 'socket' => $socket, 'socket_note' => $socketNote]);
