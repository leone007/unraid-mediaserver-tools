<?php
// Claude Guard suggestions (Sysbox, item 95 C). In the daily (and the manual) report Claude may pick actions from a fixed
// menu that the server builds itself: a waiting safe (green) or look-first (amber) container update, deleting an old
// before-update snapshot, the self-test, Check now, and (off by default) hiding a stuck Seedbox season pack job.
// Red (Wait) updates and anything outside the menu are never offered. Nothing runs without the owner: Approve and a
// confirmation on the Guard page, or the owner's number reaction under the morning brief (the shared bot, item 95 A).
// The actions are the ones the plugins' own buttons start (upd_run.php update|selftest|scan, snp_destroy, Seedbox
// st_sp_dismiss), so they show up in Activity like any other job.
require_once __DIR__ . '/guard_lib.php';
require_once __DIR__ . '/snap_lib.php';
require_once __DIR__ . '/discord.php';
require_once __DIR__ . '/brief_lib.php';   // gd_bt: texts in the Discord language
require_once __DIR__ . '/bot.php';

const GD_SUG_EMO = ["1\u{FE0F}\u{20E3}", "2\u{FE0F}\u{20E3}", "3\u{FE0F}\u{20E3}"];
const GD_SUG_NO = "\u{1F44E}";
const GD_SUG_MAX = 3;
const GD_SUG_TTL = 86400;   // a suggestion waits 24 hours
// action => the setting that allows it
const GD_SUG_ACTS = ['upd_safe' => 's_upd_safe', 'upd_look' => 's_upd_look', 'snap_del' => 's_snap', 'selftest' => 's_test', 'check' => 's_test', 'sbx_dismiss' => 's_sbx'];

function gd_sug_file() { return gd_dir() . '/guard_sugg.json'; }
// read-change-write under a lock; the file holds the suggestions (7 days) and the Discord messages that carry them
function gd_sug_edit($fn) {
    $l = @fopen(gd_lock_file('sugg'), 'c'); if ($l) { flock($l, LOCK_EX); }
    $S = upd_json(gd_sug_file(), []) + ['list' => [], 'msgs' => []]; $o = json_encode($S);
    $out = $fn($S);
    $S['list'] = array_values(array_filter($S['list'], function ($x) { return time() - (int)$x['t'] < 7 * 86400; }));
    $S['msgs'] = array_values(array_filter($S['msgs'], function ($x) { return time() - (int)$x['t'] < 3 * 86400; }));
    if (json_encode($S) !== $o) { upd_write(gd_sug_file(), $S); }
    if ($l) { flock($l, LOCK_UN); fclose($l); }
    return $out;
}
function gd_sug_all() { return upd_json(gd_sug_file(), []) + ['list' => [], 'msgs' => []]; }
function gd_sug_on($c) { foreach (array_unique(GD_SUG_ACTS) as $k) { if (!empty($c[$k])) { return true; } } return false; }

