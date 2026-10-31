<?php
// Router part (item 132): any Linux router with SSH (Asuswrt-Merlin, other Entware routers). Sysbox makes its own key; the
// router's key line runs only sysbox_ro.sh (command=), which prints counters and status and changes nothing. rtrmon.php asks
// it every few seconds and keeps the live file for the tile; the connection settings are in router.cfg.
require_once __DIR__ . '/lib.php';

const RTR_CFG_DIR = '/boot/config/plugins/sysbox';
function rtr_dir() { $d = getenv('SX_RTR_DIR'); return ($d !== false && $d !== '') ? rtrim($d, '/') : RTR_CFG_DIR; }   // tests: a scratch folder
function rtr_cfg_file() { return rtr_dir() . '/router.cfg'; }
function rtr_key_file() { return rtr_dir() . '/router_key'; }
function rtr_known_file() { return rtr_dir() . '/router_known_hosts'; }
function rtr_live_file() { $d = getenv('SX_RTR_DIR'); return ($d !== false && $d !== '') ? rtr_dir() . '/sysbox_rtr.json' : '/tmp/sysbox_rtr.json'; }

function rtr_defaults() { return ['host' => '', 'user' => '', 'port' => '22', 'path' => '/jffs/scripts/sysbox_ro.sh', 'sec' => '5']; }
function rtr_cfg() {
    $c = rtr_defaults(); $f = rtr_cfg_file();
    if (is_file($f)) { $x = @parse_ini_file($f); if (is_array($x)) { $c = array_merge($c, array_intersect_key($x, $c)); } }
    return $c;
}
// the values the page sends; null when one is not valid (the caller says which)
function rtr_check($c) {
    $o = [];
    $o['host'] = trim((string)($c['host'] ?? ''));
    if ($o['host'] !== '' && !preg_match('/^[A-Za-z0-9][A-Za-z0-9.\-]{0,252}$/', $o['host'])) { return 'host'; }
    $o['user'] = trim((string)($c['user'] ?? ''));
    if ($o['user'] !== '' && !preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.\-]{0,31}$/', $o['user'])) { return 'user'; }
    $o['port'] = trim((string)($c['port'] ?? '22'));
    if (!preg_match('/^\d{1,5}$/', $o['port']) || (int)$o['port'] < 1 || (int)$o['port'] > 65535) { return 'port'; }
    $o['path'] = trim((string)($c['path'] ?? ''));
    if (!preg_match('#^/[A-Za-z0-9_./\-]{1,200}$#', $o['path']) || strpos($o['path'], '..') !== false) { return 'path'; }
    $o['sec'] = in_array((string)($c['sec'] ?? '5'), ['2', '5', '10'], true) ? (string)$c['sec'] : '5';
    return $o;
}
function rtr_cfg_save($c) {
    $txt = '';
    foreach (rtr_defaults() as $k => $v) { $txt .= $k . '="' . str_replace('"', '', (string)($c[$k] ?? $v)) . "\"\n"; }
    $f = rtr_cfg_file(); @mkdir(dirname($f), 0755, true);
    if (@file_put_contents($f . '.tmp', $txt) === false) { return false; }
    return @rename($f . '.tmp', $f);
}
function rtr_ready($r) { return $r['host'] !== '' && $r['user'] !== '' && is_file(rtr_key_file()) && is_file(rtr_key_file() . '.pub'); }

