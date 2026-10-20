<?php
// Season packs: replaces the individually downloaded episodes of a season with the season pack.
// Scan (read-only) -> list; one click on "Replace with pack" starts a job that the tick advances:
//   grabbed -> downloading -> (pack 100 % and checked) -> importing (season files deleted in Sonarr, pack imported) -> cleaned
// Nothing is deleted before the pack is fully downloaded and its file list covers every episode you have.
const ST_SP = '/tmp/qbt_tools_sp.json';

// the reason codes of st_sp_item (also the keys of the skipped counts) in the page language
function st_sp_why($w) {
    $k = ['airing' => 'spw.airing', 'already a pack' => 'spw.pack', 'no pack' => 'spw.nopack', 'search failed' => 'spw.search', 'Sonarr did not answer' => 'spw.noanswer', 'nothing to replace' => 'spw.nothing'][$w] ?? '';
    return $k !== '' ? st_tr($k) : $w;
}
function st_sp_ep($s, $e) { return 'S' . str_pad((string)(int)$s, 2, '0', STR_PAD_LEFT) . 'E' . str_pad((string)(int)$e, 2, '0', STR_PAD_LEFT); }

// episode numbers (of the given season) that appear in a list of file names
function st_sp_epnums($names, $season) {
    $r = [];
    foreach ($names as $n) {
        if (!st_video_ext($n)) { continue; }
        if (preg_match('/S(\d{1,2})E(\d{1,3})(?:[-_.]?E(\d{1,3}))?/i', $n, $m) && (int)$m[1] === (int)$season) {
            $r[(int)$m[2]] = true; if (!empty($m[3]) && (int)$m[3] > (int)$m[2] && (int)$m[3] - (int)$m[2] < 5) { for ($k = (int)$m[2]; $k <= (int)$m[3]; $k++) { $r[$k] = true; } }
        }
    }
    return array_keys($r);
}

// ranks the season packs Sonarr found: resolution not lower than the current one, the same resolution first, then HDR (DV, HDR, SDR), then seeders
function st_sp_rank($rel, $cur_res, $need_hun, $season = 0) {
    $rows = []; $drop = [];
    foreach ((array)$rel as $r) {
        if (!is_array($r) || strtolower((string)($r['protocol'] ?? 'torrent')) !== 'torrent') { continue; }
        if (empty($r['fullSeason'])) { continue; }
        $title = (string)($r['title'] ?? ''); $size = (float)($r['size'] ?? 0);
        if ($size <= 0) { continue; }
        // the pack must be of this season (Sonarr may list packs of other seasons): its own season field, else the S01 in the name; multi-season packs are not used
        if ($season > 0) {
            $rs = (int)($r['seasonNumber'] ?? 0);
            if ($rs > 0 && $rs !== (int)$season) { $drop[st_tr('dr.season')] = ($drop[st_tr('dr.season')] ?? 0) + 1; continue; }
            if (preg_match_all('/(?<![A-Za-z0-9])S(\d{1,2})(?![0-9]|E\d)/i', $title, $mm)) {
                $ss = array_unique(array_map('intval', $mm[1]));
                if (count($ss) !== 1 || $ss[0] !== (int)$season) { $drop[st_tr('dr.season')] = ($drop[st_tr('dr.season')] ?? 0) + 1; continue; }
            }
        }
        $res = (int)($r['quality']['quality']['resolution'] ?? 0) ?: st_res_of($title);
        if ($cur_res > 0 && $res > 0 && $res < $cur_res) { $drop[st_tr('dr.lowres')] = ($drop[st_tr('dr.lowres')] ?? 0) + 1; continue; }
        if ((int)($r['seeders'] ?? 0) < 1) { $drop[st_tr('dr.noseed')] = ($drop[st_tr('dr.noseed')] ?? 0) + 1; continue; }
        $langs = []; foreach ((array)($r['languages'] ?? []) as $l) { $langs[] = (string)($l['name'] ?? ''); }
        $hun = (bool)(preg_grep('/hungar/i', $langs) || preg_match('/\b(hun|hundub|magyar)\b/i', $title));
        if ($need_hun && !$hun) { $drop[st_tr('dr.nohun')] = ($drop[st_tr('dr.nohun')] ?? 0) + 1; continue; }
        $hdr = st_hdr_rank($title);
        $rows[] = ['guid' => (string)$r['guid'], 'indexer_id' => (int)($r['indexerId'] ?? 0), 'title' => $title, 'size' => $size, 'res' => $res, 'hdr' => st_hdr_label($hdr), 'hdr_rank' => $hdr,
                   'hun' => $hun, 'seeders' => (int)($r['seeders'] ?? 0), 'indexer' => (string)($r['indexer'] ?? '')];
    }
    usort($rows, function ($a, $b) use ($cur_res) {
        $ea = ($cur_res > 0 && $a['res'] === $cur_res) ? 0 : 1; $eb = ($cur_res > 0 && $b['res'] === $cur_res) ? 0 : 1;
        return [$ea, -$a['hdr_rank'], -$a['seeders']] <=> [$eb, -$b['hdr_rank'], -$b['seeders']];
    });
    return [array_slice($rows, 0, 6), $drop];
}

// qBittorrent torrents that hold single episodes of this season of this series (by name)
function st_sp_old_torrents($title, $season, $epnums, $tor) {
    $sn = st_norm(preg_replace('/\s*\(\d{4}\)\s*$/', '', $title)); $out = [];
    if ($sn === '') { return $out; }
    foreach ((array)$tor as $t) {
        $name = (string)($t['name'] ?? '');
        if (strpos(st_norm($name), $sn) !== 0) { continue; }
        if (!preg_match('/S(\d{1,2})E(\d{1,3})/i', $name, $m) || (int)$m[1] !== (int)$season || !in_array((int)$m[2], $epnums, true)) { continue; }
        if (preg_match('/S\d{1,2}E\d{1,3}[-_.]?E\d{1,3}/i', $name)) { continue; } // multi-episode: not handled
        $out[] = ['hash' => strtolower((string)$t['hash']), 'name' => $name, 'size' => (float)($t['size'] ?? 0), 'ep' => (int)$m[2], 'path' => (string)($t['content_path'] ?? ''), 'state' => (string)($t['state'] ?? '')];
    }
    usort($out, function ($a, $b) { return [$a['ep'], $a['name']] <=> [$b['ep'], $b['name']]; });
    return $out;
}

// is a season of this series seeded as one torrent already? A torrent counts when its name starts with the series (no single episode) and its file list
// holds at least 80 % of the episode numbers we have
function st_sp_seeded_pack($title, $season, $epnums, $tor) {
    $sn = st_norm(preg_replace('/\s*\(\d{4}\)\s*$/', '', $title)); if ($sn === '' || !$tor) { return ''; }
    $need = max(2, (int)ceil(count($epnums) * 0.8)); $n = 0;
    foreach ((array)$tor as $t) {
        $name = (string)($t['name'] ?? '');
        if (strpos(st_norm($name), $sn) !== 0) { continue; }
        if (preg_match('/S\d{1,2}E\d{1,3}/i', $name) && !preg_match('/S\d{1,2}E\d{1,3}[-_.]?E\d{1,3}/i', $name)) { continue; } // a single episode
        if (++$n > 12) { break; }
        // the name may not name the season (a whole-series torrent like "The Shield 1080p"): the file list decides; fetched once per torrent
        $hk = strtolower((string)$t['hash']);
        if (!isset($GLOBALS['st_sp_fc'][$hk])) { $GLOBALS['st_sp_fc'][$hk] = st_qb_json('/api/v2/torrents/files?hash=' . rawurlencode($hk)); }
        $fl = $GLOBALS['st_sp_fc'][$hk];
        if (!is_array($fl)) { continue; }
        $have = array_intersect(st_sp_epnums(array_map(function ($f) { return (string)($f['name'] ?? ''); }, $fl), $season), $epnums);
        if (count($have) >= $need) { return $name; }
    }
    return '';
}

function st_sp_put($state, $msg, $items, $extra = []) {
    st_json_write(ST_SP, array_merge(['state' => $state, 't' => time(), 'msg' => $msg, 'items' => $items], $extra));
}