// ---- the menu: what may be suggested right now, with the facts Claude needs --------------------------------------
// [act, arg, vars (for the titles), facts (one line for Claude)]
function gd_sug_cands($c) {
    $out = [];
    $add = function ($act, $arg, $vars, $facts) use (&$out, $c) { if (!empty($c[GD_SUG_ACTS[$act]])) { $out[$act . '|' . $arg] = ['act' => $act, 'arg' => (string)$arg, 'vars' => $vars, 'facts' => $facts]; } };
    $scan = upd_json(upd_scan_file(), []); $cache = upd_json(upd_cache_file(), []);
    foreach ((array)($scan['rows'] ?? []) as $r) {
        if (($r['state'] ?? '') !== 'update' || ($r['name'] ?? '') === 'claude' || !in_array($r['risk'] ?? '', ['green', 'amber'], true)) { continue; }   // never red (Wait)
        $cl = $cache[$r['key'] ?? '']['claude']['text'] ?? '';
        $add($r['risk'] === 'green' ? 'upd_safe' : 'upd_look', $r['name'], ['n' => $r['name'], 'from' => (string)($r['ver'] ?: '?'), 'to' => (string)($r['rver'] ?: '?')],
            'container update ' . $r['name'] . ' ' . ($r['ver'] ?: '?') . ' -> ' . ($r['rver'] ?: '?') . ', ' . $r['risk'] . ' (' . ($r['risk'] === 'green' ? 'safe' : 'look first') . '), kind ' . $r['kind'] .
            ($r['age'] !== null ? ', released ' . $r['age'] . ' days ago' : '') . ($r['deps'] ? ', used by our plugins: ' . implode(', ', $r['deps']) : '') .
            ($r['flags'] ? ', release notes mention: ' . implode(', ', array_keys(array_filter((array)$r['flags']))) : '') .
            ($cl !== '' ? '; Claude read the notes earlier: ' . mb_substr(preg_replace('/\s+/', ' ', $cl), 0, 300) : '; nobody asked Claude about it'));
    }
    if (!empty($c['s_snap']) && empty(snp_cfg()['bu_on'])) {   // with item 119 on they are removed by themselves
        foreach (snp_datasets() as $ds) {
            foreach (snp_snaps($ds['name']) as $s) {
                if (!preg_match('/^before-update-(.+)-\d{4}-\d{2}-\d{2}-\d{4}$/', $s['name'], $m) || time() - $s['t'] < 86400) { continue; }
                $add('snap_del', $ds['name'] . '@' . $s['name'], ['s' => $s['name'], 'ds' => $ds['name'], 'size' => sxn_fmt_bytes($s['used'])],
                    'ZFS snapshot ' . $ds['name'] . '@' . $s['name'] . ' made before updating ' . $m[1] . ', ' . round((time() - $s['t']) / 86400, 1) . ' days old, holds ' . sxn_fmt_bytes($s['used']));
            }
        }
    }
    if (!empty($c['s_test'])) {
        $t = upd_json(upd_test_file(), []); $bad = array_column(array_filter((array)($t['tests'] ?? []), function ($x) { return empty($x['ok']); }), 'name');
        $add('selftest', '', [], 'self-test of our connections (qBittorrent, Radarr, Sonarr, Prowlarr, Jellyfin, autobrr): ' . (!empty($t['t']) ? 'last run ' . round((time() - (int)$t['t']) / 3600, 1) . ' hours ago, ' . ($bad ? 'failed: ' . implode(', ', $bad) : 'all passed') : 'no result since the last reboot'));
        $add('check', '', [], 'Check now of the Update manager (container, plugin and Unraid updates): ' . (!empty($scan['t']) ? 'last check ' . round((time() - (int)$scan['t']) / 3600, 1) . ' hours ago' : 'no result since the last reboot'));
    }
    if (!empty($c['s_sbx'])) {
        foreach ((array)(gd_seedbox_state()['packjobs'] ?? []) as $j) {
            if (($j['stage'] ?? '') !== 'blocked' || gd_sbx_closed($j) || empty($j['id']) || !preg_match('/^[A-Za-z0-9_.:-]{1,80}$/', (string)$j['id'])) { continue; }
            $nm = (string)($j['name'] ?? $j['title'] ?? $j['key'] ?? '?');
            $add('sbx_dismiss', $j['id'], ['n' => $nm], 'Seedbox Tools season pack job "' . $nm . '" stuck since ' . date('m-d H:i', (int)($j['t'] ?? 0)) . ': ' . gd_mask((string)($j['msg'] ?? '')));
        }
    }
    return $out;
}

// the part of the daily prompt that offers the menu; '' when nothing may be suggested
function gd_sug_prompt($cands) {
    if (!$cands) { return ''; }
    $l = [];
    foreach ($cands as $x) { $l[] = '- act "' . $x['act'] . '", arg "' . $x['arg'] . '": ' . $x['facts']; }
    return "\nYou may also suggest up to " . GD_SUG_MAX . " actions for the owner to approve, ONLY from this menu (the server built it; act and arg must be copied exactly):\n<suggestion_menu>\n" .
        implode("\n", $l) . "\n</suggestion_menu>\n" .
        "Suggest an action only when it is clearly useful today: an update that is safe to take (a look-first one only when the notes and our code use look harmless), " .
        "a before-update snapshot whose container has run fine since (no problems in the snapshot or the logs), the self-test when a connection looks broken or it has not run for days, " .
        "Check now when the last check is old. When in doubt, suggest nothing; an empty list is normal. Put them in the JSON as " .
        '"suggest":[{"act":"...","arg":"...","why":"one short sentence for the owner"}]' . ".\n";
}

