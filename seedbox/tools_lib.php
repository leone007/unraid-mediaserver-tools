<?php
// Seedbox tools: findmytorrent (torrents for files that are not seeded) and updateme (smaller, better
// alternatives for big seeded titles, through Radarr and Sonarr). Used by tools.php (web) and tools_run.php.
const ST_DIR   = '/boot/config/plugins/qbittorrent_widget';
const ST_CFG   = ST_DIR . '/tools.json';
const ST_STATE = ST_DIR . '/tools_state.json';
const ST_FIND  = '/tmp/qbt_tools_find.json';
const ST_UPD   = '/tmp/qbt_tools_upd.json';
const ST_RES   = '/tmp/qbt_tools_res.json';
const ST_JAR   = '/tmp/qbt_tools_cookie.txt';

require_once __DIR__ . '/moveprog.php';
function st_dir() { $d = getenv('DBX_ST_DIR'); return ($d !== false && $d !== '') ? $d : ST_DIR; }

function st_json_read($f, $def = []) {
    if (!is_file($f)) { return $def; }
    $j = json_decode((string)@file_get_contents($f), true);
    return is_array($j) ? $j : $def;
}
function st_json_write($f, $data, $mode = 0644) {
    $dir = dirname($f);
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    $tmp = $f . '.tmp' . getmypid();
    if (@file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)) === false) { return false; }
    if (!@rename($tmp, $f)) { @unlink($tmp); return false; }
    @chmod($f, $mode);
    return true;
}
// one search result in /tmp/qbt_tools_res.json, under a lock (the page, the searches and the background check all write it)
function st_res_set($key, $val) {
    $lk = @fopen('/tmp/qbt_tools_res.lock', 'c'); if ($lk) { flock($lk, LOCK_EX); }
    $res = st_json_read(ST_RES, []); $res[$key] = $val; st_json_write(ST_RES, $res);
    if ($lk) { flock($lk, LOCK_UN); fclose($lk); }
}
function st_cfg_file() { return st_dir() . '/tools.json'; }
function st_state_file() { return st_dir() . '/tools_state.json'; }

function st_cfg() {
    $c = ['prowlarr' => ['url' => '', 'key' => ''], 'radarr' => ['url' => '', 'key' => ''], 'sonarr' => ['url' => '', 'key' => ''], 'lidarr' => ['url' => '', 'key' => ''], 'autobrr' => ['url' => '', 'key' => ''], 'ab_on' => 0, 'ab_filters' => [],
          'map_qb' => '/data', 'map_host' => '/mnt/user/data',
          'roots' => ['/mnt/user/data/downloads/FILM', '/mnt/user/data/downloads/SOROZAT'], 'dl_base' => '', 'folders' => null,
          'upd_min_gb' => 40, 'upd_min_age' => 90, 'upd_saving' => 30, 'dv_all' => 0, 'upd_upg_growth' => 100, 'upd_cat_save' => 1, 'upd_cat_hdr' => 1, 'upd_cat_res' => 1, 'find_tol' => 1, 'find_accept' => 99, 'repl_max_growth' => 100, 'find_missing_files' => 1, 'trash_days' => 3, 'dc_hook' => '', 'dc_lang' => 'hu', 'dc_on' => 0, 'bot_token' => '', 'bot_user' => '', 'bot_rep' => 1, 'bot_find' => 1, 'tag_find' => '', 'tag_upd' => '', 'tag_rep' => '', 'show_find' => 'relevant', 'show_upd' => 'relevant', 'show_rep' => 'relevant', 'show_find_days' => 3, 'show_upd_days' => 3, 'show_rep_days' => 3, 'dc_find' => 0, 'dc_upd' => 0, 'dc_rep' => 0, 'find_cat' => 1, 'auto_scan' => 1, 'auto_upd' => 0, 'auto_rep' => 0, 'auto_freq' => 'weekly', 'auto_day' => 1, 'auto_hour' => 8];
    $x = st_json_read(st_cfg_file(), []);
    foreach (['prowlarr', 'radarr', 'sonarr', 'lidarr', 'autobrr'] as $k) { if (isset($x[$k]) && is_array($x[$k])) { $c[$k] = array_merge($c[$k], $x[$k]); } }
    foreach (['ab_on', 'ab_filters', 'map_qb', 'map_host', 'roots', 'dl_base', 'folders', 'upd_min_gb', 'upd_min_age', 'upd_saving', 'dv_all', 'upd_upg_growth', 'upd_cat_save', 'upd_cat_hdr', 'upd_cat_res', 'find_tol', 'find_accept', 'repl_max_growth', 'find_missing_files', 'trash_days', 'dc_hook', 'dc_lang', 'dc_on', 'bot_token', 'bot_user', 'bot_rep', 'bot_find', 'tag_find', 'tag_upd', 'tag_rep', 'show_find', 'show_upd', 'show_rep', 'show_find_days', 'show_upd_days', 'show_rep_days', 'dc_find', 'dc_upd', 'dc_rep', 'find_cat', 'auto_scan', 'auto_upd', 'auto_rep', 'auto_freq', 'auto_day', 'auto_hour'] as $k) { if (isset($x[$k])) { $c[$k] = $x[$k]; } }
    foreach (['dc_find', 'dc_upd', 'dc_rep'] as $k) { if (!isset($x[$k])) { $c[$k] = !empty($c['dc_on']) ? 1 : 0; } } // the old single switch decides until the new ones are saved
    return st_cfg_folders($c);
}

