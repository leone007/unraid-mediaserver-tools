<?php
// Jellyfin extras (item 86): IMDb ratings on cards and detail pages, a Watched menu and a "Watched too" switch on the home page.
// Called by week_web.php (cron, every 2 minutes). Writes extras.js (the renderer with its texts in English and Hungarian)
// and imdb.json (Jellyfin item id -> IMDb rating, votes, IMDb id) into the same folder as week.js.
// Ratings: IMDb's public daily file title.ratings.tsv.gz (https://datasets.imdbws.com, personal non-commercial use), downloaded
// at most once a day and kept in /tmp; only the titles of this library go into imdb.json. Read-only towards Jellyfin (GET).

define('JX_TMP', getenv('SB_JX_TMP') ?: '/tmp');   // SB_JX_TMP, SB_JX_URL: tests only
define('JX_GZ', JX_TMP . '/streambox_imdb_ratings.tsv.gz');
define('JX_ITEMS', JX_TMP . '/streambox_jx_items.json');
define('JX_URL', getenv('SB_JX_URL') ?: 'https://datasets.imdbws.com/title.ratings.tsv.gz');

// The renderer and its texts (both languages: the browser picks Jellyfin's language, English when it has no translation)
function jx_script($on) {
    if (!$on) { return "/* Streambox: IMDb and Watched are switched off in the Streambox settings */\n"; }
    $js = (string)@file_get_contents(__DIR__ . '/jx_web.js');
    if ($js === '') { return ''; }
    $t = [];
    foreach (['en', 'hu'] as $c) {
        $f = __DIR__ . '/lang/' . $c . '.json';
        $j = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
        if (!is_array($j)) { continue; }
        foreach ($j as $k => $v) {
            if (strpos((string)$k, 'jx.') !== 0 || !is_array($v)) { continue; }
            foreach (['text', 'one', 'other'] as $x) { if (isset($v[$x]) && trim((string)$v[$x]) !== '') { $t[$c][$k][$x] = (string)$v[$x]; } }
        }
    }
    return 'window.__sbxT=' . json_encode($t, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . ";\n" . $js;
}

// IMDb ids of the library (movies, series, episodes), refreshed every 30 minutes: [jellyfin id => tt id]
function jx_items($cfg, $now) {
    $old = is_file(JX_ITEMS) ? json_decode((string)@file_get_contents(JX_ITEMS), true) : null;
    if (is_array($old) && $now - (int)($old['gen'] ?? 0) < 1800 && is_array($old['m'] ?? null)) { return [$old['m'], false]; }
    if ((string)$cfg['jf_key'] === '' || !preg_match('/^[A-Za-z0-9.\-]+$/', (string)$cfg['jf_host'])) { return [null, false]; }
    $m = [];
    for ($start = 0, $page = 0; $page < 40; $page++, $start += 5000) {
        [$j] = wk_http('http://' . $cfg['jf_host'] . ':' . (int)$cfg['jf_port'] . '/Items?Recursive=true&IncludeItemTypes=Movie,Series,Episode'
            . '&Fields=ProviderIds&EnableImages=false&EnableUserData=false&StartIndex=' . $start . '&Limit=5000',
            ['Authorization: MediaBrowser Token="' . $cfg['jf_key'] . '"'], 40);
        if (!is_array($j)) { return [is_array($old['m'] ?? null) ? $old['m'] : null, false]; }   // Jellyfin hiccup: keep the last list
        $items = (array)($j['Items'] ?? []);
        foreach ($items as $it) {
            if (!is_array($it) || empty($it['Id'])) { continue; }
            $tt = (string)($it['ProviderIds']['Imdb'] ?? ($it['ProviderIds']['IMDB'] ?? ''));
            if (preg_match('/^tt\d{5,10}$/', $tt)) { $m[(string)$it['Id']] = $tt; }
        }
        if (count($items) < 5000) { break; }
    }
    @file_put_contents(JX_ITEMS, json_encode(['gen' => $now, 'm' => $m]));
    $changed = !is_array($old['m'] ?? null) || $old['m'] != $m;
    return [$m, $changed];
}

// IMDb's ratings file, at most once a day (a failed try waits an hour). True when a new file came.
function jx_fetch_gz($now) {
    $have = is_file(JX_GZ) ? (int)@filemtime(JX_GZ) : 0;
    $tried = (int)@filemtime(JX_GZ . '.try');
    if ($have && $now - $have < 86400) { return false; }
    if ($now - $tried < 3600) { return false; }
    @touch(JX_GZ . '.try');
    $tmp = JX_GZ . '.part';
    $fh = @fopen($tmp, 'w');
    if (!$fh) { return false; }
    $ch = curl_init(JX_URL);
    curl_setopt_array($ch, [CURLOPT_FILE => $fh, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 120, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'Streambox (Unraid plugin)']);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_errno($ch);
    curl_close($ch);
    fclose($fh);
    // a real file: HTTP 200, gzip magic, over 1 MB
    $ok = !$err && $code === 200 && @filesize($tmp) > 1000000 && @file_get_contents($tmp, false, null, 0, 2) === "\x1f\x8b";
    if (!$ok) { @unlink($tmp); return false; }
    @rename($tmp, JX_GZ);
    return true;
}

// The ratings of the wanted IMDb ids: [tt => [rating x 10, votes]]
function jx_ratings($want) {
    $out = [];
    $fh = @gzopen(JX_GZ, 'r');
    if (!$fh) { return null; }
    while (($l = gzgets($fh, 256)) !== false) {
        $p = strpos($l, "\t");
        if ($p === false) { continue; }
        $tt = substr($l, 0, $p);
        if (!isset($want[$tt])) { continue; }
        $f = explode("\t", rtrim($l, "\r\n"));
        if (count($f) >= 3 && is_numeric($f[1])) { $out[$tt] = [(int)round((float)$f[1] * 10), (int)$f[2]]; }
    }
    gzclose($fh);
    return $out;
}

function jx_run($cfg, $dir, $now) {
    $on = (string)($cfg['jx_on'] ?? '1') === '1';
    $js = jx_script($on);
    if ($js !== '') { ww_put($dir . '/extras.js', $js); }
    if (!$on) { return; }
    [$m, $changed] = jx_items($cfg, $now);
    if (!is_array($m)) { return; }
    $fresh = jx_fetch_gz($now);
    $out = $dir . '/imdb.json';
    if (!$fresh && !$changed && is_file($out) && is_file(JX_GZ) && @filemtime($out) >= @filemtime(JX_GZ)) { return; }
    if (!is_file(JX_GZ)) { return; }
    $r = jx_ratings(array_flip(array_values($m)));
    if ($r === null) { return; }
    $items = [];
    foreach ($m as $id => $tt) { $items[$id] = isset($r[$tt]) ? [$r[$tt][0], $r[$tt][1], $tt] : [0, 0, $tt]; }
    ww_put($out, json_encode(['gen' => $now, 'src' => (int)@filemtime(JX_GZ), 'r' => (object)$items], JSON_UNESCAPED_SLASHES));
}
