<?php
// Web endpoint of the Seedbox tools. POST op=state|saveconfig|test|find_scan|find_get|find_add|find_ignore|upd_scan|upd_get|upd_hide|upd_search|upd_res|upd_grab|upd_remove
require_once __DIR__ . '/tools_ops.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
function st_out($ok, $extra = []) { echo json_encode(array_merge(['ok' => $ok], $extra), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); exit; }

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); st_out(false, ['error' => 'method']); }
$var  = @parse_ini_file('/var/local/emhttp/var.ini');
$tok  = is_array($var) ? (string)($var['csrf_token'] ?? '') : '';
$sent = (string)($_POST['csrf_token'] ?? '');
if ($sent !== '') {
    if ($tok === '' || !hash_equals($tok, $sent)) { http_response_code(403); st_out(false, ['error' => 'csrf token mismatch']); }
} elseif (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
    http_response_code(403); st_out(false, ['error' => 'csrf: token removed and request is not AJAX']);
}

function st_spawn($args) {
    $php = '/usr/bin/php';
    foreach (['/usr/bin/php', '/usr/local/bin/php'] as $p) { if (is_executable($p)) { $php = $p; break; } }
    @exec('setsid nohup ' . escapeshellarg($php) . ' ' . escapeshellarg(__DIR__ . '/tools_run.php') . ' ' . $args . ' >/dev/null 2>&1 &');
}
function st_locked($name) {
    $f = @fopen('/tmp/qbt_tools_' . $name . '.lock', 'c');
    if (!$f) { return false; }
    if (flock($f, LOCK_EX | LOCK_NB)) { flock($f, LOCK_UN); fclose($f); return false; }
    fclose($f); return true;
}
function st_http_url($v) { $v = trim((string)$v); return ($v === '' || preg_match('#^https?://[^\s"<>]+$#i', $v)) ? rtrim($v, '/') : false; }

$op = (string)($_POST['op'] ?? 'state');

if ($op === 'state') {
    $c = st_cfg();
    foreach (['prowlarr', 'radarr', 'sonarr', 'lidarr', 'autobrr'] as $k) { $c[$k]['key_set'] = $c[$k]['key'] !== ''; $c[$k]['key'] = ''; }
    $c['dc_hook_set'] = $c['dc_hook'] !== ''; $c['dc_hook'] = '';
    $c['bot_token_set'] = $c['bot_token'] !== ''; $c['bot_token'] = '';
    $s = st_state();
    st_out(true, ['cfg' => $c, 'ab' => st_json_read(ST_AB, []), 'pending' => array_slice(array_reverse($s['pending']), 0, 20), 'jobs' => array_reverse($s['jobs']),
                  'find_running' => st_locked('find'), 'upd_running' => st_locked('upd'), 'packjobs' => array_reverse((array)$s['packjobs']), 'dl' => st_dl_summary($s)]);
}

