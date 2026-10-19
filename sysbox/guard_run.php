<?php
// Claude Guard worker (Sysbox, item 44).
//   php guard_run.php tick          cron every 2 minutes: the claude container flag, alerts into the queue, the daily report once after the set time
//   php guard_run.php run daily     the daily report (started by tick)
//   php guard_run.php run event     a report on the queued alerts (started by tick, at most ev_max a day, never in quiet hours)
//   php guard_run.php run manual    the page's "Run a report now"
if (PHP_SAPI !== 'cli') { exit(1); } // cron and the page's worker only, not a web page
require_once __DIR__ . '/guard_lib.php';
@set_time_limit(0);
$mode = $argv[1] ?? ''; $kind = (string)($argv[2] ?? '');
if (!in_array($mode, ['tick', 'run'], true) || ($mode === 'run' && !in_array($kind, ['daily', 'event', 'manual'], true))) { exit(1); }

// the state file is shared by tick and run: read-change-write under its own lock
function gd_state_edit($fn) {
    $l = @fopen(gd_lock_file('state'), 'c'); if ($l) { flock($l, LOCK_EX); }
    $S = upd_json(gd_state_file(), []); $out = $fn($S); upd_write(gd_state_file(), $S);
    if ($l) { flock($l, LOCK_UN); fclose($l); }
    return $out;
}
function gd_run_edit($fn) {
    $l = @fopen(gd_lock_file('runfile'), 'c'); if ($l) { flock($l, LOCK_EX); }
    $R = upd_json(gd_run_file(), ['jobs' => []]); $out = $fn($R); upd_write(gd_run_file(), $R);
    if ($l) { flock($l, LOCK_UN); fclose($l); }
    return $out;
}
function gd_job_start($kind, $text) {
    return gd_run_edit(function (&$R) use ($kind, $text) {
        $id = $kind . ':' . time();
        $R['jobs'][$id] = ['kind' => $kind, 'text' => $text, 'state' => 'running', 'started' => time(), 'log' => []];
        foreach ($R['jobs'] as $k => $j) { if ($j['state'] !== 'running' && time() - (int)($j['finished'] ?? 0) > 86400) { unset($R['jobs'][$k]); } }
        return $id;
    });
}
function gd_job($id, $set = [], $line = '') {
    gd_run_edit(function (&$R) use ($id, $set, $line) {
        if (!isset($R['jobs'][$id])) { return; }
        $R['jobs'][$id] = $set + $R['jobs'][$id];
        if ($line !== '') { $R['jobs'][$id]['log'][] = date('H:i:s') . ' ' . $line; }
        if (isset($set['state']) && $set['state'] !== 'running') { $R['jobs'][$id]['finished'] = time(); }
    });
}

if ($mode === 'tick') {
    $lk = @fopen(gd_lock_file('tick'), 'c');
    if (!$lk || !flock($lk, LOCK_EX | LOCK_NB)) { exit(0); }
    // the header button and the page show the Claude parts only when the container exists
    if (gd_ct_exists()) { if (!is_file(gd_flag_file())) { @touch(gd_flag_file()); } } elseif (is_file(gd_flag_file())) { @unlink(gd_flag_file()); }
    $c = gd_cfg();
    $ev = gd_take_events();   // emptied even when the Guard is off, so the queue never grows
    if (empty($c['on']) || !is_file(gd_flag_file())) { exit(0); }
    $want = ['sysbox' => !empty($c['ev_sys']), 'diskbox' => !empty($c['ev_dsk'])];
    $ev = array_values(array_filter($ev, function ($e) use ($want) { return !empty($want[$e['src']]) && in_array($e['level'] ?? '', ['a', 'rd'], true); }));
    $spawn = gd_state_edit(function (&$S) use ($c, $ev) {
        if (!empty($c['ev_sbx'])) { $ev = array_merge($ev, gd_seedbox_events($S)); }
        // the same alert key starts a report at most once in 6 hours
        $last = $S['ev_last'] ?? []; $fresh = [];
        foreach ($ev as $e) { $k = (string)($e['key'] ?? md5($e['text'])); if (time() - (int)($last[$k] ?? 0) < 6 * 3600) { continue; } $last[$k] = time(); $fresh[] = $e; }
        foreach ($last as $k => $t) { if (time() - $t > 86400) { unset($last[$k]); } }
        $S['ev_last'] = $last;
        $today = date('Y-m-d'); if (($S['ev_day']['d'] ?? '') !== $today) { $S['ev_day'] = ['d' => $today, 'n' => 0]; }
        $quiet = gd_quiet($c);
        if ($fresh) {
            if ($quiet || $S['ev_day']['n'] >= (int)$c['ev_max']) { $S['night'] = array_slice(array_merge($S['night'] ?? [], $fresh), -30); }   // into the next daily report
            else { $S['ev_queue'] = array_slice(array_merge($S['ev_queue'] ?? [], $fresh), -10); }
        }
        $todo = [];
        $at = (int)$c['hour'] * 60 + (int)$c['min']; $nowm = (int)date('G') * 60 + (int)date('i');
        if (($S['day'] ?? '') !== $today && $nowm >= $at && !$quiet) { $todo[] = 'daily'; }
        if (!$quiet && !empty($S['ev_queue']) && $S['ev_day']['n'] < (int)$c['ev_max']) { $todo[] = 'event'; }
        return $todo;
    });
    if (!gd_running('claude') && $spawn) { gd_spawn(['run', $spawn[0]]); }   // one Claude run at a time; the other waits for the next tick
    exit(0);
}

