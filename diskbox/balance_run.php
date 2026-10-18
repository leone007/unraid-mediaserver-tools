<?php
// Background worker (started by balance.php): php balance_run.php plan | run
if (PHP_SAPI !== 'cli') { exit(1); }
require_once __DIR__ . '/balance_lib.php';
require_once __DIR__ . '/discord.php';
@set_time_limit(0);
@ini_set('memory_limit', '1024M');
$mode = $argv[1] ?? '';

if ($mode === 'plan') {
    $lock = @fopen(dirname(BL_PLAN) . '/diskbox_balance_plan.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { exit(0); }
    bl_make_plan();
    exit(0);
}
if ($mode !== 'run') { exit(1); }

$lock = @fopen(BL_LOCK, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { exit(0); }
@unlink(BL_STOP);

$cfg = db_cfg();
$hook = (string)$cfg['dc_hook'];
$lang = dbn_lang($cfg);
dbn_ping_set($cfg['dc_ping']);
$hu = ($lang === 'hu');

$job = bl_json_read(BL_JOB, []);
$plan = bl_json_read(BL_PLAN, []);
$planf = bl_json_read(BL_PLANF, []);
$disks = bl_disks();
$byId = [];
foreach ((array)($plan['units'] ?? []) as $u) { $byId[$u['id']] = $u; }

$S = ['state' => 'running', 'started' => time(), 'finished' => 0, 'total' => 0.0, 'done' => 0.0, 'cur' => -1, 'curBytes' => 0.0, 'msg' => '', 'items' => [], 'log' => []];
$lastWrite = 0;
function bl_s_write($force = false) {
    global $S, $lastWrite;
    if (!$force && microtime(true) - $lastWrite < 1.5) { return; }
    $lastWrite = microtime(true);
    bl_json_write(BL_STATUS, $S);
}
function bl_s_log($m) {
    global $S;
    $S['log'][] = date('H:i:s') . ' ' . $m;
    if (count($S['log']) > 150) { $S['log'] = array_slice($S['log'], -150); }
    bl_s_write(true);
}

foreach ((array)($job['items'] ?? []) as $it) {
    $u = $byId[$it['id']] ?? null;
    if (!$u || !isset($planf[$it['id']]) || !isset($disks[$it['dst']]) || $it['dst'] === $u['src']) { continue; }
    $S['items'][] = ['id' => $u['id'], 'title' => $u['title'], 'pair' => $u['pair'], 'src' => $u['src'], 'dst' => $it['dst'], 'size' => (float)$u['size'], 'state' => 'wait', 'msg' => ''];
    $S['total'] += (float)$u['size'];
}
if (!$S['items']) { $S['state'] = 'error'; $S['msg'] = 'Nothing to move.'; $S['finished'] = time(); bl_s_write(true); exit(0); }

function bl_say($level, $title, $desc, $fields = []) {
    global $hook;
    if (!dbn_hook_ok($hook)) { return; }
    $e = dbn_embed($level, $title, $desc, $fields, 'Balance');
    $r = dbn_post($hook, [$e]);
}
function bl_hist($line) {
    $old = is_file(BL_LOG) ? preg_split('/\r?\n/', trim((string)@file_get_contents(BL_LOG))) : [];
    $old[] = date('Y-m-d H:i') . ' ' . $line;
    $old = array_slice(array_filter($old, 'strlen'), -300);
    @file_put_contents(BL_LOG, implode("\n", $old) . "\n");
}

// remove the files of one unit from a disk, then the folders that became empty (never the configured roots)
function bl_remove_files($disk, $paths, $stopDirs) {
    $root = bl_mnt() . '/' . $disk;
    $dirs = [];
    foreach ($paths as $p) { if (@unlink($root . '/' . $p)) { $dirs[dirname($p)] = true; } elseif (is_file($root . '/' . $p)) { return false; } }
    $dirs = array_keys($dirs);
    usort($dirs, function ($a, $b) { return strlen($b) <=> strlen($a); });
    foreach ($dirs as $d) {
        while ($d !== '.' && $d !== '' && !in_array($d, $stopDirs, true)) {
            if (!@rmdir($root . '/' . $d)) { break; }
            $d = dirname($d);
        }
    }
    return true;
}

$stopDirs = [];
foreach ((array)bl_cfg()['pairs'] as $p) { $stopDirs[] = trim($p['dl'], '/'); $stopDirs[] = trim($p['dl'], '/') . '/' . BL_TRASH; if (trim((string)$p['media'], '/') !== '') { $stopDirs[] = trim($p['media'], '/'); } }
// parents of the roots must stay too
foreach ($stopDirs as $d) { $x = $d; while (($x = dirname($x)) !== '.' && $x !== '') { $stopDirs[] = $x; } }

bl_say('i', $hu ? 'Balance indul' : 'Balance started', ($hu ? count($S['items']) . ' cím költöztetése, ' : count($S['items']) . ' titles, ') . dbn_fmt_bytes($S['total']));
bl_s_log('start: ' . count($S['items']) . ' titles, ' . dbn_fmt_bytes($S['total']));
bl_hist('start ' . count($S['items']) . ' titles ' . dbn_fmt_bytes($S['total']));

$okN = 0; $skipN = 0; $failN = 0; $movedBytes = 0.0; $aborted = false; $fatal = '';
foreach ($S['items'] as $i => &$item) {
    $S['cur'] = $i; $S['curBytes'] = 0.0;
    $why = bl_busy_reason();
    if ($why !== '') { $fatal = 'Stopped: ' . $why . '.'; break; }
    if (is_file(BL_STOP)) { $aborted = true; break; }
    $files = $planf[$item['id']];
    $src = $item['src']; $dst = $item['dst'];
    $sroot = bl_mnt() . '/' . $src; $droot = bl_mnt() . '/' . $dst;
    $item['state'] = 'run'; bl_s_write(true); bl_s_log($item['pair'] . '/' . $item['title'] . ' ' . strtoupper($src) . ' -> ' . strtoupper($dst));

    // re-check right before copying
    $paths = []; $groups = []; $meta = []; $size = 0.0; $bad = '';
    foreach ($files as $f) {
        $st = @stat($sroot . '/' . $f[0]);
        if ($st === false) { $bad = 'a file is gone from the source'; break; }
        if (file_exists($droot . '/' . $f[0])) { $bad = 'a file already exists on the target'; break; }
        if ($st['mtime'] > time() - 7200) { $bad = 'a file changed in the last 2 hours'; break; }
        $paths[] = $f[0];
        $groups[$st['ino']][] = $f[0];
        if (!isset($meta[$st['ino']])) { $meta[$st['ino']] = $st['nlink']; $size += $st['size']; }
    }
    if ($bad === '') {
        foreach ($groups as $ino => $g) { if ($meta[$ino] != count($g)) { $bad = 'the hardlinks changed since the scan'; break; } }
    }
    $reserve = max(5e9, 0.01 * $disks[$dst]['size']);
    if ($bad === '' && (float)@disk_free_space($droot) < $size + $reserve) { $bad = 'not enough free space on ' . strtoupper($dst); }
    if ($bad !== '') { $item['state'] = 'skip'; $item['msg'] = $bad; $skipN++; bl_s_log('skipped: ' . $bad); continue; }

    // copy: one rsync run for the whole unit, so the hardlinks are recreated on the target
    $list = tempnam(dirname(BL_PLAN), 'dbxbl');
    file_put_contents($list, implode("\0", $paths) . "\0");
    $pre = '';
    if (getenv('DBX_RSYNC') === false) { $pre = trim((string)@shell_exec('command -v ionice')) !== '' ? 'ionice -c2 -n7 ' : ''; }
    $cmd = $pre . escapeshellarg(bl_rsync()) . ' -aH --from0 --files-from=' . escapeshellarg($list) . ' --info=progress2 --no-inc-recursive ' . escapeshellarg($sroot . '/') . ' ' . escapeshellarg($droot . '/');
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $exit = -1; $err = ''; $buf = '';
    if (is_resource($proc)) {
        stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
        while (true) {
            $o = stream_get_contents($pipes[1]); $e = stream_get_contents($pipes[2]);
            if ($o !== false && $o !== '') { $buf = substr($buf . $o, -400); if (preg_match_all('/([\d,]+)\s+\d+%/', $buf, $m)) { $S['curBytes'] = (float)str_replace(',', '', end($m[1])); } }
            if ($e !== false && $e !== '') { $err = substr($err . $e, -600); }
            if (is_file(BL_STOP)) { proc_terminate($proc); $aborted = true; }
            $st = proc_get_status($proc);
            if (!$st['running']) { $exit = $st['exitcode']; break; }
            bl_s_write();
            usleep(300000);
        }
        $o = stream_get_contents($pipes[1]); $e = stream_get_contents($pipes[2]);
        if ($e) { $err = substr($err . $e, -600); }
        proc_close($proc);
    }
    @unlink($list);

    // verify: size, and every group of hardlinks on the source is the same inode group on the target
    $vbad = '';
    if ($aborted) { $vbad = 'stopped'; }
    elseif ($exit !== 0) { $vbad = 'rsync failed (' . $exit . ') ' . trim(preg_replace('/\s+/', ' ', $err)); }
    else {
        foreach ($groups as $k => $g) {
            $ino0 = null;
            foreach ($g as $p) {
                $a = @stat($sroot . '/' . $p); $b = @stat($droot . '/' . $p);
                if ($b === false || $a === false || $a['size'] !== $b['size']) { $vbad = 'verification failed: ' . $p; break 2; }
                if ($ino0 === null) { $ino0 = $b['ino']; } elseif ($b['ino'] !== $ino0) { $vbad = 'verification failed: the hardlink was not kept (' . $p . ')'; break 2; }
                if ($b['nlink'] != count($g)) { $vbad = 'verification failed: link count (' . $p . ')'; break 2; }
            }
        }
    }
    if ($vbad !== '') {
        bl_remove_files($dst, $paths, $stopDirs);   // the source is untouched; undo the copy
        if ($aborted) { $item['state'] = 'skip'; $item['msg'] = 'stopped, the copy was removed'; bl_s_log('stopped, copy removed'); break; }
        $item['state'] = 'fail'; $item['msg'] = $vbad; $failN++; bl_s_log('FAILED: ' . $vbad);
        $fatal = $vbad; break;
    }
    if (!bl_remove_files($src, $paths, $stopDirs)) {
        $item['state'] = 'fail'; $item['msg'] = 'could not remove the source files'; $failN++; $fatal = 'could not remove the source files of ' . $item['title']; bl_s_log('FAILED: ' . $fatal); break;
    }
    $item['state'] = 'ok'; $okN++; $movedBytes += $item['size']; $S['done'] += $item['size']; $S['curBytes'] = 0.0;
    bl_s_log('moved ' . dbn_fmt_bytes($item['size']));
}
unset($item);

$S['cur'] = -1; $S['curBytes'] = 0.0; $S['finished'] = time();
if ($fatal !== '') { $S['state'] = 'error'; $S['msg'] = $fatal; }
elseif ($aborted) { $S['state'] = 'aborted'; $S['msg'] = 'Stopped.'; }
else { $S['state'] = 'done'; }
bl_s_write(true);
@unlink(BL_STOP);
@unlink(BL_PLAN); @unlink(BL_PLANF);

$after = bl_disks(); $lines = [];
foreach ($after as $n => $d) { $lines[] = $d['label'] . ' ' . number_format($d['pct'], 0) . '%'; }
$sum = $okN . '/' . count($S['items']) . ' · ' . dbn_fmt_bytes($movedBytes) . ' · ' . dbn_fmt_dur(time() - $S['started']);
bl_hist($S['state'] . ' ' . $sum . ($fatal !== '' ? ' ' . $fatal : ''));
if ($S['state'] === 'done') {
    bl_say($skipN > 0 ? 'a' : 'g', $hu ? 'Balance kész' : 'Balance finished',
        ($hu ? 'Áthelyezve: ' : 'Moved: ') . $sum . ($skipN > 0 ? "\n" . ($hu ? 'Kihagyva: ' : 'Skipped: ') . $skipN : ''), [[$hu ? 'Kitöltöttség most' : 'Fill now', implode(' · ', $lines)]]);
} elseif ($S['state'] === 'aborted') {
    bl_say('a', $hu ? 'Balance megállítva' : 'Balance stopped', ($hu ? 'Áthelyezve: ' : 'Moved: ') . $sum, [[$hu ? 'Kitöltöttség most' : 'Fill now', implode(' · ', $lines)]]);
} else {
    bl_say('rd', $hu ? 'Balance leállt hibával' : 'Balance stopped with an error', $fatal . "\n" . ($hu ? 'Áthelyezve addig: ' : 'Moved so far: ') . $sum);
}