if ($op === 'saveconfig') {
    $old = st_cfg(); $new = $old;
    foreach (['prowlarr', 'radarr', 'sonarr', 'lidarr', 'autobrr'] as $k) {
        $u = st_http_url($_POST[$k . '_url'] ?? '');
        if ($u === false) { st_out(false, ['error' => st_tr('set.url', ['k' => $k])]); }
        $key = trim((string)($_POST[$k . '_key'] ?? ''));
        if ($key !== '' && !preg_match('/^[A-Za-z0-9_\-]{8,128}$/', $key)) { st_out(false, ['error' => st_tr('set.key', ['k' => $k])]); }
        $new[$k] = ['url' => $u, 'key' => $key !== '' ? $key : $old[$k]['key']];
        if ($u === '') { $new[$k]['key'] = $key !== '' ? $key : ''; }
    }
    $mq = '/' . trim((string)($_POST['map_qb'] ?? '/data'), " /"); $mh = '/' . trim((string)($_POST['map_host'] ?? '/mnt/user/data'), " /");
    if (preg_match('/[\x00-\x1f"\\\\]|\.\./', $mq . $mh)) { st_out(false, ['error' => st_tr('set.map')]); }
    $new['map_qb'] = $mq === '/' ? '' : $mq; $new['map_host'] = $mh === '/' ? '' : $mh;
    $base = rtrim(trim((string)($_POST['dl_base'] ?? '')), '/');
    if ($base === '' || $base[0] !== '/' || preg_match('/[\x00-\x1f"\\\\]|\.\./', $base) || !preg_match('#^/mnt/#', $base)) { st_out(false, ['error' => st_tr('set.base_ex')]); }
    $fr = json_decode((string)($_POST['folders'] ?? ''), true);
    if (!is_array($fr)) { st_out(false, ['error' => st_tr('set.roles')]); }
    $roles = [];
    foreach ($fr as $n => $r) {
        $n = (string)$n;
        if ($n === '' || $n[0] === '.' || strpos($n, '/') !== false || preg_match('/[\x00-\x1f]/', $n) || !in_array($r, ST_ROLES, true)) { continue; }
        $roles[$n] = $r;
    }
    $chk = st_cfg_folders(array_merge($old, ['dl_base' => $base, 'folders' => $roles]));
    if (!$chk['roots']) { st_out(false, ['error' => st_tr('set.noroot')]); }
    $new['dl_base'] = $base; $new['folders'] = $roles; $new['roots'] = $chk['roots'];
    $td = (int)($_POST['trash_days'] ?? 3); if ($td < 0 || $td > 60) { st_out(false, ['error' => st_tr('set.trash')]); }
    $hk = trim((string)($_POST['dc_hook'] ?? ''));
    if ($hk === '-') { $new['dc_hook'] = ''; } elseif ($hk !== '') { if (!st_dc_hook_ok($hk)) { st_out(false, ['error' => st_tr('set.hook')]); } $new['dc_hook'] = $hk; }
    // BoxBot (item 72): the token like an API key (empty keeps the saved one, - removes it), the owner's user id
    $bt = trim((string)($_POST['bot_token'] ?? ''));
    if ($bt === '-') { $new['bot_token'] = ''; } elseif ($bt !== '') { if (!preg_match('/^[A-Za-z0-9_\-.]{50,100}$/', $bt)) { st_out(false, ['error' => st_tr('set.bot')]); } $new['bot_token'] = $bt; }
    $bu = trim((string)($_POST['bot_user'] ?? ''));
    if ($bu !== '' && !preg_match('/^\d{15,25}$/', $bu)) { st_out(false, ['error' => st_tr('set.uid')]); }
    $new['bot_user'] = $bu;
    foreach (['bot_rep', 'bot_find'] as $k) { $new[$k] = !empty($_POST[$k]) && $_POST[$k] !== '0' ? 1 : 0; }
    if ($new['bot_token'] !== '' && $bu !== '' && $new['dc_hook'] === '') { st_out(false, ['error' => st_tr('set.bot_hook')]); }
    $new['auto_scan'] = !empty($_POST['auto_scan']) && $_POST['auto_scan'] !== '0' ? 1 : 0;
    $new['auto_upd'] = !empty($_POST['auto_upd']) && $_POST['auto_upd'] !== '0' ? 1 : 0;
    $new['auto_rep'] = !empty($_POST['auto_rep']) && $_POST['auto_rep'] !== '0' ? 1 : 0;
    $af = [];
    foreach (preg_split('/\n/', (string)($_POST['ab_filters'] ?? '')) as $x) { $x = trim(preg_replace('/[\x00-\x1f]/', '', $x)); if ($x !== '' && strlen($x) <= 120 && count($af) < 50) { $af[] = $x; } }
    $new['ab_filters'] = array_values(array_unique($af));
    $new['ab_on'] = !empty($_POST['ab_on']) && $_POST['ab_on'] !== '0' ? 1 : 0;
    if ($new['ab_on'] && ($new['autobrr']['url'] === '' || $new['autobrr']['key'] === '' || $new['sonarr']['url'] === '')) { st_out(false, ['error' => st_tr('set.ab')]); }
    $new['auto_freq'] = (($_POST['auto_freq'] ?? '') === 'daily') ? 'daily' : 'weekly';
    $ad = (int)($_POST['auto_day'] ?? 1); $ah = (int)($_POST['auto_hour'] ?? 8);
    if ($ad < 1 || $ad > 7 || $ah < 0 || $ah > 23) { st_out(false, ['error' => st_tr('set.sched')]); }
    $new['auto_day'] = $ad; $new['auto_hour'] = $ah;
    $new['find_cat'] = !empty($_POST['find_cat']) && $_POST['find_cat'] !== '0' ? 1 : 0;
    foreach (['find', 'upd', 'rep'] as $k) { // which widget rows show (item 54)
        $m = (string)($_POST['show_' . $k] ?? 'relevant'); $new['show_' . $k] = in_array($m, ['always', 'relevant', 'days', 'never'], true) ? $m : 'relevant';
        $new['show_' . $k . '_days'] = max(1, min(60, (int)($_POST['show_' . $k . '_days'] ?? 3)));
    }
    foreach (['tag_find', 'tag_upd', 'tag_rep'] as $k) { $v = trim(preg_replace('/[\x00-\x1f<>]/u', '', (string)($_POST[$k] ?? ''))); $new[$k] = mb_substr($v, 0, 60); } // the line under the tool name on the widget, empty = the default
    foreach (['dc_find', 'dc_upd', 'dc_rep'] as $k) { $new[$k] = !empty($_POST[$k]) && $_POST[$k] !== '0' ? 1 : 0; }
    $new['dc_on'] = ($new['dc_find'] || $new['dc_upd'] || $new['dc_rep']) ? 1 : 0;
    $new['dc_lang'] = (($_POST['dc_lang'] ?? 'hu') === 'en') ? 'en' : 'hu';
    $acc = (float)($_POST['find_accept'] ?? 99); if ($acc < 90 || $acc > 100) { st_out(false, ['error' => st_tr('set.accept')]); }
    $new['find_accept'] = $acc;
    $rg = (float)($_POST['repl_max_growth'] ?? 100); if ($rg < 0 || $rg > 1000) { st_out(false, ['error' => st_tr('set.growth')]); }
    $new['repl_max_growth'] = $rg;
    $mf = (int)($_POST['find_missing_files'] ?? 1); if ($mf < 0 || $mf > 20) { st_out(false, ['error' => st_tr('set.missing')]); }
    $new['find_missing_files'] = $mf;
    $gb = (float)($_POST['upd_min_gb'] ?? 40); $age = (int)($_POST['upd_min_age'] ?? 90); $sv = (int)($_POST['upd_saving'] ?? 30); $tol = (float)($_POST['find_tol'] ?? 1);
    if ($gb < 1 || $gb > 1000 || $age < 0 || $age > 5000 || $sv < 5 || $sv > 90 || $tol < 0 || $tol > 10) { st_out(false, ['error' => st_tr('set.range')]); }
    $ug = (int)($_POST['upd_upg_growth'] ?? 100); if ($ug < 0 || $ug > 1000) { st_out(false, ['error' => st_tr('set.upg')]); }
    $new['upd_upg_growth'] = $ug;
    $new['dv_all'] = (($_POST['dv_all'] ?? '0') === '1') ? 1 : 0;
    foreach (['upd_cat_save', 'upd_cat_hdr', 'upd_cat_res'] as $k) { $new[$k] = !empty($_POST[$k]) && $_POST[$k] !== '0' ? 1 : 0; }
    if (!$new['upd_cat_save'] && !$new['upd_cat_hdr'] && !$new['upd_cat_res']) { st_out(false, ['error' => st_tr('set.cats')]); }
    $new['upd_min_gb'] = $gb; $new['upd_min_age'] = $age; $new['upd_saving'] = $sv; $new['find_tol'] = $tol; $new['trash_days'] = $td;
    unset($new['folder_list'], $new['lib'], $new['series_dirs']); // worked out by st_cfg() on every read
    if (!st_json_write(st_cfg_file(), $new, 0600)) { st_out(false, ['error' => st_tr('set.write')]); }
    st_out(true);
}