// ---- folder roles (item 42) -----------------------------------------------------------------------------
// Every folder directly inside the downloads folder (dl_base) has a role: movies, series, music (the media library), other
// (seeding only, e.g. ratio torrents) or off (not watched). Find my torrent watches every folder but off; Shape up and the
// Season repacker only movies and series. Before the first save the roles come from the names; a folder that appears after
// a save is other and marked new. st_cfg() derives 'roots' (Find my torrent), 'lib' (movies + series) and 'series_dirs'.
const ST_ROLES = ['movies', 'series', 'music', 'other', 'off'];
function st_role_guess($name) {
    $n = strtolower($name);
    if (preg_match('/film|movie|mozi/', $n)) { return 'movies'; }
    if (preg_match('/sorozat|series|show|^tv/', $n)) { return 'series'; }
    if (preg_match('/muzzik|music|muzik|zene/', $n)) { return 'music'; }
    return 'other';
}
function st_folder_names($base) {
    $out = [];
    if ($base === '' || !is_dir($base)) { return $out; }
    foreach ((array)@scandir($base) as $e) {
        if ($e === '' || $e[0] === '.' || !is_dir($base . '/' . $e)) { continue; } // hidden folders and .seedbox_trash are never listed
        $out[] = $e;
    }
    return $out;
}
// [name => ['role' =>, 'new' => bool, 'path' =>]] for $base with the saved roles ($saved null = never saved)
function st_folder_list($base, $saved) {
    $names = st_folder_names($base);
    if (!$names && is_array($saved)) { $names = array_map('strval', array_keys($saved)); } // the share cannot be read (array stopped): keep the saved folders
    natcasesort($names); $out = [];
    foreach ($names as $n) {
        $has = is_array($saved) && isset($saved[$n]) && in_array($saved[$n], ST_ROLES, true);
        $out[$n] = ['role' => $has ? $saved[$n] : (is_array($saved) ? 'other' : st_role_guess($n)), 'new' => is_array($saved) && !$has, 'path' => $base . '/' . $n];
    }
    return $out;
}
function st_cfg_folders($c) {
    $base = rtrim((string)$c['dl_base'], '/');
    if ($base === '') { $r0 = (string)(((array)$c['roots'])[0] ?? ''); $base = $r0 !== '' ? dirname($r0) : '/mnt/user/data/downloads'; } // before the first save: the parent of the old list
    $c['dl_base'] = $base;
    $list = st_folder_list($base, is_array($c['folders']) ? $c['folders'] : null);
    $c['folder_list'] = $list; $c['roots'] = []; $c['lib'] = []; $c['series_dirs'] = [];
    foreach ($list as $f) {
        if ($f['role'] !== 'off') { $c['roots'][] = $f['path']; }
        if ($f['role'] === 'movies' || $f['role'] === 'series') { $c['lib'][] = $f['path']; }
        if ($f['role'] === 'series') { $c['series_dirs'][] = $f['path']; }
    }
    return $c;
}
function st_upd_hidden_file() { return dirname(st_state_file()) . '/upd_hidden.json'; }
function st_upd_hidden_read() { $a = st_json_read(st_upd_hidden_file(), []); return is_array($a) ? array_values($a) : []; }
function st_upd_hidden_set($hash, $on) {
    $lk = @fopen(st_upd_hidden_file() . '.lock', 'c');
    if ($lk) { @flock($lk, LOCK_EX); }
    $a = array_values(array_diff(st_upd_hidden_read(), [$hash]));
    if ($on) { $a[] = $hash; }
    st_json_write(st_upd_hidden_file(), $a, 0600);
    if ($lk) { @flock($lk, LOCK_UN); fclose($lk); }
}
function st_ignored_file() { return dirname(st_state_file()) . '/ignored.json'; }
function st_ignored_read() { $a = st_json_read(st_ignored_file(), []); return is_array($a) ? array_values($a) : []; }
function st_ignored_add($path) {
    $lk = @fopen(st_ignored_file() . '.lock', 'c');
    if ($lk) { @flock($lk, LOCK_EX); }
    $a = st_ignored_read();
    if (!in_array($path, $a, true)) { $a[] = $path; st_json_write(st_ignored_file(), $a, 0600); }
    if ($lk) { @flock($lk, LOCK_UN); fclose($lk); }
}
// The state lives in two copies: a live one in /tmp (written on every change) and the one on the flash drive, which is
// written only when something lasting changes (a stage, a new or removed entry, the queue), or once an hour: the
// progress messages ("downloading 45%") would otherwise rewrite the flash drive every minute. After a reboot the
// flash copy is read.
function st_state_live_file() { $d = getenv('DBX_ST_DIR'); return ($d !== false && $d !== '') ? $d . '/tools_state_live.json' : '/tmp/qbt_tools_state.json'; }
function st_state_lock_file() { $d = getenv('DBX_ST_DIR'); return ($d !== false && $d !== '') ? $d . '/tools_state.lock' : '/tmp/qbt_tools_state.lock'; }
function st_state() {
    $l = st_state_live_file();
    $s = st_json_read(is_file($l) ? $l : st_state_file(), []);
    $s = array_merge(['pending' => [], 'jobs' => [], 'packjobs' => [], 'queue' => [], 'qstat' => null], $s);
    $leg = isset($s['ignored']) && is_array($s['ignored']) ? $s['ignored'] : [];
    $s['ignored'] = array_values(array_unique(array_merge($leg, st_ignored_read())));
    return $s;
}
// what must reach the flash drive: everything except the messages of the entries that are still under way
function st_state_sig($s) {
    foreach (['pending', 'jobs', 'packjobs'] as $k) {
        foreach ((array)($s[$k] ?? []) as $i => $e) {
            if (in_array($e['stage'] ?? '', ['added', 'checking', 'grabbed', 'downloading', 'importing', 'blocked'], true)) { unset($s[$k][$i]['msg']); }
        }
    }
    return md5(json_encode($s));
}
// Writes the state. Call it only inside st_state_update (or with nothing else running), else changes can be lost.
function st_state_write($s) {
    if (!empty($s['ignored'])) { foreach ($s['ignored'] as $p) { st_ignored_add($p); } }
    unset($s['ignored']);
    $f = st_state_file();
    if (!st_json_write(st_state_live_file(), $s, 0600)) { return st_json_write($f, $s, 0600); }
    $old = is_file($f) ? st_json_read($f, null) : null;
    if (!is_array($old) || st_state_sig($old) !== st_state_sig($s) || time() - (int)@filemtime($f) >= 3600) { return st_json_write($f, $s, 0600); }
    return true;
}
// Read, change and write the state under one lock, so the minute tick and the buttons of the page cannot overwrite
// each other. $fn gets the state by reference; when it returns false nothing is written. Returns what $fn returned.
// Keep $fn short: no HTTP calls inside.
function st_state_update(callable $fn) {
    static $depth = 0;
    $lk = null;
    if ($depth === 0) { $lk = @fopen(st_state_lock_file(), 'c'); if ($lk) { @flock($lk, LOCK_EX); } }
    $depth++;
    try {
        $s = st_state();
        $r = $fn($s);
        if ($r !== false) { st_state_write($s); }
    } finally {
        $depth--;
        if ($lk) { @flock($lk, LOCK_UN); fclose($lk); }
    }
    return $r;
}
// One replacement start or removal at a time (a double click must not grab or delete twice). Returns the lock handle or null.
function st_action_lock() {
    $d = getenv('DBX_ST_DIR');
    $f = @fopen(($d !== false && $d !== '') ? $d . '/tools_action.lock' : '/tmp/qbt_tools_action.lock', 'c');
    if (!$f) { return null; }
    if (!flock($f, LOCK_EX | LOCK_NB)) { fclose($f); return null; }
    return $f;
}
function st_action_unlock($f) { if ($f) { @flock($f, LOCK_UN); fclose($f); } }
// key of a list entry (jobs and season pack jobs have an id; older check entries do not)
function st_entry_key($e) { return isset($e['id']) ? 'i' . $e['id'] : 'p' . ($e['hash'] ?? '') . '|' . ($e['path'] ?? '') . '|' . ($e['name'] ?? ''); }
// The tick worked on a copy ($orig -> $new) for minutes; put its changes onto the current state: an entry the page
// changed or added meanwhile keeps the page's version, an entry the tick dropped (old, finished) goes.
function st_merge_list($orig, $new, $cur) {
    $ob = []; foreach ((array)$orig as $e) { $ob[st_entry_key($e)] = $e; }
    $nb = []; foreach ((array)$new as $e) { $nb[st_entry_key($e)] = $e; }
    $out = [];
    foreach ((array)$cur as $e) {
        $k = st_entry_key($e);
        if (!isset($ob[$k]) || json_encode($e) !== json_encode($ob[$k])) { $out[] = $e; continue; }
        if (isset($nb[$k])) { $out[] = $nb[$k]; }
    }
    return $out;
}

