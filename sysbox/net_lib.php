<?php
// Network meter (item 94): settings, interface and gateway detection, counters of the host and of the containers, pings.
// Only reads; netmon.php samples in the background, net.php serves the Network page, api_fetch.php the tile.
require_once __DIR__ . '/lib.php';

const SXNET_CFG  = '/boot/config/plugins/sysbox/network.cfg';
const SXNET_HIST = '/boot/config/plugins/sysbox/net_hist.json';   // days, 24 h and 7 d series, today per container, outages
const SXNET_LIVE = '/tmp/sysbox_net.json';                         // written by netmon.php every 5 seconds

function sxnet_dir() { $d = getenv('SX_NET_DIR'); return ($d !== false && $d !== '') ? rtrim($d, '/') : ''; }   // tests only
function sxnet_cfg_file() { return sxnet_dir() !== '' ? sxnet_dir() . '/network.cfg' : SXNET_CFG; }
function sxnet_hist_file() { return sxnet_dir() !== '' ? sxnet_dir() . '/net_hist.json' : SXNET_HIST; }
function sxnet_live_file() { return sxnet_dir() !== '' ? sxnet_dir() . '/sysbox_net.json' : SXNET_LIVE; }
function sxnet_sys() { $d = getenv('SX_NET_SYS'); return ($d !== false && $d !== '') ? rtrim($d, '/') : ''; }       // tests only: a fake /proc and /sys root

function sxnet_defaults() { return ['iface' => 'auto', 'router' => 'auto', 'outside' => '1.1.1.1, 8.8.8.8', 'slow_ms' => '150', 'unit' => 'MB', 'dc' => '1']; }
function sxnet_cfg() {
    $c = sxnet_defaults(); $f = sxnet_cfg_file();
    if (is_file($f)) { $x = @parse_ini_file($f); if (is_array($x)) { $c = array_merge($c, array_intersect_key($x, $c)); } }
    return $c;
}
function sxnet_cfg_save($c) {
    $txt = '';
    foreach (sxnet_defaults() as $k => $v) { $txt .= $k . '="' . str_replace('"', '', (string)($c[$k] ?? $v)) . "\"\n"; }
    $f = sxnet_cfg_file(); @mkdir(dirname($f), 0755, true);
    if (@file_put_contents($f . '.tmp', $txt) === false) { return false; }
    return @rename($f . '.tmp', $f);
}
function sxnet_host_ok($h) { return (bool)preg_match('/^[A-Za-z0-9][A-Za-z0-9.:\-]{0,252}$/', (string)$h); }
function sxnet_if_ok($i) { return (bool)preg_match('/^[A-Za-z0-9_.:\-]{1,15}$/', (string)$i); }
function sxnet_outside($cfg) {
    $o = [];
    foreach (explode(',', (string)$cfg['outside']) as $h) { $h = trim($h); if ($h !== '' && sxnet_host_ok($h)) { $o[] = $h; } }
    return array_slice($o, 0, 3);
}