// the folders inside a downloads folder typed on the page (Read the folders again), with the saved roles; reads only
if ($op === 'folders') {
    $base = rtrim(trim((string)($_POST['dl_base'] ?? '')), '/');
    if ($base === '' || $base[0] !== '/' || preg_match('/[\x00-\x1f"\\\\]|\.\./', $base) || !preg_match('#^/mnt/#', $base)) { st_out(false, ['error' => st_tr('set.base')]); }
    if (!is_dir($base)) { st_out(false, ['error' => st_tr('set.nodir', ['p' => $base])]); }
    $c = st_cfg();
    st_out(true, ['folder_list' => st_folder_list($base, is_array($c['folders']) ? $c['folders'] : null)]);
}

if ($op === 'test') {
    $r = [];
    $t = st_qb_torrents();
    $r['qbittorrent'] = $t === null ? st_tr('test.qb_fail') : st_tr('test.qb_ok', ['n' => count($t)]);
    $cfg = st_cfg();
    if ($t) {
        $hit = 0; $miss = 0;
        foreach (array_slice($t, 0, 30) as $x) { $p = st_to_host((string)($x['content_path'] ?? ''), $cfg); if ($p !== '' && file_exists($p)) { $hit++; } else { $miss++; } }
        $r['path_mapping'] = st_tr('test.map', ['n' => $hit, 'm' => $hit + $miss]) . ($miss > $hit ? ' ' . st_tr('test.map_check') : '');
    }
    if ($cfg['dc_hook'] === '') { $r['discord'] = st_tr('test.nohook'); }
    else { [$ok, $e] = st_notify('i', st_l('Seedbox: teszt', 'Seedbox: test'), st_l('A Discord kapcsolat működik.', 'The Discord connection works.'), [], true); $r['discord'] = $ok ? st_tr('test.dc_ok') . (!($cfg['dc_find'] || $cfg['dc_upd'] || $cfg['dc_rep']) ? ' ' . st_tr('test.dc_alloff') : '') : $e; }
    foreach (['prowlarr' => '/system/status', 'radarr' => '/system/status', 'sonarr' => '/system/status', 'lidarr' => '/system/status'] as $k => $path) {
        if (($cfg[$k]['url'] ?? '') === '') { $r[$k] = st_tr('test.nocfg'); continue; }
        [$c, $j, $e] = st_arr($k, $path, 'GET', null, 15);
        $r[$k] = $e !== '' ? $e : st_tr('test.ver', ['v' => $j['version'] ?? '?']);
    }
    if (($cfg['autobrr']['url'] ?? '') === '') { $r['autobrr'] = st_tr('test.nocfg'); }
    else { [$fl, $e] = st_ab_filters(); $r['autobrr'] = $fl === null ? $e : st_tr('test.ab_ok', ['n' => count($fl), 'l' => implode(', ', array_column($fl, 'name'))]) . (empty($cfg['ab_on']) ? ' ' . st_tr('test.ab_off') : ''); }
    st_out(true, ['tests' => $r]);
}