function st_qbcfg() {
    $c = ['host' => '127.0.0.1', 'port' => '8080', 'user' => '', 'pass' => ''];
    $f = st_dir() . '/settings.cfg';
    if (is_file($f)) { $x = @parse_ini_file($f, false, INI_SCANNER_RAW); if (is_array($x)) { $c = array_merge($c, $x); } }
    return $c;
}

// ---- HTTP ---------------------------------------------------------------------------------------------
// opts: headers[], post (array|string), file (field => path), timeout, follow (bool), jar (path)
function st_http($method, $url, $opts = []) {
    $ch = curl_init($url);
    $h = $opts['headers'] ?? [];
    $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => $opts['timeout'] ?? 30,
          CURLOPT_FOLLOWLOCATION => !empty($opts['follow']), CURLOPT_MAXREDIRS => 5, CURLOPT_HEADER => true];
    if (!empty($opts['jar'])) { $o[CURLOPT_COOKIEJAR] = $opts['jar']; $o[CURLOPT_COOKIEFILE] = $opts['jar']; }
    if ($method === 'POST') {
        $o[CURLOPT_POST] = true;
        $post = $opts['post'] ?? '';
        if (!empty($opts['file'])) {
            $post = is_array($post) ? $post : [];
            foreach ($opts['file'] as $field => $path) { $post[$field] = new CURLFile($path, 'application/x-bittorrent', 'file.torrent'); }
        } elseif (is_array($post)) { $post = http_build_query($post); }
        $o[CURLOPT_POSTFIELDS] = $post;
    } elseif ($method !== 'GET') { $o[CURLOPT_CUSTOMREQUEST] = $method; if (isset($opts['post'])) { $o[CURLOPT_POSTFIELDS] = $opts['post']; } }
    $o[CURLOPT_HTTPHEADER] = $h;
    curl_setopt_array($ch, $o);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $err = curl_errno($ch) ? curl_error($ch) : '';
    curl_close($ch);
    if ($raw === false) { return [0, '', $err ?: 'request failed', '']; }
    return [$code, substr($raw, $hs), $err, substr($raw, 0, $hs)];
}