// ---- run ----------------------------------------------------------------------------------------------------------
$lk = @fopen(gd_lock_file('claude'), 'c');
if (!$lk || !flock($lk, LOCK_EX | LOCK_NB)) { exit(0); }
$c = gd_cfg(); $t0 = time();
// what this run covers: the daily report takes the alerts of the night, an event report the queued alerts
$events = gd_state_edit(function (&$S) use ($kind) {
    if ($kind === 'daily') { $S['day'] = date('Y-m-d'); $e = $S['night'] ?? []; $S['night'] = []; return $e; }
    if ($kind === 'event') { $e = $S['ev_queue'] ?? []; $S['ev_queue'] = []; if ($e) { $S['ev_day']['n'] = (int)($S['ev_day']['n'] ?? 0) + 1; } return $e; }
    return [];
});
if ($kind === 'event' && !$events) { exit(0); }
$label = ['daily' => sx_tr('gd.job_daily'), 'event' => sx_tr('gd.job_event', ['t' => mb_substr((string)($events[0]['text'] ?? ''), 0, 90)]), 'manual' => sx_tr('gd.job_manual')][$kind];
$id = gd_job_start($kind, $label);
$rep = ['id' => substr(md5($kind . microtime()), 0, 10), 't' => time(), 'kind' => $kind, 'events' => $events, 'verdict' => 'green', 'summary' => '', 'items' => [], 'noise' => ''];

$dir = gd_snap_dir();
if ($dir === '') { $rep['error'] = sx_tr('gd.e_noct'); }
$prev = $dir !== '' ? gd_snap_prev($dir, time()) : null;
$since = $prev ? (int)$prev['t'] : time() - 86400;
gd_job($id, [], sx_tr('gd.l_snap'));
$snap = gd_snapshot($c, $since);
if ($dir !== '') { gd_snap_save($dir, $snap); }
$u = gd_usage(); $rep['usage'] = $u ? $u['week'] : null;
// a report started by hand ignores the limit (owner, 2026-10-19)
if (empty($rep['error']) && $kind !== 'manual' && $u && $u['week'] >= (float)$c['limit']) { $rep['skipped'] = sx_tr('gd.skip_limit', ['p' => round($u['week']), 'l' => (int)$c['limit']]); }

if (empty($rep['error']) && empty($rep['skipped'])) {
    gd_job($id, [], sx_tr('gd.l_ask'));
    [$ans, $e] = gd_ask(gd_prompt($kind, $snap, $prev, $events, $c));
    if ($ans) { $rep = $ans + $rep; } else { $rep['error'] = $e; }
}
$rep['took'] = time() - $t0;
// a skipped or failed run keeps its alerts for the next daily report
if ((!empty($rep['skipped']) || !empty($rep['error'])) && $events) { gd_state_edit(function (&$S) use ($events) { $S['night'] = array_slice(array_merge($S['night'] ?? [], $events), -30); }); }
gd_report_add($rep);
gd_handover($dir, $rep);
gd_discord($c, $rep);
$n = 0; if (empty($rep['error']) && empty($rep['skipped'])) { $n = gd_state_edit(function (&$S) use ($c, $rep) { return gd_todo($c, $rep, $S); }); }
$bad = count(array_filter($rep['items'], function ($x) { return $x['level'] !== 'green'; }));
$txt = !empty($rep['error']) ? sx_tr('gd.j_err', ['e' => $rep['error']]) : (!empty($rep['skipped']) ? $rep['skipped'] : ($bad ? sx_tr('gd.j_look', ['n' => $bad]) : sx_tr('gd.j_fine')));
if ($n) { $txt .= ' · ' . sx_tr('gd.j_todo', ['n' => $n]); }
gd_job($id, ['state' => !empty($rep['error']) ? 'error' : 'done', 'text' => $label . ': ' . $txt]);
gd_log($kind . ': ' . $txt);