if ($op === 'ab_filters') {
    // the autobrr filters, with the address and key typed on the page (not saved yet) or the saved ones
    $u = st_http_url($_POST['url'] ?? ''); $k = trim((string)($_POST['key'] ?? ''));
    if ($u === false || ($k !== '' && !preg_match('/^[A-Za-z0-9_\-]{8,128}$/', $k))) { st_out(false, ['error' => st_tr('set.ab_key')]); }
    if ($u !== '' || $k !== '') {
        $c = st_cfg(); $GLOBALS['st_ab_override'] = ['url' => $u !== '' ? $u : $c['autobrr']['url'], 'key' => $k !== '' ? $k : $c['autobrr']['key']];
    }
    [$fl, $e] = st_ab_filters();
    st_out($fl !== null, $fl !== null ? ['filters' => $fl] : ['error' => 'autobrr: ' . $e]);
}

if ($op === 'find_scan') {
    if (st_locked('find')) { st_out(true); }
    st_json_write(ST_FIND, ['state' => 'running', 't' => time(), 'msg' => st_tr('f.starting'), 'items' => []]);
    st_spawn('find');
    st_out(true);
}
if ($op === 'find_get') {
    // the list is a snapshot of the last scan: what has been dealt with since drops out, what is under way is marked
    st_find_prune();
    $find = st_json_read(ST_FIND, ['state' => 'none']); $s = st_state(); $keep = [];
    foreach ((array)($find['items'] ?? []) as $it) {
        $drop = false; $busy = '';
        foreach ($s['pending'] as $p) {
            if ($p['path'] !== $it['path']) { continue; }
            if ($p['stage'] === 'done') { $drop = true; } elseif (in_array($p['stage'], ['added', 'checking'], true)) { $busy = st_tr('busy.added', ['m' => $p['msg']]); } elseif ($p['stage'] === 'failed' && time() - $p['t'] < 7 * 86400) { $it['fail'] = $p['msg']; }
        }
        foreach ($s['jobs'] as $j) {
            if (($j['key'] ?? '') !== ($it['key'] ?? '-') && ($j['old_path'] ?? '-') !== $it['path']) { continue; }
            if (in_array($j['stage'], ['cleaned', 'removed'], true)) { $drop = true; }
            elseif ($j['stage'] !== 'failed') { $busy = st_tr('busy.repl', ['m' => $j['msg']]); }
        }
        if ($drop) { continue; }
        if ($busy !== '') { $it['busy'] = $busy; }
        elseif (in_array($it['path'], array_column($s['queue'], 'path'), true)) { $it['busy'] = st_tr('busy.queue'); }
        $keep[] = $it;
    }
    if (isset($find['items'])) { $find['items'] = $keep; }
    st_out(true, ['find' => $find, 'running' => st_locked('find'), 'res' => st_json_read(ST_RES, []), 'queue' => count($s['queue'])]);
}
if ($op === 'find_queue_all') { $n = st_queue_all(); if ($n) { st_spawn('tick'); } st_out(true, ['queued' => $n]); }
if ($op === 'clear_results') { st_clear_results(); st_out(true); }
if ($op === 'find_queue_stop') { st_queue_stop(); st_out(true); }
if ($op === 'find_add') {
    [$ok, $e] = st_find_add((string)($_POST['id'] ?? ''), (int)($_POST['idx'] ?? 0));
    st_out($ok, $ok ? [] : ['error' => $e]);
}
if ($op === 'find_delete') {
    [$ok, $e] = st_find_delete((string)($_POST['id'] ?? ''));
    st_out($ok, $ok ? [] : ['error' => $e]);
}
if ($op === 'find_ignore') {
    $find = st_json_read(ST_FIND, []);
    foreach ((array)($find['items'] ?? []) as $it) { if ($it['id'] === (string)($_POST['id'] ?? '')) { st_find_ignore($it['path']); st_out(true); } }
    st_out(false, ['error' => st_tr('e.unknown_item')]);
}