// ---- qBittorrent ---------------------------------------------------------------------------------------
function st_qb_base() { $c = st_qbcfg(); return 'http://' . $c['host'] . ':' . (int)$c['port']; }

function st_qb_login() {
    $c = st_qbcfg(); $base = st_qb_base();
    [$code, $body, $err] = st_http('POST', $base . '/api/v2/auth/login', ['post' => ['username' => $c['user'], 'password' => $c['pass']],
        'headers' => ['Referer: ' . $base, 'Origin: ' . $base], 'jar' => ST_JAR, 'timeout' => 10]);
    @chmod(ST_JAR, 0600);
    return $code === 200 && trim($body) === 'Ok.';
}
// returns [code, body, err]; logs in on 401/403 and retries once
function st_qb($path, $post = null, $files = null) {
    $base = st_qb_base();
    $do = function () use ($base, $path, $post, $files) {
        $o = ['headers' => ['Referer: ' . $base, 'Origin: ' . $base], 'jar' => ST_JAR, 'timeout' => 30];
        if ($post !== null || $files) { $o['post'] = $post ?? []; if ($files) { $o['file'] = $files; } return st_http('POST', $base . $path, $o); }
        return st_http('GET', $base . $path, $o);
    };
    $r = $do();
    if ($r[0] === 401 || $r[0] === 403) { if (!st_qb_login()) { return [403, '', 'qBittorrent login failed']; } $r = $do(); }
    return $r;
}
function st_qb_json($path) {
    [$c, $b, $e] = st_qb($path);
    if ($c !== 200) { return null; }
    $j = json_decode($b, true);
    return is_array($j) ? $j : null;
}
function st_qb_torrents() { $j = st_qb_json('/api/v2/torrents/info'); return $j === null ? null : $j; }

