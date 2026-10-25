<?php
// Operations of the Seedbox tools (run in the background by tools_run.php, or from tools.php for quick actions).
require_once __DIR__ . '/tools_lib.php';
require_once __DIR__ . '/tools_packs.php';

// 'pass': the tracker rejects the passkey in the announce URL (the torrent itself still exists); 'dead': it no longer knows the torrent
function st_dead_kind($msg) {
    $msg = (string)$msg;
    if (preg_match('/pass\s?key|torrent pass|invalid pass|bad pass/i', $msg)) { return 'pass'; }
    return preg_match(ST_DEAD_RE, $msg) ? 'dead' : '';
}
const ST_DEAD_RE = '/unregistered|not registered|(?<!host )not found|torrent (has been )?(deleted|removed)|nuked|does not exist|info.?hash.*(not|unknown)/i';

function st_progress($file, $state, $msg, $extra = []) {
    $cur = st_json_read($file, []);
    st_json_write($file, array_merge($cur, ['state' => $state, 't' => time(), 'msg' => $msg], $extra));
}

// =============================================================================================================
// findmytorrent
// =============================================================================================================
function st_find_query_variants($name) {
    $base = preg_replace('/\.(mkv|mp4|avi|m4v|ts)$/i', '', $name);
    $v = [trim(preg_replace('/[._]+/', ' ', $base))];
    if (preg_match('/^(.*?)[ .(]((?:19|20)\d\d)(?=[ .)\-_]|$)/', $base, $m)) { $v[] = trim(preg_replace('/[._]+/', ' ', $m[1])) . ' ' . $m[2]; }
    elseif (preg_match('/^(.*?)[ ._]S(\d{1,2})(?:E\d+)?(?=[ ._\-]|$)/i', $base, $m)) { $v[] = trim(preg_replace('/[._]+/', ' ', $m[1])) . ' S' . str_pad($m[2], 2, '0', STR_PAD_LEFT); }
    return array_values(array_unique($v));
}

function st_find_rank($cands, $unit, $tol) {
    $nn = st_norm($unit['name']);
    $out = [];
    foreach ($cands as $c) {
        $size = $c['size'];
        $diff = $unit['size'] > 0 ? ($size - $unit['size']) / $unit['size'] : 1;
        $sizeOk = $unit['size'] > 0 && $diff <= $tol / 100 && $diff >= -0.02;
        $nameEq = st_norm($c['title']) === $nn;
        if (!$sizeOk && !$nameEq) { continue; }
        $c['name_eq'] = $nameEq; $c['size_ok'] = $sizeOk; $c['diff'] = round($diff * 100, 2);
        $c['score'] = ($nameEq && $sizeOk ? 3 : ($sizeOk ? 2 : 1));
        $out[] = $c;
    }
    usort($out, function ($a, $b) { return [$b['score'], $b['seeders']] <=> [$a['score'], $a['seeders']]; });
    return array_slice($out, 0, 5);
}

// Scans the downloads folders; the units that no torrent in qBittorrent covers (and the torrents whose tracker
// removed them) get candidates from Prowlarr.
function st_find_scan($auto = false) {
    $cfg = st_cfg(); $state = st_state();
    st_progress(ST_FIND, 'running', st_tr('run.qb'), ['items' => []]);
    { // a new scan forgets the earlier replacement searches of this tool (the Update me results use other keys)
        $old = st_json_read(ST_RES, []); foreach (array_keys((array)$old) as $k) { if (preg_match('/^f[0-9a-f]{12}$/', (string)$k)) { unset($old[$k]); } }
        st_json_write(ST_RES, $old);
    }
    $tor = st_qb_torrents();
    if ($tor === null) { st_progress(ST_FIND, 'error', st_tr('run.qb_fail')); return; }
    $content = [];
    foreach ($tor as $t) { $p = st_to_host((string)($t['content_path'] ?? ''), $cfg); if ($p !== '') { $content[] = rtrim($p, '/'); } }
    st_progress(ST_FIND, 'running', st_tr('run.dirs'));
    $units = st_units($cfg['roots']);
    $items = [];
    $ign = array_flip($state['ignored']);
    foreach ($units as $u) {
        $seeded = false;
        foreach ($content as $cp) { if ($cp === $u['path'] || strpos($cp, $u['path'] . '/') === 0) { $seeded = true; break; } }
        if ($seeded || isset($ign[$u['path']]) || (float)$u['size'] < 1e6 || !st_has_video($u['path'])) { continue; }
        $items[] = ['id' => 'f' . count($items), 'key' => 'f' . substr(md5($u['path']), 0, 12), 'path' => $u['path'], 'name' => $u['name'], 'root' => $u['root'], 'size' => $u['size'], 'why' => 'not seeded', 'old' => '', 'cands' => [], 'searched' => false];
    }
    // torrents whose tracker no longer knows them
    st_progress(ST_FIND, 'running', st_tr('run.trk'));
    $n = 0; $tot = count($tor);
    foreach ($tor as $t) {
        if ((float)($t['progress'] ?? 0) < 1) { continue; }
        if ((++$n) % 40 === 0) { st_progress(ST_FIND, 'running', st_tr('run.trk_n', ['n' => $n, 'm' => $tot])); }
        $tr = st_qb_json('/api/v2/torrents/trackers?hash=' . rawurlencode((string)$t['hash']));
        // dead = a tracker answers "unregistered" (qBittorrent 5: status 4 not working or 5 tracker error) and none works
        $dead = false; $msg = ''; $works = false; $passOnly = true;
        foreach ((array)$tr as $x) {
            $s = (int)($x['status'] ?? 0);
            if ($s === 2) { $works = true; }
            $k = ($s === 4 || $s === 5) ? st_dead_kind($x['msg'] ?? '') : '';
            if ($k !== '') { $dead = true; $msg = (string)$x['msg']; if ($k !== 'pass') { $passOnly = false; } }
        }
        if (!$dead || $works) { continue; }
        $p = rtrim(st_to_host((string)$t['content_path'], $cfg), '/');
        if (isset($ign[$p])) { continue; }
        $items[] = ['id' => 'f' . count($items), 'key' => 'f' . substr(md5($p), 0, 12), 'path' => $p, 'name' => (string)$t['name'], 'root' => basename(dirname($p)), 'size' => (float)$t['size'], 'why' => $passOnly ? 'passkey rejected' : 'tracker removed it', 'pass' => $passOnly, 'old' => (string)$t['hash'], 'dead_msg' => $msg, 'cands' => [], 'searched' => false];
    }
    st_json_write(ST_FIND, ['state' => 'running', 't' => time(), 'msg' => st_tr('f.searching'), 'items' => $items, 'done' => 0, 'total' => count($items)]);
    foreach ($items as $i => &$it) {
        $found = [];
        $err = '';
        foreach (!empty($it['pass']) ? [] : st_find_query_variants($it['name']) as $q) {
            [$res, $e] = st_prowlarr_search($q);
            if ($res === null) { $err = $e; break; }
            $found = array_merge($found, $res);
            $rank = st_find_rank($found, $it, (float)$cfg['find_tol']);
            if ($rank && $rank[0]['score'] === 3) { break; }
        }
        if ($err !== '') { $it['error'] = $err; }
        // the same release can appear on several indexers: keep every one, they differ in seeders
        $it['cands'] = st_find_rank($found, $it, (float)$cfg['find_tol']);
        $it['searched'] = true;
        $cur = st_json_read(ST_FIND, []);
        $cur['items'] = $items; $cur['done'] = $i + 1; $cur['t'] = time(); $cur['msg'] = st_tr('run.search_n', ['n' => $i + 1, 'm' => count($items)]);
        st_json_write(ST_FIND, $cur);
        if ($err !== '' && strpos($err, 'not configured') !== false) { break; }
    }
    unset($it);
    // the scan takes minutes: torrents added meanwhile (a replacement that Radarr / Sonarr just grabbed, an Add torrent) now seed some of the folders
    $tor2 = st_qb_torrents();
    if (is_array($tor2) && $tor2) {
        $content2 = [];
        foreach ($tor2 as $t) { $p = st_to_host((string)($t['content_path'] ?? ''), $cfg); if ($p !== '') { $content2[] = rtrim($p, '/'); } }
        $items = array_values(array_filter($items, function ($u) use ($content2) {
            if (($u['why'] ?? '') !== 'not seeded') { return true; }
            foreach ($content2 as $cp) { if ($cp === $u['path'] || strpos($cp, $u['path'] . '/') === 0) { return false; } }
            return true;
        }));
    }
    {
        // every folder without an identical torrent gets its replacement options from Radarr / Sonarr now, so the rows are ready to open
        $todo = [];
        foreach ($items as $it) {
            $best = 0; foreach ((array)($it['cands'] ?? []) as $c) { $best = max($best, !empty($c['name_eq']) && !empty($c['size_ok']) ? 3 : (!empty($c['size_ok']) ? 2 : 1)); }
            if ($best < 3 && empty($it['error']) && empty($it['pass'])) { $todo[] = $it; }
        }
        $index = $todo ? st_arr_index(true) : null;
        foreach ($todo as $k => $u) {
            $cur = st_json_read(ST_FIND, []); $cur['msg'] = st_tr('run.repl_n', ['n' => $k + 1, 'm' => count($todo)]); $cur['t'] = time(); st_json_write(ST_FIND, $cur);
            st_find_repl_unit($u, $index, true);
        }
    }
    st_json_write(ST_FIND, ['state' => 'ready', 't' => time(), 'msg' => '', 'items' => $items, 'done' => count($items), 'total' => count($items)]);
    $n = count($items); $green = 0; $amber = 0; $nameonly = 0; $dead = 0; $bytes = 0;
    foreach ($items as $it) {
        $best = 0; foreach ((array)($it['cands'] ?? []) as $c) { $best = max($best, !empty($c['name_eq']) && !empty($c['size_ok']) ? 3 : (!empty($c['size_ok']) ? 2 : 1)); }
        if ($best === 3) { $green++; } elseif ($best === 2) { $amber++; } elseif ($best === 1) { $nameonly++; }
        if (!empty($it['old']) && empty($it['pass'])) { $dead++; } $bytes += (float)$it['size'];
    }
    $with = $green + $amber + $nameonly;
    // remember which torrents the tracker removed: the Seedbox tile shows them as "need attention"
    $gone = []; foreach ($items as $it) { if (!empty($it['old']) && empty($it['pass'])) { $gone[] = ['hash' => (string)$it['old'], 'name' => (string)$it['name']]; } }
    st_state_update(function (&$ws) use ($gone, $n) { $ws['watch'] = array_merge((array)($ws['watch'] ?? []), ['t' => time(), 'removed' => $gone, 'unseeded' => $n]); });
    $nsd = 0; $npw = 0; foreach ($items as $it) { if (!empty($it['pass'])) { $npw++; } elseif (empty($it['old'])) { $nsd++; } }
    st_sum_set('find', ['removed' => count($gone), 'nosd' => $nsd, 'pass' => $npw]);
    if ($auto && $dead > 0 && st_bot_on('find')) { st_find_bot($items); }
    if ($auto && $dead > 0) {
        $names = array_slice(array_map(function ($g) { return $g['name']; }, $gone), 0, 8);
        st_notify('a', st_l('Seedbox: a tracker törölt torrenteket', 'Seedbox: the tracker removed torrents'),
            st_l($dead === 1 ? 'Az ellenőrzés 1 torrentet talált, amelyet a tracker már nem ismer, ezért nem seedel. Cserélni a Seedbox Tools oldalon tudod (Settings › Utilities).'
                             : 'Az ellenőrzés ' . $dead . ' torrentet talált, amelyeket a tracker már nem ismer, ezért nem seedelnek. Cserélni a Seedbox Tools oldalon tudod (Settings › Utilities).',
                 'The check found ' . $dead . ' torrent(s) that the tracker no longer knows, so they do not seed. You can replace them in Seedbox Tools (Settings › Utilities).'),
            [[st_l('Példák', 'Examples'), implode("\n", $names) . ($dead > 8 ? "\n+" . ($dead - 8) : '')], [st_l('Nem seedelt tétel összesen', 'Unseeded in total'), (string)$n, true]], true, 'find');
        return;
    }
    if ($auto) {
        // nothing removed by a tracker: say that the weekly check ran
        st_notify('g', st_l('Seedbox: a heti torrent health check lefutott', 'Seedbox: the weekly torrent health check ran'),
            st_l('Nem találtam olyan torrentet, amelyet a tracker törölt.', 'No torrent was found that a tracker removed.') . ($n > 0 ? ' ' . st_l($n . ' mappához (' . st_gb($bytes) . ') nem tartozik seedelt torrent, ezek a Seedbox Tools oldalon vannak.', $n . ' folder(s) (' . st_gb($bytes) . ') have no seeded torrent, see Seedbox Tools.') : ' ' . st_l('Minden fájl seedelve van.', 'Every file is seeded.')),
            [], true, 'find');
        return;
    }
    if ($n === 0) { st_notify('g', st_l('Seedbox: minden fájl seedelve', 'Seedbox: every file is seeded'), st_l('A letöltési mappák átvizsgálva, nincs seedeletlen tartalom.', 'The downloads folders were scanned, nothing is unseeded.'), [], false, 'find'); }
    else {
        st_notify('a', st_l('Seedbox: seedeletlen tartalom találva', 'Seedbox: unseeded content found'),
            st_l('A letöltési mappákban ' . $n . ' elemhez (' . st_gb($bytes) . ') nem tartozik seedelt torrent. Oka: a torrent nincs a qBittorrentben, vagy a tracker törölte.',
                 $n . ' item(s) (' . st_gb($bytes) . ') in the downloads folders have no seeded torrent. Cause: the torrent is not in qBittorrent, or the tracker removed it.'),
            [[st_l('Egyező torrent (név és méret)', 'Matching torrent (name and size)'), (string)$green, true], [st_l('Méret egyezik', 'Size matches'), (string)$amber, true], [st_l('Csak a név hasonlít', 'Name only'), (string)$nameonly, true], [st_l('Nincs torrent, csere kell', 'No torrent, replacement needed'), (string)($n - $with), true], [st_l('Ebből a tracker törölte', 'Of these removed by tracker'), (string)$dead, true]], false, 'find');
    }
}

