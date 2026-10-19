<?php
// Started by cron every minute: Discord alerts for the array, temperatures, SMART, fill level, pools and parity checks.
if (PHP_SAPI !== 'cli') { exit(1); } // cron only, not a web page
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/discord.php';
require_once __DIR__ . '/alerts.php';

$cfg = db_cfg();
$hook = (string)$cfg['dc_hook'];
if (!dbn_hook_ok($hook)) { exit(0); }
$lock = @fopen('/tmp/diskbox_notify.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { exit(0); }

// Same data and classification as the dashboard tile
$raw = (string)@shell_exec(escapeshellarg(db_php()) . ' ' . escapeshellarg(__DIR__ . '/api_fetch.php') . ' 2>/dev/null');
$j = json_decode($raw, true);
if (!is_array($j) || empty($j['ok']) || empty($j['started'])) { exit(0); }

$lang = dbn_lang($cfg);
dbn_ping_set($cfg['dc_ping']);
$st = dbn_state(); $st0 = json_encode($st);
$tm = dbn_tmp();
$remind = max(0, (int)$cfg['n_remind']);
$thr = db_thr($cfg);
$now = time();
$dk = db_disks();
$arr = $j['array'];

// ---- array protection ----------------------------------------------------------------------------
if (db_on($cfg['n_array'])) {
    $state = (string)$arr['state'];
    $level = '';
    if (in_array($state, ['unprotected', 'emulated', 'invalid'], true)) { $level = 'rd'; }
    elseif (in_array($state, ['degraded', 'rebuild', 'clear'], true)) { $level = 'a'; }
    $names = [];
    foreach ($arr['disks'] as $r) {
        if (($state === 'emulated' && !empty($r['emulated'])) || ($state === 'invalid' && $r['state'] === 'invalid')) { $names[] = $r['label']; }
    }
    $extra = '';
    foreach ((array)($j['array']['chip'] ?? []) as $c) { $extra = (string)$c; }
    dbn_track($st, $tm, 'array', $level, $state . '|' . implode(',', $names),
        function ($kind) use ($lang, $state, $names, $extra) { return dbn_e_array($lang, $state, $names, $kind, $extra); }, $hook, ['remind' => $remind]);
}

// ---- temperature, fill level, pools --------------------------------------------------------------
$rows = [];
foreach ($arr['parity'] as $r) { $rows[] = $r; }
foreach ($arr['disks'] as $r) { $rows[] = $r; }
foreach ($j['pools'] as $r) { $rows[] = $r; }
$fw = (float)$cfg['n_fill_warn']; $fc = (float)$cfg['n_fill_crit'];
foreach ($rows as $r) {
    $name = $r['name']; $label = $r['label'];
    $d = $dk[$name] ?? [];
    $info = trim((string)($d['id'] ?? '') . (isset($d['device']) ? ' (' . $d['device'] . ')' : ''));
    $ssd = (($d['rotational'] ?? '1') === '0');

    if (db_on($cfg['n_temp']) && $r['temp'] !== null) {
        $hot = $ssd ? $thr['hotssd'] : $thr['hot']; $max = $ssd ? $thr['maxssd'] : $thr['max'];
        $lv = ($r['tlevel'] === 'rd') ? 'rd' : (($r['tlevel'] === 'a') ? 'a' : '');
        $prev = dbn_cur_level($st, 'temp:' . $name);
        if ($lv === '' && $prev !== '' && $hot > 0 && $r['temp'] >= $hot - 2) { $lv = $prev; }   // cool down 2 degrees before the all-clear
        $t = (int)$r['temp'];
        dbn_track($st, $tm, 'temp:' . $name, $lv, '', function ($kind) use ($lang, $label, $t, $hot, $max, $lv, $info) {
            return dbn_e_temp($lang, $label, $t, $hot, $max, $lv, $kind, $info);
        }, $hook, ['need' => 3, 'remind' => $remind]);
    }

    if (db_on($cfg['n_fill']) && $r['pct'] !== null) {
        $pct = (float)$r['pct'];
        $prev = dbn_cur_level($st, 'fill:' . $name);
        $lv = ($fc > 0 && $pct >= $fc) ? 'rd' : (($fw > 0 && $pct >= $fw) ? 'a' : '');
        if ($lv === '' && $prev !== '' && $fw > 0 && $pct >= $fw - 2) { $lv = $prev; }
        // a disk being zeroed for removal: pct is the zeroing progress, not the fill level, so no fill alert;
        // an alert still open from such a reading is dropped without an all-clear message
        $zeroing = (($r['zero'] ?? '') !== '');
        if ($zeroing) { $lv = ''; }
        $used = (float)$r['used']; $size = (float)$r['size'];
        dbn_track($st, $tm, 'fill:' . $name, $lv, '', function ($kind) use ($lang, $label, $pct, $used, $size, $lv) {
            return dbn_e_fill($lang, $label, $pct, $used, $size, $lv, $kind);
        }, $hook, ['need' => 2, 'remind' => 0, 'resolve' => !$zeroing]);
    }

    if (db_on($cfg['n_pool']) && $r['kind'] === 'pool') {
        $bad = (!empty($r['health'][0]) && $r['health'][0] === 'rd' && strpos((string)$r['health'][1], 'ZFS pool') === 0);
        $htxt = $bad ? trim(substr((string)$r['health'][1], 8)) : '';
        dbn_track($st, $tm, 'pool:' . $name, $bad ? 'rd' : '', $htxt, function ($kind) use ($lang, $label, $htxt) {
            return dbn_e_pool($lang, $label, $htxt, $kind);
        }, $hook, ['need' => 2, 'remind' => $remind]);
    }
}

// ---- SMART ----------------------------------------------------------------------------------------
if (db_on($cfg['n_smart'])) {
    $ackAll = db_ack();
    foreach (db_smart() as $name => $s) {
        if (!isset($dk[$name]) || !is_array($s)) { continue; }
        $d = $dk[$name];
        $label = strtoupper($name);
        $info = trim((string)($d['id'] ?? '') . (isset($d['device']) ? ' (' . $d['device'] . ')' : ''));
        $iss = db_smart_issues($name, $s, $ackAll);
        $healthFailed = $iss['health'];
        $nvme = $iss['nvme'];
        $failedAttr = false; $sig = [];
        $last = $st['misc']['smart_last'][$name] ?? null;        // what the previous alert for this disk showed
        $ackT = (int)($ackAll[$name]['t'] ?? 0);
        $out = []; $trend = []; $fast = false; $slow = false;
        foreach ($iss['attrs'] as $a) {
            if ($a[7]) { $failedAttr = true; }
            $sig[] = $a[0] . ':' . $a[3];
            // reference: the previous alert if it already showed this attribute, else the acknowledged value
            $was = null; $refT = 0; $refName = '';
            if (is_array($last) && (int)$last['t'] >= $ackT && isset($last['raw'][(string)$a[0]]) && (int)$last['raw'][(string)$a[0]] <= (int)$a[3]) { $was = (int)$last['raw'][(string)$a[0]]; $refT = (int)$last['t']; $refName = 'since_alert'; }
            elseif ($a[8] !== null) { $was = (int)$a[8]; $refT = $ackT; $refName = 'since_ack'; }
            $out[] = [$a[0], $a[1], $a[2], $a[3], $was, $a[4], $a[5], $a[6], $a[7]];
            if ($was !== null && $a[3] > $was && $refT > 0) {
                $dl = $a[3] - $was; $age = max(60, $now - $refT);
                $trend[] = '`' . $a[0] . '` +' . $dl . ' ' . dbn_t($lang, 'within') . ' ' . dbn_fmt_dur($age) . ' (' . dbn_t($lang, $refName) . ')';
                if ($age <= 7 * 86400 || $dl >= 10) { $fast = true; } else { $slow = true; }
            }
        }
        $sev = $fast ? 'fast' : ($slow ? 'slow' : '');
        if ($healthFailed) { $sig[] = 'FAIL'; }
        foreach ($nvme as $nv) { $sig[] = $nv; }
        $lv = ($sig) ? (($healthFailed || $failedAttr || $fast) ? 'rd' : 'a') : '';
        $before = (int)($st['k']['smart:' . $name]['t'] ?? 0);
        dbn_track($st, $tm, 'smart:' . $name, $lv, implode(',', $sig), function ($kind) use ($lang, $label, $info, $out, $healthFailed, $nvme, $lv, $sev, $trend) {
            return dbn_e_smart($lang, $label, $info, $out, $healthFailed, $nvme, $lv, $sev, $trend);
        }, $hook, ['resolve' => false, 'remind' => $remind]);
        if ($lv !== '' && (int)($st['k']['smart:' . $name]['t'] ?? 0) !== $before) {   // an alert went out: remember what it showed
            $lr = [];
            foreach ($iss['attrs'] as $a) { $lr[(string)$a[0]] = $a[3]; }
            $st['misc']['smart_last'][$name] = ['t' => $now, 'raw' => $lr];
        }
    }
}

// ---- parity check / rebuild -----------------------------------------------------------------------
if (db_on($cfg['n_parity'])) {
    $v = db_var();
    $now = ((float)($v['mdResync'] ?? 0) > 0);
    $act = db_resync_kind($v['mdResyncAction'] ?? 'check')[0];
    $was = $st['misc']['resync'] ?? '';
    if ($now && $was === '') {
        $r = dbn_post($hook, [dbn_e_resync_start($lang, $act)]);
        if ($r[0] !== 'retry') { $st['misc']['resync'] = $act; }
    } elseif (!$now && $was !== '') {
        $pcr = dbn_resync_result($v);
        $emb = dbn_e_resync_end($lang, $was, $pcr); $bad = $pcr['errors'] !== null && $pcr['errors'] > 0;
        $r = dbn_post($hook, [$emb], $bad ? dbn_ping_set() : '');
        if ($r[0] !== 'retry') { unset($st['misc']['resync']); dbn_log($st, 'parity', 'i'); if ($bad) { dbn_guard_event('parity_end', 'rd', $emb); } }
    }
}

if (json_encode($st) !== $st0) { dbn_write(DBN_STATE, $st); }
dbn_write(DBN_TMP, $tm);