// ---- path mapping ---------------------------------------------------------------------------------------
function st_to_host($p, $cfg) {
    $q = rtrim($cfg['map_qb'], '/'); $h = rtrim($cfg['map_host'], '/');
    if ($q !== '' && ($p === $q || strpos($p, $q . '/') === 0)) { return $h . substr($p, strlen($q)); }
    return $p;
}
function st_to_qb($p, $cfg) {
    $q = rtrim($cfg['map_qb'], '/'); $h = rtrim($cfg['map_host'], '/');
    if ($h !== '' && ($p === $h || strpos($p, $h . '/') === 0)) { return $q . substr($p, strlen($h)); }
    return $p;
}

// Shape up and the Season repacker look only at torrents inside a movies or series folder (st_cfg 'lib'). Returns '' for
// those, else the place the torrent is in (its folder, e.g. TL, or its own path when it is outside every folder).
function st_lib_out($t, $cfg) {
    $p = st_to_host(rtrim((string)($t['content_path'] ?? $t['save_path'] ?? ''), '/'), $cfg);
    foreach ((array)($cfg['lib'] ?? []) as $r) { $r = rtrim((string)$r, '/'); if ($r !== '' && $p !== '' && ($p === $r || strpos($p, $r . '/') === 0)) { return ''; } }
    $base = rtrim((string)($cfg['dl_base'] ?? ''), '/');
    if ($base !== '' && strpos($p, $base . '/') === 0) { return explode('/', substr($p, strlen($base) + 1))[0]; }
    return $p === '' ? '?' : dirname($p);
}
function st_lib_in($tor, $cfg) { return $tor === null ? null : array_values(array_filter((array)$tor, function ($t) use ($cfg) { return st_lib_out($t, $cfg) === ''; })); }
function st_norm($s) { return preg_replace('/[^a-z0-9]+/', '', strtolower((string)$s)); }
function st_fmt($b) {
    $b = (float)$b;
    if ($b >= 1e12) { return number_format($b / 1e12, 2) . ' TB'; }
    if ($b >= 1e9) { return number_format($b / 1e9, $b >= 1e11 ? 0 : 1) . ' GB'; }
    return number_format($b / 1e6, 0) . ' MB';
}

