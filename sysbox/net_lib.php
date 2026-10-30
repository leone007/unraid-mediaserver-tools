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

function sxnet_defaults() {
    return ['iface' => 'auto', 'router' => 'auto', 'outside' => '1.1.1.1, 8.8.8.8', 'slow_ms' => '150', 'unit' => 'MB', 'dc' => '1', 'host_row' => '1',
            // stream priority (item 98): mode off | auto | button, limits in MB/s during outside streams, which streams count, minutes before full speed again
            'sp_mode' => 'auto', 'sp_up' => '25', 'sp_dn' => '0', 'sp_out' => 'outside', 'sp_back' => '5', 'sp_own' => '1'];
}
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
// name => pid of the running containers that have their own network (host-network ones share the host's counters;
// their names go into $hostNames)
function sxnet_ct_pids(&$hostNames = null) {
    $hostNames = [];
    $t = getenv('SX_NET_PIDS');   // tests only: "name=pid,name=pid"; SX_NET_HOSTCT: "name,name" on the host network
    if ($t !== false && $t !== '') {
        $hostNames = array_values(array_filter(explode(',', (string)getenv('SX_NET_HOSTCT')), 'sx_valid_name'));
        $o = []; foreach (explode(',', $t) as $x) { [$n, $p] = array_pad(explode('=', $x, 2), 2, ''); if (sx_valid_name($n) && ctype_digit($p)) { $o[$n] = (int)$p; } } return $o;
    }
    $d = sx_docker(); if ($d === '') { return []; }
    [$rc, $ids] = sx_run(escapeshellarg($d) . ' ps -q --no-trunc', 8);
    $ids = array_values(array_filter(array_map('trim', explode("\n", (string)$ids)), function ($x) { return preg_match('/^[0-9a-f]{12,64}$/', $x); }));
    if ($rc !== 0 || !$ids) { return []; }
    [$rc, $txt] = sx_run(escapeshellarg($d) . ' inspect -f ' . escapeshellarg('{{.Name}} {{.State.Pid}}') . ' ' . implode(' ', array_map('escapeshellarg', $ids)), 15);
    if ($rc !== 0) { return []; }
    $host = @readlink('/proc/self/ns/net'); $o = [];
    foreach (explode("\n", (string)$txt) as $l) {
        if (!preg_match('/^\/?(\S+)\s+(\d+)$/', trim($l), $m) || !sx_valid_name($m[1]) || (int)$m[2] <= 0) { continue; }
        if ($host && @readlink('/proc/' . $m[2] . '/ns/net') === $host) { $hostNames[] = $m[1]; continue; }
        $o[$m[1]] = (int)$m[2];
    }
    ksort($o, SORT_NATURAL | SORT_FLAG_CASE);
    sort($hostNames, SORT_NATURAL | SORT_FLAG_CASE);
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

// ---- stream priority (item 98) ------------------------------------------------------------------
// While Jellyfin, Emby or Plex (every server switched on in Streambox, owner 2026-10-30) plays to somebody outside the house, qBittorrent runs on its alternative speed limit (written there
// when the Network page is saved); after the last outside stream it goes back to full speed. Only the limit switch of
// qBittorrent is touched, and only one Sysbox switched on itself (a limit you set yourself stays).
const SXSP_STATE = '/boot/config/plugins/sysbox/streamprio.json';   // held or not, events (written only when it changes)
const SXSP_LIVE  = '/tmp/sysbox_sp.json';                           // the last check, for the tile and the page
const SXSP_JAR   = '/tmp/sysbox_qb.jar';
function sxsp_state_file() { return sxnet_dir() !== '' ? sxnet_dir() . '/streamprio.json' : SXSP_STATE; }
function sxsp_live_file() { return sxnet_dir() !== '' ? sxnet_dir() . '/sysbox_sp.json' : SXSP_LIVE; }
function sxsp_jar() { return sxnet_dir() !== '' ? sxnet_dir() . '/qb.jar' : SXSP_JAR; }

// an address the stream goes to inside the house (private, loopback, link-local); Tailscale (100.64.0.0/10) is outside
function sxsp_inside($ep) {
    $ip = trim((string)$ep);
    if (preg_match('/^\[([0-9a-fA-F:.]+)\](?::\d+)?$/', $ip, $m)) { $ip = $m[1]; }
    elseif (substr_count($ip, ':') === 1) { $ip = explode(':', $ip)[0]; }
    if (stripos($ip, '::ffff:') === 0 && filter_var(substr($ip, 7), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) { $ip = substr($ip, 7); }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $n = ip2long($ip);
        foreach ([['10.0.0.0', 8], ['172.16.0.0', 12], ['192.168.0.0', 16], ['127.0.0.0', 8], ['169.254.0.0', 16]] as [$b, $l]) {
            if (($n & (-1 << (32 - $l))) === (ip2long($b) & (-1 << (32 - $l)))) { return true; }
        }
        return false;
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $l = strtolower($ip);
        return $l === '::1' || preg_match('/^f[cd]/', $l) || preg_match('/^fe[89ab]/', $l);
    }
    return false;   // unknown: counted as outside, the safe side for the stream
}
function sxsp_ini($f) { $x = is_file($f) ? @parse_ini_file($f, false, INI_SCANNER_RAW) : false; return is_array($x) ? $x : []; }
// the media servers from the Streambox settings, qBittorrent from the Seedbox settings (no second copy of the keys)
function sxsp_sb_cfg() { return sxsp_ini(getenv('SX_SP_SB_CFG') ?: '/boot/config/plugins/streambox/settings.cfg'); }
function sxsp_jf_cfg() {
    $c = sxsp_sb_cfg();
    $h = (string)($c['jf_host'] ?? '127.0.0.1');
    return ['host' => preg_match('/^[A-Za-z0-9.\-]+$/', $h) ? $h : '', 'port' => (int)($c['jf_port'] ?? 8096), 'key' => (string)($c['jf_key'] ?? '')];
}
const SXSP_SRV_NAME = ['jf' => 'Jellyfin', 'em' => 'Emby', 'px' => 'Plex'];
// every server Streambox shows and has a key for: [id => [base url, auth header]]
function sxsp_servers() {
    $c = sxsp_sb_cfg(); $o = [];
    $on = function ($v) { return !in_array(strtolower(trim((string)$v)), ['0', 'no', 'false', 'off', ''], true); };
    $host = function ($k, $def) use ($c) { $h = (string)($c[$k] ?? $def); return preg_match('/^[A-Za-z0-9.\-]+$/', $h) ? $h : ''; };
    $jf = sxsp_jf_cfg();
    if ($jf['key'] !== '' && $jf['host'] !== '') { $o['jf'] = ['http://' . $jf['host'] . ':' . $jf['port'], 'Authorization: MediaBrowser Token="' . $jf['key'] . '"']; }
    if ($on($c['em_on'] ?? '0') && (string)($c['em_key'] ?? '') !== '' && $host('em_host', '127.0.0.1') !== '') {
        $o['em'] = ['http://' . $host('em_host', '127.0.0.1') . ':' . (int)($c['em_port'] ?? 8097), 'X-Emby-Token: ' . $c['em_key']];
    }
    if ($on($c['px_on'] ?? '0') && $host('px_host', '127.0.0.1') !== '') {
        $t = sxsp_plex_token($c);
        if ($t !== '') { $o['px'] = ['http://' . $host('px_host', '127.0.0.1') . ':' . (int)($c['px_port'] ?? 32400), 'X-Plex-Token: ' . $t]; }
    }
    return $o;
}
// Plex: the token saved in Streambox, else the server owner's token from Plex's Preferences.xml (as Streambox does)
function sxsp_plex_token($c) {
    $t = trim((string)($c['px_token'] ?? ''));
    if ($t !== '') { return preg_match('/^[A-Za-z0-9_\-]{8,64}$/', $t) ? $t : ''; }
    $dir = rtrim((string)($c['px_dir'] ?? '/mnt/cache/docker/plex'), '/');
    if (!preg_match('#^/mnt/[^\0]+$#', $dir) || strpos($dir, '..') !== false) { return ''; }
    $x = @file_get_contents($dir . '/Library/Application Support/Plex Media Server/Preferences.xml');
    return ($x !== false && preg_match('/\sPlexOnlineToken="([A-Za-z0-9_\-]{8,64})"/', $x, $m)) ? $m[1] : '';
}
// the Streambox nicknames: Jellyfin user id, em:<Emby user id>, px:<Plex account id>
function sxsp_nicks() {
    $f = getenv('SX_SP_NICKS') ?: '/boot/config/plugins/streambox/nicknames.json';
    $j = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
    return is_array($j) ? $j : [];
}
function sxsp_qb_cfg() {
    $c = sxsp_ini(getenv('SX_SP_QB_CFG') ?: '/boot/config/plugins/qbittorrent_widget/settings.cfg');
    $h = (string)($c['host'] ?? '127.0.0.1');
    return ['host' => preg_match('/^[A-Za-z0-9.\-]+$/', $h) ? $h : '', 'port' => (int)($c['port'] ?? 8080), 'user' => (string)($c['user'] ?? ''), 'pass' => (string)($c['pass'] ?? '')];
}
function sxsp_http($method, $url, $headers = [], $post = null, $jar = '') {
    $ch = curl_init($url);
    $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 6, CURLOPT_HTTPHEADER => $headers];
    if ($jar !== '') { $o[CURLOPT_COOKIEJAR] = $jar; $o[CURLOPT_COOKIEFILE] = $jar; }
    if ($method === 'POST') { $o[CURLOPT_POST] = true; $o[CURLOPT_POSTFIELDS] = is_array($post) ? http_build_query($post) : (string)$post; }
    curl_setopt_array($ch, $o);
    $b = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($jar !== '' && is_file($jar)) { @chmod($jar, 0600); }
    return [$b === false ? 0 : $code, $b === false ? '' : (string)$b];
}
// the playing sessions of every media server: [[user, device, title, outside, server id], ...]; null when no server could be
// asked. $st gets each server's answer: [id => true | false]. The user is the Streambox nickname, with the server's name
// after it when more than one server is watched ("Zoli (Plex)").
function sxsp_sessions(&$st = null) {
    $st = []; $o = []; $srv = sxsp_servers(); $nick = sxsp_nicks(); $multi = count($srv) > 1;
    foreach ($srv as $id => [$base, $hdr]) {
        $path = $id === 'px' ? '/status/sessions' : ($id === 'em' ? '/emby/Sessions?ActiveWithinSeconds=120' : '/Sessions?ActiveWithinSeconds=120');
        [$code, $b] = sxsp_http('GET', $base . $path, [$hdr, 'Accept: application/json']);
        $j = $code === 200 ? json_decode($b, true) : null;
        if ($id === 'px' && is_array($j)) { $j = sxsp_plex_rows($j); }
        $st[$id] = is_array($j);
        if (!is_array($j)) { continue; }
        foreach ($j as $s) {
            if (!is_array($s) || empty($s['NowPlayingItem'])) { continue; }
            $it = $s['NowPlayingItem'];
            $t = (string)($it['Name'] ?? '');
            if (!empty($it['SeriesName'])) { $t = $it['SeriesName'] . ' · ' . $t; }
            $uid = (string)($s['UserId'] ?? ''); $nk = $id === 'jf' ? $uid : $id . ':' . $uid;
            $u = ($uid !== '' && trim((string)($nick[$nk] ?? '')) !== '') ? trim((string)$nick[$nk]) : (string)($s['UserName'] ?? '?');
            if ($multi) { $u .= ' (' . SXSP_SRV_NAME[$id] . ')'; }
            $out = array_key_exists('Outside', $s) ? (bool)$s['Outside'] : !sxsp_inside($s['RemoteEndPoint'] ?? '');
            $o[] = [$u, (string)($s['DeviceName'] ?? ''), mb_substr($t, 0, 80), $out, $id];
        }
    }
    return in_array(true, $st, true) ? $o : null;
}
// Plex /status/sessions in the shape above; outside = Plex's own lan/wan mark, else its "local" flag, else the address
function sxsp_plex_rows($j) {
    $list = $j['MediaContainer']['Metadata'] ?? [];
    if (!is_array($list)) { return null; }
    $o = [];
    foreach ($list as $m) {
        if (!is_array($m)) { continue; }
        $pl = is_array($m['Player'] ?? null) ? $m['Player'] : []; $loc = (string)($m['Session']['location'] ?? '');
        $out = $loc !== '' ? $loc === 'wan' : (isset($pl['local']) ? !filter_var($pl['local'], FILTER_VALIDATE_BOOLEAN) : !sxsp_inside($pl['address'] ?? ''));
        $o[] = ['NowPlayingItem' => ['Name' => (string)($m['title'] ?? '?'), 'SeriesName' => (string)($m['grandparentTitle'] ?? '')],
                'UserId' => (string)($m['User']['id'] ?? ''), 'UserName' => (string)($m['User']['title'] ?? '?'),
                'DeviceName' => (string)($pl['title'] ?? $pl['device'] ?? ''), 'Outside' => $out];
    }
    return $o;
}
// qBittorrent Web API with the Seedbox login (one login retry); [code, body]
function sxsp_qb($path, $post = null) {
    $c = sxsp_qb_cfg();
    if ($c['host'] === '') { return [0, '']; }
    $base = 'http://' . $c['host'] . ':' . $c['port']; $h = ['Referer: ' . $base, 'Origin: ' . $base];
    $do = function () use ($base, $path, $post, $h) { return sxsp_http($post === null ? 'GET' : 'POST', $base . $path, $h, $post, sxsp_jar()); };
    $r = $do();
    if ($r[0] === 401 || $r[0] === 403) {
        [$lc, $lb] = sxsp_http('POST', $base . '/api/v2/auth/login', $h, ['username' => $c['user'], 'password' => $c['pass']], sxsp_jar());
        if ($lc !== 200 || trim($lb) !== 'Ok.') { return [403, '']; }
        $r = $do();
    }
    return $r;
}
// 1 = alternative limit on, 0 = off, null = qBittorrent cannot be asked
function sxsp_qb_mode() { [$c, $b] = sxsp_qb('/api/v2/transfer/speedLimitsMode'); return $c === 200 && in_array(trim($b), ['0', '1'], true) ? (int)trim($b) : null; }
function sxsp_qb_toggle() { [$c] = sxsp_qb('/api/v2/transfer/toggleSpeedLimitsMode', []); return $c === 200; }
// the alternative limits in MB/s (qBittorrent keeps bytes per second; 0 = no limit)
function sxsp_qb_alt() {
    [$c, $b] = sxsp_qb('/api/v2/app/preferences'); $j = $c === 200 ? json_decode($b, true) : null;
    return is_array($j) ? [round((float)($j['alt_up_limit'] ?? 0) / 1e6, 1), round((float)($j['alt_dl_limit'] ?? 0) / 1e6, 1)] : null;
}
function sxsp_qb_set_alt($up, $dn) {
    [$c] = sxsp_qb('/api/v2/app/setPreferences', ['json' => json_encode(['alt_up_limit' => (int)round($up * 1e6), 'alt_dl_limit' => (int)round($dn * 1e6)])]);
    return $c === 200;
}

