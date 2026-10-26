<?php
// Test and sample messages for the Discord alerts. POST op=test|sample|weekly|ups (uses the saved settings).
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/discord.php';
require_once __DIR__ . '/alerts.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { sx_json(['ok' => false, 'error' => 'method'], 405); }
sx_guard();
$cfg = sx_cfg();
$hook = (string)$cfg['dc_hook'];
if (!sxn_hook_ok($hook)) { sx_json(['ok' => false, 'error' => sx_tr('test.no_hook')]); }
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
// power outage (item 99): what the first message will show, with the UPS values of now
if ($op === 'ups') {
    require_once __DIR__ . '/ups_lib.php';
    [$U, $ue] = sx_ups_fetch((string)$cfg['ups_host'], (int)$cfg['ups_port'], (string)$cfg['ups_name']);
    if ($U === null) { sx_json(['ok' => false, 'error' => sx_tr('test.no_nut', ['e' => $ue])]); }
    $rule = ups_nut_rule();
    $foot = (ups_bot_on($cfg) ? sx_trd(sx_on($cfg['ups_confirm']) ? 'ups.d.how_stop_ok' : 'ups.d.how_stop') : sx_trd('ups.d.how_page')) . ($rule['level'] !== null ? ' · ' . sx_trd('ups.d.nut_at', ['l' => $rule['level']]) : '');
    $f = ups_fields($U, null); $f[] = [sx_trd('ups.d.input'), $U['vin'] === null ? '–' : round($U['vin']) . ' V', true];
    $r = sxn_post($hook, [sxn_embed('i', '🧪 ' . sx_trd('ups.d.test_t'), sx_trd('ups.d.test_d', ['m' => $U['model'] !== '' ? $U['model'] : 'UPS']), $f, $foot)]);
    sx_json($r[0] === 'ok' ? ['ok' => true] : ['ok' => false, 'error' => $r[1]]);
}
$r = sxn_post($hook, [sxn_embed('i', '✅ ' . sxn_t($lang, 'test_t'), sxn_t($lang, 'test_d'))], sxn_ping_text($cfg['dc_ping']));
sx_json($r[0] === 'ok' ? ['ok' => true] : ['ok' => false, 'error' => $r[1]]);
