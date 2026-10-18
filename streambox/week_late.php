<?php
// Started by cron every 10 minutes: one Discord message (series webhook) for each episode of this week that is late,
// i.e. no release a set number of hours after the usual time. Off unless switched on in the settings.
if (PHP_SAPI !== 'cli') { exit(1); } // cron only, not a web page
require_once __DIR__ . '/week_lib.php';
require_once __DIR__ . '/notify_lib.php';

$cfg = wk_cfg();
if (!nf_on($cfg['wk_dc'])) { exit(0); }
$hook = nf_hook($cfg['dc_series']);
if ($hook === '') { exit(0); }
$lock = @fopen('/tmp/streambox_late.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { exit(0); }

wk_tz();
$now = time();
$w = wk_build($cfg, $now);
if (!$w['ok']) { exit(0); }

$sf = '/boot/config/plugins/streambox/late_state.json';
$done = is_file($sf) ? json_decode((string)@file_get_contents($sf), true) : null;
$done = is_array($done) ? $done : [];
$days = ['vasárnap', 'hétfő', 'kedd', 'szerda', 'csütörtök', 'péntek', 'szombat'];
$changed = false;

foreach ($w['days'] as $day) {
    foreach ($day['rows'] as $r) {
        if ($r['st'] !== 'late' || isset($done[(string)$r['id']])) { continue; }
        $h = intdiv($now - $r['air'], 3600);
        $msg = '⏰ Késik: **' . nf_md($r['series']) . '** ' . $r['code']
             . ' · adás ' . $days[(int)date('w', $r['air'])] . ' ' . date('H:i', $r['air'])
             . ($r['exp'] !== null ? ', általában ~' . date('H:i', $r['exp']) . ' körül jön (' . $r['n'] . ' rész alapján)' : '')
             . '; az adás óta ' . $h . ' óra telt el, és még nincs kiadás.';
        list($res, $m) = nf_send($hook, ['username' => 'Streambox', 'content' => $msg]);
        if ($res === 'retry') { nf_log('late "' . $r['series'] . ' ' . $r['code'] . '": ' . $m . ', will retry'); break 2; }
        $done[(string)$r['id']] = $now;
        $changed = true;
        nf_log('late ' . ($res === 'ok' ? 'message sent' : 'message not sent (' . $m . ')') . ': ' . $r['series'] . ' ' . $r['code']);
    }
}
if ($changed) {
    $done = array_filter($done, function ($t) use ($now) { return $now - (int)$t < 21 * 86400; });
    @file_put_contents($sf . '.tmp', json_encode($done));
    @rename($sf . '.tmp', $sf);
}
