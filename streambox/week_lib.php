<?php
// This week's episodes (item 55): the monitored episodes airing Monday to Sunday, from Sonarr's calendar, with the time the
// torrent usually shows up (air time + the median delay of earlier grabs in Sonarr's history) and where each one is now.
// Read-only: only GET requests to Sonarr and Jellyfin. Used by week.php (the tile) and week_late.php (cron, Discord).

const WK_CACHE = '/tmp/streambox_week.json';
const WK_DELAY = '/tmp/streambox_delay.json';

function wk_cfg() {
    $c = [
        'jf_host' => '127.0.0.1', 'jf_port' => '8096', 'jf_key' => '',
        'wk_show' => '1', 'wk_src' => 'seedbox', 'son_url' => '', 'son_key' => '',
        'wk_delay' => 'series', 'wk_late' => '2', 'wk_dc' => '0', 'dc_series' => '',
        'wk_web' => '1', 'wk_web_dir' => '/mnt/cache/docker/streambox/web', 'wk_web_view' => 'grid',
    ];
    $f = getenv('SB_CFG') ?: '/boot/config/plugins/streambox/settings.cfg';   // SB_CFG: tests only
    if (is_file($f)) {
        $x = @parse_ini_file($f, false, INI_SCANNER_RAW);
        if (is_array($x)) { $c = array_merge($c, $x); }
    }
    return $c;
}

// Sonarr address and key: Seedbox's (tools.json) or Streambox's own
function wk_sonarr($cfg) {
    if ((string)$cfg['wk_src'] === 'own') { return [rtrim(trim((string)$cfg['son_url']), '/'), trim((string)$cfg['son_key'])]; }
    $f = getenv('SB_SEEDBOX_TOOLS') ?: '/boot/config/plugins/qbittorrent_widget/tools.json';
    $j = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
    $s = is_array($j) && is_array($j['sonarr'] ?? null) ? $j['sonarr'] : [];
    return [rtrim(trim((string)($s['url'] ?? '')), '/'), trim((string)($s['key'] ?? ''))];
}

function wk_tz() {
    $v = @parse_ini_file('/var/local/emhttp/var.ini');
    $tz = is_array($v) ? (string)($v['timeZone'] ?? '') : '';
    if ($tz !== '' && in_array($tz, timezone_identifiers_list(), true)) { date_default_timezone_set($tz); }
}

function wk_http($url, $headers, $timeout = 8) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => $timeout,
        CURLOPT_FOLLOWLOCATION => true, CURLOPT_HTTPHEADER => array_merge($headers, ['Accept: application/json'])]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_errno($ch);
    curl_close($ch);
    if ($err) { return [null, 'unreachable']; }
    if ($code === 401 || $code === 403) { return [null, 'auth']; }
    if ($code !== 200) { return [null, 'HTTP ' . $code]; }
    $j = json_decode((string)$body, true);
    return is_array($j) ? [$j, ''] : [null, 'bad answer'];
}

function wk_son($son, $path, $timeout = 8) {
    if ($son[0] === '' || $son[1] === '') { return [null, 'nosonarr']; }
    return wk_http($son[0] . '/api/v3' . $path, ['X-Api-Key: ' . $son[1]], $timeout);
}

function wk_jf($cfg, $path) {
    if ((string)$cfg['jf_key'] === '' || !preg_match('/^[A-Za-z0-9.\-]+$/', (string)$cfg['jf_host'])) { return [null, 'nokey']; }
    return wk_http('http://' . $cfg['jf_host'] . ':' . (int)$cfg['jf_port'] . $path, ['Authorization: MediaBrowser Token="' . $cfg['jf_key'] . '"'], 6);
}

function wk_iso($ts) { return gmdate('Y-m-d\TH:i:s\Z', $ts); }
function wk_ts($iso) { $t = strtotime((string)$iso); return $t ? (int)$t : 0; }

// Monday 00:00 of this week (local time) and the next Monday 00:00
function wk_week($now) {
    $mon = strtotime('monday this week 00:00', $now);
    return [$mon, strtotime('+7 days', $mon)];
}

function wk_median($a) {
    sort($a);
    $n = count($a);
    if (!$n) { return null; }
    return ($n % 2) ? $a[intdiv($n, 2)] : (int)round(($a[$n / 2 - 1] + $a[$n / 2]) / 2);
}