// One season of a series -> [item, ''] when it is kept as single episodes and Sonarr finds a season pack for it, else [null, reason]
// ('' = not counted). $s: the Sonarr series, $list: its episodes of this season, $fby: its episode files by id, $min: episodes you need.
// An incomplete season counts too (item 6, 2026-10-16: on nCore the season finale often comes only in the pack): the pack must hold
// every episode you have (checked again after the download), the missing ones are imported from it by Sonarr.
function st_sp_item($s, $sn, $list, $fby, $tor, $min = 2) {
    $now = time();
    $have = array_values(array_filter($list, function ($x) use ($fby) { return !empty($x['hasFile']) && isset($fby[(int)($x['episodeFileId'] ?? 0)]); }));
    if (count($have) < $min) { return [null, '']; }
    $future = false; $missing = [];
    foreach ($list as $x) {
        $ts = !empty($x['airDateUtc']) ? strtotime($x['airDateUtc']) : 0;
        if ($ts === 0 || $ts > $now) { $future = true; }
        elseif (empty($x['hasFile']) && !empty($x['monitored'])) { $missing[] = (int)$x['episodeNumber']; }
    }
    if ($future) { return [null, 'airing']; }
    sort($missing);
    // already imported from one download? then it is a pack (or at least seeded as one torrent)
    [$c3, $hist] = st_arr('sonarr', '/history/series?seriesId=' . (int)$s['id'] . '&seasonNumber=' . (int)$sn . '&eventType=3', 'GET', null, 60);
    if (is_array($hist) && $hist) {
        $by = []; foreach ($hist as $h) { if (!empty($h['downloadId'])) { $by[$h['downloadId']][(int)($h['episodeId'] ?? 0)] = 1; } }
        $mx = 0; foreach ($by as $v) { $mx = max($mx, count($v)); }
        if ($mx >= max(2, (int)ceil(count($have) * 0.8))) { return [null, 'already a pack']; }
    }
    $nums0 = array_map(function ($x) { return (int)$x['episodeNumber']; }, $have);
    if (st_sp_seeded_pack((string)$s['title'], $sn, $nums0, $tor) !== '') { return [null, 'already a pack']; }
    $first = $fby[(int)$have[0]['episodeFileId']];
    $cur_res = (int)($first['quality']['quality']['resolution'] ?? 0);
    $langs = []; foreach ((array)($first['languages'] ?? []) as $l) { $langs[] = (string)($l['name'] ?? ''); }
    $need_hun = (bool)preg_grep('/hungar/i', $langs);
    $ef = []; $nums = []; $tot = 0; $hdr = 1;
    foreach ($have as $x) {
        $f = $fby[(int)$x['episodeFileId']];
        $ef[] = ['file' => (int)$f['id'], 'ep_id' => (int)$x['id'], 'ep' => (int)$x['episodeNumber'], 'path' => (string)($f['path'] ?? ''), 'size' => (float)($f['size'] ?? 0), 'monitored' => !empty($x['monitored'])];
        $nums[] = (int)$x['episodeNumber']; $tot += (float)($f['size'] ?? 0);
        $hdr = max($hdr, st_hdr_rank($f['mediaInfo']['videoDynamicRangeType'] ?? ''));
    }
    [$c4, $rel, $re] = st_arr('sonarr', '/release?seriesId=' . (int)$s['id'] . '&seasonNumber=' . (int)$sn, 'GET', null, 240);
    if ($re !== '' || !is_array($rel)) { return [null, 'search failed']; }
    [$packs, $drop] = st_sp_rank($rel, $cur_res, $need_hun, (int)$sn);
    if (!$packs) { return [null, 'no pack']; }
    $old = st_sp_old_torrents((string)$s['title'], $sn, $nums, $tor);
    return [['key' => 'sp' . substr(md5($s['id'] . ':' . $sn), 0, 10), 'series_id' => (int)$s['id'], 'title' => (string)$s['title'], 'season' => (int)$sn,
             'cur_res' => $cur_res, 'cur_quality' => (string)($first['quality']['quality']['name'] ?? ''), 'cur_hdr' => $hdr, 'cur_size' => $tot, 'need_hun' => $need_hun,
             'files' => $ef, 'missing' => $missing, 'old' => $old, 'old_size' => array_sum(array_column($old, 'size')), 'packs' => $packs, 'dropped' => $drop], ''];
}

function st_sp_scan($filter = '') {
    $GLOBALS['st_sp_fc'] = [];
    $filter = trim((string)$filter); $fn = st_norm($filter); $items = [];
    st_sp_put('running', st_tr('run.sonarr'), []);
    [$c, $series, $e] = st_arr('sonarr', '/series', 'GET', null, 120);
    if ($e !== '' || !is_array($series)) { st_sp_put('error', 'Sonarr: ' . ($e ?: st_tr('e.bad_resp')), []); return; }
    $tor = st_lib_in(st_qb_torrents(), st_cfg()); $now = time(); $n = 0; $total = count($series); $skipped = ['airing' => 0, 'already a pack' => 0, 'no pack' => 0];
    foreach ($series as $s) {
        $n++;
        if (empty($s['monitored'])) { continue; } // finished series you keep (unmonitored) are left alone
        $smon = []; foreach ((array)($s['seasons'] ?? []) as $ss) { $smon[(int)$ss['seasonNumber']] = !empty($ss['monitored']); }
        if ($fn !== '' && strpos(st_norm($s['title'] ?? ''), $fn) === false) { continue; }
        st_sp_put('running', st_tr('run.sp_n', ['n' => $n, 'm' => $total, 't' => $s['title'] ?? '', 'f' => count($items)]), $items, ['skipped' => $skipped]);
        [$c1, $files] = st_arr('sonarr', '/episodefile?seriesId=' . (int)$s['id'], 'GET', null, 60);
        [$c2, $eps] = st_arr('sonarr', '/episode?seriesId=' . (int)$s['id'], 'GET', null, 60);
        if (!is_array($files) || !is_array($eps)) { continue; }
        $fby = []; foreach ($files as $f) { $fby[(int)$f['id']] = $f; }
        $bySeason = [];
        foreach ($eps as $ep) { if ((int)$ep['seasonNumber'] > 0) { $bySeason[(int)$ep['seasonNumber']][] = $ep; } }
        foreach ($bySeason as $sn => $list) {
            if (isset($smon[$sn]) && !$smon[$sn]) { continue; } // unmonitored season
            [$it, $why] = st_sp_item($s, (int)$sn, $list, $fby, $tor);
            if ($it) { $items[] = $it; } elseif ($why !== '') { $skipped[$why] = ($skipped[$why] ?? 0) + 1; }
        }
    }
    st_sp_put('ready', '', $items, ['skipped' => $skipped, 'filter' => $filter]);
    if ($filter === '') { st_sum_set('rep', ['n' => count($items)]); }
}

// ---- the job --------------------------------------------------------------------------------------------------
function st_sp_start($key, $guid = '') {
    $sp = st_json_read(ST_SP, []); $it = null;
    foreach ((array)($sp['items'] ?? []) as $x) { if ($x['key'] === $key) { $it = $x; } }
    foreach (st_sp_ab_list() as $x) { if ($x['key'] === $key) { $it = $x; } } // a pack from autobrr is the newer find
    if (!$it) { return [false, st_tr('e.sp_season')]; }
    $pack = null; foreach ($it['packs'] as $p) { if ($guid === '' ? $pack === null : $p['guid'] === $guid) { $pack = $p; if ($guid === '') { break; } } }
    if (!$pack) { return [false, st_tr('e.sp_pack')]; }
    $lk = st_action_lock(); if (!$lk) { return [false, st_tr('e.lock')]; }
    try { return st_sp_start_do($key, $it, $pack); } finally { st_action_unlock($lk); }
}
function st_sp_start_do($key, $it, $pack) {
    $state = st_state();
    foreach ((array)($state['packjobs'] ?? []) as $j) { if ($j['key'] === $key && !in_array($j['stage'], ['done', 'failed', 'blocked'], true)) { return [false, st_tr('e.sp_busy')]; } }
    [$code, $r, $err] = st_arr('sonarr', '/release', 'POST', ['guid' => $pack['guid'], 'indexerId' => $pack['indexer_id']], 60);
    if ($err !== '' && stripos($err, 'cache') !== false) {
        // the search result expired in Sonarr: search again and use the same release
        [$c4, $rel] = st_arr('sonarr', '/release?seriesId=' . (int)$it['series_id'] . '&seasonNumber=' . (int)$it['season'], 'GET', null, 240);
        $g2 = ''; foreach ((array)$rel as $x) { if (is_array($x) && ($x['guid'] ?? '') === $pack['guid'] || (($x['title'] ?? '') === $pack['title'] && !empty($x['fullSeason']))) { $g2 = (string)$x['guid']; $pack['indexer_id'] = (int)($x['indexerId'] ?? $pack['indexer_id']); } }
        if ($g2 === '') { return [false, st_tr('e.sp_gone')]; }
        [$code, $r, $err] = st_arr('sonarr', '/release', 'POST', ['guid' => $g2, 'indexerId' => $pack['indexer_id']], 60);
    }
    if ($err !== '') { return [false, st_tr('e.rel_refused', ['k' => 'Sonarr', 'e' => $err])]; }
    $job = ['id' => substr(md5($key . microtime()), 0, 10), 'key' => $key, 'series_id' => $it['series_id'], 'title' => $it['title'], 'season' => $it['season'],
        'pack' => $pack['title'], 'pack_size' => $pack['size'], 'files' => $it['files'], 'old' => $it['old'], 'cur_res' => $it['cur_res'], 'stage' => 'grabbed', 'msg' => st_tr('pk.sent'),
        't' => time(), 'gt' => time(), 'hash' => ''];
    st_state_update(function (&$s) use ($job) { $s['packjobs'][] = $job; });
    st_notify('i', st_l('Seedbox: évadpakk csere elindítva', 'Seedbox: season pack replacement started'), st_l('A pakk letöltése után, ellenőrzéssel cserélem az epizódokat; addig semmi nem törlődik.', 'The episodes are replaced after the pack is downloaded and checked; nothing is deleted before that.'),
        [[st_l('Sorozat', 'Series'), $it['title'] . ' ' . 'S' . str_pad((string)$it['season'], 2, '0', STR_PAD_LEFT)], [st_l('Pakk', 'Pack'), $pack['title'] . ' (' . st_gb($pack['size']) . ', ' . $pack['seeders'] . ' seeder)']], false, 'rep');
    return [true, ''];
}