// 'E05' when a torrent name holds exactly one episode (S02E05), so the title does not look like a whole season
function st_ep_suffix($name) {
    if (preg_match('/S\d{1,2}E\d{1,3}[-_.]?E\d{1,3}/i', (string)$name)) { return ''; }
    return preg_match('/S\d{1,2}E(\d{1,3})(?!\d)/i', (string)$name, $m) ? 'E' . str_pad((string)(int)$m[1], 2, '0', STR_PAD_LEFT) : '';
}
// BoxBot (item 72): one message with choices per torrent the tracker removed, when the replacement search found releases;
// at most 5 per scan, and a torrent is offered again only after 7 days
function st_find_bot($items) {
    $res = st_json_read(ST_RES, []); $sent = 0;
    $old = []; foreach ((array)(st_json_read(st_bot_file(), [])['msgs'] ?? []) as $m) { if ($m['kind'] === 'find' && time() - (int)$m['t'] < 7 * 86400) { $old[$m['key'] . ':' . ($m['old'] ?? '')] = 1; } }
    foreach ($items as $it) {
        if (empty($it['old']) || !empty($it['pass']) || $sent >= 5) { continue; }
        $r = $res[$it['key']] ?? null;
        if (!is_array($r) || ($r['state'] ?? '') !== 'ready' || empty($r['rows']) || isset($old[$it['key'] . ':' . $it['old']])) { continue; }
        $ri = (array)($r['item'] ?? []);
        $now = trim(((int)($ri['cur_res'] ?? 0) > 0 ? $ri['cur_res'] . 'p ' : '') . st_hdr_label((int)($ri['cur_hdr'] ?? 1))) . ', ' . st_gb((float)$it['size']);
        [$ok] = st_bot_post('find', $it['key'], st_l('🗑️ Lekerült a trackerről · ', '🗑️ Removed from the tracker · ') . (string)($ri['title'] ?? $it['name']),
            st_l('A torrent már nem seedel. Csere-jelöltek a ' . ucfirst((string)($ri['kind'] ?? 'arr')) . ' keresése szerint; a régi mappa a csere után a kukába kerül.', 'The torrent no longer seeds. Replacement releases from the ' . ucfirst((string)($ri['kind'] ?? 'arr')) . ' search; the old folder goes to the trash after the replacement.'),
            [[st_l('Most', 'Now'), (string)$it['name'] . "
" . $now]], $r['rows'], ['old' => (string)$it['old']]);
        if ($ok) { $sent++; }
    }
}

// Adds a candidate to qBittorrent: stopped, with a recheck; tick finishes it (starts it only when it is 100%).
// The qBittorrent category Radarr (movies) or Sonarr (series) uses for its downloads, read from its download client
function st_arr_category($kind) {
    [$code, $j] = st_arr($kind, '/downloadclient', 'GET', null, 20);
    if ($code !== 200 || !is_array($j)) { return ''; }
    $want = $kind === 'radarr' ? 'movieCategory' : 'tvCategory';
    foreach ($j as $dc) {
        if (stripos((string)($dc['implementation'] ?? '') . ' ' . (string)($dc['name'] ?? ''), 'qbittorrent') === false || empty($dc['enable'])) { continue; }
        foreach ((array)($dc['fields'] ?? []) as $f) { if (($f['name'] ?? '') === $want && trim((string)($f['value'] ?? '')) !== '') { return trim((string)$f['value']); } }
    }
    return '';
}
function st_category_for($path, $name) {
    if (!(int)st_cfg()['find_cat']) { return ''; }
    $vids = [];
    $out = (string)@shell_exec('find ' . escapeshellarg($path) . ' -type f -printf ' . escapeshellarg('%s\t%p\n') . ' 2>/dev/null');
    foreach (explode("\n", $out) as $l) { $p = explode("\t", $l, 2); if (count($p) === 2 && st_main_video($p[1])) { $vids[] = (float)$p[0]; } }
    $m = st_arr_match($vids, $name, st_arr_index());
    return $m['kind'] !== '' ? st_arr_category($m['kind']) : '';
}

function st_find_add($itemId, $idx) {
    $cfg = st_cfg(); $find = st_json_read(ST_FIND, []);
    $item = null;
    foreach ((array)($find['items'] ?? []) as $it) { if ($it['id'] === $itemId) { $item = $it; } }
    if (!$item || !isset($item['cands'][$idx])) { return [false, st_tr('e.unknown_item')]; }
    $c = $item['cands'][$idx];
    $dl = (string)$c['dl'];
    if ($dl === '') { return [false, st_tr('e.nolink')]; }
    $hash = ''; $urls = ''; $file = '';
    if (stripos($dl, 'magnet:') === 0) { $urls = $dl; $hash = st_magnet_hash($dl); }
    else {
        [$code, $body, $err, $hdr] = st_http('GET', $dl, ['timeout' => 60, 'follow' => false]);
        if (in_array($code, [301, 302, 303, 307, 308], true) && preg_match('/^location:\s*(magnet:[^\r\n]+)/im', $hdr, $m)) { $urls = trim($m[1]); $hash = st_magnet_hash($urls); }
        else {
            if ($code >= 300 && $code < 400 && preg_match('/^location:\s*(\S+)/im', $hdr, $m)) { [$code, $body, $err, $hdr] = st_http('GET', trim($m[1]), ['timeout' => 60, 'follow' => true]); }
            if ($code !== 200 || $body === '' || $body[0] !== 'd') { return [false, st_tr('e.dl_torrent', ['e' => $err ?: 'HTTP ' . $code])]; }
            $hash = st_torrent_hash($body);
            $file = tempnam('/tmp', 'qbtt'); file_put_contents($file, $body);
        }
    }
    if ($hash === '') { if ($file) { @unlink($file); } return [false, st_tr('e.hash')]; }
    $exists = null;
    foreach ((array)st_qb_torrents() as $t) { if (strtolower((string)$t['hash']) === $hash) { $exists = $t; } }
    if ($exists) { if ($file) { @unlink($file); } return [false, st_tr('e.exists', ['n' => $exists['name']])]; }
    $save = st_to_qb(dirname($item['path']), $cfg);
    $post = ['savepath' => $save, 'paused' => 'true', 'stopped' => 'true', 'skip_checking' => 'false', 'autoTMM' => 'false', 'useDownloadPath' => 'false', 'tags' => 'findmytorrent']; // useDownloadPath=false: never move the files to qBittorrent's incomplete folder (that would break hardlinks)
    $cat = st_category_for($item['path'], $item['name']);
    if ($cat === '' && (int)$cfg['find_cat']) {
        // not in Radarr/Sonarr (or another size): use the category most torrents in the same folder already have
        $cnt = [];
        foreach ((array)st_qb_torrents() as $t) { if (rtrim((string)$t['save_path'], '/') === rtrim($save, '/') && (string)$t['category'] !== '') { $cnt[(string)$t['category']] = ($cnt[(string)$t['category']] ?? 0) + 1; } }
        if ($cnt) { arsort($cnt); $cat = (string)key($cnt); }
    }
    if ($cat !== '') { $post['category'] = $cat; }
    if ($urls !== '') { $post['urls'] = $urls; }
    [$code, $b, $e] = st_qb('/api/v2/torrents/add', $post, $file !== '' ? ['torrents' => $file] : null);
    if ($file) { @unlink($file); }
    if ($code !== 200) { return [false, st_tr('e.qb_refused', ['e' => $e ?: 'HTTP ' . $code])]; }
    st_state_update(function (&$state) use ($hash, $c, $item) {
        $state['pending'][] = ['id' => substr(md5($hash . microtime()), 0, 10), 'hash' => $hash, 'name' => $c['title'], 'item' => $item['name'], 'path' => $item['path'], 'replaces' => (string)($item['old'] ?? ''),
                               't' => time(), 'stage' => 'added', 'rechecked' => 0, 'msg' => st_tr('job.checking_files')];
    });
    sleep(1);
    st_qb('/api/v2/torrents/recheck', ['hashes' => $hash]);
    return [true, ''];
}

// The best identical release of an item: same name and size, most seeders. Returns its index or -1.
function st_best_green($item) {
    $best = -1; $seed = -1;
    foreach ((array)($item['cands'] ?? []) as $j => $c) { if (!empty($c['name_eq']) && !empty($c['size_ok']) && (int)$c['seeders'] > $seed) { $best = $j; $seed = (int)$c['seeders']; } }
    return $best;
}
// Add all matching: puts every identical-release item in the queue (by folder path). Returns the number queued.
function st_queue_all() {
    $find = st_json_read(ST_FIND, []);
    return (int)st_state_update(function (&$s) use ($find) {
        $have = array_flip(array_column($s['queue'], 'path')); $n = 0;
        $busy = []; foreach ($s['pending'] as $p) { if (in_array($p['stage'], ['added', 'checking', 'done'], true) || ($p['stage'] === 'failed' && time() - $p['t'] < 7 * 86400)) { $busy[$p['path']] = 1; } }
        foreach ((array)($find['items'] ?? []) as $it) {
            if (isset($have[$it['path']]) || isset($busy[$it['path']]) || st_best_green($it) < 0) { continue; }
            $s['queue'][] = ['path' => $it['path'], 'name' => $it['name']]; $n++;
        }
        if (!$n) { return false; }
        if (!$s['qstat']) { $s['qstat'] = ['t' => time(), 'total' => $n]; } else { $s['qstat']['total'] += $n; }
        return $n;
    });
}
function st_queue_stop() { st_state_update(function (&$s) { $s['queue'] = []; }); st_queue_feed(); }
// One check at a time: when nothing is being checked, add the next queued item. At the end, one Discord summary.
function st_queue_feed() {
    $act = st_state_update(function (&$s) {
        if (!$s['queue'] && !$s['qstat']) { return false; }
        foreach ($s['pending'] as $p) { if (in_array($p['stage'], ['added', 'checking'], true)) { return false; } }
        if (!$s['queue']) {
            $t0 = (int)$s['qstat']['t']; $ok = 0; $bad = []; $partial = 0;
            foreach ($s['pending'] as $p) { if ((int)$p['t'] < $t0) { continue; } if ($p['stage'] === 'done') { $ok++; if (!empty($p['partial'])) { $partial++; } } elseif ($p['stage'] === 'failed') { $bad[] = $p['item'] . ': ' . $p['msg']; } }
            $s['qstat'] = null;
            return ['sum', $ok, $partial, $bad];
        }
        return ['next', array_shift($s['queue'])];
    });
    if (!$act) { return; }
    if ($act[0] === 'sum') {
        [, $ok, $partial, $bad] = $act;
        st_notify(count($bad) ? 'a' : 'g', st_l('Seedbox: Add all matching kész', 'Seedbox: Add all matching finished'),
            st_l($ok . ' torrent seedel újra' . ($partial ? ' (ebből ' . $partial . '-nál a qBittorrent letölti a hiányzó részt)' : '') . ', ' . count($bad) . ' nem egyezett.', $ok . ' torrent(s) seeding again' . ($partial ? ' (' . $partial . ' of them will download the missing part)' : '') . ', ' . count($bad) . ' did not match.'),
            [[st_l('Nem egyezett', 'Did not match'), implode("\n", array_slice($bad, 0, 10))]], false, 'find');
        return;
    }
    $q = $act[1];
    $find = st_json_read(ST_FIND, []); $item = null;
    foreach ((array)($find['items'] ?? []) as $it) { if ($it['path'] === $q['path']) { $item = $it; } }
    $idx = $item ? st_best_green($item) : -1;
    if ($idx < 0) { st_queue_feed(); return; } // the list changed since: skip
    [$ok, $e] = st_find_add($item['id'], $idx);
    if (!$ok) {
        st_state_update(function (&$s) use ($item, $idx, $e) { $s['pending'][] = ['id' => substr(md5($item['path'] . microtime()), 0, 10), 'hash' => '', 'name' => $item['cands'][$idx]['title'], 'item' => $item['name'], 'path' => $item['path'], 'replaces' => '', 't' => time(), 'stage' => 'failed', 'rechecked' => 1, 'msg' => $e]; });
        st_queue_feed();
    }
}

function st_find_ignore($path) { st_ignored_add($path); }

// Delete button of the Find my torrent list: the folder goes to the trash folder of its downloads folder (or is deleted at once when
// trash_days is 0), and the torrent the tracker removed is taken out of qBittorrent. A folder that another torrent seeds is never touched.
function st_find_delete($id) {
    $find = st_json_read(ST_FIND, []); $cfg = st_cfg(); $hit = null;
    foreach ((array)($find['items'] ?? []) as $it) { if (($it['id'] ?? '') === $id) { $hit = $it; } }
    if (!$hit) { return [false, st_tr('e.unknown_item')]; }
    $p = (string)$hit['path'];
    if ($p === '' || !file_exists($p)) { return [false, st_tr('e.gone')]; }
    $root = st_under_roots($p, $cfg);
    if ($root === false) { return [false, st_tr('e.nodel_root', ['p' => $p])]; }
    $rp = realpath($p); $old = strtolower((string)($hit['old'] ?? ''));
    $tors = st_qb_torrents();
    if ($tors === null) { return [false, st_tr('e.qb_nodel')]; }
    foreach ($tors as $t) {
        $cp = rtrim(st_to_host((string)($t['content_path'] ?? ''), $cfg), '/');
        if ($cp !== '' && ($cp === $rp || strpos($cp, $rp . '/') === 0) && strtolower((string)$t['hash']) !== $old) { return [false, st_tr('e.nodel_seed', ['p' => $p, 't' => $t['name']])]; }
    }
    if ($old !== '') { st_qb('/api/v2/torrents/delete', ['hashes' => $old, 'deleteFiles' => 'false']); st_watch_forget($old); }
    $days = (int)($cfg['trash_days'] ?? 0);
    if ($days > 0) {
        $tr = $root . '/' . ST_TRASH; @mkdir($tr, 0777, true);
        if (!@rename($rp, $tr . '/' . date('YmdHis') . '_' . basename($rp))) { return [false, st_tr('e.notrash', ['p' => $p])]; }
    } elseif (!st_rm_tree($rp)) { return [false, st_tr('e.nodelete', ['p' => $p])]; }
    st_find_prune();
    return [true, ''];
}

// drops the Find my torrent entries whose folder is gone (for example moved to the trash by the Season repacker); returns how many
function st_find_prune() {
    $find = st_json_read(ST_FIND, []); if (empty($find['items']) || !is_array($find['items'])) { return 0; }
    $keep = []; foreach ($find['items'] as $it) { if (isset($it['path']) && !file_exists((string)$it['path'])) { continue; } $keep[] = $it; }
    $n = count($find['items']) - count($keep);
    if ($n > 0) { $find['items'] = $keep; st_json_write(ST_FIND, $find); }
    return $n;
}
// a torrent that a tool removed no longer counts as "removed from tracker" on the widget
// last-scan summary of a tool (kept across reboots; the widget shows it)
function st_sum_set($k, $v) { st_state_update(function (&$s) use ($k, $v) { $s['sum'][$k] = array_merge(['t' => time()], $v); }); }
function st_watch_forget($hash) {
    $hash = strtolower((string)$hash); if ($hash === '') { return; }
    st_state_update(function (&$s) use ($hash) {
        $r = (array)($s['watch']['removed'] ?? []); if (!$r) { return false; }
        $k = array_values(array_filter($r, function ($g) use ($hash) { return strtolower((string)($g['hash'] ?? '')) !== $hash; }));
        if (count($k) === count($r)) { return false; }
        $s['watch']['removed'] = $k;
    });
}

// ---- finishing pending adds and watching the replacement jobs (cron, every minute) -----------------------
function st_qb_start($hash) {
    [$c] = st_qb('/api/v2/torrents/start', ['hashes' => $hash]);
    if ($c === 404 || $c === 405) { st_qb('/api/v2/torrents/resume', ['hashes' => $hash]); }
}
// Scheduled scan (daily or weekly, at the chosen hour): the most recent slot that has passed and was not scanned yet.
// Only scans run by themselves, one after the other, for the tools that are ticked; nothing is added, replaced or deleted.
function st_auto_due() {
    $c = st_cfg();
    $tools = [];
    if ((int)$c['auto_scan']) { $tools[] = 'find auto'; }
    if ((int)$c['auto_upd']) { $tools[] = 'upd'; }
    if ((int)$c['auto_rep']) { $tools[] = 'sp'; }
    if (!$tools) { return; }
    $now = time(); $slot = mktime((int)$c['auto_hour'], 0, 0);
    if (($c['auto_freq'] ?? 'weekly') === 'daily') { if ($slot > $now) { $slot -= 86400; } }
    else { $slot -= (((int)date('N') - (int)$c['auto_day'] + 7) % 7) * 86400; if ($slot > $now) { $slot -= 7 * 86400; } }
    $go = st_state_update(function (&$s) use ($now, $slot) {
        $w = (array)($s['watch'] ?? []);
        if (!isset($w['auto_t'])) { $w['auto_t'] = $now; $s['watch'] = $w; return 'first'; } // first run: wait for the next slot
        if ((int)$w['auto_t'] >= $slot) { return false; }
        $w['auto_t'] = $now; $s['watch'] = $w; return 'go';
    });
    if ($go !== 'go') { return; }
    $php = '/usr/bin/php'; foreach (['/usr/bin/php', '/usr/local/bin/php'] as $p) { if (is_executable($p)) { $php = $p; break; } }
    $cmd = [];
    foreach ($tools as $t) { $cmd[] = escapeshellarg($php) . ' ' . escapeshellarg(__DIR__ . '/tools_run.php') . ' ' . $t; }
    @exec('setsid nohup sh -c ' . escapeshellarg(implode('; ', $cmd)) . ' >/dev/null 2>&1 &');
}

function st_tick() {
    st_trash_purge();
    st_auto_due();
    try { st_ab_poll(); } catch (Throwable $e) { $w = st_json_read(ST_AB, []); $w['t'] = time(); $w['err'] = st_tr('e.unexpected', ['e' => $e->getMessage()]); st_json_write(ST_AB, $w); }
    try { st_bot_poll(); } catch (Throwable $e) { /* Discord unreachable: the next minute tries again */ }
    st_upd_prune(st_qb_torrents()); // only touches a finished (ready) Shape up list
    $state = st_state(); $orig = $state; $changed = false;
    $active = false;
    foreach ($state['pending'] as $p) { if (in_array($p['stage'], ['added', 'checking'], true)) { $active = true; } }
    foreach ($state['jobs'] as $j) { if (!in_array($j['stage'], ['imported', 'removed', 'failed', 'cleaned'], true) || (empty($j['tag']) && $j['stage'] !== 'failed' && time() - (int)($j['gt'] ?? $j['t']) < 86400)) { $active = true; } }
    if ($state['queue']) { $active = true; }
    if (st_sp_active($state)) { $active = true; }
    if (!$active) { return; }
    $tor = st_qb_torrents();
    if ($tor !== null) {
        $by = []; foreach ($tor as $t) { $by[strtolower((string)$t['hash'])] = $t; }
        foreach ($state['pending'] as &$p) {
            if (!in_array($p['stage'], ['added', 'checking'], true)) { continue; }
            $t = $by[$p['hash']] ?? null;
            if (!$t) { if (time() - $p['t'] > 600) { $p['stage'] = 'failed'; $p['msg'] = st_tr('job.gone'); $changed = true; } continue; }
            $st = (string)$t['state'];
            if (preg_match('/^(checking|allocating|moving|metaDL|forcedMetaDL)/', $st)) { $p['stage'] = 'checking'; $p['msg'] = st_tr('job.checking', ['p' => round(((float)$t['progress']) * 100)]); $changed = true; continue; }
            if (!$p['rechecked'] && (float)$t['progress'] < 1) {
                // the first check may not have run on a stopped torrent: ask once more, then judge
                $p['rechecked'] = 1; $p['t'] = time(); st_qb('/api/v2/torrents/recheck', ['hashes' => $p['hash']]); $changed = true; continue;
            }
            $acc = max(0.9, min(1.0, (float)st_cfg()['find_accept'] / 100));
            $okMatch = (float)$t['progress'] >= min(0.99999, $acc); $missing = '';
            if (!$okMatch && (float)$t['progress'] >= 0.5 && (int)st_cfg()['find_missing_files'] > 0) {
                // only a few whole files missing (an episode, an extra): the rest is there, so keep it and download just those
                $inc = st_missing_files($p['hash']);
                if ($inc && count($inc) <= (int)st_cfg()['find_missing_files']) { $okMatch = true; $missing = st_missing_txt($inc); }
            }
            if ($okMatch) {
                st_qb_start($p['hash']);
                $full = (float)$t['progress'] >= 0.99999; $lack = (1 - (float)$t['progress']) * (float)$t['size'];
                if (!$full && $missing === '') {
                    // 99.9x %: show which file is really missing; qBittorrent still fetches whole pieces, so the byte count is bigger
                    $inc = st_missing_files($p['hash']);
                    if ($inc && count($inc) <= 20) { $missing = st_missing_txt($inc); }
                }
                $p['stage'] = 'done'; $p['msg'] = $full ? st_tr('job.matched') : st_tr('job.matched_part', ['p' => round(((float)$t['progress']) * 100, 2), 'm' => $missing !== '' ? $missing : st_gb($lack)]); $p['t'] = time(); $p['partial'] = !$full;
                if ($p['replaces'] !== '') { st_qb('/api/v2/torrents/delete', ['hashes' => $p['replaces'], 'deleteFiles' => 'false']); $p['msg'] .= '; ' . st_tr('job.old_removed'); }
                $pc = round(((float)$t['progress']) * 100, 2);
                $hu = $full ? 'A hozzáadott torrent minden fájlja egyezett a letöltött fájlokkal, a seedelés elindult.' : $pc . '%-ban egyezett a torrent a fájlokkal, a seedelés elindult, a hiányzó részt a qBittorrent letölti: ' . ($missing !== '' ? str_replace(' files', ' fájl', str_replace(' file ', ' fájl ', $missing)) : st_gb($lack)) . '.';
                $en = $full ? 'Every file of the added torrent matched the downloaded files, so seeding started.' : $pc . '% of the torrent matched the files, seeding started; qBittorrent downloads the missing ' . ($missing !== '' ? $missing : st_gb($lack)) . '.';
                if ($p['replaces'] !== '') { $hu .= ' A tracker által törölt régi torrent bejegyzése eltávolítva (a fájlok megmaradtak).'; $en .= ' The old torrent the tracker had removed was taken out of qBittorrent (files kept).'; }
                empty($state['qstat']) && st_notify('g', st_l('Seedbox: a tartalom újra seedelve', 'Seedbox: content is seeded again'), st_l($hu, $en),
                    [[st_l('Mappa', 'Folder'), $p['item']], [st_l('Torrent', 'Torrent'), $p['name']]], false, 'find');
            } else {
                st_qb('/api/v2/torrents/delete', ['hashes' => $p['hash'], 'deleteFiles' => 'false']);
                $p['stage'] = 'failed'; $p['msg'] = st_tr('job.nomatch', ['p' => round(((float)$t['progress']) * 100, 1)]); $p['t'] = time();
                empty($state['qstat']) && st_notify('a', st_l('Seedbox: a torrent nem egyezik a fájlokkal', 'Seedbox: the torrent does not match the files'),
                    st_l('A hozzáadott torrent csak ' . round(((float)$t['progress']) * 100, 1) . '%-ban egyezett a letöltött fájlokkal, ezért töröltem a qBittorrentből. A fájlokhoz nem nyúltam.',
                         'The added torrent matched the downloaded files only ' . round(((float)$t['progress']) * 100, 1) . '%, so it was removed from qBittorrent. The files were not touched.'),
                    [[st_l('Mappa', 'Folder'), $p['item']], [st_l('Torrent', 'Torrent'), $p['name']]], false, 'find');
            }
            $changed = true;
        }
        unset($p);
    }
    if ($tor !== null && st_sp_tick($state, $tor)) { $changed = true; }
    // replacement jobs: look at the Radarr / Sonarr queue and at the files
    foreach ($state['jobs'] as &$j) {
        if (empty($j['tag']) && $j['stage'] !== 'failed' && $tor !== null && st_job_tag($j, $tor)) { $j['tag'] = 1; $changed = true; }
        if (in_array($j['stage'], ['imported', 'removed', 'failed', 'cleaned'], true)) { continue; }
        $new = st_job_status($j);
        if ($new !== null && ($new['stage'] !== $j['stage'] || $new['msg'] !== $j['msg'])) {
            $prev = $j['stage'];
            $j['stage'] = $new['stage']; $j['msg'] = $new['msg']; $j['msgd'] = $new['msgd'] ?? $new['msg']; $changed = true;
            // content nobody seeded: once the replacement is imported the old files go (to the trash, see trash_days)
            if ($j['stage'] === 'imported' && ($j['mode'] ?? '') === 'repl') {
                [$ok, $m, $mL] = st_cleanup_old($j); $mL = $mL ?? $m;
                if ($ok) { $j['stage'] = 'cleaned'; $j['msg'] = $m; $j['msgd'] = $mL; $j['t'] = time(); } else { $j['msg'] .= '; ' . st_tr('job.old_kept', ['m' => $m]); $j['msgd'] = ($j['msgd'] ?? '') . '; ' . st_trd('job.old_kept', ['m' => $m]); }
                st_notify($ok ? 'g' : 'a', $ok ? st_l('Seedbox: csere kész, régi fájlok eltávolítva', 'Seedbox: replacement done, old files removed') : st_l('Seedbox: a csere kész, de a régi fájlokat nem tudtam eltávolítani', 'Seedbox: replaced, but the old files could not be removed'),
                    st_l('A ' . ucfirst($j['kind']) . ' beimportálta az új kiadást.', ucfirst($j['kind']) . ' imported the new release.') . ' ' . $mL,
                    [[st_l('Cím', 'Title'), $j['title']], [st_l('Új', 'New'), $j['new']], [st_l('Régi', 'Old'), $j['old_name']]], false, st_ntool($j));
            } elseif ($j['stage'] === 'imported') {
                st_notify('g', st_l('Seedbox: az új kiadás importálva', 'Seedbox: the new release is imported'), st_l('A ' . ucfirst($j['kind']) . ' beimportálta; a régi torrent és fájljai a jóváhagyásodra várnak az oldalon (Remove).', ucfirst($j['kind']) . ' imported it; the old torrent and files wait for your click on the page (Remove).'),
                    [[st_l('Cím', 'Title'), $j['title']], [st_l('Új', 'New'), $j['new']]], false, st_ntool($j));
            } elseif ($j['stage'] === 'blocked' && $prev !== 'blocked') {
                st_notify('a', st_l('Seedbox: a csere elakadt', 'Seedbox: the replacement is stuck'), st_l('A ' . ucfirst($j['kind']) . ' nem importálja az új kiadást: ', ucfirst($j['kind']) . ' does not import the new release: ') . $j['msg'] . "\n" . st_l('Nézd meg a ' . ucfirst($j['kind']) . ' Activity, Queue oldalát, és importáld kézzel.', 'Open Activity, Queue in ' . ucfirst($j['kind']) . ' and import it by hand.'),
                    [[st_l('Cím', 'Title'), $j['title']], [st_l('Új', 'New'), $j['new']]], false, st_ntool($j));
            }
        }
    }
    unset($j);
    // forget old finished entries
    $keep = [];
    foreach ($state['pending'] as $p) { if (in_array($p['stage'], ['done', 'failed'], true) && time() - $p['t'] > 7 * 86400) { $changed = true; continue; } $keep[] = $p; }
    $state['pending'] = $keep;
    $kj = [];
    foreach ($state['jobs'] as $j) { if (in_array($j['stage'], ['removed', 'failed', 'cleaned'], true) && time() - (int)$j['t'] > 7 * 86400) { $changed = true; continue; } $kj[] = $j; }
    $state['jobs'] = $kj;
    if ($changed) {
        // put the changes onto the current state: the page may have added or changed entries during this tick
        st_state_update(function (&$cur) use ($orig, $state) { foreach (['pending', 'jobs', 'packjobs'] as $k) { $cur[$k] = st_merge_list($orig[$k] ?? [], $state[$k] ?? [], $cur[$k] ?? []); } });
    }
    st_queue_feed();
}

// =============================================================================================================
// updateme
// =============================================================================================================
function st_video_ext($n) { return (bool)preg_match('/\.(mkv|mp4|avi|m4v|ts|mov|wmv|iso)$/i', $n); }
// a video that counts for matching: sample clips (Sample/Minta folder or sample/minta in the file name) and bonus material
// (a folder named Extras, Featurettes, Trailers, ...) are not part of the content
function st_main_video($n) {
    $n = (string)$n;
    if (!st_video_ext($n) || preg_match('/(^|[^a-z0-9])(samples?|minta)([^a-z0-9]|$)/i', $n)) { return false; }
    return !preg_match('#(^|/)(extras?|featurettes?|trailers?|behind[ ._-]the[ ._-]scenes|deleted[ ._-]scenes|interviews?|bonus)/#i', $n);
}

const ST_ARR = '/tmp/qbt_tools_arr.json';

// Radarr movie files and Sonarr episode files by byte size, cached for ten minutes (Sonarr needs one call per series)
function st_arr_index($force = false) {
    $c = st_json_read(ST_ARR, []);
    if (!$force && !empty($c['t']) && time() - $c['t'] < 600) { return $c; }
    $cfg = st_cfg(); $movies = []; $eps = []; $byname = []; $sname = [];
    if (($cfg['radarr']['url'] ?? '') !== '') {
        [$code, $j] = st_arr('radarr', '/movie', 'GET', null, 120);
        foreach ((array)$j as $m) {
            if (empty($m['movieFile']['size'])) { continue; }
            $mf = $m['movieFile']; $q = $mf['quality']['quality'] ?? [];
            $ent = ['id' => (int)$m['id'], 'title' => $m['title'] . ' (' . ($m['year'] ?? '') . ')', 'file' => (int)$mf['id'], 'size' => (float)$mf['size'],
                'path' => (string)($mf['path'] ?? ''), 'res' => (int)($q['resolution'] ?? 0), 'q' => (string)($q['name'] ?? ''), 'hdr' => st_hdr_rank($mf['mediaInfo']['videoDynamicRangeType'] ?? '')];
            $movies[(string)(int)$mf['size']][] = $ent;
            $byname[st_norm($m['title']) . (int)($m['year'] ?? 0)][] = $ent;
        }
    }
    if (($cfg['sonarr']['url'] ?? '') !== '') {
        [$code, $series] = st_arr('sonarr', '/series', 'GET', null, 120);
        foreach ((array)$series as $s) {
            $names = [(string)($s['title'] ?? '')]; foreach ((array)($s['alternateTitles'] ?? []) as $at) { $names[] = (string)($at['title'] ?? ''); }
            foreach ($names as $nm) { if ($nm === '') { continue; } $sname[st_norm($nm)][(int)$s['id']] = ['id' => (int)$s['id'], 'title' => (string)$s['title'], 'year' => (int)($s['year'] ?? 0)]; }
            [$c2, $files] = st_arr('sonarr', '/episodefile?seriesId=' . (int)$s['id'], 'GET', null, 60);
            foreach ((array)$files as $f) {
                if (empty($f['size'])) { continue; }
                $q = $f['quality']['quality'] ?? [];
                $eps[(string)(int)$f['size']][] = ['id' => (int)$s['id'], 'title' => (string)$s['title'], 'file' => (int)$f['id'], 'season' => (int)$f['seasonNumber'], 'size' => (float)$f['size'],
                    'path' => (string)($f['path'] ?? ''), 'res' => (int)($q['resolution'] ?? 0), 'q' => (string)($q['name'] ?? ''), 'hdr' => st_hdr_rank($f['mediaInfo']['videoDynamicRangeType'] ?? '')];
            }
        }
    }
    $c = ['t' => time(), 'movies' => $movies, 'eps' => $eps, 'byname' => $byname, 'sname' => $sname];
    st_json_write(ST_ARR, $c);
    return $c;
}

// Matches the video file sizes of a torrent or folder with one Radarr movie or one Sonarr season
function st_arr_match($vids, $name, $index) {
    if (count($vids) === 1 && isset($index['movies'][(string)(int)$vids[0]])) {
        $m = $index['movies'][(string)(int)$vids[0]][0];
        return ['kind' => 'radarr', 'arr_id' => $m['id'], 'title' => $m['title'], 'season' => 0, 'file_ids' => [$m['file']], 'cur_size' => $m['size'],
                'cur_res' => $m['res'] ?: st_res_of($name), 'cur_hdr' => max($m['hdr'], st_hdr_rank($name)), 'cur_quality' => $m['q'], 'media_paths' => [$m['path']]];
    }
    $seasons = []; $hit = 0;
    foreach ($vids as $sz) { foreach ($index['eps'][(string)(int)$sz] ?? [] as $h) { $seasons[$h['id'] . ':' . $h['season']][] = $h; $hit++; break; } }
    // the files of one season are the great majority: a few stray matches of other seasons (equal sizes) do not count
    if (count($seasons) > 1) {
        uasort($seasons, function ($x, $y) { return count($y) <=> count($x); });
        $top = reset($seasons);
        if (count($top) >= max(1, (int)floor(count($vids) * 0.8))) { $seasons = [key($seasons) => $top]; $hit = count($top); }
    }
    if (count($seasons) === 1 && $hit >= max(1, (int)floor(count($vids) * 0.8))) {
        $list = reset($seasons); $f0 = $list[0];
        $tot = 0; foreach ($list as $h) { $tot += $h['size']; }
        return ['kind' => 'sonarr', 'arr_id' => $f0['id'], 'title' => $f0['title'] . ' S' . str_pad((string)$f0['season'], 2, '0', STR_PAD_LEFT) . (count($list) === 1 ? st_ep_suffix($name) : ''), 'season' => $f0['season'],
                'file_ids' => array_map(function ($h) { return $h['file']; }, $list), 'cur_size' => $tot, 'cur_res' => $f0['res'] ?: st_res_of($name),
                'cur_hdr' => max($f0['hdr'], st_hdr_rank($name)), 'cur_quality' => $f0['q'], 'media_paths' => array_map(function ($h) { return $h['path']; }, $list)];
    }
    if (count($seasons) > 1) {
        $det = []; foreach ($seasons as $k => $l) { $det[] = $l[0]['title'] . ' S' . str_pad((string)$l[0]['season'], 2, '0', STR_PAD_LEFT) . ': ' . count($l) . ' file(s)'; }
        return ['kind' => '', 'skip' => 'several seasons', 'note' => st_tr('u.n_seasons', ['n' => count($vids), 'l' => implode(', ', $det)])];
    }
    return $vids ? ['kind' => '', 'note' => st_tr('u.n_nomatch')] : ['kind' => '', 'skip' => 'no video', 'note' => st_tr('u.n_novideo')];
}

function st_upd_scan() {
    $cfg = st_cfg();
    st_progress(ST_UPD, 'running', st_tr('run.qb'), ['items' => []]);
    $tor = st_qb_torrents();
    if ($tor === null) { st_progress(ST_UPD, 'error', st_tr('run.qb_fail')); return; }
    $minB = (float)$cfg['upd_min_gb'] * 1e9; $minAge = (int)$cfg['upd_min_age'] * 86400;
    $cand = [];
    $out = [];
    foreach ($tor as $t) {
        if ((float)($t['progress'] ?? 0) < 1) { continue; }
        $lo = st_lib_out($t, $cfg);
        if ($lo !== '') { // not part of the library: no tracker check, no matching; listed folded with the reason when big and old
            if ((float)$t['size'] >= (float)$cfg['upd_min_gb'] * 1e9 && (time() - (int)($t['added_on'] ?? time())) >= (int)$cfg['upd_min_age'] * 86400) {
                $out[] = ['hash' => (string)$t['hash'], 'name' => (string)$t['name'], 'size' => (float)$t['size'], 'added' => (int)($t['added_on'] ?? 0), 'dead' => false, 'dead_msg' => '', 'kind' => '', 'note' => '', 'skip' => st_tr('u.n_outside', ['l' => $lo])];
            }
            continue;
        }
        $dead = false; $dmsg = '';
        $tr = st_qb_json('/api/v2/torrents/trackers?hash=' . rawurlencode((string)$t['hash'])); $works = false;
        foreach ((array)$tr as $x) {
            $s = (int)($x['status'] ?? 0);
            if ($s === 2) { $works = true; }
            if (($s === 4 || $s === 5) && st_dead_kind($x['msg'] ?? '') === 'dead') { $dead = true; $dmsg = (string)$x['msg']; }
        }
        if ($works) { $dead = false; }
        $big = (float)$t['size'] >= $minB && (time() - (int)($t['added_on'] ?? time())) >= $minAge;
        if (!$big && !$dead) { continue; }
        $cand[] = ['t' => $t, 'dead' => $dead, 'dead_msg' => $dmsg];
    }
    st_progress(ST_UPD, 'running', st_tr('run.match'), ['total' => count($cand)]);
    $index = $cand ? st_arr_index(true) : ['movies' => [], 'eps' => []];
    $items = [];
    foreach ($cand as $x) {
        $t = $x['t'];
        $files = st_qb_json('/api/v2/torrents/files?hash=' . rawurlencode((string)$t['hash'])) ?: [];
        $vids = [];
        foreach ($files as $f) { if (st_main_video((string)$f['name'])) { $vids[] = (float)$f['size']; } }
        $it = ['hash' => (string)$t['hash'], 'name' => (string)$t['name'], 'size' => (float)$t['size'], 'added' => (int)($t['added_on'] ?? 0),
               'dead' => $x['dead'], 'dead_msg' => $x['dead_msg'], 'kind' => '', 'note' => ''];
        $m = st_arr_match($vids, (string)$t['name'], $index);
        if ($m['kind'] === 'sonarr') { $m['cur_size'] = (float)$t['size']; }
        $items[] = array_merge($it, $m);
    }
    usort($items, function ($a, $b) { return [$b['dead'], $b['size']] <=> [$a['dead'], $a['size']]; });
    // not listed: torrents without a video, packs of several seasons, and the ones the user hid (shown in a folded list)
    [$items, $skipped] = st_upd_split(array_merge($items, $out));
    st_json_write(ST_UPD, ['state' => 'ready', 't' => time(), 'msg' => '', 'items' => $items, 'skipped' => $skipped]);
    st_sum_set('upd', ['n' => count($items)]);
    st_upd_bg_spawn();
}

// Splits the Update me rows into the listed ones and the not listed ones (skip reason, or hidden by the user)
function st_upd_split($rows) {
    $hid = array_flip(st_upd_hidden_read()); $items = []; $skipped = [];
    foreach ($rows as $it) {
        if (isset($hid[$it['hash']])) { $it['skip'] = 'hidden'; }
        if (!empty($it['skip'])) { $skipped[] = $it; } else { unset($it['skip']); $items[] = $it; }
    }
    return [$items, $skipped];
}

// The old torrent of a Shape up replacement was deleted: drop it from the last scan's list too, so it does not come back
// when the finished job is forgotten (7 days) before the next scan. A scan in progress reads qBittorrent anyway.
function st_upd_drop($hash) {
    $u = st_json_read(ST_UPD, []);
    if (($u['state'] ?? '') !== 'ready' || $hash === '') { return; }
    $keep = function ($x) use ($hash) { return strtolower((string)($x['hash'] ?? '')) !== strtolower($hash); };
    $u['items'] = array_values(array_filter((array)($u['items'] ?? []), $keep));
    $u['skipped'] = array_values(array_filter((array)($u['skipped'] ?? []), $keep));
    st_json_write(ST_UPD, $u);
    st_sum_set('upd', ['n' => count($u['items'])]);
}

// Shape up list: drop the torrents that are no longer in qBittorrent (removed by a replacement, by hand, or by Radarr),
// so the list and its counts match what seeds now. Runs from the minute tick; an empty answer (qB starting) changes nothing.
function st_upd_prune($tor) {
    if (!is_array($tor) || !$tor) { return; }
    $u = st_json_read(ST_UPD, []);
    if (($u['state'] ?? '') !== 'ready') { return; }
    $have = []; foreach ($tor as $t) { $have[strtolower((string)($t['hash'] ?? ''))] = 1; }
    $keep = function ($x) use ($have) { return isset($have[strtolower((string)($x['hash'] ?? ''))]); };
    $it = array_values(array_filter((array)($u['items'] ?? []), $keep)); $sk = array_values(array_filter((array)($u['skipped'] ?? []), $keep));
    if (count($it) === count((array)($u['items'] ?? [])) && count($sk) === count((array)($u['skipped'] ?? []))) { return; }
    $u['items'] = $it; $u['skipped'] = $sk;
    st_json_write(ST_UPD, $u);
    st_sum_set('upd', ['n' => count($it)]);
}

// Hide (or show again) one Update me row: only the list changes, nothing in qBittorrent or on the disk
function st_upd_hide($hash, $on) {
    st_upd_hidden_set($hash, $on);
    $u = st_json_read(ST_UPD, []);
    if (($u['state'] ?? '') !== 'ready') { return; }
    $all = array_merge((array)($u['items'] ?? []), (array)($u['skipped'] ?? []));
    foreach ($all as &$it) { if (($it['skip'] ?? '') === 'hidden') { unset($it['skip']); } }
    unset($it);
    usort($all, function ($a, $b) { return [$b['dead'], $b['size']] <=> [$a['dead'], $a['size']]; });
    [$u['items'], $u['skipped']] = st_upd_split($all);
    st_json_write(ST_UPD, $u);
    st_sum_set('upd', ['n' => count($u['items'])]);
}

// Interactive search in Radarr / Sonarr and the filter. $mode 'upd': same or higher resolution, same HDR format or
// better, at least the chosen saving. 'repl' (a replacement for content nobody seeds, or a dead tracker): same or higher
// resolution and HDR, any size, and it must have seeders.
function st_search_item($key, $it, $mode) {
    $cfg = st_cfg();
    $info = ''; $dropped = [];
    $put = function ($state, $msg, $rows = [], $item = null) use ($key, &$info, &$dropped, $mode) {
        st_res_set($key, ['state' => $state, 't' => time(), 'rows' => $rows, 'msg' => $msg, 'item' => $item, 'info' => $info, 'dropped' => $dropped, 'mode' => $mode]);
    };
    $put('running', st_tr('f.searching'));
    if (empty($it['kind'])) { $put('error', $it['note'] ?? st_tr('u.not_matched')); return; }
    $q = $it['kind'] === 'radarr' ? '/release?movieId=' . $it['arr_id'] : '/release?seriesId=' . $it['arr_id'] . '&seasonNumber=' . $it['season'];
    // a single episode in the library: look for single-episode releases of that episode (a season pack would import the whole season)
    $epMode = false; $epInfo = ''; $epNum = 0;
    if ($it['kind'] === 'sonarr' && (count((array)($it['file_ids'] ?? [])) === 1 || (preg_match('/S\d{1,2}E\d{1,3}/i', (string)($it['name'] ?? '')) && !preg_match('/S\d{1,2}E\d{1,3}[-_.]?E\d{1,3}/i', (string)($it['name'] ?? ''))))) {
        $ep1 = null;
        if (!empty($it['file_ids'][0])) {
            [$ce, $eps1] = st_arr('sonarr', '/episode?seriesId=' . (int)$it['arr_id'] . '&episodeFileId=' . (int)$it['file_ids'][0]);
            if (is_array($eps1) && count($eps1) === 1 && !empty($eps1[0]['id'])) { $ep1 = $eps1[0]; }
        }
        if (!$ep1 && preg_match('/S(\d{1,2})E(\d{1,3})/i', (string)($it['name'] ?? ''), $mm)) {
            [$ce, $eps2] = st_arr('sonarr', '/episode?seriesId=' . (int)$it['arr_id'] . '&seasonNumber=' . (int)$mm[1]);
            foreach ((array)$eps2 as $e2) { if ((int)($e2['episodeNumber'] ?? -1) === (int)$mm[2] && !empty($e2['id'])) { $ep1 = $e2; } }
        }
        if ($ep1) {
            $epMode = true; $it['ep_id'] = (int)$ep1['id']; $q = '/release?episodeId=' . $it['ep_id'];
            $epNum = (int)($ep1['episodeNumber'] ?? 0);
            $epInfo = ' ' . st_tr('rs.ep1', ['e' => 'S' . str_pad((string)$it['season'], 2, '0', STR_PAD_LEFT) . 'E' . str_pad((string)(int)($ep1['episodeNumber'] ?? 0), 2, '0', STR_PAD_LEFT)]);
        } else { $epInfo = ' ' . st_tr('rs.ep_fail'); }
    }
    $t0 = microtime(true);
    [$code, $j, $err] = st_arr($it['kind'], $q, 'GET', null, 240);
    if ($err !== '' || !is_array($j)) { $put('error', $err ?: st_tr('e.bad_resp')); return; }
    // what Radarr/Sonarr judged with: the profile of this title (diagnostics for the rejections)
    $info = st_tr('rs.returned', ['k' => ucfirst($it['kind']), 'n' => count($j), 'e' => $epInfo, 's' => round(microtime(true) - $t0, 1)]);
    [$c1, $mv] = st_arr($it['kind'], ($it['kind'] === 'radarr' ? '/movie/' : '/series/') . (int)$it['arr_id']);
    if (is_array($mv) && isset($mv['qualityProfileId'])) {
        [$c2, $pf] = st_arr($it['kind'], '/qualityprofile/' . (int)$mv['qualityProfileId']);
        if (is_array($pf)) { $info .= '. ' . st_tr('rs.profile', ['p' => $pf['name'] ?? '?']) . (isset($pf['language']['name']) ? ', ' . st_tr('rs.lang', ['l' => $pf['language']['name']]) : '') . (isset($pf['upgradeAllowed']) ? ', ' . st_tr($pf['upgradeAllowed'] ? 'rs.upg_yes' : 'rs.upg_no') : ''); }
    }
    if (is_array($mv) && !empty($mv['originalLanguage']['name'])) { $info .= '. ' . st_tr('rs.orig', ['l' => $mv['originalLanguage']['name']]); }
    $saving = (float)$cfg['upd_saving'] / 100;
    $rows = []; $drop = [];
    $dr = function ($k, $r) use (&$drop, &$dropped) { $drop[$k] = ($drop[$k] ?? 0) + 1; if (count($dropped) < 12) { $dropped[] = ['title' => (string)($r['title'] ?? ''), 'why' => $k, 'size' => (float)($r['size'] ?? 0), 'seeders' => (int)($r['seeders'] ?? 0)]; } };
    foreach ($j as $r) {
        if (!is_array($r) || strtolower((string)($r['protocol'] ?? 'torrent')) !== 'torrent') { continue; }
        if ($it['kind'] === 'sonarr') {
            if ($epMode) {
                if (!empty($r['fullSeason']) || count((array)($r['episodeNumbers'] ?? [])) > 1) { $dr(st_tr('dr.pack'), $r); continue; }
                // Sonarr also lists the single-episode releases of the other episodes of the season: only this episode counts
                $rt = (string)($r['title'] ?? ''); $rs = (int)($r['seasonNumber'] ?? 0); $re = (array)($r['episodeNumbers'] ?? []);
                if ($epNum > 0) {
                    $okEp = $re ? in_array($epNum, array_map('intval', $re), true) : null;
                    if ($okEp === null && preg_match('/S(\d{1,2})E(\d{1,3})/i', $rt, $mq)) { $okEp = ((int)$mq[2] === $epNum && ((int)$it['season'] <= 0 || (int)$mq[1] === (int)$it['season'])); }
                    if ($okEp === false || ($rs > 0 && (int)$it['season'] > 0 && $rs !== (int)$it['season'])) { $dr(st_tr('dr.ep'), $r); continue; }
                }
            } elseif (empty($r['fullSeason'])) { $dr(st_tr('dr.notpack'), $r); continue; }
            else {
                // a season pack of this season only (Sonarr may list packs of other seasons)
                $rt = (string)($r['title'] ?? ''); $rs = (int)($r['seasonNumber'] ?? 0);
                if ((int)$it['season'] > 0 && $rs > 0 && $rs !== (int)$it['season']) { $dr(st_tr('dr.season'), $r); continue; }
                if ((int)$it['season'] > 0 && preg_match_all('/(?<![A-Za-z0-9])S(\d{1,2})(?![0-9]|E\d)/i', $rt, $mm2)) {
                    $ss = array_unique(array_map('intval', $mm2[1]));
                    if (count($ss) !== 1 || $ss[0] !== (int)$it['season']) { $dr(st_tr('dr.season'), $r); continue; }
                }
            }
        }
        $title = (string)($r['title'] ?? ''); $size = (float)($r['size'] ?? 0);
        if ($size <= 0 || st_norm($title) === st_norm($it['name'])) { continue; }
        $res_ = preg_match('/(2160|1080|720|480|576)[pi]/i', $title) ? st_res_of($title) : max((int)($r['quality']['quality']['resolution'] ?? 0), st_res_of($title)); // Radarr / Sonarr sometimes misjudge names without a resolution (BD25 and the like)
        $hdr = st_hdr_rank($title);
        if ($it['cur_res'] > 0 && $res_ > 0 && $res_ < $it['cur_res']) { $dr(st_tr('dr.lowres'), $r); continue; }
        if ($mode === 'upd' && $it['cur_res'] > 0 && $res_ <= 0) { $dr(st_tr('dr.nores'), $r); continue; } // a name Radarr / Sonarr cannot read (Az ötödik elem, 837 MB) is no offer
        if ($mode !== 'repl' && $hdr < $it['cur_hdr']) { $dr(!st_dv_all() && st_hdr_kind($title) === 'dv' ? st_tr('dr.dvonly') : st_tr('dr.lowhdr'), $r); continue; } // replacing unseeded content: any HDR format is fine, the same one is listed first
        $save = $it['cur_size'] > 0 ? 1 - $size / $it['cur_size'] : 0;
        $cats = [];
        if ($mode === 'upd') {
            // what the release offers: smaller by the saving, a better HDR format, a higher resolution (upgrades may grow by upd_upg_growth %)
            $fits = (float)$cfg['upd_upg_growth'] <= 0 || $it['cur_size'] <= 0 || $size <= $it['cur_size'] * (1 + (float)$cfg['upd_upg_growth'] / 100);
            if (!empty($cfg['upd_cat_save']) && $save >= $saving - 1e-9) { $cats[] = 'save'; } // 1e-9: exactly the saving counts (float rounding)
            if (!empty($cfg['upd_cat_hdr']) && $hdr > (int)$it['cur_hdr'] && $fits) { $cats[] = 'hdr'; }
            if (!empty($cfg['upd_cat_res']) && (int)$it['cur_res'] > 0 && $res_ > (int)$it['cur_res'] && $fits) { $cats[] = 'res'; }
            if (!$cats && empty($it['dead'])) { $dr(($hdr > (int)$it['cur_hdr'] || $res_ > (int)$it['cur_res']) && !$fits ? st_tr('dr.upg_big') : st_tr('dr.nosave'), $r); continue; }
        }
        if ($mode === 'repl' && (int)($r['seeders'] ?? 0) < 1) { $dr(st_tr('dr.noseed'), $r); continue; }
        if ($mode === 'repl' && (float)$cfg['repl_max_growth'] > 0 && $it['cur_size'] > 0 && $size > $it['cur_size'] * (1 + (float)$cfg['repl_max_growth'] / 100)) { $dr(st_tr('dr.big'), $r); continue; }
        $langs = []; foreach ((array)($r['languages'] ?? []) as $l) { $langs[] = (string)($l['name'] ?? ''); }
        $isHun = (bool)(preg_grep('/hungar/i', $langs) || preg_match('/\b(hun|hundub|magyar)\b/i', $title));
        $rows[] = ['guid' => (string)$r['guid'], 'indexer_id' => (int)($r['indexerId'] ?? 0), 'title' => $title, 'size' => $size, 'saving' => round($save * 100, 1),
                   'res' => $res_, 'hdr' => st_hdr_label($hdr), 'hdr_rank' => $hdr, 'hun' => $isHun,
                   'seeders' => (int)($r['seeders'] ?? 0), 'indexer' => (string)($r['indexer'] ?? ''), 'quality' => (string)($r['quality']['quality']['name'] ?? ''),
                   'rejected' => !empty($r['rejected']), 'rejections' => array_slice(array_map('strval', (array)($r['rejections'] ?? [])), 0, 3), 'cats' => $cats];
    }
    if ($mode === 'repl') {
        // equal or better: the closest size first keeps what you have
        $cur = (float)$it['cur_size'];
        $cr = (int)$it['cur_res']; $ch = (int)$it['cur_hdr'];
        $cat = function ($r) use ($cr, $ch) { return ($r['res'] === $cr && $r['hdr_rank'] === $ch) ? 0 : 1; }; // 0: the same resolution and HDR format
        // better HDR (DV first), then higher resolution, then the size closest to the current file
        usort($rows, function ($a, $b) use ($cur) { return [$b['hdr_rank'], $b['res'], abs($a['size'] - $cur)] <=> [$a['hdr_rank'], $a['res'], abs($b['size'] - $cur)]; });
    } else {
        // Hungarian audio first (not required: English audio with Hungarian subtitles is fine), then the better HDR format and resolution,
        // then the biggest release, which keeps the most quality
        usort($rows, function ($a, $b) { return [$b['hun'], $b['hdr_rank'], $b['res'], $b['size']] <=> [$a['hun'], $a['hdr_rank'], $a['res'], $a['size']]; });
    }
    $rows = array_slice($rows, 0, 10);
    $it['mode'] = $mode;
    $why = ''; if ($drop && count($rows)) { arsort($drop); $parts = []; foreach ($drop as $k => $v) { $parts[] = $v . ' ' . $k; } $info .= '. ' . st_tr('rs.filtered_out', ['l' => implode(', ', $parts)]); }
    if ($drop) { arsort($drop); $parts = []; foreach ($drop as $k => $v) { $parts[] = $v . ' ' . $k; } $why = ' ' . st_tr('rs.filtered_out', ['l' => implode(', ', $parts)]) . '.'; }
    $put('ready', count($rows) ? '' : st_tr('rs.no_offer') . ' ' . $info . '.' . $why, $rows, $it);
}

// ---- background check: after a scan every listed torrent is searched, one every ~90 seconds (the trackers must not see a burst),
// a result is kept for ST_UPD_FRESH; the page then lists the torrents with an offer first
const ST_UPDBG = '/tmp/qbt_tools_updbg.json';
const ST_UPD_FRESH = 10 * 86400;
function st_upd_bg_fresh($rr) { return is_array($rr) && in_array($rr['state'] ?? '', ['ready', 'error'], true) && ($rr['mode'] ?? 'upd') === 'upd' && time() - (int)($rr['t'] ?? 0) < (($rr['state'] ?? '') === 'error' ? 86400 : ST_UPD_FRESH); }
function st_upd_bg_count($upd, $res) {
    $tot = 0; $done = 0;
    foreach ((array)($upd['items'] ?? []) as $x) { if (empty($x['kind'])) { continue; } $tot++; if (st_upd_bg_fresh($res[$x['hash']] ?? null)) { $done++; } }
    return [$done, $tot];
}
function st_upd_bg($gap = 90) {
    @unlink('/tmp/qbt_tools_updbg.stop');
    while (true) {
        if (is_file('/tmp/qbt_tools_updbg.stop')) { @unlink('/tmp/qbt_tools_updbg.stop'); $w = st_json_read(ST_UPDBG, []); $w['state'] = 'stopped'; $w['t'] = time(); st_json_write(ST_UPDBG, $w); return; }
        $upd = st_json_read(ST_UPD, []); $res = st_json_read(ST_RES, []);
        if (($upd['state'] ?? '') === 'running') { sleep(10); continue; } // a new scan is running: wait for its list
        if (($upd['state'] ?? '') !== 'ready') { return; } // no list (the plugin was updated, /tmp cleared): the next scan starts a new search
        [$done, $tot] = st_upd_bg_count($upd, $res);
        $next = null;
        foreach ((array)$upd['items'] as $x) { if (!empty($x['kind']) && !st_upd_bg_fresh($res[$x['hash']] ?? null) && !(($res[$x['hash']]['state'] ?? '') === 'running' && time() - (int)($res[$x['hash']]['t'] ?? 0) < 900)) { $next = $x; break; } } // a search you started is not repeated
        if (!$next) { st_json_write(ST_UPDBG, ['state' => 'done', 't' => time(), 'done' => $done, 'total' => $tot]); return; }
        st_json_write(ST_UPDBG, ['state' => 'running', 't' => time(), 'done' => $done, 'total' => $tot, 'cur' => $next['name']]);
        $next['old_hash'] = $next['hash']; $next['old_path'] = '';
        st_search_item($next['hash'], $next, 'upd');
        for ($i = 0; $i < $gap; $i += 5) { if (is_file('/tmp/qbt_tools_updbg.stop')) { break; } sleep(5); }
    }
}

function st_upd_bg_spawn() {
    $php = '/usr/bin/php'; foreach (['/usr/bin/php', '/usr/local/bin/php'] as $p) { if (is_executable($p)) { $php = $p; break; } }
    if (getenv('DBX_ST_DIR')) { return; } // tests call st_upd_bg() themselves
    @exec('setsid nohup ' . escapeshellarg($php) . ' ' . escapeshellarg(__DIR__ . '/tools_run.php') . ' updbg >/dev/null 2>&1 &');
}

function st_upd_search($hash) {
    $upd = st_json_read(ST_UPD, []);
    foreach ((array)($upd['items'] ?? []) as $x) { if ($x['hash'] === $hash) { $x['old_hash'] = $hash; $x['old_path'] = ''; st_search_item($hash, $x, 'upd'); return; } }
    st_search_item($hash, ['kind' => '', 'note' => st_tr('u.n_notscan')], 'upd');
}

// Replacement for a folder or file that nobody seeds (or whose tracker removed the torrent)
function st_find_repl_search($itemId) {
    $find = st_json_read(ST_FIND, []);
    $unit = null; foreach ((array)($find['items'] ?? []) as $x) { if ($x['id'] === $itemId) { $unit = $x; } }
    if (!$unit) { st_search_item('f' . substr(md5($itemId), 0, 12), ['kind' => '', 'note' => st_tr('e.unknown_item')], 'repl'); return; }
    st_find_repl_unit($unit, st_arr_index());
}
function st_find_repl_unit($unit, $index, $quiet = false) {
    $key = 'f' . substr(md5($unit['path']), 0, 12);
    $vids = [];
    $out = (string)@shell_exec('find ' . escapeshellarg($unit['path']) . ' -type f -printf ' . escapeshellarg('%s\t%p\n') . ' 2>/dev/null');
    foreach (explode("\n", $out) as $l) { $p = explode("\t", $l, 2); if (count($p) === 2 && st_main_video($p[1])) { $vids[] = (float)$p[0]; } }
    $m = st_arr_match($vids, $unit['name'], $index);
    if (empty($m['kind']) && preg_match('/^(.+?)[. _(\[]((?:19|20)\d\d)(?=[. _)\]])/', (string)$unit['name'], $mm)) {
        // sizes differ from the library (another release is imported): match the movie by title and year
        $hits = (array)($index['byname'][st_norm($mm[1]) . (int)$mm[2]] ?? []);
        if (count($hits) === 1) {
            $h = $hits[0];
            $m = ['kind' => 'radarr', 'arr_id' => $h['id'], 'title' => $h['title'], 'season' => 0, 'file_ids' => [$h['file']], 'cur_size' => (float)$unit['size'],
                  'cur_res' => $h['res'] ?: st_res_of($unit['name']), 'cur_hdr' => max($h['hdr'], st_hdr_rank($unit['name'])), 'cur_quality' => $h['q'], 'media_paths' => [$h['path']]];
        }
    }
    if (empty($m['kind']) && preg_match('/^(.+?)[. _]+((?:19|20)\d\d)?[. _]*S(\d{1,2})E(\d{1,3})(?!\d)/i', (string)$unit['name'], $ms)) {
        // an episode whose library file is another release: match the series by name (and year when the folder name has one)
        $key2 = st_norm(preg_replace('/[. _]+(19|20)\d\d$/', '', $ms[1]));
        $cand = array_values((array)($index['sname'][$key2] ?? []));
        if ($ms[2] !== '') { $by = array_values(array_filter($cand, function ($c) use ($ms) { return !$c['year'] || (int)$c['year'] === (int)$ms[2]; })); if ($by) { $cand = $by; } }
        if (count($cand) === 1) {
            $sr = $cand[0]; $fid = 0;
            [$ce, $eps3] = st_arr('sonarr', '/episode?seriesId=' . (int)$sr['id'] . '&seasonNumber=' . (int)$ms[3]);
            foreach ((array)$eps3 as $e3) { if ((int)($e3['episodeNumber'] ?? -1) === (int)$ms[4]) { $fid = (int)($e3['episodeFileId'] ?? 0); } }
            $m = ['kind' => 'sonarr', 'arr_id' => $sr['id'], 'title' => $sr['title'] . ' S' . str_pad((string)(int)$ms[3], 2, '0', STR_PAD_LEFT) . st_ep_suffix((string)$unit['name']), 'season' => (int)$ms[3], 'file_ids' => $fid ? [$fid] : [],
                  'cur_size' => (float)$unit['size'], 'cur_res' => st_res_of($unit['name']), 'cur_hdr' => st_hdr_rank($unit['name']), 'cur_quality' => '', 'media_paths' => []];
        }
    }
    if ($quiet && empty($m['kind'])) { return; } // not in Radarr/Sonarr: the row keeps its Replace button, the reason shows when it is pressed
    $it = array_merge(['name' => $unit['name'], 'size' => $unit['size'], 'dead' => false, 'old_hash' => (string)($unit['old'] ?? ''), 'old_path' => $unit['path'], 'why' => (string)($unit['why'] ?? '')], $m);
    if ($m['kind'] === 'sonarr') { $it['cur_size'] = $unit['size']; }
    st_search_item($key, $it, 'repl');
}

// Why a title is being replaced, in one sentence (for Discord)
function st_job_reason($j) {
    if (($j['mode'] ?? '') === 'repl') {
        if (!empty($j['dead']) || ($j['why'] ?? '') === 'tracker removed it') { return st_l('Ok: a tracker törölte a torrentet, így a tartalom már nem seedelhető; helyette egyenértékű vagy jobb kiadás kerül be.', 'Cause: the tracker removed the torrent, so the content can no longer be seeded; an equal or better release replaces it.'); }
        return st_l('Ok: ehhez a mappához nem volt seedelt torrent és Prowlarr sem talált hozzá egyezőt; helyette egyenértékű vagy jobb kiadás kerül be.', 'Cause: no torrent seeded this folder and Prowlarr found no match; an equal or better release replaces it.');
    }
    return st_l('Ok: nagy és régi cím, az új kiadás ' . abs((int)($j['saving'] ?? 0)) . '%-kal kisebb, felbontás és DV/HDR nem romlik.', 'Cause: big and old title; the new release is ' . abs((int)($j['saving'] ?? 0)) . '% smaller with no loss of resolution or DV/HDR.');
}

// Sends a release to Radarr/Sonarr. The item (what is being replaced) comes from the stored search.
function st_replace_grab($key, $guid) {
    $res = st_json_read(ST_RES, []);
    $it = $res[$key]['item'] ?? null; $mode = $it['mode'] ?? '';
    $row = null; foreach ((array)($res[$key]['rows'] ?? []) as $r) { if ($r['guid'] === $guid) { $row = $r; } }
    if (!$it || !$row) { return [false, st_tr('e.unknown_rel')]; }
    $lk = st_action_lock(); if (!$lk) { return [false, st_tr('e.lock')]; }
    try { return st_replace_grab_do($key, $it, $mode, $row); } finally { st_action_unlock($lk); }
}
function st_replace_grab_do($key, $it, $mode, $row) {
    $state = st_state();
    foreach ($state['jobs'] as $j) { if ($j['key'] === $key && !in_array($j['stage'], ['removed', 'failed', 'cleaned'], true)) { return [false, st_tr('e.inprog')]; } }
    [$code, $j, $err] = st_arr($it['kind'], '/release', 'POST', ['guid' => $row['guid'], 'indexerId' => $row['indexer_id']], 60);
    if ($err !== '' && stripos($err, 'cache') !== false) {
        // Radarr/Sonarr keep a search result only for a short while: search again with the same settings and retry once
        st_search_item($key, $it, $mode);
        $res = st_json_read(ST_RES, []);
        $row2 = null; foreach ((array)($res[$key]['rows'] ?? []) as $r) { if ($r['guid'] === $row['guid'] || $r['title'] === $row['title']) { $row2 = $r; break; } }
        if (!$row2) { return [false, st_tr('e.rel_gone', ['k' => ucfirst($it['kind'])])]; }
        $row = $row2;
        [$code, $j, $err] = st_arr($it['kind'], '/release', 'POST', ['guid' => $row['guid'], 'indexerId' => $row['indexer_id']], 60);
    }
    if ($err !== '') { return [false, st_tr('e.rel_refused', ['k' => ucfirst($it['kind']), 'e' => $err])]; }
    $cfg = st_cfg();
    $media = [];
    foreach ((array)($it['media_paths'] ?? []) as $p) { if ($p !== '') { $media[] = st_to_host($p, $cfg); } }
    $job = ['id' => substr(md5($key . microtime()), 0, 10), 'key' => $key, 'mode' => $mode === 'repl' ? 'repl' : 'upd', 'hash' => (string)($it['old_hash'] ?? ''), 'old_path' => (string)($it['old_path'] ?? ''),
                        'kind' => $it['kind'], 'arr_id' => $it['arr_id'], 'season' => $it['season'], 'ep_id' => (int)($it['ep_id'] ?? 0), 'title' => $it['title'], 'old_name' => $it['name'], 'old_size' => $it['size'],
                        'file_ids' => $it['file_ids'], 'media_paths' => $media, 'new' => $row['title'], 'new_size' => $row['size'], 't' => time(), 'gt' => time(), 'stage' => 'grabbed', 'msg' => st_tr('job.sent', ['k' => ucfirst($it['kind'])]),
                        'why' => (string)($it['why'] ?? ''), 'dead' => !empty($it['dead']), 'saving' => $row['saving'] ?? 0, 'seeders' => $row['seeders'] ?? 0, 'q' => ($row['res'] ?? '') . 'p ' . ($row['hdr'] ?? '')];
    st_state_update(function (&$s) use ($job) { $s['jobs'][] = $job; });
    $j = $job;
    st_notify('i', st_l('Seedbox: csere elindítva', 'Seedbox: replacement started'), st_job_reason($j) . ' ' . st_l('Elküldtem a ' . ucfirst($j['kind']) . 'nak, az import után takarítok.', 'Sent to ' . ucfirst($j['kind']) . '; the old files are cleaned up after the import.'),
        [[st_l('Cím', 'Title'), $j['title']], [st_l('Régi', 'Old'), $j['old_name'] . ' (' . st_gb($j['old_size']) . ')'], [st_l('Új', 'New'), $j['new'] . ' (' . st_gb($j['new_size']) . ', ' . $j['q'] . ', ' . $j['seeders'] . ' seeder)']], false, st_ntool($j));
    return [true, ''];
}

// Tags the torrent that Radarr / Sonarr grabbed for a replacement job in qBittorrent: findmytorrent for a replacement of unseeded content,
// updateme for a smaller release. The torrent is recognised by its name (the release title) and by being added after the job started.
function st_job_tag($j, $tor) {
    $want = st_norm($j['new'] ?? ''); if ($want === '') { return false; }
    foreach ($tor as $t) {
        if (st_norm($t['name'] ?? '') !== $want || (int)($t['added_on'] ?? 0) < (int)($j['gt'] ?? $j['t']) - 600) { continue; }
        [$c] = st_qb('/api/v2/torrents/addTags', ['hashes' => $t['hash'], 'tags' => (($j['mode'] ?? '') === 'repl') ? 'findmytorrent' : 'updateme']);
        if ($c === 200) { return true; } // qBittorrent busy or unreachable: not tagged yet, the next check tries again
        continue;
    }
    return false;
}

// Activity summary of the replacements still downloading: bytes left of their torrents and qBittorrent's total download
// speed (the torrents share the line, so one ETA for all of them). Read-only: two GETs, only while something downloads.
// Radarr/Sonarr time left ('16.07:31:53' = 16 days 7 hours) in a readable form: '16 d 7 h', '1 h 17 m', '9 m'
function st_fmt_left($tl) {
    if (!preg_match('/^(?:(\d+)\.)?(\d+):(\d+):(\d+)/', $tl, $m)) { return $tl; }
    $d = (int)$m[1]; $h = (int)$m[2]; $mi = (int)$m[3];
    if ($d) { return $d . ' d ' . $h . ' h'; }
    if ($h) { return $h . ' h ' . $mi . ' m'; }
    return max(1, $mi) . ' m';
}
function st_dl_summary($s) {
    $want = []; $hashes = [];
    foreach ((array)($s['jobs'] ?? []) as $j) { if (in_array($j['stage'] ?? '', ['grabbed', 'downloading'], true)) { $want[st_norm($j['new'] ?? '')] = (string)($j['id'] ?? ''); } }
    foreach ((array)($s['packjobs'] ?? []) as $j) {
        if (!in_array($j['stage'] ?? '', ['grabbed', 'downloading'], true)) { continue; }
        if (!empty($j['hash'])) { $hashes[strtolower((string)$j['hash'])] = (string)($j['id'] ?? ''); } else { $want[st_norm($j['pack'] ?? '')] = (string)($j['id'] ?? ''); }
    }
    unset($want['']);
    $n = count($want) + count($hashes);
    if (!$n) { return null; }
    $tor = st_qb_json('/api/v2/torrents/info'); // all: a finished download may be moving or waiting for the import
    $ti = st_qb_json('/api/v2/transfer/info');
    if ($tor === null || $ti === null) { return ['jobs' => $n, 'err' => st_tr('e.qb_noanswer')]; }
    $left = 0; $found = 0; $stalled = 0; $seeds = 0; $swarm = 0; $stall = []; $per = [];
    $mv = 0.0; $mrate = 0; $last = 0.0; $lastEta = -1; // bytes still to move to the array, the measured move speed, the download that ends last
    $dl = array_flip(['downloading', 'metaDL', 'forcedMetaDL', 'stalledDL', 'checkingDL', 'pausedDL', 'stoppedDL', 'queuedDL', 'forcedDL', 'allocating']);
    foreach ($tor as $t) {
        $id = $hashes[strtolower((string)($t['hash'] ?? ''))] ?? $want[st_norm($t['name'] ?? '')] ?? null;
        if ($id === null) { continue; }
        // per job (its own Activity row): progress, bytes, speed and time left; a move gets its own measured progress
        $st = (string)($t['state'] ?? ''); $size = (float)($t['size'] ?? 0); $eta = (int)($t['eta'] ?? -1);
        if ($id !== '') {
            if ($st === 'moving') {
                [$mf, $me, $mw] = st_move_info($t);
                $per[$id] = ['st' => $mw ? 'movewait' : 'moving', 'p' => $mf, 'size' => $size, 'speed' => ($me > 0 ? (int)((1 - $mf) * $size / $me) : 0), 'eta' => $me];
                $mv += $mw ? $size : (1 - $mf) * $size;
                if ($per[$id]['speed'] > 0) { $mrate = max($mrate, $per[$id]['speed']); }
            } else {
                $per[$id] = ['st' => isset($dl[$st]) ? 'dl' : 'done', 'p' => (float)($t['progress'] ?? 0), 'size' => $size, 'done' => max(0, $size - (float)($t['amount_left'] ?? 0)),
                             'speed' => (int)($t['dlspeed'] ?? 0), 'eta' => ($eta > 0 && $eta < 8640000) ? $eta : -1];
            }
        }
        if (!isset($dl[$st])) { continue; } // the summary line counts what still downloads
        $found++; $left += (float)($t['amount_left'] ?? 0);
        // it downloads to an incomplete folder (cache): qBittorrent moves it to the save path when it is done, one torrent at a time
        $dp = rtrim((string)($t['download_path'] ?? ''), '/');
        if ($dp !== '' && $dp !== rtrim((string)($t['save_path'] ?? ''), '/')) {
            $mv += $size;
            if ($eta > $lastEta || ($eta < 0 && $lastEta >= 0)) { $lastEta = $eta < 0 ? PHP_INT_MAX : $eta; $last = $size; }
        }
        // per job: why this one does not move (shown on its own Activity row)
        if ((string)($t['state'] ?? '') === 'stalledDL') { $stalled++; if ($id !== '') { $stall[$id] = ['seeds' => max(0, (int)($t['num_seeds'] ?? 0)), 'swarm' => max(0, (int)($t['num_complete'] ?? 0))]; } }
        $seeds += max(0, (int)($t['num_seeds'] ?? 0)); $swarm += max(0, (int)($t['num_complete'] ?? 0));
    }
    // the move speed: measured now, otherwise the last measurement (kept a week; a guess is never shown)
    $rf = '/tmp/qbt_move_rate.json';
    if ($mrate > 0) { @file_put_contents($rf, json_encode(['t' => time(), 'r' => $mrate])); }
    elseif (is_file($rf)) { $r = json_decode((string)@file_get_contents($rf), true); if (is_array($r) && time() - (int)($r['t'] ?? 0) < 7 * 86400) { $mrate = (int)($r['r'] ?? 0); } }
    // stalled: qBittorrent's own state; seeds = seeders connected now, swarm = seeders the tracker lists
    return ['jobs' => $n, 'found' => $found, 'left' => $left, 'speed' => (int)($ti['dl_info_speed'] ?? 0), 'stalled' => $stalled, 'seeds' => $seeds, 'swarm' => $swarm, 'stall' => $stall, 'per' => $per,
            'mv' => $mv, 'mrate' => $mrate, 'mlast' => $last];
}

// Where is a replacement job: downloading / blocked (the *arr says why) / imported (the old file is gone)
function st_job_status($j) {
    $kind = $j['kind'];
    if ($kind === 'radarr') {
        [$c, $m] = st_arr('radarr', '/movie/' . (int)$j['arr_id']);
        if (!is_array($m)) { return null; }
        $fid = (int)($m['movieFile']['id'] ?? 0);
        if ($fid > 0 && !in_array($fid, $j['file_ids'], true)) { return ['stage' => 'imported', 'msg' => st_tr('job.imp_radarr'), 'msgd' => st_trd('job.imp_radarr')]; }
        $key = 'movieId';
    } else {
        $cur = [];
        if (!empty($j['ep_id'])) {
            [$c, $ep] = st_arr('sonarr', '/episode/' . (int)$j['ep_id']);
            if (!is_array($ep)) { return null; }
            if ((int)($ep['episodeFileId'] ?? 0) > 0) { $cur[] = (int)$ep['episodeFileId']; }
        } else {
            [$c, $files] = st_arr('sonarr', '/episodefile?seriesId=' . (int)$j['arr_id']);
            if (!is_array($files)) { return null; }
            foreach ($files as $f) { if ((int)$f['seasonNumber'] === (int)$j['season']) { $cur[] = (int)$f['id']; } }
        }
        if ($cur && !array_intersect($cur, $j['file_ids'])) { return ['stage' => 'imported', 'msg' => st_tr('job.imp_sonarr'), 'msgd' => st_trd('job.imp_sonarr')]; }
        $key = 'seriesId';
    }
    [$c, $q] = st_arr($kind, '/queue?page=1&pageSize=200');
    foreach ((array)($q['records'] ?? []) as $r) {
        if ((int)($r[$key] ?? 0) !== (int)$j['arr_id']) { continue; }
        if ($kind === 'sonarr' && isset($r['seasonNumber']) && (int)$r['seasonNumber'] !== (int)$j['season']) { continue; }
        if ($kind === 'sonarr' && !empty($j['ep_id']) && isset($r['episodeId']) && (int)$r['episodeId'] !== (int)$j['ep_id']) { continue; }
        $tds = (string)($r['trackedDownloadState'] ?? ''); $tdst = (string)($r['trackedDownloadStatus'] ?? '');
        if ($tds === 'importBlocked' || $tdst === 'error' || $tdst === 'warning') {
            $msgs = [];
            foreach ((array)($r['statusMessages'] ?? []) as $sm) { foreach ((array)($sm['messages'] ?? []) as $mm) { $msgs[] = (string)$mm; } if (!empty($sm['title']) && !$msgs) { $msgs[] = (string)$sm['title']; } }
            $wv = ['m' => $msgs ? implode(' / ', array_slice($msgs, 0, 2)) : ($tds ?: $tdst)]; return ['stage' => 'blocked', 'msg' => st_tr('job.waiting', $wv), 'msgd' => st_trd('job.waiting', $wv)];
        }
        $left = (float)($r['sizeleft'] ?? 0); $size = (float)($r['size'] ?? 0);
        $p = $size > 0 ? ($left > 0 ? min(99, (int)floor((1 - $left / $size) * 100)) : 100) : 0;
        $lt = !empty($r['timeleft']) ? ['t' => st_fmt_left((string)$r['timeleft'])] : null;
        return ['stage' => 'downloading', 'msg' => st_tr('job.dl', ['p' => $p]) . ($lt ? ', ' . st_tr('job.left', $lt) : ''), 'msgd' => st_trd('job.dl', ['p' => $p]) . ($lt ? ', ' . st_trd('job.left', $lt) : '')];
    }
    if (time() - (int)$j['t'] > 1800) { return ['stage' => 'blocked', 'msg' => st_tr('job.lost', ['k' => ucfirst($kind)]), 'msgd' => st_trd('job.lost', ['k' => ucfirst($kind)])]; }
    return null;
}

// ---- removing what was replaced ---------------------------------------------------------------------------------
const ST_TRASH = '.seedbox_trash';

function st_under_roots($path, $cfg) {
    $rp = realpath($path);
    if ($rp === false) { return false; }
    foreach ((array)$cfg['roots'] as $r) {
        $rr = realpath($r);
        if ($rr !== false && $rp !== $rr && strpos($rp, $rr . '/') === 0 && strpos($rp, '/' . ST_TRASH . '/') === false) { return $rr; }
    }
    return false;
}
// device:inode of a file ('' when it cannot be read, which never matches a real one)
function st_dev_ino($p) { $st = @stat($p); return $st ? $st['dev'] . ':' . $st['ino'] : ''; }
function st_rm_tree($p) {
    if (is_link($p) || is_file($p)) { return @unlink($p); }
    if (!is_dir($p)) { return true; }
    foreach (scandir($p) as $n) { if ($n !== '.' && $n !== '..') { st_rm_tree($p . '/' . $n); } }
    return @rmdir($p);
}

// After a replacement of unseeded content: the old folder or file goes to a trash folder inside its downloads folder
// (or is deleted at once when trash_days is 0), and so does the old hardlink in the media folder if the *arr left it.
// Returns [ok, message].
function st_cleanup_old($j) {
    $cfg = st_cfg(); $msg = []; $msgL = []; $oldIno = [];
    $add = function ($hu, $en, $k, $v = []) use (&$msg, &$msgL) { $msg[] = st_tr($k, $v); $msgL[] = st_l($hu, $en); }; // $msg: the page language, $msgL: the Discord language
    if ($j['hash'] !== '') { st_qb('/api/v2/torrents/delete', ['hashes' => $j['hash'], 'deleteFiles' => 'false']); st_watch_forget($j['hash']); $add('a régi torrent kikerült a qBittorrentből (a fájlok megmaradtak)', 'old torrent removed from qBittorrent (files kept)', 'cl.torrent'); }
    $p = (string)$j['old_path'];
    if ($p !== '' && file_exists($p)) {
        $root = st_under_roots($p, $cfg);
        if ($root === false) { return [false, st_tr('e.norm_root', ['p' => $p])]; }
        // never remove what a torrent seeds
        $rp = realpath($p);
        foreach ((array)st_qb_torrents() as $t) {
            if ($j['hash'] !== '' && strtolower((string)$t['hash']) === strtolower((string)$j['hash'])) { continue; } // the old torrent itself (just deleted, qBittorrent may still list it)
            $cp = rtrim(st_to_host((string)($t['content_path'] ?? ''), $cfg), '/');
            if ($cp !== '' && ($cp === $rp || strpos($cp, $rp . '/') === 0)) { return [false, st_tr('e.norm_seed', ['p' => $p, 't' => $t['name']])]; }
        }
        // inodes of the old files: a media link is only removed if it still points at one of them (never the freshly imported file)
        // keyed by device and inode: two array disks can hold the same inode number (item 104)
        if (is_file($rp)) { $oldIno[st_dev_ino($rp)] = true; } else { foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rp, FilesystemIterator::SKIP_DOTS)) as $f) { if ($f->isFile()) { $oldIno[st_dev_ino($f->getPathname())] = true; } } }
        $days = (int)($cfg['trash_days'] ?? 0);
        if ($days > 0) {
            $tr = $root . '/' . ST_TRASH; @mkdir($tr, 0777, true);
            $dest = $tr . '/' . date('YmdHis') . '_' . basename($rp);
            if (!@rename($rp, $dest)) { return [false, st_tr('e.notrash', ['p' => $p])]; }
            $add('a régi fájlok a ' . ST_TRASH . ' mappába kerültek (' . $days . ' nap után törlődnek)', 'old files moved to ' . ST_TRASH . ' (deleted after ' . $days . ' days)', 'cl.trash', ['f' => ST_TRASH, 'n' => $days]);
        } else {
            if (!st_rm_tree($rp)) { return [false, st_tr('e.nodelete', ['p' => $p])]; }
            $add('a régi fájlok törölve', 'old files deleted', 'cl.deleted');
        }
    }
    // the old hardlink in the media folder (Radarr/Sonarr normally removes it when it upgrades)
    $gone = 0; unset($oldIno['']);
    foreach ((array)($j['media_paths'] ?? []) as $mp) {
        if ($mp !== '' && is_file($mp) && strpos(realpath($mp), rtrim($cfg['map_host'], '/') . '/') === 0 && isset($oldIno[st_dev_ino($mp)])) { if (@unlink($mp)) { $gone++; } }
    }
    if ($gone) { $add($gone . ' régi hardlink törölve a média mappából', $gone . ' old media link(s) removed', 'cl.links', ['n' => $gone]); }
    return [true, implode('; ', $msg), implode('; ', $msgL)];
}

