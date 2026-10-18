<?php
// The tile's "This week's episodes" band (JSON, cached for a minute). ?test=1 from the settings page checks the Sonarr connection.
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/week_lib.php';

wk_tz();
$cfg = wk_cfg();
$now = time();
$show = (int)$cfg['wk_show'];

if (!empty($_GET['test'])) {
    $son = wk_sonarr($cfg);
    if ($son[0] === '' || $son[1] === '') {
        echo json_encode(['ok' => false, 'message' => (string)$cfg['wk_src'] === 'own' ? 'Enter the Sonarr address and API key, then Apply.' : 'Seedbox has no Sonarr address and key (Seedbox Tools, Connections).']);
        exit;
    }
    [$st, $e] = wk_son($son, '/system/status');
    if (!is_array($st)) { echo json_encode(['ok' => false, 'message' => 'Sonarr: ' . ($e === 'auth' ? 'the API key was rejected' : $e)]); exit; }
    @unlink(WK_DELAY);
    $n = count(wk_samples($son, $now));
    echo json_encode(['ok' => true, 'message' => 'Sonarr ' . (string)($st['version'] ?? '?') . ' answers; the usual delay is learned from ' . $n . ' grab' . ($n === 1 ? '' : 's') . ' of the last 60 days.']);
    exit;
}

if ($show <= 0) { echo json_encode(['ok' => false, 'show' => 0]); exit; }

$c = is_file(WK_CACHE) ? json_decode((string)@file_get_contents(WK_CACHE), true) : null;
if (is_array($c) && ($now - (int)($c['t'] ?? 0)) < 60 && is_array($c['w'] ?? null) && $now < (int)($c['w']['to'] ?? 0)) {
    $w = $c['w'];
} else {
    $w = wk_build($cfg, $now);
    if ($w['ok']) { @file_put_contents(WK_CACHE, json_encode(['t' => $now, 'w' => $w], JSON_INVALID_UTF8_SUBSTITUTE)); }
    elseif (is_array($c['w'] ?? null) && $w['error'] !== 'nosonarr' && $now < (int)($c['w']['to'] ?? 0)) { $w = $c['w']; }   // Sonarr hiccup: last known list
}
$w['show'] = $show;
$w['now'] = $now;
echo json_encode($w, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