// episode ids -> monitored on/off (Sonarr bulk call)
function st_sp_monitor($ids, $on) {
    if (!$ids) { return ''; }
    [$c, $r, $e] = st_arr('sonarr', '/episode/monitor', 'PUT', ['episodeIds' => array_values($ids), 'monitored' => (bool)$on], 60);
    return $e;
}
function st_sp_fail(&$j, $msg) {
    $j['stage'] = 'blocked'; $j['msg'] = $msg; $j['t'] = time();
    st_notify('a', st_l('Seedbox: az évadpakk csere elakadt', 'Seedbox: season pack replacement is stuck'), $msg,
        [[st_l('Sorozat', 'Series'), $j['title'] . ' S' . str_pad((string)$j['season'], 2, '0', STR_PAD_LEFT)], [st_l('Pakk', 'Pack'), $j['pack']]], false, 'rep');
}

// advances one job; returns true when it changed
function st_sp_step(&$j, $tor) {
    $stage = $j['stage']; $cfg = st_cfg();
    if (in_array($stage, ['done', 'failed', 'blocked'], true)) { return false; }
    $by = []; foreach ((array)$tor as $t) { $by[strtolower((string)$t['hash'])] = $t; }
    $pt = null;
    if (!empty($j['hash'])) { $pt = $by[$j['hash']] ?? null; }
    else {
        foreach ((array)$tor as $t) { if (st_norm($t['name'] ?? '') === st_norm($j['pack']) && (int)($t['added_on'] ?? 0) >= (int)$j['gt'] - 600) { $pt = $t; $j['hash'] = strtolower((string)$t['hash']); st_qb('/api/v2/torrents/addTags', ['hashes' => $t['hash'], 'tags' => 'seasonrepacker']); break; } }
    }
    if ($stage === 'grabbed' || $stage === 'downloading') {
        if (!$pt) {
            if (time() - (int)$j['gt'] > 1800) { st_sp_fail($j, st_tr('pk.nodl')); return true; }
            return false;
        }
        $prog = (float)$pt['progress'];
        if ($prog < 0.99999) { $m = st_tr('job.dl', ['p' => number_format($prog * 100, 1, '.', '')]); if ($j['stage'] !== 'downloading' || $m !== $j['msg']) { $j['stage'] = 'downloading'; $j['msg'] = $m; return true; } return false; }
        if (preg_match('/^(checking|allocating|moving|metaDL)/', (string)$pt['state'])) {
            $m = 'downloaded, ' . (string)$pt['state'];
            if ((string)$pt['state'] === 'moving') { [$fr, $eta] = st_move_info($pt); $m = 'moving ' . number_format($fr * 100, 1, '.', '') . '%' . ($eta >= 0 ? ', about ' . st_move_eta_txt($eta) . ' left' : ''); }
            if ($m !== $j['msg']) { $j['msg'] = $m; return true; }
            return false;
        }
        // downloaded: does its file list cover every episode we have?
        $fl = st_qb_json('/api/v2/torrents/files?hash=' . rawurlencode($j['hash']));
        if (!is_array($fl) || !$fl) { return false; }
        $names = array_map(function ($f) { return (string)($f['name'] ?? ''); }, $fl);
        $packEps = st_sp_epnums($names, $j['season']);
        $want = array_map(function ($f) { return (int)$f['ep']; }, $j['files']);
        $miss = array_values(array_diff($want, $packEps));
        if ($miss) { st_sp_fail($j, st_tr('pk.miss', ['l' => implode(', ', $miss)])); return true; }
        if (count($packEps) < count($want)) { st_sp_fail($j, st_tr('pk.fewer')); return true; }
        // Sonarr may have imported the pack by itself already (an upgrade it accepts): then there is nothing to delete
        [$cE, $curEps] = st_arr('sonarr', '/episode?seriesId=' . (int)$j['series_id'] . '&seasonNumber=' . (int)$j['season']);
        if (!is_array($curEps)) { return false; }
        $oldIds = array_map(function ($f) { return (int)$f['file']; }, $j['files']); $live = [];
        $got = 0; foreach ($curEps as $ep) {
            $fid = (int)($ep['episodeFileId'] ?? 0);
            if (in_array((int)$ep['episodeNumber'], $want, true) && $fid > 0 && !in_array($fid, $oldIds, true)) { $got++; }
            if ($fid > 0 && in_array($fid, $oldIds, true)) { $live[] = $fid; }
        }
        if ($got >= count($want)) { $j['packeps'] = count($packEps); $j['stage'] = 'imported'; $j['msg'] = st_tr('pk.already', ['n' => $got]); $j['t'] = time(); return true; }
        // the old files are already gone (a restart between the delete and the saved state): go on waiting for the import
        if (!$live) { $j['packeps'] = count($packEps); $j['stage'] = 'importing'; $j['msg'] = st_tr('pk.deleted'); $j['t'] = time(); $j['dt'] = time(); return true; }
        // go: remember monitoring, unmonitor the episodes (no other release may be grabbed meanwhile), delete the season files in Sonarr
        $j['packeps'] = count($packEps); $j['pack_path'] = (string)($pt['content_path'] ?? '');
        $e = st_sp_monitor(array_map(function ($f) { return (int)$f['ep_id']; }, $j['files']), false);
        if ($e !== '') { st_sp_fail($j, st_tr('pk.unmon', ['e' => $e])); return true; }
        [$c, $r, $e] = st_arr('sonarr', '/episodefile/bulk', 'DELETE', ['episodeFileIds' => array_values($live)], 120);
        if ($e !== '') {
            st_sp_monitor(array_map(function ($f) { return (int)$f['ep_id']; }, array_filter($j['files'], function ($f) { return !empty($f['monitored']); })), true);
            st_sp_fail($j, st_tr('pk.nodel', ['e' => $e])); return true;
        }
        $j['stage'] = 'importing'; $j['msg'] = st_tr('pk.deleted'); $j['t'] = time(); $j['dt'] = time();
        return true;
    }
    if ($stage === 'importing') {
        [$c, $eps] = st_arr('sonarr', '/episode?seriesId=' . (int)$j['series_id'] . '&seasonNumber=' . (int)$j['season']);
        if (!is_array($eps)) { return false; }
        $oldIds = array_map(function ($f) { return (int)$f['file']; }, $j['files']); $want = array_map(function ($f) { return (int)$f['ep']; }, $j['files']);
        $got = 0; foreach ($eps as $ep) { if (in_array((int)$ep['episodeNumber'], $want, true) && (int)($ep['episodeFileId'] ?? 0) > 0 && !in_array((int)$ep['episodeFileId'], $oldIds, true)) { $got++; } }
        if ($got >= count($want)) { $j['stage'] = 'imported'; $j['msg'] = st_tr('pk.imported', ['n' => $got]); $j['t'] = time(); return true; }
        $age = time() - (int)$j['dt'];
        $m = st_tr('pk.wait', ['n' => $got, 'm' => count($want)]);
        if ($age > 120 && empty($j['scanned'])) {
            // Sonarr did not pick it up by itself: ask it to scan the finished download
            $did = ''; [$cq, $q] = st_arr('sonarr', '/queue?page=1&pageSize=200');
            foreach ((array)($q['records'] ?? []) as $r) { if ((int)($r['seriesId'] ?? 0) === (int)$j['series_id'] && !empty($r['downloadId'])) { $did = (string)$r['downloadId']; } }
            $path = (string)($j['pack_path'] ?? '');
            $cmd = ['name' => 'DownloadedEpisodesScan', 'path' => $path, 'importMode' => 'auto'];
            if ($did !== '') { $cmd['downloadClientId'] = $did; }
            if ($path !== '') { [$cc, $rr, $ee] = st_arr('sonarr', '/command', 'POST', $cmd, 60); $j['scanned'] = 1; $m .= ' ' . ($ee !== '' ? st_tr('pk.scan_fail', ['e' => $ee]) : st_tr('pk.scan')); }
        }
        if ($age > 900) {
            st_sp_monitor(array_map(function ($f) { return (int)$f['ep_id']; }, array_filter($j['files'], function ($f) { return !empty($f['monitored']); })), true);
            st_sp_fail($j, st_tr('pk.noimp', ['n' => $got, 'm' => count($want)])); return true;
        }
        if ($m !== $j['msg']) { $j['msg'] = $m; return true; }
        return false;
    }
    if ($stage === 'imported') {
        // monitoring back as it was, then the single-episode torrents go to the trash folder
        $e = st_sp_monitor(array_map(function ($f) { return (int)$f['ep_id']; }, array_filter($j['files'], function ($f) { return !empty($f['monitored']); })), true);
        $notes = [];
        if ($e !== '') { $notes[] = st_tr('pk.remon', ['e' => $e]); }
        $cl = 0; $paths = [];
        // first take every single-episode torrent out of qBittorrent (files kept), then move each folder once: two torrents can share a folder
        foreach ((array)$j['old'] as $o) {
            if (!empty($j['hash']) && strtolower($o['hash']) === $j['hash']) { continue; }
            st_qb('/api/v2/torrents/delete', ['hashes' => $o['hash'], 'deleteFiles' => 'false']); st_watch_forget($o['hash']);
            $paths[st_to_host((string)$o['path'], $cfg)] = $o['name'];
        }
        foreach ($paths as $hp => $nm) {
            [$ok, $m] = st_cleanup_old(['hash' => '', 'old_path' => $hp, 'media_paths' => []]);
            if ($ok) { $cl++; } elseif (st_under_roots($hp, $cfg) !== false && !file_exists($hp)) { $cl++; } else { $notes[] = $nm . ': ' . $m; }
        }
        st_find_prune();
        $j['stage'] = 'done'; $j['t'] = time();
        $j['msg'] = st_tr('pk.done', ['n' => count($j['files']), 'm' => $cl]) . ($notes ? '; ' . implode('; ', array_slice($notes, 0, 3)) : '');
        st_notify($notes ? 'a' : 'g', st_l('Seedbox: évadpakk csere kész', 'Seedbox: season pack replacement done'), st_l('Az epizódok a pakkból vannak importálva, az egyedi torrentek a kukába kerültek.', 'The episodes are imported from the pack; the single-episode torrents went to the trash.') . ($notes ? "\n" . implode("\n", array_slice($notes, 0, 3)) : ''),
            [[st_l('Sorozat', 'Series'), $j['title'] . ' S' . str_pad((string)$j['season'], 2, '0', STR_PAD_LEFT)], [st_l('Pakk', 'Pack'), $j['pack']]], false, 'rep');
        return true;
    }
    return false;
}