// deletes trash entries older than trash_days (called from tick, at most once an hour)
// Every .seedbox_trash folder: in the watched roots and in each folder of the downloads base (a folder whose role changed
// to Not watched may still hold one)
function st_trash_dirs() {
    $cfg = st_cfg(); $out = [];
    $cand = (array)$cfg['roots'];
    $base = rtrim((string)($cfg['dl_base'] ?? ''), '/');
    if ($base !== '' && is_dir($base)) { foreach ((array)@scandir($base) as $n) { if ($n !== '.' && $n !== '..' && is_dir($base . '/' . $n)) { $cand[] = $base . '/' . $n; } } }
    foreach ($cand as $r) { $tr = rtrim($r, '/') . '/' . ST_TRASH; $rp = realpath($tr); if ($rp !== false && is_dir($rp) && basename($rp) === ST_TRASH) { $out[$rp] = true; } }
    return array_keys($out);
}
// what the trash holds now: items (moved folders or files) and bytes
function st_trash_info() {
    $n = 0; $b = 0.0;
    foreach (st_trash_dirs() as $tr) { foreach ((array)@scandir($tr) as $x) { if ($x === '.' || $x === '..') { continue; } $n++; $b += st_dir_size($tr . '/' . $x); } }
    return ['items' => $n, 'bytes' => $b];
}
// Empty the trash now (the owner's button, with a confirm on the page): everything inside the .seedbox_trash folders goes
function st_trash_empty() {
    $n = 0; $fail = [];
    foreach (st_trash_dirs() as $tr) {
        foreach ((array)@scandir($tr) as $x) {
            if ($x === '.' || $x === '..') { continue; }
            if (st_rm_tree($tr . '/' . $x)) { $n++; } else { $fail[] = $x; }
        }
    }
    if ($n) { st_notify('i', st_l('Seedbox: kuka kiürítve', 'Seedbox: trash emptied'), st_l($n . ' elem véglegesen törölve a .seedbox_trash mappákból (kézi ürítés).', $n . ' item(s) deleted from the .seedbox_trash folders (emptied by hand).'), [], false, ''); }
    return [$n, $fail];
}