function sxsp_state() {
    $j = json_decode((string)@file_get_contents(sxsp_state_file()), true);
    return (is_array($j) ? $j : []) + ['held' => 0, 'since' => 0, 'last_out' => 0, 'own' => 0, 'user_off' => 0, 'ev' => []];
}
function sxsp_save(&$S, $before) {
    $S['ev'] = array_slice($S['ev'], -30);
    $txt = json_encode($S);
    if ($txt === $before) { return; }   // the flash is written only when something changed
    $f = sxsp_state_file(); @mkdir(dirname($f), 0755, true);
    @file_put_contents($f . '.tmp', $txt); @rename($f . '.tmp', $f);
}
function sxsp_ev(&$S, $k, $v = []) { $S['ev'][] = ['t' => time(), 'k' => $k, 'v' => $v]; }
function sxsp_lock() { $h = @fopen((sxnet_dir() !== '' ? sxnet_dir() : '/tmp') . '/sysbox_sp.state.lock', 'c'); if ($h) { @flock($h, LOCK_EX); } return $h; }
function sxsp_names($out) { return implode(', ', array_values(array_unique(array_map(function ($x) { return $x[0]; }, $out)))); }

// one check (cron, every minute); $op: tick | hold (the tile's button) | release; returns the live data
function sxsp_run($op = 'tick') {
    $lk = sxsp_lock();
    $cfg = sxnet_cfg(); $S = sxsp_state(); $before = json_encode($S); $now = time();
    $mode = in_array($cfg['sp_mode'], ['off', 'auto', 'button'], true) ? $cfg['sp_mode'] : 'auto';
    $back = max(1, min(120, (int)$cfg['sp_back'])) * 60;
    $sess = sxsp_sessions($srvSt);
    $out = $sess === null ? [] : array_values(array_filter($sess, function ($x) use ($cfg) { return $cfg['sp_out'] === 'all' || $x[3]; }));
    $q = sxsp_qb_mode(); $msg = '';
    if ($out) { $S['last_out'] = $now; }

    if ($op === 'hold') {
        if ($q === null) { $msg = 'qb'; }
        else {
            if ($q === 0 && !sxsp_qb_toggle()) { $msg = 'qb'; }
            else { $S['held'] = 1; $S['since'] = $now; $S['user_off'] = 0; $S['last_out'] = max($S['last_out'], $now); sxsp_ev($S, 'hold_btn', ['u' => sxsp_names($out)]); $q = 1; }
        }
    } elseif ($op === 'release') {
        if ($S['held'] && $q === 1 && !sxsp_qb_toggle()) { $msg = 'qb'; }
        else { if ($S['held']) { sxsp_ev($S, 'release_btn'); } $S['held'] = 0; $S['user_off'] = $out ? 1 : 0; $q = $q === null ? null : 0; }
    } elseif ($mode === 'off') {
        if ($S['held'] && $q === 1 && sxsp_qb_toggle()) { sxsp_ev($S, 'release_off'); $q = 0; }
        if ($q !== null) { $S['held'] = 0; }
    } elseif ($sess !== null && $q !== null) {
        if ($S['held'] && $q === 0) {
            // somebody switched the limit off in qBittorrent: leave it off until the outside streams have ended
            $S['held'] = 0; if ($out) { $S['user_off'] = 1; sxsp_ev($S, 'user_off'); }
        } elseif ($S['held'] && !$out && $now - $S['last_out'] >= $back) {
            if (sxsp_qb_toggle()) { $S['held'] = 0; sxsp_ev($S, 'release', ['m' => (int)round(($now - $S['since']) / 60)]); $q = 0; }
            else { $msg = 'qb'; }
        } elseif (!$S['held'] && $out && $mode === 'auto' && !$S['user_off']) {
            if ($q === 1) {
                if (!$S['own']) { $S['own'] = 1; sxsp_ev($S, 'own'); }
                if ($cfg['sp_own'] === '0') { $S['held'] = 1; $S['since'] = $now; }
            } elseif (sxsp_qb_toggle()) { $S['held'] = 1; $S['since'] = $now; sxsp_ev($S, 'hold', ['u' => sxsp_names($out)]); $q = 1; }
            else { $msg = 'qb'; }
        }
        if (!$out && $now - $S['last_out'] >= $back) { $S['user_off'] = 0; $S['own'] = 0; }
    }
    // no media server has answered for a long time while the limit is on: give qBittorrent its speed back
    if ($op === 'tick' && $sess === null && $S['held'] && $q === 1 && $now - $S['last_out'] >= max($back, 1800) && sxsp_qb_toggle()) {
        $S['held'] = 0; sxsp_ev($S, 'release_nojf'); $q = 0;
    }
    sxsp_save($S, $before);
    $L = ['t' => $now, 'mode' => $mode, 'jf' => $sess !== null, 'srv' => $srvSt, 'qb' => $q !== null, 'q' => $q, 'out' => array_map(function ($x) { return ['u' => $x[0], 'd' => $x[1], 't' => $x[2]]; }, $out),
          'n_all' => $sess === null ? 0 : count($sess), 'held' => (int)$S['held'], 'since' => (int)$S['since'], 'own' => (int)$S['own'], 'user_off' => (int)$S['user_off'],
          'last_out' => (int)$S['last_out'], 'back' => $back, 'ev' => array_slice($S['ev'], -20), 'err' => $msg];
    $f = sxsp_live_file(); @file_put_contents($f . '.tmp', json_encode($L)); @rename($f . '.tmp', $f);
    if ($lk) { @flock($lk, LOCK_UN); @fclose($lk); }
    return $L;
}
function sxsp_live() { $j = json_decode((string)@file_get_contents(sxsp_live_file()), true); return is_array($j) ? $j : null; }