// "nCore (Prowlarr)" -> "nCore"
function wk_idx($s) { $s = trim((string)preg_replace('/\s*\(Prowlarr\)\s*$/i', '', (string)$s)); return $s !== '' ? $s : '?'; }

// Grab delays of the last 60 days: [seriesId, indexer, seconds from air time to the first grab], cached for 6 hours
function wk_samples($son, $now) {
    $c = is_file(WK_DELAY) ? json_decode((string)@file_get_contents(WK_DELAY), true) : null;
    $sig = md5($son[0]);
    if (is_array($c) && ($c['sig'] ?? '') === $sig && ($now - (int)($c['t'] ?? 0)) < 6 * 3600 && is_array($c['s'] ?? null)) { return $c['s']; }
    [$h, $e] = wk_son($son, '/history/since?date=' . rawurlencode(wk_iso($now - 60 * 86400)) . '&eventType=1&includeEpisode=true', 20);
    if (!is_array($h)) { return is_array($c['s'] ?? null) ? $c['s'] : []; }
    $first = [];
    foreach ($h as $r) {
        if (!is_array($r)) { continue; }
        $air = wk_ts($r['episode']['airDateUtc'] ?? '');
        $at = wk_ts($r['date'] ?? '');
        $eid = (int)($r['episodeId'] ?? 0);
        if (!$air || !$at || !$eid) { continue; }
        $d = $at - $air;
        if ($d < 0 || $d > 3 * 86400) { continue; }   // grabbed before airing or a late backfill: not a release delay
        if (!isset($first[$eid]) || $d < $first[$eid][2]) { $first[$eid] = [(int)($r['seriesId'] ?? 0), wk_idx($r['data']['indexer'] ?? ''), $d]; }
    }
    $s = array_values($first);
    @file_put_contents(WK_DELAY, json_encode(['t' => $now, 'sig' => $sig, 's' => $s]));
    return $s;
}

// Usual delay for one series: [seconds or null, samples, basis 'series'|'all'|'none', per indexer [[name, seconds, samples]]]
function wk_delay($samples, $sid, $mode) {
    $ser = array_values(array_filter($samples, function ($r) use ($sid) { return $r[0] === $sid; }));
    if ($mode === 'all') {
        $all = array_column($samples, 2);
        return count($all) >= 3 ? [wk_median($all), count($all), 'all', []] : [null, count($all), 'none', []];
    }
    $base = count($ser) >= 3 ? [wk_median(array_column($ser, 2)), count($ser), 'series']
          : (count($samples) >= 3 ? [wk_median(array_column($samples, 2)), count($samples), 'all'] : [null, count($samples), 'none']);
    $idx = [];
    if ($mode === 'idx') {
        // only the indexers this series came from before (a new series: every indexer)
        $names = array_unique(array_column($ser ?: $samples, 1));
        foreach ($names as $n) {
            $s1 = array_column(array_filter($ser, function ($r) use ($n) { return $r[1] === $n; }), 2);
            $s2 = array_column(array_filter($samples, function ($r) use ($n) { return $r[1] === $n; }), 2);
            if (count($s1) >= 3) { $idx[] = [$n, wk_median($s1), count($s1)]; }
            elseif (count($s2) >= 3) { $idx[] = [$n, wk_median($s2), count($s2)]; }
        }
        usort($idx, function ($a, $b) { return $a[1] <=> $b[1]; });
        if ($idx) { $base[0] = $idx[0][1]; }   // the earliest indexer decides when it is expected
    }
    $base[] = $idx;
    return $base;
}

// Sonarr's poster of a series (its public artwork address), '' when it has none
function wk_poster($imgs) {
    foreach ((array)$imgs as $i) {
        $u = is_array($i) ? (string)($i['remoteUrl'] ?? '') : '';
        if (($i['coverType'] ?? '') === 'poster' && preg_match('#^https://[^\s"<>]+$#', $u)) { return $u; }
    }
    return '';
}

// Series Jellyfin lists: tvdb id and normalized name -> Jellyfin item id; null when Jellyfin cannot be asked
function wk_jf_series($cfg) {
    [$j] = wk_jf($cfg, '/Items?IncludeItemTypes=Series&Recursive=true&Fields=ProviderIds&EnableImages=false');
    if (!is_array($j)) { return null; }
    $m = [];
    foreach ((array)($j['Items'] ?? []) as $it) {
        if (!is_array($it) || empty($it['Id'])) { continue; }
        $tv = (string)($it['ProviderIds']['Tvdb'] ?? '');
        if ($tv !== '') { $m['t' . $tv] = (string)$it['Id']; }
        $m['n' . wk_norm($it['Name'] ?? '')] = (string)$it['Id'];
        $m['N' . $it['Id']] = (string)($it['Name'] ?? ''); // the name Jellyfin shows (its metadata language, e.g. Hungarian)
    }
    return $m;
}