function st_trash_purge() {
    $mark = '/tmp/qbt_tools_purge';
    if (is_file($mark) && time() - (int)@filemtime($mark) < 3600) { return; }
    @touch($mark);
    $cfg = st_cfg(); $days = (int)($cfg['trash_days'] ?? 0);
    if ($days < 1) { return; }
    $purged = [];
    foreach ((array)$cfg['roots'] as $r) {
        $tr = rtrim($r, '/') . '/' . ST_TRASH;
        if (!is_dir($tr)) { continue; }
        foreach (scandir($tr) as $n) {
            if (!preg_match('/^(\d{14})_/', $n, $m)) { continue; }
            $t = DateTime::createFromFormat('YmdHis', $m[1]);
            if ($t && time() - $t->getTimestamp() > $days * 86400) { st_rm_tree($tr . '/' . $n); $purged[] = preg_replace('/^\d{14}_/', '', $n); }
        }
    }
    if ($purged) {
        st_notify('i', st_l('Seedbox: kuka kiürítve', 'Seedbox: trash emptied'), st_l($days . ' napnál régebbi elem véglegesen törölve a .seedbox_trash mappából (csere utáni régi fájlok).', 'Items older than ' . $days . ' days were permanently deleted from .seedbox_trash (old files left over from replacements).'),
            [[st_l('Törölve', 'Deleted'), implode("\n", array_slice($purged, 0, 10)) . (count($purged) > 10 ? "\n+" . (count($purged) - 10) : '')]], false, '');
    }
}

