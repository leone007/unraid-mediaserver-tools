<?php
// Started by cron every 2 minutes: the "Ezen a héten" row on Jellyfin's home page (web client and Jellyfin Desktop only).
// Writes week.json (series, episode codes, times and states; no keys, no addresses) and the renderer week.js into a folder
// that the Jellyfin container serves read-only under /web/streambox/ (a path mapping). The JavaScript Injector plugin loads
// week.js. Read-only towards Sonarr and Jellyfin; writes only into that folder.
// week.js also loads extras.js (IMDb ratings, Watched menu: jx_lib.php), so the folder and the loader serve both.
if (PHP_SAPI !== 'cli') { exit(1); } // cron only, not a web page
require_once __DIR__ . '/week_lib.php';
require_once __DIR__ . '/jx_lib.php';

$cfg = wk_cfg();
$jxOn = (string)($cfg['jx_on'] ?? '1') === '1';
if ((string)$cfg['wk_web'] !== '1' && !$jxOn) { exit(0); }
$dir = rtrim(trim((string)$cfg['wk_web_dir']), '/');
if (!wk_web_dir_ok($dir)) { exit(0); }
// The pool or share is mounted only while the array runs: never create its top folder (/mnt/cache) ourselves
$top = '/mnt/' . explode('/', substr($dir, 5))[0];
if (!is_dir($top)) { exit(0); }
if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
if (!is_dir($dir)) { exit(0); }
$lock = @fopen('/tmp/streambox_web.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { exit(0); }

function ww_put($f, $data) {
    if (is_file($f) && (string)@file_get_contents($f) === $data) { return; }
    if (@file_put_contents($f . '.tmp', $data) === false) { return; }
    @chmod($f . '.tmp', 0644);
    @rename($f . '.tmp', $f);
}

// The renderer ships with the plugin; copy it when it changed
$js = (string)@file_get_contents(__DIR__ . '/week_web.js');
if ($js !== '') { ww_put($dir . '/week.js', $js); }

wk_tz();
$now = time();
jx_run($cfg, $dir, $now);
if ((string)$cfg['wk_web'] !== '1') { ww_put($dir . '/week.json', json_encode(['ok' => false, 'gen' => $now, 'off' => true])); exit(0); }
// the tile's list when it is under a minute old (week.php writes it), else a fresh one (item 104: Sonarr was asked twice)
$c = is_file(WK_CACHE) ? json_decode((string)@file_get_contents(WK_CACHE), true) : null;
if (is_array($c) && ($now - (int)($c['t'] ?? 0)) < 60 && is_array($c['w'] ?? null) && $now < (int)($c['w']['to'] ?? 0)) { $w = $c['w']; }
else {
    $w = wk_build($cfg, $now);
    if ($w['ok']) { @file_put_contents(WK_CACHE, json_encode(['t' => $now, 'w' => $w], JSON_INVALID_UTF8_SUBSTITUTE)); }
}
$jf = $dir . '/week.json';
if (!$w['ok']) {
    // Sonarr hiccup: keep the last list while its week lasts, then say there is nothing to show; without Sonarr settings
    // (removed, or the source changed) the old list goes at once, as on the tile (item 104)
    $old = is_file($jf) ? json_decode((string)@file_get_contents($jf), true) : null;
    if (($w['error'] ?? '') !== 'nosonarr' && is_array($old) && !empty($old['ok']) && $now < (int)($old['to'] ?? 0)) { exit(0); }
    ww_put($jf, json_encode(['ok' => false, 'gen' => $now]));
    exit(0);
}

// Jellyfin's series list changes rarely: asked at most every 15 minutes (it was every 2 minutes)
$ser = null;
if ($w['counts']['all']) {
    $sf = '/tmp/streambox_jfseries.json'; $sc = is_file($sf) ? json_decode((string)@file_get_contents($sf), true) : null;
    if (is_array($sc) && $now - (int)($sc['t'] ?? 0) < 900 && is_array($sc['m'] ?? null)) { $ser = $sc['m']; }
    else { $ser = wk_jf_series($cfg); if (is_array($ser)) { @file_put_contents($sf, json_encode(['t' => $now, 'm' => $ser], JSON_INVALID_UTF8_SUBSTITUTE)); } }
}
$eps = [];
foreach ($w['days'] as $day) {
    foreach ($day['rows'] as $r) {
        $sid = '';
        if (is_array($ser)) { $sid = $ser['t' . $r['tvdb']] ?? ($ser['n' . wk_norm($r['series'])] ?? ''); }
        $nm = $sid !== '' ? (string)($ser['N' . $sid] ?? '') : '';
        $eps[] = ['s' => $nm !== '' ? $nm : $r['series'], 'c' => $r['code'], 't' => $r['title'], 'air' => $r['air'], 'exp' => $r['exp'],
                  'st' => $r['st'], 'pct' => $r['pct'], 'new' => $r['new'], 'ep' => $r['jfid'], 'sid' => $sid,
                  'img' => $sid === '' ? $r['poster'] : ''];
    }
}
// Episode titles in Jellyfin's language for the episodes it already has (one request); the rest keep Sonarr's title
$ids = array_values(array_unique(array_filter(array_column($eps, 'ep'), function ($v) { return $v !== '' && $v !== '1'; })));
if ($ids) {
    [$j] = wk_jf($cfg, '/Items?Ids=' . implode(',', array_slice($ids, 0, 200)) . '&EnableImages=false');
    $tn = [];
    foreach ((array)($j['Items'] ?? []) as $it) { if (is_array($it) && !empty($it['Id'])) { $tn[(string)$it['Id']] = (string)($it['Name'] ?? ''); } }
    foreach ($eps as &$x) { if (($tn[$x['ep']] ?? '') !== '') { $x['t'] = $tn[$x['ep']]; } }
    unset($x);
}
$out = ['ok' => true, 'gen' => $now, 'view' => (string)$cfg['wk_web_view'] === 'row' ? 'row' : 'grid', 'from' => $w['from'], 'to' => $w['to'], 'late' => max(1, (float)$cfg['wk_late']) * 3600, 'eps' => $eps];
ww_put($jf, json_encode($out, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