// Claude's picks checked against the menu: [[act, arg, vars, why], ...]
function gd_sug_pick($raw, $cands) {
    $out = [];
    foreach ((array)$raw as $x) {
        if (!is_array($x)) { continue; }
        $k = (string)($x['act'] ?? '') . '|' . (string)($x['arg'] ?? '');
        if (!isset($cands[$k]) || isset($out[$k])) { continue; }
        $out[$k] = $cands[$k] + ['why' => mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string)($x['why'] ?? ''))), 0, 300)];
        if (count($out) >= GD_SUG_MAX) { break; }
    }
    return array_values($out);
}

// a new report's suggestions: the older waiting ones expire; an action the owner said Not now to in the last 3 days is not offered again
function gd_sug_add($rid, $picks) {
    return gd_sug_edit(function (&$S) use ($rid, $picks) {
        foreach ($S['list'] as &$x) { if (in_array($x['state'], ['wait', 'fail'], true)) { $x['state'] = 'expired'; $x['et'] = time(); } }
        unset($x);
        $no = []; foreach ($S['list'] as $x) { if ($x['state'] === 'no' && time() - (int)($x['et'] ?? 0) < 3 * 86400) { $no[$x['act'] . '|' . $x['arg']] = 1; } }
        $ids = [];
        foreach ($picks as $p) {
            if (isset($no[$p['act'] . '|' . $p['arg']])) { continue; }
            $id = substr(md5($rid . $p['act'] . $p['arg'] . microtime()), 0, 10);
            $S['list'][] = ['id' => $id, 'rid' => $rid, 't' => time(), 'act' => $p['act'], 'arg' => $p['arg'], 'vars' => $p['vars'], 'why' => $p['why'], 'state' => 'wait'];
            $ids[] = $id;
        }
        return $ids;
    });
}
function gd_sug_get($id) { foreach (gd_sug_all()['list'] as $x) { if ($x['id'] === $id) { return $x; } } return null; }
// the waiting ones of a report, oldest first (their order is their Discord number)
function gd_sug_of($rid) { return array_values(array_filter(gd_sug_all()['list'], function ($x) use ($rid) { return $x['rid'] === $rid && $x['state'] === 'wait'; })); }

// ---- texts ---------------------------------------------------------------------------------------------------------
// $tr: sx_tr (page language) or gd_bt (Discord language); the keys are the same
function gd_sug_title($x, $tr) { return $tr('sug.t_' . $x['act'], (array)$x['vars']); }