function st_sp_tick(&$state, $tor) {
    $ch = false;
    foreach ($state['packjobs'] as &$j) { if (st_sp_step($j, $tor)) { $ch = true; } }
    unset($j);
    $keep = [];
    foreach ($state['packjobs'] as $j) { if (in_array($j['stage'], ['done', 'failed'], true) && time() - (int)$j['t'] > 7 * 86400) { $ch = true; continue; } $keep[] = $j; }
    $state['packjobs'] = $keep;
    return $ch;
}
function st_sp_active($state) {
    foreach ((array)($state['packjobs'] ?? []) as $j) { if (!in_array($j['stage'], ['done', 'failed', 'blocked'], true)) { return true; } }
    return false;
}
function st_sp_clear() {
    st_state_update(function (&$s) { $s['packjobs'] = array_values(array_filter((array)($s['packjobs'] ?? []), function ($j) { return !in_array($j['stage'], ['done', 'failed', 'blocked'], true); })); });
}

// ---- autobrr watch (phase 1: only a Discord message) ---------------------------------------------------------
// The tick asks autobrr every 5 minutes (GET) for the releases its filters let through. A new season pack of a monitored series is
// checked with Sonarr (GET /parse, episodes, files): at least one episode of the season is here, the pack is not of a lower
// resolution, Hungarian when the episodes are, and the season is not one torrent already. Then one Discord message. Nothing is started.
const ST_AB = '/tmp/qbt_tools_ab.json';
function st_ab_seen_file() { return dirname(st_state_file()) . '/ab_seen.json'; }

function st_ab_get($path, $timeout = 20) {
    $c = $GLOBALS['st_ab_override'] ?? (st_cfg()['autobrr'] ?? ['url' => '', 'key' => '']); // override: the Load filters button before Save
    $url = rtrim((string)$c['url'], '/');
    if ($url === '' || $c['key'] === '') { return [0, null, st_tr('e.ab_nocfg')]; }
    [$code, $body, $err] = st_http('GET', $url . '/api' . $path, ['headers' => ['X-API-Token: ' . $c['key'], 'Accept: application/json'], 'timeout' => $timeout, 'follow' => true]);
    if ($err) { return [0, null, $err]; }
    $j = json_decode($body, true);
    if ($code >= 400) { return [$code, $j, 'HTTP ' . $code]; }
    return [$code, $j, ''];
}

// the releases autobrr stored (newest first in autobrr), as [id, name, size, indexer, filter]; the field names differ a little between versions
function st_ab_releases($limit = 100) {
    [$c, $j, $e] = st_ab_get('/release?limit=' . (int)$limit . '&offset=0');
    if ($e !== '') { return [null, $e]; }
    $rows = is_array($j) ? ($j['data'] ?? (isset($j[0]) ? $j : null)) : null;
    if (!is_array($rows)) { return [null, st_tr('e.ab_answer')]; }
    $out = [];
    foreach ($rows as $r) {
        if (!is_array($r) || (int)($r['id'] ?? 0) <= 0) { continue; }
        $ix = $r['indexer'] ?? '';
        $ix = is_array($ix) ? (string)($ix['name'] ?? ($ix['identifier'] ?? '')) : (string)$ix;
        $name = (string)($r['name'] ?? ($r['torrent_name'] ?? ($r['title'] ?? '')));
        if ($name === '') { continue; }
        $fl = $r['filter'] ?? ($r['filter_name'] ?? '');
        $out[] = ['id' => (int)$r['id'], 'name' => $name, 'size' => (float)($r['size'] ?? 0), 'indexer' => $ix, 'filter' => is_array($fl) ? (string)($fl['name'] ?? '') : (string)$fl];
    }
    usort($out, function ($a, $b) { return $a['id'] <=> $b['id']; });
    return [$out, ''];
}

// the filters of autobrr: [[name, enabled], ...]
function st_ab_filters() {
    [$c, $j, $e] = st_ab_get('/filters');
    if ($e !== '') { return [null, $e]; }
    $rows = is_array($j) ? ($j['data'] ?? $j) : null;
    if (!is_array($rows)) { return [null, st_tr('e.ab_answer')]; }
    $out = [];
    foreach ($rows as $f) { if (is_array($f) && ($f['name'] ?? '') !== '') { $out[] = ['name' => (string)$f['name'], 'enabled' => !empty($f['enabled'])]; } }
    usort($out, function ($a, $b) { return strnatcasecmp($a['name'], $b['name']); });
    return [$out, ''];
}

// cheap checks on the name alone, before Sonarr is asked: one episode (S02E01, S02E01-E03), or not a filter that was chosen
function st_ab_quick($rel, $filters) {
    if ($filters && !in_array($rel['filter'], $filters, true)) { return st_tr('abw.filter', ['f' => $rel['filter'] !== '' ? $rel['filter'] : '-']); }
    if (preg_match('/(?<![A-Za-z0-9])S\d{1,2}[ ._-]?E\d{1,4}/i', $rel['name'])) { return st_tr('abw.ep'); }
    return '';
}

