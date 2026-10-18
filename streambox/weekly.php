<?php
// Started by cron every hour: on the chosen day, from the chosen hour, post the weekly summary once.
if (PHP_SAPI !== 'cli') { exit(1); } // cron only, not a web page
require_once __DIR__ . '/notify_lib.php';
nf_tz();

$cfg = nf_cfg();
if (!nf_on($cfg['weekly']) || (string)$cfg['jf_key'] === '') { exit(0); }
$hook = nf_hook($cfg['dc_weekly']);
if ($hook === '' || !preg_match('/^[A-Za-z0-9.\-]+$/', (string)$cfg['jf_host'])) { exit(0); }

$now = time();
if ((int)date('w', $now) !== (int)$cfg['weekly_day'] || (int)date('G', $now) < (int)$cfg['weekly_hour']) { exit(0); }

$sf = '/boot/config/plugins/streambox/weekly_state.json';
$st = is_file($sf) ? json_decode((string)@file_get_contents($sf), true) : [];
$today = date('Y-m-d', $now);
if (is_array($st) && ($st['last'] ?? '') === $today) { exit(0); }

$lock = @fopen('/tmp/streambox_weekly.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { exit(0); }

function nf_mark_week($sf, $today) {
    $tmp = $sf . '.tmp';
    $data = json_encode(['last' => $today]);
    if (@file_put_contents($tmp, $data) === false || !@rename($tmp, $sf)) { @file_put_contents($sf, $data); @unlink($tmp); }
}

$payload = nf_weekly($cfg, $now - 7 * 86400, $now);
if ($payload === false) { nf_log('weekly summary: Jellyfin not reachable, will retry'); exit(0); }
if ($payload === null) { nf_log('weekly summary: nothing new this week'); nf_mark_week($sf, $today); exit(0); }

list($res, $msg) = nf_send($hook, $payload);
if ($res === 'retry') { nf_log('weekly summary: ' . $msg . ', will retry'); exit(0); }
nf_mark_week($sf, $today);
nf_log($res === 'ok' ? 'weekly summary sent' : 'weekly summary not sent: ' . $msg);