// ---- doing it ------------------------------------------------------------------------------------------------------
function gd_sbx_dismiss($id) {
    $lib = '/usr/local/emhttp/plugins/qbittorrent_widget';
    if (!is_file($lib . '/tools_packs.php')) { return sx_tr('sug.e_noseedbox'); }
    $code = 'require "' . $lib . '/tools_lib.php"; require "' . $lib . '/tools_packs.php"; echo json_encode(st_sp_dismiss($argv[1]));';
    [$rc, $o] = sx_run(escapeshellarg(sx_php()) . ' -d display_errors=0 -r ' . escapeshellarg($code) . ' ' . escapeshellarg($id), 60);
    $ln = explode("\n", trim((string)$o)); $j = json_decode((string)end($ln), true);
    return is_array($j) && !empty($j[0]) ? '' : (is_array($j) && !empty($j[1]) ? (string)$j[1] : sx_tr('sug.e_seedbox'));
}
// Approve (page) or the owner's reaction (Discord): checks that it is still on the menu, then starts it. [ok, error]
function gd_sug_do($id, $via) {
    $x = gd_sug_get($id);
    if (!$x) { return [false, sx_tr('sug.e_gone')]; }
    if (!in_array($x['state'], ['wait', 'fail'], true)) { return [false, sx_tr('sug.e_done')]; }
    if (time() - (int)$x['t'] > GD_SUG_TTL) { gd_sug_set($id, ['state' => 'expired']); return [false, sx_tr('sug.e_old')]; }
    $c = gd_cfg(); $cands = gd_sug_cands($c);
    if (!isset($cands[$x['act'] . '|' . $x['arg']])) { gd_sug_set($id, ['state' => 'gone', 'msg' => sx_tr('sug.e_stale')]); return [false, sx_tr('sug.e_stale')]; }
    $title = gd_sug_title($x, 'sx_tr');
    $e = '';
    if ($x['act'] === 'upd_safe' || $x['act'] === 'upd_look') {
        if (upd_running('update')) { return [false, sx_tr('ue.upd_busy')]; }
        upd_spawn(['update', $x['arg']]);
    } elseif ($x['act'] === 'selftest') {
        if (upd_running('selftest')) { return [false, sx_tr('ue.test_busy')]; }
        upd_spawn(['selftest']);
    } elseif ($x['act'] === 'check') {
        if (upd_running('scan')) { return [false, sx_tr('ue.scan_busy')]; }
        upd_spawn(['scan']);
    } elseif ($x['act'] === 'snap_del') {
        [$ds, $sn] = explode('@', $x['arg'], 2);
        $e = snp_destroy($ds, $sn); if ($e === '') { snp_log($x['arg'] . ' deleted (Claude Guard suggestion, approved ' . ($via === 'discord' ? 'on Discord' : 'on the page') . ')'); }
    } elseif ($x['act'] === 'sbx_dismiss') {
        $e = gd_sbx_dismiss($x['arg']);
    }
    $quick = in_array($x['act'], ['snap_del', 'sbx_dismiss'], true);
    $jid = gd_job_start('sug', sx_tr($via === 'discord' ? 'sug.j_dc' : 'sug.j_page', ['t' => $title]));
    if ($quick) {
        gd_job($jid, ['state' => $e === '' ? 'done' : 'error', 'text' => $e === '' ? sx_tr('sug.j_ok', ['t' => $title]) : sx_tr('sug.j_fail', ['t' => $title, 'e' => $e])]);
        gd_sug_set($id, ['state' => $e === '' ? 'done' : 'fail', 'msg' => $e === '' ? sx_tr('sug.m_ok') : $e, 'via' => $via, 'rt' => time()]);
    } else {
        gd_job($jid, [], sx_tr('sug.l_started'));
        gd_sug_set($id, ['state' => 'run', 'via' => $via, 'rt' => time(), 'jid' => $jid, 'msg' => '']);
    }
    gd_log('suggestion ' . $x['act'] . ' ' . $x['arg'] . ' approved (' . $via . ')' . ($e !== '' ? ': ' . $e : ''));
    return $e === '' ? [true, ''] : [false, $e];
}
function gd_sug_set($id, $set) {
    gd_sug_edit(function (&$S) use ($id, $set) { foreach ($S['list'] as &$x) { if ($x['id'] === $id) { $x = $set + $x; $x['et'] = time(); } } unset($x); });
}
// Not now
function gd_sug_no($id) {
    $x = gd_sug_get($id);
    if (!$x || !in_array($x['state'], ['wait', 'fail'], true)) { return false; }
    gd_sug_set($id, ['state' => 'no']); return true;
}