// Radarr refuses an import it does not see as an upgrade ("Not an upgrade"), which an equal replacement never is.
// This asks Radarr to import the finished download anyway (the ManualImport command); Radarr then replaces the old file.
function st_force_import($id) {
    $s = st_state(); $job = null; foreach ($s['jobs'] as $j) { if ($j['id'] === $id) { $job = $j; } }
    if (!$job) { return [false, st_tr('e.unknown_job')]; }
    if ($job['kind'] !== 'radarr') { return [false, st_tr('e.force_sonarr')]; }
    if ($job['stage'] !== 'blocked') { return [false, st_tr('e.notblocked')]; }
    [$c, $q, $e] = st_arr('radarr', '/queue?page=1&pageSize=200');
    $did = '';
    foreach ((array)($q['records'] ?? []) as $r) { if ((int)($r['movieId'] ?? 0) === (int)$job['arr_id'] && !empty($r['downloadId'])) { $did = (string)$r['downloadId']; } }
    if ($did === '') { return [false, st_tr('e.notqueue')]; }
    [$c, $list, $e] = st_arr('radarr', '/manualimport?downloadId=' . rawurlencode($did) . '&filterExistingFiles=false', 'GET', null, 120);
    if ($e !== '' || !is_array($list)) { return [false, st_tr('e.mi_list', ['e' => $e ?: st_tr('e.bad_resp')])]; }
    $files = [];
    foreach ($list as $m) {
        if (empty($m['path']) || !st_main_video((string)$m['path'])) { continue; }
        $files[] = ['path' => $m['path'], 'movieId' => (int)$job['arr_id'], 'quality' => $m['quality'] ?? null, 'languages' => $m['languages'] ?? [], 'releaseGroup' => (string)($m['releaseGroup'] ?? ''), 'downloadId' => $did];
    }
    if (count($files) !== 1) { return [false, st_tr('e.mi_count', ['n' => count($files)])]; }
    [$c, $r, $e] = st_arr('radarr', '/command', 'POST', ['name' => 'ManualImport', 'files' => $files, 'importMode' => 'auto'], 60);
    if ($e !== '') { return [false, st_tr('e.mi_refused', ['e' => $e])]; }
    st_state_update(function (&$s) use ($id) { foreach ($s['jobs'] as &$j) { if ($j['id'] === $id) { $j['msg'] = st_tr('job.forced'); $j['t'] = time(); } } unset($j); });
    return [true, ''];
}