// ---- release name helpers -------------------------------------------------------------------------------
// 3 = Dolby Vision, 2 = HDR, 1 = SDR / unknown
// HDR format of a release name or of Radarr / Sonarr's videoDynamicRangeType ('DV HDR10', 'DV', 'HDR10', 'HLG'):
// dvhdr = Dolby Vision with an HDR10 base layer (plays as HDR10 on a TV without DV), dv = Dolby Vision only (no fallback: a purple
// and green picture on a TV without DV), hdr, sdr
function st_hdr_kind($t) {
    $t = (string)$t;
    $dv = (bool)preg_match('/dolby[ .\-_]?vision|\bdovi\b|\bdv\b/i', $t);
    $hdr = (bool)preg_match('/hdr10\+?|hdr10plus|\bhdr\b|\bhlg\b|\bpq\b/i', $t);
    // a remux or a full disc keeps the HDR10 base layer of the UHD Blu-ray under DV (profile 7 or 8.1); an encode (x265, single layer)
    // may be profile 5 = DV only, so for an encode only an HDR / HDR10 in the name counts (the owner found a DV only BluRay encode)
    $disc = (bool)preg_match('/remux|\bbd(?:25|50|66|100)\b|\buhd[ .\-_]?bd\b|complete[ .\-_]?uhd/i', $t);
    return $dv ? (($hdr || $disc) ? 'dvhdr' : 'dv') : ($hdr ? 'hdr' : 'sdr');
}
// does every TV play Dolby Vision (setting dv_all)? If not, a DV-only release ranks below SDR: it is never an upgrade
function st_dv_all() { static $v = null; if ($v === null) { $v = (int)(st_cfg()['dv_all'] ?? 0); } return $v; }
function st_hdr_rank($t) {
    $k = st_hdr_kind($t);
    return st_dv_all() ? ['sdr' => 1, 'hdr' => 2, 'dv' => 3, 'dvhdr' => 4][$k] : ['dv' => 0, 'sdr' => 1, 'hdr' => 2, 'dvhdr' => 3][$k];
}
function st_hdr_label($r) { return (st_dv_all() ? [1 => 'SDR', 2 => 'HDR', 3 => 'DV', 4 => 'DV+HDR'] : [0 => 'DV only', 1 => 'SDR', 2 => 'HDR', 3 => 'DV+HDR'])[$r] ?? 'SDR'; }
function st_res_of($t) {
    $t = (string)$t;
    if (preg_match('/2160p/i', $t)) { return 2160; }
    if (preg_match('/1080[pi]/i', $t)) { return 1080; } // an explicit 1080p wins over UHD in the name (1080p UHD BluRay hybrids)
    if (preg_match('/720p/i', $t)) { return 720; }
    if (preg_match('/480p|576p/i', $t)) { return 480; }
    if (preg_match('/\b4k\b|\buhd\b/i', $t)) { return 2160; }
    if (preg_match('/\bBD(25|50|66|100)\b/i', $t)) { return 1080; } // a Blu-ray disc image without a resolution in the name
    return 0;
}

// ---- Prowlarr -------------------------------------------------------------------------------------------
function st_arr($which, $path, $method = 'GET', $json = null, $timeout = 60) {
    $c = st_cfg()[$which] ?? ['url' => '', 'key' => ''];
    $url = rtrim((string)$c['url'], '/');
    if ($url === '' || $c['key'] === '') { return [0, null, $which . ' is not configured']; }
    $api = ($which === 'prowlarr' || $which === 'lidarr') ? '/api/v1' : '/api/v3';
    $h = ['X-Api-Key: ' . $c['key'], 'Accept: application/json'];
    $o = ['headers' => $h, 'timeout' => $timeout, 'follow' => true];
    if ($json !== null) { $o['headers'][] = 'Content-Type: application/json'; $o['post'] = json_encode($json); }
    [$code, $body, $err] = st_http($method, $url . $api . $path, $o);
    if ($err) { return [0, null, $err]; }
    $j = json_decode($body, true);
    if ($code >= 400) { return [$code, $j, 'HTTP ' . $code . (is_array($j) && !empty($j['message']) ? ': ' . $j['message'] : '')]; }
    return [$code, $j, ''];
}

function st_prowlarr_search($q) {
    [$c, $j, $e] = st_arr('prowlarr', '/search?type=search&limit=100&query=' . rawurlencode($q), 'GET', null, 90);
    if ($e || !is_array($j)) { return [null, $e ?: 'bad response']; }
    $out = [];
    foreach ($j as $r) {
        if (!is_array($r) || strtolower((string)($r['protocol'] ?? 'torrent')) !== 'torrent') { continue; }
        $out[] = ['title' => (string)($r['title'] ?? ''), 'size' => (float)($r['size'] ?? 0), 'seeders' => (int)($r['seeders'] ?? 0),
                  'indexer' => (string)($r['indexer'] ?? ''), 'dl' => (string)($r['downloadUrl'] ?? ($r['magnetUrl'] ?? '')), 'info' => (string)($r['infoUrl'] ?? ''),
                  'date' => (string)($r['publishDate'] ?? '')];
    }
    return [$out, ''];
}