// started jobs: follow the Update manager job (the newest of its kind started after the click); old waiting ones expire.
// Called by the tick and by the page's state. Returns true when something changed.
function gd_sug_follow() {
    $S = gd_sug_all(); $ch = false;
    $jobs = upd_json(upd_run_file(), ['jobs' => []])['jobs'];
    foreach ($S['list'] as $x) {
        if ($x['state'] === 'wait' && time() - (int)$x['t'] > GD_SUG_TTL) { gd_sug_set($x['id'], ['state' => 'expired']); $ch = true; continue; }
        if ($x['state'] !== 'run') { continue; }
        $kind = ['upd_safe' => 'update', 'upd_look' => 'update', 'selftest' => 'selftest', 'check' => 'scan'][$x['act']] ?? '';
        $j = null;
        foreach ($jobs as $y) { if (($y['kind'] ?? '') === $kind && ($kind !== 'update' || ($y['name'] ?? '') === $x['arg']) && (int)$y['started'] >= (int)$x['rt'] - 5 && (!$j || $y['started'] > $j['started'])) { $j = $y; } }
        $title = gd_sug_title($x, 'sx_tr');
        if (!$j) {
            if (time() - (int)$x['rt'] > 600) { gd_sug_set($x['id'], ['state' => 'fail', 'msg' => sx_tr('sug.e_nostart')]); gd_job($x['jid'] ?? '', ['state' => 'error', 'text' => sx_tr('sug.j_fail', ['t' => $title, 'e' => sx_tr('sug.e_nostart')])]); $ch = true; }
            continue;
        }
        if ($j['state'] === 'running') { if (($x['msg'] ?? '') !== (string)$j['text']) { gd_sug_set($x['id'], ['msg' => (string)$j['text']]); gd_job($x['jid'] ?? '', [], (string)$j['text']); } continue; }
        $ok = $j['state'] === 'done';
        gd_sug_set($x['id'], ['state' => $ok ? 'done' : 'fail', 'msg' => (string)$j['text']]);
        gd_job($x['jid'] ?? '', ['state' => $ok ? 'done' : 'error', 'text' => sx_tr($ok ? 'sug.j_ok' : 'sug.j_fail', ['t' => $title, 'e' => (string)$j['text']])]);
        $ch = true;
    }
    return $ch;
}

