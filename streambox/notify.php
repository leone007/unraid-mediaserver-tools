<?php
// Started by cron every minute: post a Discord card for each new movie and each new season.
if (PHP_SAPI !== 'cli') { exit(1); } // cron only, not a web page
require_once __DIR__ . '/notify_lib.php';

$cfg = nf_cfg();
if (!nf_on($cfg['notify']) || (string)$cfg['jf_key'] === '') { exit(0); }
$hookMovies = nf_hook($cfg['dc_movies']);
$hookSeries = nf_hook($cfg['dc_series']);
if ($hookMovies === '' && $hookSeries === '') { exit(0); }
if (!preg_match('/^[A-Za-z0-9.\-]+$/', (string)$cfg['jf_host'])) { exit(0); }

$lock = @fopen('/tmp/streambox_notify.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { exit(0); }

$sf  = '/boot/config/plugins/streambox/notify_state.json';
$st  = is_file($sf) ? json_decode((string)@file_get_contents($sf), true) : null;
$now = time();

function nf_save_state($sf, $st) {
    $tmp = $sf . '.tmp';
    $data = json_encode($st);
    if (@file_put_contents($tmp, $data) === false || !@rename($tmp, $sf)) { @file_put_contents($sf, $data); @unlink($tmp); }
}

if (!is_array($st) || !isset($st['since'])) {
    // first run: only things added from now on are announced
    nf_save_state($sf, ['since' => $now, 'done' => []]);
    nf_log('notifier started, announcing items added from now on');
    exit(0);
}

$since  = (int)$st['since'];
$done   = array_flip(array_map('strval', (array)($st['done'] ?? [])));
$settle = max(1, (int)$cfg['notify_settle']) * 60;
$wait   = max(1, (int)$cfg['notify_wait']) * 60;
$posted = 0;
$changed = false;
$stop = false;
$MAX_CARDS = 6;
$BULK = 20;

// ---- movies -----------------------------------------------------------------------------------
if ($hookMovies !== '') {
    $d = nf_json($cfg, '/Items?IncludeItemTypes=Movie&Recursive=true&SortBy=DateCreated&SortOrder=Descending&Limit=60&Fields=' . NF_FIELDS);
    $pending = [];
    foreach ((array)($d['Items'] ?? []) as $m) {
        $id = (string)($m['Id'] ?? '');
        if ($id === '' || isset($done[$id])) { continue; }
        $ts = nf_ts($m['DateCreated'] ?? '');
        if ($ts <= $since) { continue; }
        $m['_ts'] = $ts;
        $pending[] = $m;
    }
    usort($pending, function ($a, $b) { return $a['_ts'] <=> $b['_ts']; });
    if (count($pending) > $BULK) {
        foreach (array_slice($pending, 0, count($pending) - $BULK) as $m) { $done[(string)$m['Id']] = 1; }
        nf_log('bulk import: skipped ' . (count($pending) - $BULK) . ' older movies');
        $pending = array_slice($pending, -$BULK);
        $changed = true;
    }
    foreach ($pending as $m) {
        if ($posted >= $MAX_CARDS || $stop) { break; }
        $id = (string)$m['Id'];
        if (!nf_ready($m) && ($now - $m['_ts']) < $wait) { continue; }   // metadata and poster not in yet
        $card = nf_card_movie($cfg, $m);
        list($res, $msg) = nf_send($hookMovies, $card['payload'], $card['poster'], $card['backdrop']);
        if ($res === 'retry') { nf_log('movie "' . ($m['Name'] ?? $id) . '": ' . $msg . ', will retry'); $stop = true; break; }
        $done[$id] = 1;
        $changed = true;
        $posted++;
        if ($res === 'fail') { nf_log('movie "' . ($m['Name'] ?? $id) . '" not sent: ' . $msg); }
        else { nf_log('movie card sent: ' . ($m['Name'] ?? $id)); }
    }
}

// ---- seasons ----------------------------------------------------------------------------------
if ($hookSeries !== '' && !$stop) {
    $d = nf_json($cfg, '/Items?IncludeItemTypes=Episode&Recursive=true&SortBy=DateCreated&SortOrder=Descending&Limit=300&Fields=' . NF_FIELDS);
    $groups = [];
    foreach ((array)($d['Items'] ?? []) as $e) {
        $id = (string)($e['Id'] ?? '');
        if ($id === '' || isset($done[$id])) { continue; }
        $ts = nf_ts($e['DateCreated'] ?? '');
        if ($ts <= $since) { continue; }
        $e['_ts'] = $ts;
        $key = (string)($e['SeasonId'] ?? '') !== '' ? (string)$e['SeasonId'] : ((string)($e['SeriesId'] ?? '') . '-' . (int)($e['ParentIndexNumber'] ?? 0));
        $groups[$key][] = $e;
    }
    foreach ($groups as $k => $eps) {
        $min = PHP_INT_MAX; $max = 0;
        foreach ($eps as $e) { $min = min($min, $e['_ts']); $max = max($max, $e['_ts']); }
        $groups[$k] = ['eps' => $eps, 'min' => $min, 'max' => $max];
    }
    uasort($groups, function ($a, $b) { return $a['min'] <=> $b['min']; });
    if (count($groups) > 15) {
        $skip = array_slice($groups, 0, count($groups) - 15, true);
        foreach ($skip as $g) { foreach ($g['eps'] as $e) { $done[(string)$e['Id']] = 1; } }
        nf_log('bulk import: skipped ' . count($skip) . ' older seasons');
        $groups = array_slice($groups, -15, null, true);
        $changed = true;
    }
    foreach ($groups as $g) {
        if ($posted >= $MAX_CARDS || $stop) { break; }
        if (($now - $g['max']) < $settle) { continue; }   // more episodes may still be arriving
        $eps = $g['eps'];
        $series = nf_item($cfg, (string)($eps[0]['SeriesId'] ?? ''));
        if (!nf_ready($series) && ($now - $g['min']) < $wait) { continue; }
        $season = nf_item($cfg, (string)($eps[0]['SeasonId'] ?? ''));
        $card = nf_card_season($cfg, $series ?: [], $season ?: [], $eps);
        list($res, $msg) = nf_send($hookSeries, $card['payload'], $card['poster'], $card['backdrop']);
        $label = (string)($eps[0]['SeriesName'] ?? '?') . ' S' . sprintf('%02d', (int)($eps[0]['ParentIndexNumber'] ?? 0));
        if ($res === 'retry') { nf_log('season "' . $label . '": ' . $msg . ', will retry'); $stop = true; break; }
        foreach ($eps as $e) { $done[(string)$e['Id']] = 1; }
        $changed = true;
        $posted++;
        if ($res === 'fail') { nf_log('season "' . $label . '" not sent: ' . $msg); }
        else { nf_log('season card sent: ' . $label . ' (' . count($eps) . ' episodes)'); }
    }
}

if ($changed) {
    nf_save_state($sf, ['since' => $since, 'done' => array_slice(array_keys($done), -400)]);
}