// free space where the series are downloaded: the root with SOROZAT or TV in its name, else the first one
function st_ab_free() {
    $c = st_cfg(); $p = ((array)$c['series_dirs'])[0] ?? (((array)$c['roots'])[0] ?? '');
    $f = $p !== '' ? @disk_free_space($p) : false;
    return $f === false ? -1 : (float)$f;
}

// one release -> ['go' => bool, 'why' => text, ...]; 'retry' => true when Sonarr could not answer (ask again at the next poll)
function st_ab_eval($rel, &$tor) {
    [$c, $p, $e] = st_arr('sonarr', '/parse?title=' . rawurlencode($rel['name']), 'GET', null, 30);
    if ($e !== '' || !is_array($p)) { return ['go' => false, 'retry' => true, 'why' => 'Sonarr: ' . ($e ?: st_tr('e.bad_resp'))]; }
    $pe = (array)($p['parsedEpisodeInfo'] ?? []);
    if (empty($pe['fullSeason'])) { return ['go' => false, 'why' => st_tr('dr.notpack')]; }
    if (!empty($pe['isMultiSeason'])) { return ['go' => false, 'why' => st_tr('abw.multi')]; }
    $sn = (int)($pe['seasonNumber'] ?? 0); if ($sn < 1) { return ['go' => false, 'why' => st_tr('abw.specials')]; }
    $ser = (array)($p['series'] ?? []); $sid = (int)($ser['id'] ?? 0);
    if ($sid <= 0) { return ['go' => false, 'why' => st_tr('abw.noseries')]; }
    $title = (string)($ser['title'] ?? $rel['name']); $tag = $title . ' S' . str_pad((string)$sn, 2, '0', STR_PAD_LEFT);
    if (empty($ser['monitored'])) { return ['go' => false, 'why' => st_tr('abw.sermon', ['s' => $tag])]; }
    foreach ((array)($ser['seasons'] ?? []) as $ss) { if ((int)($ss['seasonNumber'] ?? -1) === $sn && empty($ss['monitored'])) { return ['go' => false, 'why' => st_tr('abw.seamon', ['s' => $tag])]; } }
    foreach ((array)(st_state()['packjobs'] ?? []) as $j) { if ((int)$j['series_id'] === $sid && (int)$j['season'] === $sn && !in_array($j['stage'], ['done', 'failed', 'blocked'], true)) { return ['go' => false, 'why' => st_tr('abw.busy', ['s' => $tag])]; } }
    [$c1, $eps, $e1] = st_arr('sonarr', '/episode?seriesId=' . $sid . '&seasonNumber=' . $sn, 'GET', null, 60);
    [$c2, $files, $e2] = st_arr('sonarr', '/episodefile?seriesId=' . $sid, 'GET', null, 60);
    if (!is_array($eps) || !is_array($files)) { return ['go' => false, 'retry' => true, 'why' => 'Sonarr: ' . ($e1 ?: ($e2 ?: st_tr('e.bad_resp')))]; }
    $fby = []; foreach ($files as $f) { $fby[(int)$f['id']] = $f; }
    $have = []; $aired = 0; $missing = []; $now = time();
    foreach ($eps as $x) {
        if ((int)($x['seasonNumber'] ?? -1) !== $sn) { continue; }
        $ts = !empty($x['airDateUtc']) ? strtotime($x['airDateUtc']) : 0;
        if ($ts > 0 && $ts <= $now) { $aired++; }
        if (!empty($x['hasFile']) && isset($fby[(int)($x['episodeFileId'] ?? 0)])) { $have[] = $x; }
        elseif ($ts > 0 && $ts <= $now && !empty($x['monitored'])) { $missing[] = (int)$x['episodeNumber']; }
    }
    if (!$have) { return ['go' => false, 'why' => st_tr('abw.noep', ['s' => $tag])]; }
    $first = $fby[(int)$have[0]['episodeFileId']];
    $cur_res = (int)($first['quality']['quality']['resolution'] ?? 0);
    $langs = []; foreach ((array)($first['languages'] ?? []) as $l) { $langs[] = (string)($l['name'] ?? ''); }
    $need_hun = (bool)preg_grep('/hungar/i', $langs);
    $res = (int)($pe['quality']['quality']['resolution'] ?? 0) ?: st_res_of($rel['name']);
    if ($cur_res > 0 && $res > 0 && $res < $cur_res) { return ['go' => false, 'why' => st_tr('abw.lowres', ['s' => $tag, 'r' => $res, 'c' => $cur_res])]; }
    $pl = []; foreach ((array)($p['languages'] ?? ($pe['languages'] ?? [])) as $l) { $pl[] = is_array($l) ? (string)($l['name'] ?? '') : (string)$l; }
    $hun = (bool)(preg_grep('/hungar/i', $pl) || preg_match('/\b(hun|hundub|magyar)\b/i', $rel['name']));
    if ($need_hun && !$hun) { return ['go' => false, 'why' => st_tr('abw.nohun', ['s' => $tag])]; }
    $nums = array_map(function ($x) { return (int)$x['episodeNumber']; }, $have);
    [$c3, $hist] = st_arr('sonarr', '/history/series?seriesId=' . $sid . '&seasonNumber=' . $sn . '&eventType=3', 'GET', null, 60);
    if (is_array($hist) && $hist) {
        $by = []; foreach ($hist as $h) { if (!empty($h['downloadId'])) { $by[$h['downloadId']][(int)($h['episodeId'] ?? 0)] = 1; } }
        $mx = 0; foreach ($by as $v) { $mx = max($mx, count($v)); }
        if ($mx >= max(2, (int)ceil(count($have) * 0.8))) { return ['go' => false, 'why' => st_tr('abw.imported', ['s' => $tag])]; }
    }
    if ($tor === null) { $tor = st_qb_torrents() ?? []; }
    $tor = st_lib_in($tor, st_cfg()); // a ratio torrent (downloads/TL) is not the library's pack
    if (count($have) >= 2 && st_sp_seeded_pack($title, $sn, $nums, $tor) !== '') { return ['go' => false, 'why' => st_tr('abw.seeded', ['s' => $tag])]; }
    $cur_size = 0; foreach ($have as $x) { $cur_size += (float)($fby[(int)$x['episodeFileId']]['size'] ?? 0); }
    return ['go' => true, 'why' => st_tr('abw.new', ['s' => $tag]), 'series_id' => $sid, 'title' => $title, 'season' => $sn, 'tag' => $tag, 'res' => $res, 'hun' => $hun,
            'cur_res' => $cur_res, 'cur_quality' => (string)($first['quality']['quality']['name'] ?? ''), 'cur_size' => $cur_size, 'have' => count($have), 'aired' => $aired, 'missing' => $missing];
}

function st_ab_message($rel, $v) {
    $free = st_ab_free();
    $miss = $v['missing'] ? implode(', ', array_map(function ($n) { return 'E' . str_pad((string)$n, 2, '0', STR_PAD_LEFT); }, array_slice($v['missing'], 0, 15))) . (count($v['missing']) > 15 ? ' ...' : '') : '';
    $packTxt = $rel['name'] . "\n" . ($rel['size'] > 0 ? st_gb($rel['size']) . ', ' : '') . ($v['res'] > 0 ? $v['res'] . 'p' : '?') . ($v['hun'] ? ', HUN' : '') . ($rel['indexer'] !== '' ? ', ' . $rel['indexer'] : '');
    $haveTxt = $v['have'] . '/' . max($v['have'], $v['aired']) . st_l(' epizód', ' episodes') . ', ' . ($v['cur_quality'] !== '' ? $v['cur_quality'] : ($v['cur_res'] . 'p')) . ', ' . st_gb($v['cur_size']);
    $desc = st_l('Az autobrr új évadpakkot látott egy figyelt évadhoz. Nem indítottam semmit.', 'autobrr saw a new season pack of a monitored season. Nothing was started.') . "\n"
          . st_l('Csere: Seedbox Tools, Season repacker, From autobrr, Replace with pack (a sor pár perc múlva ott van).', 'To replace: Seedbox Tools, Season repacker, From autobrr, Replace with pack (the row is there in a few minutes).')
          . ($v['missing'] ? "\n" . st_l('Az évad hiányos nálad: a hiányzó részeket (gyakran az évadzárót) a pakkból hozza.', 'The season is incomplete here: the missing episodes (often the finale) come from the pack.') : '');
    return st_notify('i', st_l('Seedbox: évadpakk érkezett', 'Seedbox: a season pack arrived') . ' · ' . $v['tag'], $desc,
        [[st_l('Pakk', 'Pack'), $packTxt], [st_l('Nálad', 'You have'), $haveTxt], [st_l('Hiányzik', 'Missing'), $miss],
         [st_l('Szabad hely', 'Free space'), $free >= 0 ? st_gb($free) . ($rel['size'] > 0 && $free < $rel['size'] * 1.1 ? st_l(' (KEVÉS)', ' (NOT ENOUGH)') : '') : '']], true, 'rep');
}