function wk_norm($s) { return preg_replace('/[^a-z0-9]+/', '', strtolower((string)preg_replace('/\s*\(\d{4}\)\s*$/', '', (string)$s))); }

// Episodes Jellyfin already lists: tvdb ids and "series|S|E" keys (value: the Jellyfin item id); null when Jellyfin cannot be asked
function wk_jf_have($cfg, $from, $to) {
    $have = [];
    $ok = false;
    $paths = [
        '/Items?IncludeItemTypes=Episode&Recursive=true&MinPremiereDate=' . rawurlencode(wk_iso($from - 2 * 86400)) . '&MaxPremiereDate=' . rawurlencode(wk_iso($to + 86400)) . '&Fields=ProviderIds&EnableImages=false&Limit=500',
        '/Items?IncludeItemTypes=Episode&Recursive=true&SortBy=DateCreated&SortOrder=Descending&Fields=ProviderIds&EnableImages=false&Limit=200',
    ];
    foreach ($paths as $p) {
        [$j, $e] = wk_jf($cfg, $p);
        if (!is_array($j)) { continue; }
        $ok = true;
        foreach ((array)($j['Items'] ?? []) as $it) {
            if (!is_array($it)) { continue; }
            $tv = (string)($it['ProviderIds']['Tvdb'] ?? '');
            $id = (string)($it['Id'] ?? '') ?: '1';
            if ($tv !== '') { $have['t' . $tv] = $id; }
            if (isset($it['ParentIndexNumber'], $it['IndexNumber'])) {
                $have[wk_norm($it['SeriesName'] ?? '') . '|' . (int)$it['ParentIndexNumber'] . '|' . (int)$it['IndexNumber']] = $id;
            }
        }
    }
    return $ok ? $have : null;
}

