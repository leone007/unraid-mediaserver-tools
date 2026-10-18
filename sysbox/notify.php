<?php
// Started by cron every minute: Discord alerts for containers, memory, storage, CPU temperature and restarts.
if (PHP_SAPI !== 'cli') { exit(1); } // cron only, not a web page
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/discord.php';
require_once __DIR__ . '/alerts.php';

$cfg = sx_cfg();
$hook = (string)$cfg['dc_hook'];
if (!sxn_hook_ok($hook)) { exit(0); }
$lock = @fopen('/tmp/sysbox_notify.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { exit(0); }

$lang = sxn_lang($cfg);
sxn_ping_set($cfg['dc_ping']);
$st = sxn_state(); $st0 = json_encode($st);
$tm = sxn_tmp();
$remind = max(0, (int)$cfg['n_remind']);
$now = time();

// ---- server restart ------------------------------------------------------------------------------
if (sx_on($cfg['n_boot'])) {
    $bid = trim((string)@file_get_contents('/proc/sys/kernel/random/boot_id'));
    $bootTs = $now - (int)((float)@file_get_contents('/proc/uptime'));
    $prevId = (string)($st['misc']['boot_id'] ?? '');
    $seen = (int)($st['misc']['seen'] ?? 0);
    if ($bid !== '' && $bid !== $prevId) {
        $send = ($prevId !== '');
        if ($send) {
            $r = sxn_post($hook, [sxn_e_boot($lang, $bootTs, $seen)]);
            if ($r[0] === 'retry') { $send = null; } else { sxn_log($st, 'boot', 'i'); }
        }
        if ($send !== null) { $st['misc']['boot_id'] = $bid; $st['misc']['seen'] = $now; }
    } elseif ($now - $seen >= 1800) { $st['misc']['seen'] = $now; }   // written to flash at most every 30 minutes
}

// ---- containers ------------------------------------------------------------------------------------
$ct = sx_on($cfg['n_containers']) ? sxn_inspect() : null;
if ($ct !== null) {
    $stop = is_file('/tmp/sysbox_userstop.json') ? (json_decode((string)@file_get_contents('/tmp/sysbox_userstop.json'), true) ?: []) : [];
    foreach ($ct as $name => $c) {
        $s = $tm['x']['ct'][$name] ?? ['up' => false, 'dn' => 0, 'al' => false, 'h' => []];

        // stopped unexpectedly: it ran at the last check, and its exit was not a normal one (0 or SIGTERM) nor made from the dashboard
        if (in_array($c['state'], ['running', 'restarting', 'paused'], true)) { $s['up'] = true; $s['dn'] = 0; $s['al'] = false; }
        elseif ($s['up']) {
            $s['dn']++;
            if ($s['dn'] >= 2) {
                $byUser = isset($stop[$name]) && ($now - (int)$stop[$name]) < 900;
                $s['al'] = (!$byUser && !in_array($c['exit'], [0, 143], true)) || $c['oom'];
                $s['up'] = false;
            }
        }
        $ex = $c['exit']; $oom = $c['oom'];
        sxn_track($st, $tm, 'down:' . $name, $s['al'] ? 'rd' : '', (string)$ex, function ($kind) use ($lang, $name, $ex, $oom) {
            return sxn_e_down($lang, $name, $ex, $oom, $kind);
        }, $hook, ['remind' => $remind]);

        // restart loop: three or more restarts within ten minutes
        $h = $s['h'];
        $h[] = [$now, $c['restart']];
        $h = array_values(array_filter($h, function ($x) use ($now) { return $x[0] >= $now - 600; }));
        $delta = $c['restart'] - $h[0][1];
        if ($delta < 0) { $h = [[$now, $c['restart']]]; $delta = 0; }
        $s['h'] = $h;
        $active = (sxn_cur_level($st, 'loop:' . $name) !== '');
        $lvl = ($delta >= 3 || ($active && $delta > 0)) ? 'rd' : '';
        sxn_track($st, $tm, 'loop:' . $name, $lvl, '', function ($kind) use ($lang, $name, $delta, $ex) {
            return sxn_e_loop($lang, $name, $delta, 10, $ex, $kind);
        }, $hook, ['remind' => $remind]);

        // unhealthy for three minutes in a row
        sxn_track($st, $tm, 'unh:' . $name, ($c['health'] === 'unhealthy' && $c['state'] === 'running') ? 'a' : '', '', function ($kind) use ($lang, $name) {
            return sxn_e_unhealthy($lang, $name, $kind);
        }, $hook, ['need' => 3]);

        $tm['x']['ct'][$name] = $s;
    }
    // forget containers that were deleted
    foreach (array_keys($st['k']) as $k) {
        if (preg_match('/^(down|loop|unh):(.+)$/', $k, $m) && !isset($ct[$m[2]])) { unset($st['k'][$k]); }
    }
    foreach (array_keys($tm['x']['ct'] ?? []) as $n) { if (!isset($ct[$n])) { unset($tm['x']['ct'][$n]); } }
}

// ---- memory ----------------------------------------------------------------------------------------
if (sx_on($cfg['n_ram'])) {
    $m = sxn_mem();
    $w = (float)$cfg['ram_warn']; $c = (float)$cfg['ram_crit'];
    $lv = $m['pct'] >= $c ? 'rd' : ($m['pct'] >= $w ? 'a' : '');
    $prev = sxn_cur_level($st, 'ram');
    if ($lv === '' && $prev !== '' && $m['pct'] >= $w - 3) { $lv = $prev; }
    sxn_track($st, $tm, 'ram', $lv, '', function ($kind) use ($lang, $m, $lv) { return sxn_e_ram($lang, $m, $lv, $kind); }, $hook, ['need' => 5, 'remind' => $remind]);
}

// ---- storage ---------------------------------------------------------------------------------------
if (sx_on($cfg['n_storage'])) {
    $items = [['BOOT', '/boot', 90, 97, 'boot'], ['LOG', '/var/log', (float)$cfg['log_warn'], (float)$cfg['log_crit'], 'log']];
    if (sxn_mounted('/var/lib/docker')) { $items[] = ['DOCKER VDISK', '/var/lib/docker', (float)$cfg['vd_warn'], (float)$cfg['vd_crit'], 'vdisk']; }
    foreach ($items as $it) {
        $f = sxn_fs($it[1]);
        if (!$f) { continue; }
        $key = 'st:' . $it[4];
        $lv = $f['pct'] >= $it[3] ? 'rd' : ($f['pct'] >= $it[2] ? 'a' : '');
        $prev = sxn_cur_level($st, $key);
        if ($lv === '' && $prev !== '' && $f['pct'] >= $it[2] - 3) { $lv = $prev; }
        $label = $it[0];
        sxn_track($st, $tm, $key, $lv, '', function ($kind) use ($lang, $label, $f, $lv) { return sxn_e_storage($lang, $label, $f, $lv, $kind); }, $hook, ['need' => 2, 'remind' => $remind]);
    }
}

// ---- CPU temperature ---------------------------------------------------------------------------------
if (sx_on($cfg['n_cpu'])) {
    list($tp,) = sx_temps();
    if ($tp !== null) {
        $w = (float)$cfg['temp_warn']; $c = (float)$cfg['temp_crit'];
        $lv = $tp >= $c ? 'rd' : ($tp >= $w ? 'a' : '');
        $prev = sxn_cur_level($st, 'cpu_temp');
        if ($lv === '' && $prev !== '' && $tp >= $w - 3) { $lv = $prev; }
        sxn_track($st, $tm, 'cpu_temp', $lv, '', function ($kind) use ($lang, $tp, $w, $c, $lv) { return sxn_e_cpu($lang, $tp, $w, $c, $lv, $kind); }, $hook, ['need' => 5, 'remind' => $remind]);
    }
}

if (json_encode($st) !== $st0) { sxn_write(SXN_STATE, $st); }
sxn_write(SXN_TMP, $tm);