// ---- the key: made once (or again on New key), kept on the flash; ssh wants a private file, the flash cannot give one ----
function rtr_key_make($force = false) {
    $k = rtr_key_file();
    if (!$force && is_file($k) && is_file($k . '.pub')) { return true; }
    $tmp = sys_get_temp_dir() . '/sysbox_rtrnew_' . getmypid();
    @unlink($tmp); @unlink($tmp . '.pub');
    $host = preg_replace('/[^A-Za-z0-9_.\-]/', '', (string)php_uname('n')) ?: 'unraid';
    list($rc) = sx_run('ssh-keygen -q -t ed25519 -N "" -C ' . escapeshellarg('sysbox-' . strtolower($host)) . ' -f ' . escapeshellarg($tmp), 15);
    if ($rc !== 0 || !is_file($tmp) || !is_file($tmp . '.pub')) { return false; }
    @mkdir(dirname($k), 0755, true);
    $ok = @copy($tmp, $k) && @copy($tmp . '.pub', $k . '.pub');
    @unlink($tmp); @unlink($tmp . '.pub'); @unlink(rtr_key_work());
    return $ok;
}
function rtr_key_work() { $d = getenv('SX_RTR_DIR'); return ($d !== false && $d !== '') ? rtr_dir() . '/work_key' : '/tmp/sysbox_rtrkey'; }
function rtr_key_path() {
    $src = rtr_key_file(); $w = rtr_key_work();
    if (!is_file($src)) { return ''; }
    if (!is_file($w) || md5_file($w) !== md5_file($src)) { @copy($src, $w . '.tmp'); @chmod($w . '.tmp', 0600); @rename($w . '.tmp', $w); }
    @chmod($w, 0600);
    return $w;
}
function rtr_pub() { $p = rtr_key_file() . '.pub'; return is_file($p) ? trim((string)@file_get_contents($p)) : ''; }
// the line for the router's authorized keys: this key may only run the read-only script
function rtr_keyline($r) {
    $pub = rtr_pub(); if ($pub === '') { return ''; }
    return 'command="' . $r['path'] . '",no-port-forwarding,no-agent-forwarding,no-X11-forwarding,no-pty ' . $pub;
}
// one short line for the router's SSH (under the 4 KB a terminal line takes): writes sysbox_ro.sh, nothing else
function rtr_install_cmd($r) {
    $src = (string)@file_get_contents(__DIR__ . '/sysbox_ro.sh'); if ($src === '') { return ''; }
    $b64 = base64_encode(gzencode($src, 9));
    $p = $r['path'];
    return 'mkdir -p ' . dirname($p) . ' && echo ' . $b64 . ' | base64 -d | gunzip > ' . $p . ' && chmod 755 ' . $p . ' && echo Sysbox OK';
}

// ---- asking the router: one ssh call; the error kinds the tile and the page explain ----
function rtr_ssh($r, $cmd, $secs = 15, $accept = false) {
    $key = rtr_key_path();
    if ($key === '' || $r['host'] === '' || $r['user'] === '') { return [false, '', 'setup']; }
    $bin = getenv('SX_RTR_SSH') ?: 'ssh';   // tests: a fake ssh
    $line = escapeshellarg($bin) . ' -T -i ' . escapeshellarg($key) . ' -p ' . (int)$r['port']
        . ' -o BatchMode=yes -o ConnectTimeout=4 -o LogLevel=ERROR -o PasswordAuthentication=no -o ServerAliveInterval=5'
        . ' -o UserKnownHostsFile=' . escapeshellarg(rtr_known_file()) . ' -o StrictHostKeyChecking=' . ($accept ? 'accept-new' : 'yes')
        . ' ' . escapeshellarg($r['user'] . '@' . $r['host']) . ' ' . escapeshellarg($cmd);
    $to = sx_bin('timeout'); if ($to !== '') { $line = $to . ' ' . (int)$secs . ' ' . $line; }
    $p = @proc_open($line, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp);
    if (!is_resource($p)) { return [false, '', 'unreach']; }
    fclose($pp[0]); $out = stream_get_contents($pp[1]); $err = stream_get_contents($pp[2]); fclose($pp[1]); fclose($pp[2]);
    $rc = proc_close($p);
    if ($rc === 0) { return [true, (string)$out, '']; }
    $e = (string)$err;
    if (stripos($e, 'host key') !== false || stripos($e, 'IDENTIFICATION HAS CHANGED') !== false) { return [false, $out, 'hostkey']; }
    if (stripos($e, 'Permission denied') !== false) { return [false, $out, 'auth']; }
    if ($rc === 127 || stripos($e, 'not found') !== false || stripos($e, 'No such file') !== false) { return [false, $out, 'noscript']; }
    if (stripos($e, 'not allowed') !== false) { return [false, $out, 'bad']; }
    return [false, $out, 'unreach'];
}
function rtr_sections($txt) {
    $S = []; $cur = null;
    foreach (preg_split('/\r?\n/', (string)$txt) as $l) {
        if (preg_match('/^@([a-z]+)$/', $l, $m)) { $cur = $m[1]; $S[$cur] = []; continue; }
        if ($cur !== null && $l !== '') { $S[$cur][] = $l; }
    }
    return $S;
}
function rtr_snap($r, $accept = false) {
    list($ok, $out, $err) = rtr_ssh($r, 'snap', 15, $accept);
    if (!$ok) { return ['ok' => false, 'err' => $err]; }
    $S = rtr_sections($out);
    if (!isset($S['ver'], $S['stat'], $S['mem'])) { return ['ok' => false, 'err' => 'bad']; }
    return ['ok' => true, 'err' => '', 'D' => rtr_digest($S)];
}