// ---- /proc and /sys readers -------------------------------------------------------------------
// iface => [rx bytes, tx bytes] from a net/dev file (the host's, or a container's /proc/<pid>/net/dev)
function sxnet_dev($file = null) {
    $file = $file ?? sxnet_sys() . '/proc/net/dev';
    $out = [];
    foreach ((array)@file($file, FILE_IGNORE_NEW_LINES) as $l) {
        if (!preg_match('/^\s*([^:\s]+):\s*(.*)$/', $l, $m)) { continue; }
        $v = preg_split('/\s+/', trim($m[2]));
        if (count($v) >= 9) { $out[$m[1]] = [(float)$v[0], (float)$v[8]]; }
    }
    return $out;
}
// [interface of the default route, gateway address] from /proc/net/route
function sxnet_route() {
    foreach ((array)@file(sxnet_sys() . '/proc/net/route', FILE_IGNORE_NEW_LINES) as $l) {
        $p = preg_split('/\s+/', trim($l));
        if (count($p) < 3 || $p[1] !== '00000000' || !preg_match('/^[0-9A-Fa-f]{8}$/', $p[2])) { continue; }
        $g = implode('.', array_reverse(array_map('hexdec', str_split($p[2], 2))));
        return [$p[0], $g === '0.0.0.0' ? '' : $g];
    }
    return ['', ''];
}
function sxnet_sysnet($i, $f) { $v = @file_get_contents(sxnet_sys() . '/sys/class/net/' . $i . '/' . $f); return $v === false ? null : trim($v); }
// the interface whose counters show the wire: the default route's one, or the physical port under a bridge (br0 -> eth0)
function sxnet_iface($cfg) {
    if ($cfg['iface'] !== 'auto' && sxnet_if_ok($cfg['iface'])) { return $cfg['iface']; }
    [$dev] = sxnet_route();
    if ($dev === '') { return 'eth0'; }
    if (is_dir(sxnet_sys() . '/sys/class/net/' . $dev . '/bridge')) {
        $m = glob(sxnet_sys() . '/sys/class/net/' . $dev . '/brif/*') ?: [];
        foreach ($m as $p) { $n = basename($p); if (file_exists(sxnet_sys() . '/sys/class/net/' . $n . '/device') || is_dir(sxnet_sys() . '/sys/class/net/' . $n . '/bonding')) { return $n; } }
    }
    return $dev;
}
// interfaces offered on the settings page: real ports, bonds and bridges (no veth, docker or loopback)
function sxnet_ifaces() {
    $o = [];
    foreach (glob(sxnet_sys() . '/sys/class/net/*') ?: [] as $p) {
        $n = basename($p);
        if ($n === 'lo' || preg_match('/^(veth|docker|br-|vnet|virbr|tunl|shim|vhost)/', $n)) { continue; }
        $o[] = $n;
    }
    sort($o, SORT_NATURAL);
    return $o;
}
function sxnet_link($i) {
    $sp = (int)sxnet_sysnet($i, 'speed');
    $st = function ($f) use ($i) { return (int)sxnet_sysnet($i, 'statistics/' . $f); };
    return ['speed' => $sp > 0 ? $sp : null, 'duplex' => (string)sxnet_sysnet($i, 'duplex'), 'up' => sxnet_sysnet($i, 'operstate') === 'up',
            'errors' => $st('rx_errors') + $st('tx_errors'), 'dropped' => $st('rx_dropped') + $st('tx_dropped')];
}

// ---- containers -------------------------------------------------------------------------------
// name => pid of the running containers that have their own network (host-network ones share the host's counters)
function sxnet_ct_pids() {
    $t = getenv('SX_NET_PIDS');   // tests only: "name=pid,name=pid"
    if ($t !== false && $t !== '') { $o = []; foreach (explode(',', $t) as $x) { [$n, $p] = array_pad(explode('=', $x, 2), 2, ''); if (sx_valid_name($n) && ctype_digit($p)) { $o[$n] = (int)$p; } } return $o; }
    $d = sx_docker(); if ($d === '') { return []; }
    [$rc, $ids] = sx_run(escapeshellarg($d) . ' ps -q --no-trunc', 8);
    $ids = array_values(array_filter(array_map('trim', explode("\n", (string)$ids)), function ($x) { return preg_match('/^[0-9a-f]{12,64}$/', $x); }));
    if ($rc !== 0 || !$ids) { return []; }
    [$rc, $txt] = sx_run(escapeshellarg($d) . ' inspect -f ' . escapeshellarg('{{.Name}} {{.State.Pid}}') . ' ' . implode(' ', array_map('escapeshellarg', $ids)), 15);
    if ($rc !== 0) { return []; }
    $host = @readlink('/proc/self/ns/net'); $o = [];
    foreach (explode("\n", (string)$txt) as $l) {
        if (!preg_match('/^\/?(\S+)\s+(\d+)$/', trim($l), $m) || !sx_valid_name($m[1]) || (int)$m[2] <= 0) { continue; }
        if ($host && @readlink('/proc/' . $m[2] . '/ns/net') === $host) { continue; }
        $o[$m[1]] = (int)$m[2];
    }
    ksort($o, SORT_NATURAL | SORT_FLAG_CASE);
    return $o;
}
// [rx, tx] of a container: the sum of its interfaces except loopback
function sxnet_ct_bytes($pid) {
    $f = getenv('SX_NET_SYS') ? sxnet_sys() . '/proc/' . (int)$pid . '/net/dev' : '/proc/' . (int)$pid . '/net/dev';
    $d = sxnet_dev($f); if (!$d) { return null; }
    $rx = 0.0; $tx = 0.0;
    foreach ($d as $i => $v) { if ($i !== 'lo') { $rx += $v[0]; $tx += $v[1]; } }
    return [$rx, $tx];
}