// The whole band: ['ok', 'error', 'week' => [from, to], 'days' => [...], 'counts' => [...]]
function wk_build($cfg, $now) {
    $son = wk_sonarr($cfg);
    [$from, $to] = wk_week($now);
    $out = ['ok' => false, 'error' => '', 'from' => $from, 'to' => $to, 'days' => [], 'counts' => ['jf' => 0, 'dl' => 0, 'late' => 0, 'all' => 0]];
    if ($son[0] === '' || $son[1] === '') { $out['error'] = 'nosonarr'; return $out; }
    [$cal, $e] = wk_son($son, '/calendar?start=' . rawurlencode(wk_iso($from)) . '&end=' . rawurlencode(wk_iso($to)) . '&unmonitored=false&includeSeries=true');
    if (!is_array($cal)) { $out['error'] = $e; return $out; }
    [$q] = wk_son($son, '/queue?page=1&pageSize=500&includeEpisode=false');
    $queue = [];
    foreach ((array)($q['records'] ?? []) as $r) {
        if (is_array($r) && !empty($r['episodeId'])) { $queue[(int)$r['episodeId']] = $r; }
    }
    $samples = wk_samples($son, $now);
    $have = null;
    foreach ($cal as $ep) { if (!empty($ep['hasFile'])) { $have = wk_jf_have($cfg, $from, $to); break; } }
    $late = max(0, (float)$cfg['wk_late']) * 3600;
    $mode = in_array((string)$cfg['wk_delay'], ['series', 'idx', 'all'], true) ? (string)$cfg['wk_delay'] : 'series';

    // A new series (its first episode airs this week): NEW badge on its rows. Sonarr's firstAired, or S01E01 in the week.
    $newSer = [];
    $d0 = date('Y-m-d', $from); $d1 = date('Y-m-d', $to);
    foreach ($cal as $ep) {
        if (!is_array($ep)) { continue; }
        $fa = substr((string)($ep['series']['firstAired'] ?? ''), 0, 10);
        $air = wk_ts($ep['airDateUtc'] ?? '');
        $first = (int)($ep['seasonNumber'] ?? 0) === 1 && (int)($ep['episodeNumber'] ?? 0) === 1 && $air >= $from && $air < $to;
        if ($first || ($fa !== '' && $fa >= $d0 && $fa < $d1)) { $newSer[(int)($ep['seriesId'] ?? 0)] = 1; }
    }

    $rows = [];
    foreach ($cal as $ep) {
        if (!is_array($ep) || empty($ep['monitored'])) { continue; }
        $air = wk_ts($ep['airDateUtc'] ?? '');
        if (!$air || $air < $from || $air >= $to) { continue; }
        $sid = (int)($ep['seriesId'] ?? 0);
        $ser = (string)($ep['series']['title'] ?? '?');
        $sn = (int)($ep['seasonNumber'] ?? 0); $en = (int)($ep['episodeNumber'] ?? 0);
        [$d, $n, $basis, $idx] = wk_delay($samples, $sid, $mode);
        $exp = $d === null ? null : $air + $d;
        $row = ['id' => (int)($ep['id'] ?? 0), 'series' => $ser, 'code' => sprintf('S%02dE%02d', $sn, $en), 'title' => (string)($ep['title'] ?? ''),
                'air' => $air, 'exp' => $exp, 'delay' => $d, 'n' => $n, 'basis' => $basis, 'idx' => [], 'st' => '', 'pct' => null, 'left' => null, 'new' => isset($newSer[$sid]),
                'tvdb' => (string)($ep['series']['tvdbId'] ?? ''), 'poster' => wk_poster($ep['series']['images'] ?? []), 'jfid' => ''];
        foreach ($idx as $i) { $row['idx'][] = [$i[0], $air + $i[1]]; }
        $qr = $queue[$row['id']] ?? null;
        if (!empty($ep['hasFile'])) {
            if ($have === null) { $row['st'] = 'file'; }
            else {
                $tv = (string)($ep['tvdbId'] ?? '');
                $in = ($tv !== '' && $tv !== '0' && isset($have['t' . $tv])) ? $have['t' . $tv] : ($have[wk_norm($ser) . '|' . $sn . '|' . $en] ?? '');
                $row['st'] = $in !== '' ? 'jf' : 'scan';   // imported, Jellyfin has not listed it yet
                $row['jfid'] = $in !== '1' ? $in : '';
            }
        } elseif ($qr !== null) {
            $ts = strtolower((string)($qr['trackedDownloadState'] ?? ''));
            $qs = strtolower((string)($qr['status'] ?? ''));
            $size = (float)($qr['size'] ?? 0);
            if ($ts === 'failed' || $ts === 'failedpending' || $qs === 'failed') { $row['st'] = 'fail'; }
            elseif (in_array($ts, ['importpending', 'importing', 'imported'], true) || $qs === 'completed') { $row['st'] = 'imp'; }
            elseif ($qs === 'paused') { $row['st'] = 'pause'; }
            elseif ($size > 0 && (float)($qr['sizeleft'] ?? $size) < $size) {
                $row['st'] = 'dl';
                $row['pct'] = round(100 * (1 - (float)$qr['sizeleft'] / $size));
                if (preg_match('/^(?:(\d+)\.)?(\d+):(\d+):(\d+)/', (string)($qr['timeleft'] ?? ''), $m)) { $row['left'] = (int)$m[1] * 86400 + (int)$m[2] * 3600 + (int)$m[3] * 60 + (int)$m[4]; }
            } else { $row['st'] = 'grab'; }
        } elseif ($air > $now) { $row['st'] = 'wait'; }
        elseif ($now > ($exp ?? $air) + $late) { $row['st'] = 'late'; }
        else { $row['st'] = 'aired'; }
        $rows[] = $row;
    }
    usort($rows, function ($a, $b) { return [$a['air'], $a['series']] <=> [$b['air'], $b['series']]; });

    foreach ($rows as $r) {
        $k = date('Y-m-d', $r['air']);
        if (!isset($out['days'][$k])) { $out['days'][$k] = ['label' => date('D j M', $r['air']), 'ts' => strtotime($k . ' 12:00'), 'today' => $k === date('Y-m-d', $now), 'past' => $k < date('Y-m-d', $now), 'rows' => []]; }
        $out['days'][$k]['rows'][] = $r;
        $out['counts']['all']++;
        if ($r['st'] === 'jf' || $r['st'] === 'file') { $out['counts']['jf']++; }
        if ($r['st'] === 'dl' || $r['st'] === 'grab') { $out['counts']['dl']++; }
        if ($r['st'] === 'late') { $out['counts']['late']++; }
    }
    $out['days'] = array_values($out['days']);
    $out['samples'] = count($samples);
    $out['ok'] = true;
    return $out;
}
