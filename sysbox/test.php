<?php
// Test and sample messages for the Discord alerts. POST op=test|sample|weekly (uses the saved settings).
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/discord.php';
require_once __DIR__ . '/alerts.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { sx_json(['ok' => false, 'error' => 'method'], 405); }
sx_guard();
$cfg = sx_cfg();
$hook = (string)$cfg['dc_hook'];
if (!sxn_hook_ok($hook)) { sx_json(['ok' => false, 'error' => 'No valid Discord webhook is saved yet. Enter it, press Apply, then try again.']); }
$lang = sxn_lang($cfg);
$op = (string)($_POST['op'] ?? 'test');

if ($op === 'weekly') {
    $r = sxn_post($hook, [sxn_e_weekly($lang, $cfg, sxn_state())]);
    sx_json($r[0] === 'ok' ? ['ok' => true] : ['ok' => false, 'error' => $r[1]]);
}
if ($op === 'sample') {
    $tag = '[' . sxn_t($lang, 'sample') . '] ';
    $m = ['total' => 33.4e9, 'avail' => 3.1e9, 'arc' => 1.1e9, 'app' => 29.2e9, 'pct' => 93.0];
    $emb = [
        sxn_e_down($lang, 'jellyfin', 137, true, 'alert'),
        sxn_e_loop($lang, 'qbittorrent', 5, 10, 1, 'alert'),
        sxn_e_unhealthy($lang, 'seerr', 'alert'),
        sxn_e_ram($lang, $m, 'rd', 'alert'),
        sxn_e_storage($lang, 'DOCKER VDISK', ['size' => 53.6e9, 'used' => 49.3e9, 'pct' => 92.0], 'rd', 'alert'),
        sxn_e_cpu($lang, 82, 75, 90, 'a', 'alert'),
        sxn_e_boot($lang, time() - 420, time() - 1500),
        sxn_e_down($lang, 'jellyfin', 0, false, 'resolved'),
    ];
    foreach ($emb as $e) {
        $e['title'] = $tag . $e['title'];
        $r = sxn_post($hook, [$e]);
        if ($r[0] !== 'ok') { sx_json(['ok' => false, 'error' => $r[1]]); }
    }
    sx_json(['ok' => true]);
}
$r = sxn_post($hook, [sxn_embed('i', '✅ ' . sxn_t($lang, 'test_t'), sxn_t($lang, 'test_d'))], sxn_ping_text($cfg['dc_ping']));
sx_json($r[0] === 'ok' ? ['ok' => true] : ['ok' => false, 'error' => $r[1]]);
