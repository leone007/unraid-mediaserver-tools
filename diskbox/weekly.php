<?php
// Started by cron every hour: sends the weekly summary once, on the chosen day, from the chosen hour.
if (PHP_SAPI !== 'cli') { exit(1); } // cron only, not a web page
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/discord.php';
require_once __DIR__ . '/alerts.php';

$cfg = db_cfg();
$hook = (string)$cfg['dc_hook'];
if (!dbn_hook_ok($hook) || !db_on($cfg['wk_on'])) { exit(0); }
if ((int)date('N') !== (int)$cfg['wk_day'] || (int)date('G') < (int)$cfg['wk_hour']) { exit(0); }
$lock = @fopen('/tmp/diskbox_weekly.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { exit(0); }

$st = dbn_state();
$week = date('o-W');
if (($st['misc']['wk_last'] ?? '') === $week) { exit(0); }
$e = dbn_weekly_now($cfg);
if ($e === null) { exit(0); }
$r = dbn_post($hook, [$e]);
if ($r[0] === 'retry') { exit(0); }
$st['misc']['wk_last'] = $week;
dbn_write(DBN_STATE, $st);