// called by the tick; $force skips the 5 minute wait (Test button)
function st_ab_poll($force = false) {
    $cfg = st_cfg();
    if (empty($cfg['ab_on']) || ($cfg['autobrr']['url'] ?? '') === '' || ($cfg['autobrr']['key'] ?? '') === '') { return; }
    $w = st_json_read(ST_AB, []);
    if (!$force && time() - (int)($w['t'] ?? 0) < 300) { return; }
    $w['t'] = time();
    [$rels, $e] = st_ab_releases(100);
    if ($rels === null) { $w['err'] = $e; st_json_write(ST_AB, $w); return; }
    $w['err'] = '';
    $max = 0; foreach ($rels as $r) { $max = max($max, $r['id']); }
    if (!isset($w['last_id'])) { $w['last_id'] = $max; $w['since'] = time(); $w['recent'] = []; st_json_write(ST_AB, $w); return; } // first run: only what comes from now on
    if ($max < (int)$w['last_id']) { $w['last_id'] = $max; } // autobrr's list was cleared
    $seen = st_json_read(st_ab_seen_file(), []); $seenCh = false; $tor = null; $n = 0; $abq = [];
    foreach ($seen as $k => $t) { if (time() - (int)$t > 30 * 86400) { unset($seen[$k]); $seenCh = true; } }
    $recent = (array)($w['recent'] ?? []);
    foreach ($rels as $r) {
        if ($r['id'] <= (int)$w['last_id']) { continue; }
        $q = st_ab_quick($r, (array)($cfg['ab_filters'] ?? []));
        if ($q !== '') { $w['last_id'] = $r['id']; $w['skipped'] = (int)($w['skipped'] ?? 0) + 1; continue; } // not listed: most of the feed is single episodes
        if (++$n > 20) { break; } // the rest at the next poll
        $v = st_ab_eval($r, $tor);
        if (!empty($v['retry'])) {
            // Sonarr did not answer: try this release again at the next poll, three times at most
            $w['err'] = $v['why']; $w['fail_n'] = (($w['fail_id'] ?? 0) === $r['id']) ? (int)($w['fail_n'] ?? 0) + 1 : 1; $w['fail_id'] = $r['id'];
            if ($w['fail_n'] < 3) { break; }
        }
        $w['last_id'] = $r['id'];
        if ($v['go']) {
            $key = $v['series_id'] . ':' . $v['season'] . ':' . st_norm($r['name']);
            if (isset($seen[$key])) { $v['why'] .= ' ' . st_tr('abw.reported'); }
            else {
                $bot = st_bot_on('sp');
                $abq[] = ['series_id' => $v['series_id'], 'season' => $v['season'], 'name' => $r['name'], 'indexer' => $r['indexer'], 'size' => $r['size'], 't' => time(), 'tries' => 0] + ($bot ? ['rel' => $r, 'v' => $v] : []);
                if ($bot) { $ok = true; $v['why'] .= ', ' . st_tr('abw.bot'); }
                else { [$ok, $er] = st_ab_message($r, $v); $v['why'] .= $ok ? ', ' . st_tr('abw.dc') : ', Discord: ' . $er; }
                $seen[$key] = time(); $seenCh = true;
            }
        }
        $recent[] = ['t' => time(), 'name' => $r['name'], 'why' => $v['why'], 'go' => $v['go'] ? 1 : 0];
    }
    $w['recent'] = array_slice($recent, -15);
    if ($seenCh) { st_json_write(st_ab_seen_file(), $seen); }
    st_json_write(ST_AB, $w);
    if ($abq) { st_sp_ab_update(function (&$d) use ($abq) { foreach ($abq as $q) { $d['queue'][] = $q; } }); }
    if ($abq || st_sp_ab_due()) { st_sp_ab_spawn(); }
}

// ---- autobrr finds as Season repacker rows (item 6, 2026-10-16) ------------------------------------------------------
// A pack the watch reported is queued; a background worker (tools_run.php spab) builds the same row as the library scan
// (st_sp_item: Sonarr search of the season, the autobrr release first when Sonarr lists it) into sp_ab.json next to the state.
// The row leaves the list when it is started (its job shows in Activity; it comes back if the job fails), dismissed, or 7 days old.
// Nothing is started by itself.
function st_sp_ab_file() { return dirname(st_state_file()) . '/sp_ab.json'; }
function st_sp_ab_update($fn) {
    $f = st_sp_ab_file(); $lk = @fopen($f . '.lock', 'c'); if ($lk) { flock($lk, LOCK_EX); }
    $d = st_json_read($f, []); $d += ['items' => [], 'queue' => []]; $o = json_encode($d);
    $fn($d);
    if (json_encode($d) !== $o) { st_json_write($f, $d); }
    if ($lk) { flock($lk, LOCK_UN); fclose($lk); }
    return $d;
}
// the rows to show: not dismissed, younger than 7 days, not being replaced right now
function st_sp_ab_list() {
    $d = st_json_read(st_sp_ab_file(), []); $busy = [];
    foreach ((array)(st_state()['packjobs'] ?? []) as $j) { if (($j['stage'] ?? '') !== 'failed') { $busy[$j['key']] = 1; } }
    return array_values(array_filter((array)($d['items'] ?? []), function ($x) use ($busy) {
        return empty($x['dismissed']) && time() - (int)($x['t'] ?? 0) < 7 * 86400 && !isset($busy[$x['key']]);
    }));
}
function st_sp_ab_due() {
    $d = st_json_read(st_sp_ab_file(), []);
    foreach ((array)($d['queue'] ?? []) as $q) { if (time() >= (int)($q['next'] ?? 0)) { return true; } }
    return false;
}
function st_sp_ab_spawn() {
    $php = '/usr/bin/php'; foreach (['/usr/bin/php', '/usr/local/bin/php'] as $p) { if (is_executable($p)) { $php = $p; break; } }
    @exec('setsid nohup ' . escapeshellarg($php) . ' ' . escapeshellarg(__DIR__ . '/tools_run.php') . ' spab >/dev/null 2>&1 &');
}
// the BoxBot message of a new row: the first three packs as choices
function st_sp_ab_bot($it, $q) {
    $v = (array)$q['v']; $miss = (array)($it['missing'] ?? []);
    $have = count($it['files']) . '/' . (count($it['files']) + count($miss)) . st_l(' epizód', ' episodes') . ', ' . ($it['cur_quality'] !== '' ? $it['cur_quality'] : $it['cur_res'] . 'p') . ', ' . st_gb($it['cur_size']) . ', ' . count($it['old']) . ' torrent';
    $cands = array_map(function ($p) use ($it) { return $p + ['note' => !empty($it['ab_match']) && st_norm($p['title']) === st_norm($it['ab_name']) ? st_l('ezt látta az autobrr', 'the one autobrr saw') : '']; }, $it['packs']);
    $free = st_ab_free();
    $info = [[st_l('Nálad', 'You have'), $have, true]];
    if ($miss) { $info[] = [st_l('A pakkból jön', 'Comes from the pack'), implode(', ', array_map(function ($n) { return 'E' . str_pad((string)$n, 2, '0', STR_PAD_LEFT); }, array_slice($miss, 0, 15))), true]; }
    $info[] = [st_l('Szabad hely', 'Free space'), $free >= 0 ? st_gb($free) : '?', true];
    return st_bot_post('sp', $it['key'], st_l('📦 Évadpakk érkezett · ', '📦 Season pack arrived · ') . $it['title'] . ' S' . str_pad((string)$it['season'], 2, '0', STR_PAD_LEFT),
        st_l('Válassz egy pakkot: a régi epizódok a kukába kerülnek, ha a pakk megvan és minden nálad lévő részt tartalmaz.', 'Choose a pack: the old episodes go to the trash once the pack is here and holds every episode you have.'), $info, $cands);
}
function st_sp_ab_dismiss($key) {
    st_sp_ab_update(function (&$d) use ($key) { foreach ($d['items'] as &$x) { if ($x['key'] === $key) { $x['dismissed'] = time(); } } unset($x); });
}
// the worker: one queued find at a time; Sonarr may not list a pack in the first minutes, so a find without a pack is tried
// again 5, 10, 20, 40 minutes later, then given up
function st_sp_ab_run() {
    for ($guard = 0; $guard < 20; $guard++) {
        $d = st_json_read(st_sp_ab_file(), []); $q = null;
        foreach ((array)($d['queue'] ?? []) as $x) { if (time() >= (int)($x['next'] ?? 0)) { $q = $x; break; } }
        if (!$q) { return; }
        $sid = (int)$q['series_id']; $sn = (int)$q['season'];
        [$c0, $ser, $e0] = st_arr('sonarr', '/series/' . $sid, 'GET', null, 60);
        [$c1, $files] = st_arr('sonarr', '/episodefile?seriesId=' . $sid, 'GET', null, 60);
        [$c2, $eps] = st_arr('sonarr', '/episode?seriesId=' . $sid . '&seasonNumber=' . $sn, 'GET', null, 60);
        $it = null; $why = 'Sonarr did not answer';
        if (is_array($ser) && is_array($files) && is_array($eps)) {
            $fby = []; foreach ($files as $f) { $fby[(int)$f['id']] = $f; }
            $list = array_values(array_filter($eps, function ($x) use ($sn) { return (int)($x['seasonNumber'] ?? -1) === $sn; }));
            [$it, $why] = st_sp_item($ser, $sn, $list, $fby, st_lib_in(st_qb_torrents(), st_cfg()), 1); // autobrr: one episode is enough (the rest, often the finale, comes from the pack)
        }
        if ($it) {
            // the release autobrr saw first, when Sonarr lists it; else the best pack Sonarr found
            $n = st_norm($q['name']); $hit = null;
            foreach ($it['packs'] as $i => $p) { if (st_norm($p['title']) === $n) { $hit = $i; } }
            if ($hit !== null) { $p = $it['packs'][$hit]; array_splice($it['packs'], $hit, 1); array_unshift($it['packs'], $p); }
            $it += ['src' => 'ab', 't' => (int)$q['t'], 'ab_name' => (string)$q['name'], 'ab_match' => $hit !== null, 'ab_indexer' => (string)$q['indexer']];
        }
        $retry = !$it && in_array($why, ['no pack', 'search failed', 'Sonarr did not answer'], true) && (int)$q['tries'] < 4;
        if (!empty($q['rel'])) {
            if ($it) { st_sp_ab_bot($it, $q); }
            elseif (!$retry) { st_ab_message($q['rel'], $q['v']); } // no row: the message without choices, as before
        }
        st_sp_ab_update(function (&$d) use ($q, $it, $retry, $why) {
            $d['queue'] = array_values(array_filter($d['queue'], function ($x) use ($q) { return !($x['name'] === $q['name'] && (int)$x['t'] === (int)$q['t']); }));
            if ($retry) { $q['tries'] = (int)$q['tries'] + 1; $q['next'] = time() + 300 * (2 ** ((int)$q['tries'] - 1)); $d['queue'][] = $q; }
            if ($it) {
                $d['items'] = array_values(array_filter($d['items'], function ($x) use ($it) { return $x['key'] !== $it['key']; }));
                $d['items'][] = $it;
            }
            $d['items'] = array_values(array_filter($d['items'], function ($x) { return time() - (int)($x['t'] ?? 0) < 14 * 86400; }));
            $d['last'] = ['t' => time(), 'name' => $q['name'], 'why' => $it ? st_tr('abw.listed') : ($retry ? st_tr('abw.retry') : st_tr('abw.notlisted', ['w' => st_sp_why($why !== '' ? $why : 'nothing to replace')]))];
        });
    }
}

