<?php
// Started by cron every hour: sends the weekly summary once, on the chosen day, from the chosen hour.
if (PHP_SAPI !== 'cli') { exit(1); } // cron only, not a web page
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/discord.php';
require_once __DIR__ . '/alerts.php';

$cfg = sx_cfg();
$hook = (string)$cfg['dc_hook'];
if (!sxn_hook_ok($hook) || !sx_on($cfg['wk_on'])) { exit(0); }
if ((int)date('N') !== (int)$cfg['wk_day'] || (int)date('G') < (int)$cfg['wk_hour']) { exit(0); }
$lock = @fopen('/tmp/sysbox_weekly.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { exit(0); }

$st = sxn_state();
$week = date('o-W');
if (($st['misc']['wk_last'] ?? '') === $week) { exit(0); }
$r = sxn_post($hook, [sxn_e_weekly(sxn_lang($cfg), $cfg, $st)]);
if ($r[0] === 'retry') { exit(0); }
$st['misc']['wk_last'] = $week;
sxn_write(SXN_STATE, $st);