// ---- Discord: the brief's suggestion part, the reactions and the status line ----------------------------------------
function gd_sug_dc_on($c) { return !empty($c['s_dc']) && mt_bot_ready(); }
// the field under the brief: [name, value] or null
function gd_sug_field($c, $list) {
    if (!$list) { return null; }
    $l = [];
    foreach (array_slice($list, 0, GD_SUG_MAX) as $i => $x) { $l[] = GD_SUG_EMO[$i] . ' ' . gd_sug_title($x, 'gd_bt') . ($x['why'] !== '' ? ': ' . $x['why'] : ''); }
    $l[] = gd_sug_dc_on($c) ? gd_bt('brief.sug_how') : gd_bt('brief.sug_page');
    return ['💡 ' . gd_bt('brief.h_sug', ['n' => count($l) - 1]), implode("\n", $l)];
}
// the status line of a message: one line per number, in the Discord language
function gd_sug_status($m, $byId) {
    $p = [];
    foreach ($m['sids'] as $i => $sid) {
        $x = $byId[$sid] ?? null; if (!$x) { continue; }
        $st = in_array($x['state'], ['wait', 'run', 'done', 'fail', 'no', 'expired', 'gone'], true) ? $x['state'] : 'gone';
        $p[] = GD_SUG_EMO[$i] . ' ' . gd_bt('brief.sst_' . $st) . ': ' . gd_sug_title($x, 'gd_bt');
    }
    return implode("\n", $p);
}
// posts the brief with ?wait=true so the bot can react under it; [ok, error]
function gd_sug_post($hook, $embed, $sids) {
    $pl = json_encode(['embeds' => [$embed], 'allowed_mentions' => ['parse' => []]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    [$code, $b, $err] = mt_bot_http('POST', $hook . (strpos($hook, '?') === false ? '?' : '&') . 'wait=true', ['Content-Type: application/json'], $pl);
    $msg = json_decode($b, true);
    if ($err !== '' || $code >= 400 || !is_array($msg) || empty($msg['id'])) { return [false, $err !== '' ? $err : 'Discord HTTP ' . $code]; }
    $rec = ['mid' => (string)$msg['id'], 'ch' => (string)($msg['channel_id'] ?? ''), 't' => time(), 'sids' => $sids, 'embed' => $embed, 'last' => ''];
    $er = '';
    foreach (array_merge(array_slice(GD_SUG_EMO, 0, count($sids)), [GD_SUG_NO]) as $em) {
        [$c, $j, $e] = mt_bot_api('PUT', '/channels/' . $rec['ch'] . '/messages/' . $rec['mid'] . '/reactions/' . rawurlencode($em) . '/@me');
        if ($e !== '') { $er = $e; break; }
        usleep(350000);
    }
    if ($er !== '') { $rec['err'] = $er; gd_log('suggestions: the bot could not react: ' . $er); }
    gd_sug_edit(function (&$S) use ($rec) { $S['msgs'][] = $rec; $S['msgs'] = array_slice($S['msgs'], -20); });
    return [true, ''];
}
// edits the brief through the webhook: the stored embed with the status field
function gd_sug_edit_msg($hook, $m, $status) {
    $e = (array)$m['embed']; $name = '📋 ' . gd_bt('brief.h_sst');
    $f = array_values(array_filter((array)($e['fields'] ?? []), function ($x) use ($name) { return ($x['name'] ?? '') !== $name; }));
    $f[] = ['name' => $name, 'value' => mb_substr($status, 0, 1000), 'inline' => false];
    $e['fields'] = array_slice($f, 0, 25);
    $hp = explode('?', $hook, 2);   // a webhook with ?thread_id=...: the message path goes before the query (item 104)
    mt_bot_http('PATCH', $hp[0] . '/messages/' . $m['mid'] . (isset($hp[1]) ? '?' . $hp[1] : ''), ['Content-Type: application/json'], json_encode(['embeds' => [$e]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
}
// who reacted with $em (user ids), null on an error
function gd_sug_who($m, $em) {
    [$c, $j, $e] = mt_bot_api('GET', '/channels/' . $m['ch'] . '/messages/' . $m['mid'] . '/reactions/' . rawurlencode($em) . '?limit=100');
    if ($e !== '' || !is_array($j)) { return null; }
    return array_map(function ($u) { return (string)($u['id'] ?? ''); }, $j);
}
// the tick (every 2 minutes): the owner's reactions under the briefs of the last 24 hours, then the status lines
function gd_sug_poll($c, $hook) {
    $S = gd_sug_all(); if (!$S['msgs']) { return; }
    $byId = []; foreach ($S['list'] as $x) { $byId[$x['id']] = $x; }
    if (gd_sug_dc_on($c)) {
        $me = mt_bot_cfg()['user'];
        foreach ($S['msgs'] as $m) {
            if (time() - (int)$m['t'] > GD_SUG_TTL || !empty($m['err'])) { continue; }
            $wait = array_filter($m['sids'], function ($sid) use ($byId) { return ($byId[$sid]['state'] ?? '') === 'wait'; });
            if (!$wait) { continue; }
            $no = gd_sug_who($m, GD_SUG_NO); if ($no === null) { continue; }
            if (in_array($me, $no, true)) { foreach ($wait as $sid) { gd_sug_no($sid); } continue; }   // 👎: Not now for the waiting ones
            foreach ($wait as $i => $sid) {
                $w = gd_sug_who($m, GD_SUG_EMO[$i]);
                if ($w !== null && in_array($me, $w, true)) { gd_sug_do($sid, 'discord'); }
            }
        }
    }
    gd_sug_follow();
    // the status line follows the suggestions (also the ones approved on the page); not before anything happened
    $S = gd_sug_all(); $byId = []; foreach ($S['list'] as $x) { $byId[$x['id']] = $x; }
    foreach ($S['msgs'] as $m) {
        if (time() - (int)$m['t'] > 2 * 86400 || $hook === '') { continue; }
        $moved = array_filter($m['sids'], function ($s) use ($byId) { return ($byId[$s]['state'] ?? 'wait') !== 'wait'; });
        if (!$moved && ($m['last'] ?? '') === '') { continue; }
        $st = gd_sug_status($m, $byId);
        if ($st === '' || $st === ($m['last'] ?? '')) { continue; }
        gd_sug_edit_msg($hook, $m, $st);
        gd_sug_edit(function (&$D) use ($m, $st) { foreach ($D['msgs'] as &$y) { if ($y['mid'] === $m['mid']) { $y['last'] = $st; } } unset($y); });
    }
}