if ($op === 'upd_scan') {
    if (st_locked('upd')) { st_out(true); }
    st_json_write(ST_UPD, ['state' => 'running', 't' => time(), 'msg' => st_tr('f.starting'), 'items' => []]);
    st_spawn('upd');
    st_out(true);
}
if ($op === 'upd_get') {
    $upd = st_json_read(ST_UPD, ['state' => 'none']); $res = st_json_read(ST_RES, []); $bg = st_json_read(ST_UPDBG, []);
    [$bg['done'], $bg['total']] = st_upd_bg_count($upd, $res); $bg['running'] = st_locked('updbg');
    st_out(true, ['upd' => $upd, 'res' => $res, 'bg' => $bg]);
}
if ($op === 'trash_info') { st_out(true, st_trash_info()); }
if ($op === 'trash_empty') {
    [$n, $fail] = st_trash_empty();
    st_out(!$fail, ['emptied' => $n, 'error' => $fail ? st_tr('e.trash_fail', ['l' => implode(', ', array_slice($fail, 0, 5))]) : ''] + st_trash_info());
}
if ($op === 'upd_forget') {
    // forget every Update me search result and search the whole list again (after a change of the thresholds, for example)
    if (st_locked('upd')) { st_out(false, ['error' => st_tr('e.scan_busy')]); }
    @exec('pkill -f ' . escapeshellarg('qbittorrent_widget/tools_run.php updbg') . ' 2>/dev/null');
    for ($i = 0; $i < 20 && st_locked('updbg'); $i++) { usleep(100000); }
    $lk = @fopen('/tmp/qbt_tools_res.lock', 'c'); if ($lk) { flock($lk, LOCK_EX); }
    $res = st_json_read(ST_RES, []); $n = 0;
    foreach (array_keys($res) as $k) { if (preg_match('/^[0-9a-f]{40}$/', (string)$k)) { unset($res[$k]); $n++; } } // Update me keys are torrent hashes; Find my torrent keys start with f
    st_json_write(ST_RES, $res);
    if ($lk) { flock($lk, LOCK_UN); fclose($lk); }
    @unlink(ST_UPDBG); @unlink('/tmp/qbt_tools_updbg.stop');
    st_spawn('updbg');
    st_out(true, ['forgot' => $n]);
}
if ($op === 'upd_bg') {
    // stop or go on with the background check
    if (!empty($_POST['stop'])) { @touch('/tmp/qbt_tools_updbg.stop'); st_out(true); }
    if (st_locked('upd')) { st_out(false, ['error' => st_tr('e.scan_busy_bg')]); }
    @unlink('/tmp/qbt_tools_updbg.stop'); if (!st_locked('updbg')) { st_spawn('updbg'); }
    st_out(true);
}
if ($op === 'upd_hide') {
    $h = strtolower((string)($_POST['hash'] ?? ''));
    if (!preg_match('/^[0-9a-f]{40}$/', $h)) { st_out(false, ['error' => st_tr('e.bad')]); }
    if (st_locked('upd')) { st_out(false, ['error' => st_tr('e.scan_busy')]); }
    st_upd_hide($h, !empty($_POST['on']));
    st_out(true, ['upd' => st_json_read(ST_UPD, ['state' => 'none'])]);
}
if ($op === 'upd_search') {
    $h = strtolower((string)($_POST['hash'] ?? ''));
    if (!preg_match('/^[0-9a-f]{40}$/', $h)) { st_out(false, ['error' => st_tr('e.bad')]); }
    st_spawn('search ' . escapeshellarg($h));
    st_res_set($h, ['state' => 'running', 't' => time(), 'rows' => [], 'msg' => st_tr('f.searching'), 'mode' => 'upd']);
    st_out(true);
}
if ($op === 'repl_search') {
    $id = (string)($_POST['id'] ?? '');
    if (!preg_match('/^f\d{1,6}$/', $id)) { st_out(false, ['error' => st_tr('e.bad')]); }
    $find = st_json_read(ST_FIND, []); $key = '';
    foreach ((array)($find['items'] ?? []) as $it) { if ($it['id'] === $id) { $key = 'f' . substr(md5($it['path']), 0, 12); } }
    if ($key === '') { st_out(false, ['error' => st_tr('e.unknown_item')]); }
    st_spawn('fsearch ' . escapeshellarg($id));
    st_res_set($key, ['state' => 'running', 't' => time(), 'rows' => [], 'msg' => st_tr('f.searching'), 'mode' => 'repl']);
    st_out(true, ['key' => $key]);
}
if ($op === 'upd_grab' || $op === 'repl_grab') {
    $key = (string)($_POST['key'] ?? ($_POST['hash'] ?? ''));
    if (!preg_match('/^[0-9a-f]{40}$|^f[0-9a-f]{12}$/', $key)) { st_out(false, ['error' => st_tr('e.bad')]); }
    [$ok, $e] = st_replace_grab($key, (string)($_POST['guid'] ?? ''));
    st_out($ok, $ok ? [] : ['error' => $e]);
}
if ($op === 'sp_scan') {
    if (st_locked('sp')) { st_out(true); }
    $q = trim((string)($_POST['q'] ?? ''));
    if (strlen($q) > 80 || preg_match('/[\x00-\x1f]/', $q)) { st_out(false, ['error' => st_tr('e.bad_series')]); }
    st_json_write(ST_SP, ['state' => 'running', 't' => time(), 'msg' => st_tr('f.starting'), 'items' => []]);
    st_spawn('sp ' . escapeshellarg($q));
    st_out(true);
}
if ($op === 'sp_series') {
    // titles of the series that are monitored in Sonarr (for the suggestion list), cached for ten minutes
    $f = '/tmp/qbt_tools_series.json'; $c = st_json_read($f, []);
    if (empty($c['t']) || time() - $c['t'] > 600) {
        [$cd, $ser, $er] = st_arr('sonarr', '/series', 'GET', null, 60);
        if ($er !== '' || !is_array($ser)) { st_out(false, ['error' => 'Sonarr: ' . ($er ?: st_tr('e.bad_resp'))]); }
        $t = []; foreach ($ser as $x) {
            if (empty($x['monitored']) || empty($x['title'])) { continue; }
            // only series with a season of at least two episode files (VisionQuest, not out yet, is of no use here)
            if (isset($x['seasons'])) { $okS = false; foreach ((array)$x['seasons'] as $ss) { if ((int)($ss['seasonNumber'] ?? 0) > 0 && (int)($ss['statistics']['episodeFileCount'] ?? 0) >= 2) { $okS = true; } } if (!$okS) { continue; } }
            $t[] = (string)$x['title'];
        }
        sort($t, SORT_NATURAL | SORT_FLAG_CASE); $c = ['t' => time(), 'titles' => $t]; st_json_write($f, $c);
    }
    st_out(true, ['titles' => $c['titles']]);
}
if ($op === 'sp_get') {
    $s = st_state(); $abd = st_json_read(st_sp_ab_file(), []);
    st_out(true, ['sp' => st_json_read(ST_SP, ['state' => 'none']), 'jobs' => array_reverse((array)$s['packjobs']), 'running' => st_locked('sp'),
                  'ab' => st_sp_ab_list(), 'ab_queue' => count((array)($abd['queue'] ?? [])), 'ab_last' => $abd['last'] ?? null]);
}
if ($op === 'bot_test') { [$ok, $m] = st_bot_test(); st_out($ok, $ok ? ['msg' => $m] : ['error' => $m]); }
if ($op === 'sp_ab_dismiss') {
    $k = (string)($_POST['key'] ?? ''); if (!preg_match('/^sp[0-9a-f]{10}$/', $k)) { st_out(false, ['error' => st_tr('e.bad')]); }
    st_sp_ab_dismiss($k); st_out(true);
}
if ($op === 'sp_start') {
    $k = (string)($_POST['key'] ?? ''); if (!preg_match('/^sp[0-9a-f]{10}$/', $k)) { st_out(false, ['error' => st_tr('e.bad')]); }
    [$ok, $e] = st_sp_start($k, (string)($_POST['guid'] ?? ''));
    if ($ok) { st_spawn('tick'); }
    st_out($ok, $ok ? [] : ['error' => $e]);
}
if ($op === 'sp_clear') { st_sp_clear(); st_out(true); }
if ($op === 'job_force') { [$ok, $e] = st_force_import((string)($_POST['id'] ?? '')); st_out($ok, $ok ? [] : ['error' => $e]); }
if ($op === 'job_dismiss') {
    $id = (string)($_POST['id'] ?? '');
    $hit = st_state_update(function (&$s) use ($id) {
        $hit = false;
        foreach ($s['jobs'] as &$j) { if ($j['id'] === $id && $j['stage'] === 'imported') { $j['stage'] = 'removed'; $j['msg'] = st_tr('job.dismissed'); $j['t'] = time(); $hit = true; } }
        unset($j);
        return $hit;
    });
    if (!$hit) { st_out(false, ['error' => st_tr('e.nodismiss')]); }
    st_out(true);
}
if ($op === 'upd_remove') {
    [$ok, $e] = st_job_remove_old((string)($_POST['id'] ?? ''));
    st_out($ok, $ok ? [] : ['error' => $e]);
}
http_response_code(400);
st_out(false, ['error' => st_tr('e.unknown_op')]);