// ---- BoxBot: start a replacement from Discord with a reaction (item 6 phase 2 and item 72, 2026-10-16) -------------------
// The Seedbox webhook sends the message as before (with ?wait=true, which returns its id and channel); the bot (token and the
// owner's user id in the settings) adds 1️⃣ 2️⃣ 3️⃣ 👎 under it. The tick asks Discord once a minute (REST, no gateway, nothing
// listens on the server) who reacted: only the owner counts, for 24 hours. A number starts the same thing as the page button
// (st_sp_start / st_replace_grab, with their own checks), 👎 dismisses; the bottom of the message follows the job.
const ST_BOT_EMO = ["1\u{FE0F}\u{20E3}", "2\u{FE0F}\u{20E3}", "3\u{FE0F}\u{20E3}"];
const ST_BOT_NO = "\u{1F44E}";
function st_bot_file() { return dirname(st_state_file()) . '/bot_msgs.json'; }
function st_bot_on($kind = '') {
    $c = st_cfg();
    if ((string)$c['bot_token'] === '' || !preg_match('/^\d{15,25}$/', (string)$c['bot_user'])) { return false; }
    if ($kind === 'sp') { return !empty($c['bot_rep']); }
    if ($kind === 'find') { return !empty($c['bot_find']); }
    return true;
}
function st_bot_update($fn) {
    $f = st_bot_file(); $lk = @fopen($f . '.lock', 'c'); if ($lk) { flock($lk, LOCK_EX); }
    $d = st_json_read($f, []); $d += ['msgs' => []]; $o = json_encode($d);
    $r = $fn($d);
    if (json_encode($d) !== $o) { st_json_write($f, $d); }
    if ($lk) { flock($lk, LOCK_UN); fclose($lk); }
    return $r;
}
// Discord REST as the bot: [code, json, error]; one wait on a rate limit
function st_bot_api($method, $path, $body = null) {
    $base = getenv('DBX_ST_DCAPI') ?: 'https://discord.com/api/v10';
    $tok = (string)st_cfg()['bot_token'];
    for ($try = 0; $try < 2; $try++) {
        $o = ['headers' => ['Authorization: Bot ' . $tok, 'User-Agent: DiscordBot (seedbox, 1)', 'Content-Type: application/json'], 'timeout' => 15];
        if ($body !== null) { $o['post'] = json_encode($body, JSON_UNESCAPED_UNICODE); } elseif ($method === 'PUT') { $o['post'] = ''; }
        [$code, $b, $err] = st_http($method, $base . $path, $o);
        if ($err) { return [0, null, $err]; }
        $j = json_decode((string)$b, true);
        if ($code === 429 && $try === 0) { $w = is_array($j) ? (float)($j['retry_after'] ?? 1) : 1.0; if ($w <= 5) { usleep((int)(($w + 0.2) * 1e6)); continue; } }
        if ($code >= 400) { return [$code, $j, 'Discord HTTP ' . $code . (is_array($j) && !empty($j['message']) ? ': ' . $j['message'] : '')]; }
        return [$code, $j, ''];
    }
    return [429, null, 'Discord rate limit'];
}
// edits the webhook message: the stored embed with its colour and the status field
function st_bot_edit($m, $status, $color) {
    $hook = getenv('DBX_ST_DC') ?: (string)st_cfg()['dc_hook'];
    if ($hook === '' || empty($m['mid'])) { return; }
    $e = (array)$m['embed']; $e['color'] = $color;
    $f = array_values(array_filter((array)($e['fields'] ?? []), function ($x) { return ($x['name'] ?? '') !== st_l('Állapot', 'Status'); }));
    $f[] = ['name' => st_l('Állapot', 'Status'), 'value' => mb_substr($status, 0, 1000)];
    $e['fields'] = array_slice($f, 0, 25);
    st_http('PATCH', $hook . '/messages/' . $m['mid'], ['headers' => ['Content-Type: application/json'], 'post' => json_encode(['embeds' => [$e]], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), 'timeout' => 15]);
}
function st_bot_cand_line($c) {
    return implode(' · ', array_filter([(float)$c['size'] > 0 ? st_gb((float)$c['size']) : '', trim(((int)$c['res'] > 0 ? $c['res'] . 'p ' : '') . (string)($c['hdr'] ?? '')), !empty($c['hun']) ? 'HUN' : '',
        (int)$c['seeders'] > 0 ? (int)$c['seeders'] . ' seeder' : '', (string)($c['indexer'] ?? ''), (string)($c['note'] ?? '')])) ?: '-';
}
// sends a message with candidates and the reactions. $kind: sp | find | test; $cands: [[guid, title, size, res, hdr, hun, seeders, indexer, note], ...]
function st_bot_post($kind, $key, $title, $desc, $info, $cands, $extra = []) {
    $cands = array_slice(array_values($cands), 0, 3);
    $fields = [];
    foreach ($cands as $i => $c) { $fields[] = [ST_BOT_EMO[$i] . ' ' . mb_substr((string)$c['title'], 0, 200), st_bot_cand_line($c)]; }
    foreach ($info as $x) { $fields[] = $x; }
    $until = date('m-d H:i', time() + 86400);
    $fields[] = [st_l('Állapot', 'Status'), st_l('Koppints egy számra a cseréhez, 👎 = elvetés. Érvényes eddig: ', 'Tap a number to replace, 👎 = dismiss. Valid until ') . $until];
    [$ok, $er, $msg, $emb] = array_pad(st_notify('i', $title, $desc, $fields, true, '', ['wait' => true, 'color' => 0x9A7DE6]), 4, null);
    if (!$ok || !is_array($msg) || empty($msg['id'])) { return [false, $ok ? 'Discord did not return the message' : $er]; }
    $rec = ['mid' => (string)$msg['id'], 'ch' => (string)($msg['channel_id'] ?? ''), 'kind' => $kind, 'key' => $key, 't' => time(), 'state' => 'wait',
            'cands' => array_map(function ($c) { return ['guid' => (string)$c['guid'], 'title' => (string)$c['title']]; }, $cands), 'embed' => $emb] + $extra;
    $err = '';
    foreach (array_merge(array_slice(ST_BOT_EMO, 0, count($cands)), [ST_BOT_NO]) as $em) {
        [$c, $j, $e] = st_bot_api('PUT', '/channels/' . $rec['ch'] . '/messages/' . $rec['mid'] . '/reactions/' . rawurlencode($em) . '/@me');
        if ($e !== '') { $err = $e; break; }
        usleep(350000);
    }
    if ($err !== '') { $rec['err'] = $err; }
    st_bot_update(function (&$d) use ($rec) { $d['msgs'][] = $rec; $d['msgs'] = array_slice($d['msgs'], -60); });
    return [$err === '', $err];
}
// who reacted with $em (user ids)
function st_bot_who($m, $em) {
    [$c, $j, $e] = st_bot_api('GET', '/channels/' . $m['ch'] . '/messages/' . $m['mid'] . '/reactions/' . rawurlencode($em) . '?limit=100');
    if ($e !== '' || !is_array($j)) { return null; }
    return array_map(function ($u) { return (string)($u['id'] ?? ''); }, $j);
}
// the tick, once a minute: read the reactions of the waiting messages, follow the started ones
function st_bot_poll() {
    if (!st_bot_on()) { return; }
    $msgs = (array)(st_json_read(st_bot_file(), [])['msgs'] ?? []);
    if (!$msgs) { return; }
    $me = (string)st_cfg()['bot_user'];
    $st = st_state();
    foreach ($msgs as $m) {
        $ch = null; // ['state' => .., 'status' => .., 'color' => ..]
        if ($m['state'] === 'wait') {
            if (time() - (int)$m['t'] > 86400) {
                $ch = ['state' => 'expired', 'status' => st_l('⌛ Lejárt (24 óra). Az oldalon még elindíthatod.', '⌛ Expired (24 hours). You can still start it on the page.'), 'color' => 0x8A8A8A];
            } else {
                $pick = null; $no = false;
                $who = st_bot_who($m, ST_BOT_NO); if ($who === null) { continue; } if (in_array($me, $who, true)) { $no = true; }
                if (!$no) { foreach ($m['cands'] as $i => $c) { $w = st_bot_who($m, ST_BOT_EMO[$i]); if ($w !== null && in_array($me, $w, true)) { $pick = $i; break; } } }
                if ($no) {
                    if ($m['kind'] === 'sp') { st_sp_ab_dismiss($m['key']); }
                    $ch = ['state' => 'no', 'status' => $m['kind'] === 'test' ? st_l('✅ A teszt rendben: a BoxBot látja a reakciódat.', '✅ Test passed: BoxBot sees your reaction.') : st_l('👎 Elvetve.', '👎 Dismissed.'), 'color' => $m['kind'] === 'test' ? 0x3FA24A : 0x8A8A8A];
                } elseif ($pick !== null) {
                    $g = $m['cands'][$pick]['guid'];
                    if ($m['kind'] === 'test') { [$ok, $e] = [true, '']; }
                    elseif ($m['kind'] === 'sp') { [$ok, $e] = st_sp_start($m['key'], $g); }
                    else { [$ok, $e] = st_replace_grab($m['key'], $g); }
                    $lab = ST_BOT_EMO[$pick] . ' ' . $m['cands'][$pick]['title'];
                    if ($m['kind'] === 'test') { $ch = ['state' => 'done', 'status' => st_l('✅ A teszt rendben: a BoxBot látja a reakciódat.', '✅ Test passed: BoxBot sees your reaction.'), 'color' => 0x3FA24A]; }
                    elseif ($ok) { $ch = ['state' => 'run', 'pick' => $pick, 'status' => st_l('▶ Csere elindult: ', '▶ Replacement started: ') . $lab, 'color' => 0x4A90E2]; }
                    else { $ch = ['state' => 'fail', 'status' => st_l('⛔ Nem indult: ', '⛔ Not started: ') . $e . st_l(' Semmi nem változott; az oldalon választhatsz másikat.', ' Nothing changed; you can choose another one on the page.'), 'color' => 0xE5534B]; }
                }
            }
        } elseif ($m['state'] === 'run') {
            // follow the job of this key: the newest one started after the reaction
            $job = null;
            foreach ((array)($m['kind'] === 'sp' ? ($st['packjobs'] ?? []) : ($st['jobs'] ?? [])) as $j) { if (($j['key'] ?? '') === $m['key'] && (int)($j['gt'] ?? $j['t'] ?? 0) >= (int)($m['rt'] ?? 0) - 120) { $job = $j; } }
            if (!$job) { continue; }
            $done = $m['kind'] === 'sp' ? $job['stage'] === 'done' : in_array($job['stage'], ['cleaned', 'removed'], true);
            $jm = (string)($job['msgd'] ?? $job['msg']);   // the Discord language (msgd), not the page's
            if ($done) { $ch = ['state' => 'done', 'status' => '✅ ' . st_l('Kész: ', 'Done: ') . $jm, 'color' => 0x3FA24A]; }
            elseif (in_array($job['stage'], ['failed', 'blocked'], true)) { $ch = ['state' => 'fail', 'status' => '⛔ ' . $jm, 'color' => 0xE5534B]; }
            elseif ($jm !== (string)($m['last'] ?? '') && time() - (int)($m['et'] ?? 0) > 600) {
                // progress, at most every 10 minutes (Discord edits are cheap but the message should not flicker)
                $ch = ['state' => 'run', 'status' => '▶ ' . $jm, 'color' => 0x4A90E2, 'last' => $jm];
            }
        }
        if (!$ch) { continue; }
        st_bot_edit($m, $ch['status'], $ch['color']);
        st_bot_update(function (&$d) use ($m, $ch) {
            foreach ($d['msgs'] as &$x) {
                if ($x['mid'] !== $m['mid']) { continue; }
                if ($x['state'] === 'wait' && $ch['state'] === 'run') { $x['rt'] = time(); }
                $x['state'] = $ch['state']; $x['et'] = time(); if (isset($ch['last'])) { $x['last'] = $ch['last']; } if (isset($ch['pick'])) { $x['pick'] = $ch['pick']; }
            }
            unset($x);
            $d['msgs'] = array_values(array_filter($d['msgs'], function ($x) { return time() - (int)$x['t'] < 8 * 86400; }));
        });
    }
}
// Settings, Test the bot: a message with 1️⃣ and 👎; a reaction of the owner turns it green within a minute
function st_bot_test() {
    if (!st_bot_on()) { return [false, st_tr('e.bot_cfg')]; }
    [$c, $me, $e] = st_bot_api('GET', '/users/@me');
    if ($e !== '') { return [false, st_tr('e.bot_tok', ['e' => $e])]; }
    [$ok, $er] = st_bot_post('test', 'test', st_l('Seedbox: BoxBot teszt', 'Seedbox: BoxBot test'), st_l('Koppints az 1️⃣-re vagy a 👎-ra; egy percen belül zöld lesz.', 'Tap 1️⃣ or 👎; it turns green within a minute.'), [],
        [['guid' => 'test', 'title' => st_l('Próba', 'Test'), 'size' => 0, 'res' => 0, 'hdr' => '', 'hun' => false, 'seeders' => 0, 'indexer' => '']]);
    return [$ok, $ok ? (string)($me['username'] ?? 'bot') : $er];
}