// ---- ping ---------------------------------------------------------------------------------------
// round trip in ms, or null when there was no answer within 2 seconds
function sxnet_ping($host) {
    if (!sxnet_host_ok($host)) { return null; }
    $fake = getenv('SX_NET_PING');   // tests only: "host=ms,host=-" ('-' = no answer), or a file that holds it
    if ($fake !== false && $fake !== '' && $fake[0] === '/') { $fake = trim((string)@file_get_contents($fake)); }
    if ($fake !== false && $fake !== '') { foreach (explode(',', $fake) as $x) { [$h, $v] = array_pad(explode('=', $x, 2), 2, '-'); if ($h === $host) { return is_numeric($v) ? (float)$v : null; } } return null; }
    $b = sx_bin('ping'); if ($b === '') { return null; }
    [$rc, $o] = sx_run(escapeshellarg($b) . ' -n -c 1 -W 2 ' . escapeshellarg($host), 4);
    return ($rc === 0 && preg_match('/time[=<]\s*([0-9.]+)\s*ms/', $o, $m)) ? round((float)$m[1], 1) : null;
}
// one check: router, then the outside addresses until one answers. state: ok | slow | down | norouter (before the debounce)
function sxnet_check($cfg) {
    $gw = $cfg['router'] !== 'auto' && sxnet_host_ok($cfg['router']) ? $cfg['router'] : sxnet_route()[1];
    $r = $gw !== '' ? sxnet_ping($gw) : null; $out = null; $oh = '';
    foreach (sxnet_outside($cfg) as $h) { $v = sxnet_ping($h); if ($v !== null) { $out = $v; $oh = $h; break; } }
    $raw = $out !== null ? ($out > (float)$cfg['slow_ms'] ? 'slow' : 'ok') : (($gw !== '' && $r === null) ? 'norouter' : 'down');
    return ['t' => time(), 'router' => $gw, 'router_ms' => $r, 'out' => $oh, 'out_ms' => $out, 'raw' => $raw];
}

// Discord texts in the Discord language (lang/<code>.json, English for a missing text)
function sxnet_dt($lang, $k, $v = []) {
    static $tab = [];
    if (!isset($tab[$lang])) {
        $rd = function ($f) { $j = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null; return is_array($j) ? $j : []; };
        $en = $rd(__DIR__ . '/lang/en.json'); $tr = $lang !== 'en' ? $rd(__DIR__ . '/lang/' . $lang . '.json') : [];
        $tab[$lang] = ['en' => $en, 'tr' => $tr];
    }
    $e = $tab[$lang]['tr'][$k] ?? null; $s = (is_array($e) && trim((string)($e['text'] ?? '')) !== '') ? $e : ($tab[$lang]['en'][$k] ?? null);
    return is_array($s) ? mt_i18n_fmt(['text' => (string)($s['text'] ?? $k)], $v) : $k;
}
function sxnet_live() { $j = json_decode((string)@file_get_contents(sxnet_live_file()), true); return is_array($j) ? $j : null; }