// ---- bencode (only to read the info hash of a .torrent) ---------------------------------------------------
function st_bdec($s, &$i) {
    $c = $s[$i] ?? '';
    if ($c === 'i') { $e = strpos($s, 'e', $i); $v = (int)substr($s, $i + 1, $e - $i - 1); $i = $e + 1; return $v; }
    if ($c === 'l') { $i++; $a = []; while (($s[$i] ?? 'e') !== 'e') { $a[] = st_bdec($s, $i); } $i++; return $a; }
    if ($c === 'd') {
        $i++; $a = [];
        while (($s[$i] ?? 'e') !== 'e') {
            $k = st_bdec($s, $i);
            $start = $i;
            $v = st_bdec($s, $i);
            if ($k === 'info') { $a['__info_raw'] = substr($s, $start, $i - $start); }
            $a[$k] = $v;
        }
        $i++; return $a;
    }
    $col = strpos($s, ':', $i);
    if ($col === false) { throw new Exception('bad bencode'); }
    $len = (int)substr($s, $i, $col - $i);
    $v = substr($s, $col + 1, $len); $i = $col + 1 + $len;
    return $v;
}
function st_torrent_hash($bytes) {
    try { $i = 0; $d = st_bdec($bytes, $i); } catch (Throwable $e) { return ''; }
    return (is_array($d) && isset($d['__info_raw'])) ? sha1($d['__info_raw']) : '';
}
function st_magnet_hash($m) {
    if (!preg_match('/xt=urn:btih:([A-Za-z0-9]+)/', $m, $x)) { return ''; }
    $h = $x[1];
    if (strlen($h) === 40) { return strtolower($h); }
    if (strlen($h) === 32) {
        $al = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits = '';
        foreach (str_split(strtoupper($h)) as $ch) { $p = strpos($al, $ch); if ($p === false) { return ''; } $bits .= str_pad(decbin($p), 5, '0', STR_PAD_LEFT); }
        $out = ''; foreach (str_split($bits, 8) as $b) { if (strlen($b) === 8) { $out .= sprintf('%02x', bindec($b)); } }
        return $out;
    }
    return '';
}

// ---- local downloads folders ------------------------------------------------------------------------------
function st_dir_size($p) {
    if (is_file($p)) { return (float)@filesize($p); }
    $o = (string)@shell_exec('find ' . escapeshellarg($p) . ' -type f -printf ' . escapeshellarg('%s\n') . ' 2>/dev/null');
    $t = 0.0; foreach (explode("\n", $o) as $l) { if ($l !== '') { $t += (float)$l; } }
    return $t;
}
function st_units($roots) {
    $u = [];
    foreach ($roots as $r) {
        $r = rtrim((string)$r, '/');
        if ($r === '' || !is_dir($r)) { continue; }
        foreach (scandir($r) as $n) {
            if ($n === '.' || $n === '..' || $n[0] === '.' || $n === '@eaDir' || $n === '#recycle' || $n === '.recycle') { continue; }
            $p = $r . '/' . $n;
            $u[] = ['path' => $p, 'name' => $n, 'root' => basename($r), 'isdir' => is_dir($p), 'size' => st_dir_size($p)];
        }
    }
    return $u;
}

// Does the file or folder contain a video file? (artwork, .nfo and similar leftovers are not content)
function st_has_video($p) {
    $re = '/\.(mkv|mp4|avi|m4v|ts|mov|wmv|iso)$/i';
    if (is_file($p)) { return (bool)preg_match($re, $p); }
    $o = (string)@shell_exec('find ' . escapeshellarg($p) . ' -type f \\( -iname "*.mkv" -o -iname "*.mp4" -o -iname "*.avi" -o -iname "*.m4v" -o -iname "*.ts" -o -iname "*.mov" -o -iname "*.wmv" -o -iname "*.iso" \\) -print -quit 2>/dev/null');
    return trim($o) !== '';
}