// ---- reading the sections into figures ----
function rtr_kv($lines) { $o = []; foreach ((array)$lines as $l) { $p = strpos($l, '='); if ($p !== false) { $o[substr($l, 0, $p)] = trim(substr($l, $p + 1)); } } return $o; }
function rtr_digest($S) {
    $D = ['cpu' => [], 'mem' => [], 'load' => null, 'up' => 0, 'dev' => [], 'wan' => '', 'temps' => [], 'arp' => 0, 'radios' => [], 'svc' => [], 'month' => null, 'ping' => null, 'mesh' => [], 'name' => '', 'fw' => ''];
    $host = (array)($S['host'] ?? []); $D['name'] = (string)($host[0] ?? ''); $D['fw'] = isset($host[1]) ? 'Linux ' . $host[1] : '';
    foreach ((array)($S['stat'] ?? []) as $l) {
        $f = preg_split('/\s+/', trim($l)); if (count($f) < 5) { continue; }
        $v = array_map('floatval', array_slice($f, 1, 8)); $tot = array_sum($v); $idle = $v[3] + ($v[4] ?? 0);
        $D['cpu'][$f[0]] = [$tot, $idle];
    }
    $mi = []; foreach ((array)($S['mem'] ?? []) as $l) { if (preg_match('/^(\w+):\s+(\d+)/', $l, $m)) { $mi[$m[1]] = (float)$m[2] * 1024; } }
    $tot = $mi['MemTotal'] ?? 0; $av = $mi['MemAvailable'] ?? (($mi['MemFree'] ?? 0) + ($mi['Buffers'] ?? 0) + ($mi['Cached'] ?? 0));
    $D['mem'] = ['used' => max(0, $tot - $av), 'total' => $tot, 'swap_used' => max(0, ($mi['SwapTotal'] ?? 0) - ($mi['SwapFree'] ?? 0)), 'swap_total' => $mi['SwapTotal'] ?? 0];
    $la = preg_split('/\s+/', trim((string)(($S['load'] ?? [''])[0]))); $D['load'] = is_numeric($la[0] ?? null) ? (float)$la[0] : null;
    $D['up'] = (int)(float)(preg_split('/\s+/', trim((string)(($S['up'] ?? ['0'])[0])))[0] ?? 0);
    foreach ((array)($S['dev'] ?? []) as $l) {
        if (!preg_match('/^\s*([^:\s]+):\s*(.*)$/', $l, $m)) { continue; }
        $f = preg_split('/\s+/', trim($m[2])); if (count($f) >= 9) { $D['dev'][$m[1]] = [(float)$f[0], (float)$f[8]]; }
    }
    foreach ((array)($S['temp'] ?? []) as $l) { if (is_numeric(trim($l))) { $t = (float)trim($l); $D['temps'][] = round($t > 1000 ? $t / 1000 : $t, 1); } }
    $D['arp'] = (int)trim((string)(($S['arp'] ?? ['0'])[0]));
    $nv = rtr_kv($S['nvram'] ?? []);
    if ($nv) {
        $model = ($nv['odmpid'] ?? '') !== '' ? $nv['odmpid'] : ($nv['productid'] ?? '');
        if ($model !== '') { $D['name'] = $model; }
        if (($nv['firmver'] ?? '') !== '' && ($nv['buildno'] ?? '') !== '') {
            $D['fw'] = str_replace('.', '', $nv['firmver']) . '.' . $nv['buildno'] . ((($nv['extendno'] ?? '0') !== '0' && ($nv['extendno'] ?? '') !== '') ? '_' . $nv['extendno'] : '');
        }
    }
    // the WAN: the router's own WAN interface, or the one of the default route
    $wan = (string)($nv['wan0_ifname'] ?? '');
    if ($wan === '' || !isset($D['dev'][$wan])) { foreach ((array)($S['route'] ?? []) as $l) { if (preg_match('/\bdev\s+(\S+)/', $l, $m) && isset($D['dev'][$m[1]])) { $wan = $m[1]; break; } } }
    $D['wan'] = isset($D['dev'][$wan]) ? $wan : '';
    $D['wan_proto'] = (string)($nv['wan0_proto'] ?? '');
    $lan = (string)($nv['lan_ipaddr'] ?? '');
    foreach (explode('<', implode('', (array)($S['mesh'] ?? []))) as $n) {
        $f = explode('>', $n);
        if (count($f) >= 2 && $f[0] !== '' && filter_var($f[1], FILTER_VALIDATE_IP) && $f[1] !== $lan) { $D['mesh'][] = [preg_replace('/[^A-Za-z0-9 _.\-]/', '', $f[0]), $f[1]]; }
    }
    foreach ((array)($S['radio'] ?? []) as $l) {
        $f = preg_split('/\s+/', trim($l)); if (count($f) < 3) { continue; }
        $band = ['2' => '2.4 GHz', '1' => '5 GHz', '4' => '6 GHz'][$f[1]] ?? $f[0];
        $t = (isset($f[3]) && is_numeric($f[3]) && (float)$f[3] > 0) ? floor((float)$f[3] / 2 + 20) : null;   // Broadcom phy_tempsense: raw / 2 + 20 (as scMerlin shows it)
        $D['radios'][] = [$band, (int)$f[2], $t];
    }
    $sv = rtr_kv($S['svc'] ?? []);
    if (!empty($sv['ent_exp']) || !empty($sv['ent_ok'])) { $D['svc'][] = ['entware', !empty($sv['ent_ok'])]; }
    if (!empty($sv['agh_inst'])) { $D['svc'][] = ['adguard', ($sv['AdGuardHome'] ?? '0') === '1']; }
    if (!empty($sv['sky_inst'])) { $D['svc'][] = ['skynet', (int)($sv['sky_run'] ?? 0) > 0]; }
    if (!empty($sv['ts_inst'])) { $D['svc'][] = ['tailscale', ($sv['tailscaled'] ?? '0') === '1']; }
    if (!empty($sv['vn_inst'])) { $D['svc'][] = ['vnstat', ($sv['vnstatd'] ?? '0') === '1']; }
    $vj = json_decode(implode('', (array)($S['vnm'] ?? [])), true);
    if (is_array($vj) && !empty($vj['interfaces'])) {
        $pick = null; foreach ($vj['interfaces'] as $i) { if (($i['name'] ?? '') === $D['wan']) { $pick = $i; } } if (!$pick) { $pick = $vj['interfaces'][0]; }
        $ym = [(int)date('Y'), (int)date('n')];
        foreach ((array)($pick['traffic']['month'] ?? []) as $mo) {
            if ((int)($mo['date']['year'] ?? 0) === $ym[0] && (int)($mo['date']['month'] ?? 0) === $ym[1]) { $D['month'] = [(float)($mo['rx'] ?? 0), (float)($mo['tx'] ?? 0)]; }
        }
        if ($D['month'] === null) { $D['month'] = [0.0, 0.0]; }
        $D['vn_since'] = (int)($pick['created']['timestamp'] ?? 0);
    }
    $pg = explode(',', (string)(($S['ping'] ?? [''])[0]));
    if (count($pg) >= 4 && is_numeric($pg[1])) { $D['ping'] = ['ms' => round((float)$pg[1], 1), 'jit' => round((float)$pg[2], 1), 'q' => (float)$pg[3], 'to' => preg_replace('/[^0-9A-Za-z.:\-]/', '', (string)($pg[4] ?? '')), 't' => (int)$pg[0]]; }
    return $D;
}
// Skynet's own statistics (firewall stats), read every 10 minutes: block events, top ports in, top devices out
function rtr_sky_parse($txt) {
    $txt = preg_replace('/\x1b\[[0-9;?]*[A-Za-z]/', '', (string)$txt);
    $o = ['ev' => null, 'ips' => null, 'from' => 0, 'ports' => [], 'out' => [], 'ban' => null, 'blk' => null];
    if (preg_match('/Block Events\D*?(\d+)\s*\((\d+)\s+Unique/u', $txt, $m)) { $o['ev'] = (int)$m[1]; $o['ips'] = (int)$m[2]; }
    if (preg_match('/Monitor Span\s*\S*\s*(\w{3}\s+\d+\s+[\d:]+)\s*-\s*(\w{3}\s+\d+\s+[\d:]+)/u', $txt, $m)) {
        $f = strtotime($m[1]); if ($f !== false && $f > time() + 86400) { $f = strtotime($m[1] . ' -1 year'); }   // the log has no year
        $o['from'] = $f === false ? 0 : $f;
    }
    $sec = '';
    foreach (preg_split('/\r?\n/', $txt) as $l) {
        if (preg_match('/^Top \d+ (.+?);?\s*$/', trim($l), $m)) { $sec = $m[1]; continue; }
        if (!preg_match('/^(\d+)x\s*\|\s*(\S+)/', trim($l), $m)) { continue; }
        if (stripos($sec, 'Targeted Ports (Inbound)') !== false && count($o['ports']) < 5 && ctype_digit($m[2])) { $o['ports'][] = [(int)$m[1], (int)$m[2]]; }
        elseif (stripos($sec, 'Blocked Devices (Outbound)') !== false && count($o['out']) < 5) {
            $name = preg_match('/^\d+x\s*\|\s*\S+\s*\|\s*(.+?)\s*$/', trim($l), $n) ? $n[1] : $m[2];   // the device name when Skynet knows it
            $o['out'][] = [(int)$m[1], preg_replace('/[^0-9A-Za-z .:_\-]/', '', $name)];
        }
    }
    // the closing line: banned IPs and ranges, connections blocked in and out
    if (preg_match('/(\d+)\s+IPs.*?(\d+)\s+Ranges Banned.*?(\d+)\s+Inbound.*?(\d+)\s+Outbound/u', $txt, $m)) { $o['ban'] = [(int)$m[1], (int)$m[2]]; $o['blk'] = [(int)$m[3], (int)$m[4]]; }
    return $o;
}
function rtr_live() { $f = rtr_live_file(); $j = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null; return is_array($j) ? $j : null; }
// for the settings test: which parts the router has
function rtr_modules($D) {
    $m = [['base', count($D['cpu']) - 1, $D['wan']]];
    if ($D['fw'] !== '' && strpos($D['fw'], 'Linux') !== 0) { $m[] = ['nvram', $D['name'], $D['fw'], count($D['mesh'])]; }
    if ($D['radios']) { $m[] = ['radio', count($D['radios'])]; }
    foreach ($D['svc'] as $s) { $m[] = ['svc_' . $s[0], $s[1]]; }
    if ($D['ping']) { $m[] = ['ping', $D['ping']['to']]; }
    return $m;
}