// Name of another torrent whose content is the same as, inside, or around the content of torrent $hash ('' when none).
function st_shared_by($hash, $tors) {
    $hash = strtolower((string)$hash); $mine = '';
    foreach ($tors as $t) { if (strtolower((string)$t['hash']) === $hash) { $mine = rtrim((string)($t['content_path'] ?? ''), '/'); } }
    if ($mine === '') { return ''; }
    foreach ($tors as $t) {
        if (strtolower((string)$t['hash']) === $hash) { continue; }
        $cp = rtrim((string)($t['content_path'] ?? ''), '/');
        if ($cp !== '' && ($cp === $mine || strpos($cp, $mine . '/') === 0 || strpos($mine, $cp . '/') === 0)) { return (string)$t['name']; }
    }
    return '';
}

function st_job_remove_old($id) {
    $lk = st_action_lock(); if (!$lk) { return [false, st_tr('e.lock')]; }
    try { return st_job_remove_old_do($id); } finally { st_action_unlock($lk); }
}
function st_job_remove_old_do($id) {
    $j = null; foreach (st_state()['jobs'] as $x) { if ($x['id'] === $id) { $j = $x; } }
    if (!$j) { return [false, st_tr('e.unknown_job')]; }
    if (in_array($j['stage'], ['removed', 'cleaned'], true)) { return [false, st_tr('e.already_rm')]; }
    if ($j['stage'] !== 'imported') { return [false, st_tr('e.notimp')]; }
    $set = function ($stage, $msg, $msgd = null) use ($id) { st_state_update(function (&$s) use ($id, $stage, $msg, $msgd) { foreach ($s['jobs'] as &$x) { if ($x['id'] === $id) { $x['stage'] = $stage; $x['msg'] = $msg; $x['msgd'] = $msgd ?? $msg; $x['t'] = time(); } } unset($x); }); };
    if ($j['mode'] === 'repl') {
        [$ok, $m, $mL] = st_cleanup_old($j); if (!$ok) { return [false, $m]; }
        $set('cleaned', $m, $mL);
        st_notify('g', st_l('Seedbox: régi fájlok eltávolítva', 'Seedbox: old files removed'), $mL, [[st_l('Cím', 'Title'), $j['title']], [st_l('Régi', 'Old'), $j['old_name']]], false, st_ntool($j));
        return [true, ''];
    }
    // another torrent may seed the same files (cross-seed on a second tracker): then only the old torrent goes, the files stay
    $tors = st_qb_torrents();
    if ($tors === null) { return [false, st_tr('e.qb_nodel')]; }
    $share = st_shared_by($j['hash'], $tors);
    // nothing else seeds it: the whole folder of the old torrent goes (torrent files and what came with them: nfo, images,
    // subtitles), to the trash like Find my torrent replacements (owner's decision 2026-10-11)
    $cp = '';
    foreach ($tors as $t) { if (strtolower((string)$t['hash']) === strtolower((string)$j['hash'])) { $cp = rtrim(st_to_host((string)($t['content_path'] ?? ''), st_cfg()), '/'); } }
    if ($share === '' && $cp !== '' && file_exists($cp) && st_under_roots($cp, st_cfg()) !== false) {
        $j2 = $j; $j2['old_path'] = $cp; $j2['media_paths'] = [];
        [$ok, $m, $mL] = st_cleanup_old($j2);
        if (!$ok) { return [false, $m]; }
        $m = str_replace(st_tr('cl.torrent'), st_tr('cl.torrent_rm'), $m); $mL = str_replace(' (a fájlok megmaradtak)', '', $mL); // the folder went to the trash right after
        st_upd_drop((string)$j['hash']);
        $set('removed', $m, $mL);
        st_notify('g', st_l('Seedbox: a régi torrent és fájljai törölve', 'Seedbox: the old torrent and its files were deleted'), $mL,
            [[st_l('Cím', 'Title'), $j['title']], [st_l('Régi', 'Old'), $j['old_name'] . ' (' . st_gb($j['old_size']) . ')'], [st_l('Új', 'New'), $j['new']]], false, st_ntool($j));
        return [true, ''];
    }
    // with the trash on, files that cannot go to the trash (outside the watched folders, path not found) stay: qBittorrent
    // must not delete them for good (item 104); only with trash_days 0 does qBittorrent delete them
    $out = $share === '' && (int)(st_cfg()['trash_days'] ?? 0) > 0;
    [$c, $b, $e] = st_qb('/api/v2/torrents/delete', ['hashes' => $j['hash'], 'deleteFiles' => ($share === '' && !$out) ? 'true' : 'false']);
    if ($c !== 200) { return [false, st_tr('e.qb_refused', ['e' => $e ?: 'HTTP ' . $c])]; }
    st_watch_forget((string)$j['hash']);
    st_upd_drop((string)$j['hash']);
    if ($out) {
        $pp = $cp !== '' ? $cp : (string)$j['old_name'];
        $set('removed', st_tr('job.rm_outside', ['p' => $pp]), st_trd('job.rm_outside', ['p' => $pp]));
        st_notify('g', st_l('Seedbox: a régi torrent törölve, a fájlok maradtak', 'Seedbox: the old torrent was deleted, the files stay'), st_trd('job.rm_outside', ['p' => $pp]),
            [[st_l('Cím', 'Title'), $j['title']], [st_l('Régi', 'Old'), $j['old_name'] . ' (' . st_gb($j['old_size']) . ')'], [st_l('Új', 'New'), $j['new']]], false, st_ntool($j));
        return [true, ''];
    }
    $set('removed', $share === '' ? st_tr('job.rm_all') : st_tr('job.rm_kept', ['t' => $share]), $share === '' ? st_trd('job.rm_all') : st_trd('job.rm_kept', ['t' => $share]));
    st_notify('g', $share === '' ? st_l('Seedbox: a régi torrent és fájljai törölve', 'Seedbox: the old torrent and its files were deleted') : st_l('Seedbox: a régi torrent törölve, a fájlok maradtak', 'Seedbox: the old torrent was deleted, the files stay'), st_l('A jóváhagyásod után, mert az új kiadás már importálva van.', 'After your approval, because the new release is already imported.') . ($share === '' ? '' : ' ' . st_l('A fájlokat egy másik torrent is seedeli: ' . $share, 'Another torrent seeds the same files: ' . $share)),
        [[st_l('Cím', 'Title'), $j['title']], [st_l('Régi', 'Old'), $j['old_name'] . ' (' . st_gb($j['old_size']) . ')'], [st_l('Új', 'New'), $j['new']]], false, st_ntool($j));
    return [true, ''];
}

// Forget every finished entry in the Results list (running jobs and pending checks stay).
function st_clear_results() {
    st_state_update(function (&$s) {
        $s['pending'] = array_values(array_filter($s['pending'], function ($p) { return !in_array($p['stage'], ['done', 'failed'], true); }));
        $s['jobs'] = array_values(array_filter($s['jobs'], function ($j) { return !in_array($j['stage'], ['removed', 'failed', 'cleaned'], true); }));
    });
}