// ---- Discord summaries: what happened and why --------------------------------------------------------------
function st_dc_hook_ok($u) { return (bool)preg_match('#^https://(?:discord|discordapp)\.com/api/webhooks/\d{15,25}/[A-Za-z0-9_\-]{20,120}$#', (string)$u); }
// Hungarian or English text, by the setting
require_once __DIR__ . '/lang.php';
// Discord texts: the language is shared by all the plugins (lang.php)
function st_l($hu, $en) { return mt_lang_discord() === 'en' ? $en : $hu; }
// $level: rd red, a amber, g green, i blue. $fields: [[name, value, inline?], ...]. Returns [ok, error]. Never throws.
// which tool a job belongs to: replacements of unseeded content are Find my torrent, the rest is Update me
function st_ntool($j) { return (($j['mode'] ?? '') === 'repl') ? 'find' : 'upd'; }
// $opts: 'wait' => true returns the message Discord stored as the third element (its id and channel_id, for BoxBot);
// 'color' => an embed colour instead of the level's
function st_notify($level, $title, $desc = '', $fields = [], $force = false, $tool = '', $opts = []) {
    $cfg = st_cfg(); $hook = (string)($cfg['dc_hook'] ?? '');
    $on = $tool !== '' ? !empty($cfg['dc_' . $tool]) : (!empty($cfg['dc_find']) || !empty($cfg['dc_upd']) || !empty($cfg['dc_rep']));
    if (!$force && !$on) { return [false, 'Discord messages are switched off for this tool']; }
    if (getenv('DBX_ST_DC')) { $hook = getenv('DBX_ST_DC'); } // test hook
    elseif (!st_dc_hook_ok($hook)) { return [false, 'no Discord webhook set']; }
    $col = ['rd' => 0xE5534B, 'a' => 0xD9921F, 'g' => 0x3FA24A, 'i' => 0x4A90E2];
    $host = @gethostname();
    $e = ['title' => mb_substr($title, 0, 250), 'color' => $opts['color'] ?? ($col[$level] ?? $col['i']), 'timestamp' => gmdate('c'), 'footer' => ['text' => trim(($host ? $host . ' · ' : '') . 'Seedbox')]];
    if ($desc !== '') { $e['description'] = mb_substr($desc, 0, 3900); }
    $f = [];
    foreach ($fields as $x) { if ($x[1] === '' || $x[1] === null) { continue; } $f[] = ['name' => mb_substr((string)$x[0], 0, 250), 'value' => mb_substr((string)$x[1], 0, 1000), 'inline' => !empty($x[2])]; }
    if ($f) { $e['fields'] = array_slice($f, 0, 24); }
    $json = json_encode(['embeds' => [$e], 'allowed_mentions' => ['parse' => []]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    for ($try = 0; $try < 2; $try++) {
        $ch = curl_init($hook . (!empty($opts['wait']) ? '?wait=true' : ''));
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15, CURLOPT_POSTFIELDS => $json, CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
        $body = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $er = curl_errno($ch); curl_close($ch);
        if ($code === 200 || $code === 204) { $m = json_decode((string)$body, true); return [true, '', is_array($m) ? $m : null, $e]; }
        if ($code === 429) { $d = json_decode((string)$body, true); $w = is_array($d) && isset($d['retry_after']) ? (float)$d['retry_after'] : 2.0; if ($try === 0 && $w <= 5.0) { usleep((int)(($w + 0.3) * 1000000)); continue; } }
        return [false, $er ? 'network error ' . $er : 'Discord HTTP ' . $code];
    }
    return [false, 'Discord did not accept the message'];
}
function st_missing_files($hash) { $r = []; foreach ((array)st_qb_json('/api/v2/torrents/files?hash=' . rawurlencode($hash)) as $f) { if ((float)($f['progress'] ?? 1) < 0.999) { $r[] = (float)$f['size'] * (1 - (float)$f['progress']); } } return $r; }
function st_missing_txt($inc) { return count($inc) . ' file' . (count($inc) === 1 ? '' : 's') . ' (' . st_sz(array_sum($inc)) . ')'; }
function st_sz($b) { $b = (float)$b; return $b >= 1e6 ? st_gb($b) : max(1, round($b / 1e3)) . ' KB'; }
function st_gb($b) { $b = (float)$b; return $b >= 1e9 ? round($b / 1e9, 1) . ' GB' : round($b / 1e6) . ' MB'; }
