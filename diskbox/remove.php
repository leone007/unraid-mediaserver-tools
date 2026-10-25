<?php
// Diskbox Balance, third mode "Remove disks" (item 52): after the disks are chosen in the Setup, the steps are
// 2 shares keep new files off them (the owner changes the shares; this only checks), 3 empty them (the Empty disks plan),
// 4 check they are empty, 5 clear them (zeros through parity, both side by side), 6 New Config (the owner, in Unraid).
// Every step is a button and checks every step before it again on the server: nothing can be skipped.
require_once __DIR__ . '/balance_lib.php';

define('RM_STATE', dirname(BL_CFG) . '/remove.json');

function rm_ini($f, $sections) {
    $d = getenv('DBX_EMHTTP'); $d = ($d !== false && $d !== '') ? rtrim($d, '/') : '/var/local/emhttp';
    $x = @parse_ini_file($d . '/' . $f, $sections);
    return is_array($x) ? $x : [];
}

// the disks chosen in the Setup (Remove disks mode), only array data disks that exist
function rm_sel() {
    $c = bl_cfg();
    if (($c['mode'] ?? '') !== 'remove') { return []; }
    $ini = rm_ini('disks.ini', true); $out = [];
    foreach ((array)$c['drain'] as $n) { if (is_string($n) && preg_match('/^disk\d+$/', $n) && isset($ini[$n])) { $out[] = $n; } }
    return $out;
}

// '' = parity protects the array now; else why not. An empty parity slot (DISK_NP_DSBL, for example Parity 2 not used) is fine.
function rm_parity_reason() {
    $v = rm_ini('var.ini', false);
    if (($v['mdState'] ?? '') !== 'STARTED') { return bl_tr('why.not_started'); }
    if ((float)($v['mdResync'] ?? 0) > 0) { return bl_tr('why.resync'); }
    $np = 0;
    foreach (rm_ini('disks.ini', true) as $k => $d) {
        if (!is_array($d) || (string)($d['device'] ?? '') === '') { continue; }
        $t = (string)($d['type'] ?? '');
        if ($t !== 'Parity' && $t !== 'Data') { continue; }
        if (($d['status'] ?? '') !== 'DISK_OK') { return bl_tr('why.disk_not_ok', ['d' => strtoupper($k), 's' => ($d['status'] ?? '?')]); }
        if ($t === 'Parity') { $np++; }
    }
    return $np ? '' : bl_tr('why.no_parity');
}

// shares that may still write new files onto the chosen disks: [[share, [disks]]]. A share only on a pool (useCache=only)
// never writes to the array. Included/excluded disks of the share and the global share settings are both applied.
function rm_shares($sel) {
    $v = rm_ini('var.ini', false);
    $lst = function ($s) { return array_values(array_filter(array_map('trim', explode(',', (string)$s)))); };
    $gi = $lst($v['shareUserInclude'] ?? ''); $ge = $lst($v['shareUserExclude'] ?? '');
    $out = [];
    foreach (rm_ini('shares.ini', true) as $name => $s) {
        if (!is_array($s) || ($s['useCache'] ?? '') === 'only') { continue; }
        $in = $lst($s['include'] ?? ''); $ex = $lst($s['exclude'] ?? ''); $w = [];
        foreach ($sel as $d) {
            if (($in && !in_array($d, $in, true)) || in_array($d, $ex, true) || ($gi && !in_array($d, $gi, true)) || in_array($d, $ge, true)) { continue; }
            $w[] = $d;
        }
        if ($w) { $out[] = [(string)$name, $w]; }
    }
    return $out;
}

// what is still on a disk: every entry that is not a folder (files, links), hidden ones too. Empty folders do not count.
function rm_scan($disk) {
    $n = 0; $bytes = 0.0; $sample = [];
    $h = @popen('find ' . escapeshellarg(bl_mnt() . '/' . $disk) . ' -xdev -mindepth 1 ! -type d -printf ' . escapeshellarg('%s\t%P\0') . ' 2>/dev/null', 'r');
    if (!$h) { return ['n' => -1, 'bytes' => 0, 'sample' => []]; }
    $buf = '';
    while (!feof($h)) {
        $buf .= (string)fread($h, 65536);
        while (($p = strpos($buf, "\0")) !== false) {
            $rec = substr($buf, 0, $p); $buf = substr($buf, $p + 1);
            $f = explode("\t", $rec, 2); $n++; $bytes += (float)$f[0];
            if (count($sample) < 30) { $sample[] = $f[1] ?? ''; }
        }
    }
    pclose($h);
    return ['n' => $n, 'bytes' => $bytes, 'sample' => $sample];
}

function rm_dev($disk) {
    $n = (int)substr($disk, 4);
    foreach (['/dev/md' . $n . 'p1', '/dev/md' . $n] as $d) { if (@filetype($d) === 'block') { return $d; } }
    return '';
}

// everything the page shows; also the check every step makes before it acts
function rm_status() {
    $sel = rm_sel(); $st = bl_json_read(RM_STATE, []);
    if (($st['disks'] ?? null) !== $sel) { $st = ['disks' => $sel]; } // another choice of disks: the old check and clearing do not count
    $ini = rm_ini('disks.ini', true); $bd = bl_disks(); $z = db_zeroing(); $info = [];
    foreach ($sel as $d) {
        $zz = $z[$d] ?? null;
        $info[$d] = ['id' => (string)($ini[$d]['id'] ?? ''), 'present' => (string)($ini[$d]['device'] ?? '') !== '', 'mounted' => ($ini[$d]['fsStatus'] ?? '') === 'Mounted',
                     'used' => $bd[$d]['used'] ?? null, 'size' => (float)($ini[$d]['size'] ?? 0) * 1024,
                     'zero' => $zz ? ['run' => !empty($zz['running']), 'done' => !empty($zz['done']), 'pos' => $zz['pos'], 'size' => $zz['size'], 'rate' => $zz['rate']] : null];
    }
    $shares = $sel ? rm_shares($sel) : [];
    $chk = $st['check'] ?? null;
    $cleared = $sel && count(array_filter($info, function ($i) { return $i['zero'] && $i['zero']['done']; })) === count($sel);
    $gone = $sel && count(array_filter($info, function ($i) { return !$i['present']; })) === count($sel);
    return ['sel' => $sel, 'disks' => $info, 'parity' => rm_parity_reason(), 'busy' => bl_busy_reason(), 'balance' => bl_running(),
            'shares' => $shares, 'check' => $chk, 'clear' => $st['clear'] ?? null, 'cleared' => $cleared, 'gone' => $gone,
            'step' => !$sel ? 1 : ($shares ? 2 : (!($chk['ok'] ?? false) ? 3 : (!$cleared ? 5 : 6))), 'st' => $st];
}

function rm_op($op) {
    $s = rm_status(); $sel = $s['sel']; $st = $s['st']; unset($s['st']);
    if ($op === 'rm_state') { bl_out(true, ['rm' => $s]); }
    if (!$sel) { bl_out(false, ['error' => bl_tr('rm.err.choose')]); }
    if ($op === 'rm_check') {
        if ($s['shares']) { bl_out(false, ['error' => 'Step 2 first: ' . count($s['shares']) . ' share(s) can still write to the disks.']); }
        if ($s['balance']) { bl_out(false, ['error' => bl_tr('rm.err.move_check')]); }
        $res = []; $ok = true;
        foreach ($sel as $d) { $r = rm_scan($d); $res[$d] = $r; if ($r['n'] !== 0) { $ok = false; } }
        $st['check'] = ['t' => time(), 'ok' => $ok, 'res' => $res];
        bl_json_write(RM_STATE, $st, 0600);
        bl_out(true);
    }
    if ($op === 'rm_clear') {
        // one start at a time: a double click must not start two dd runs on one disk (item 104)
        $lk = @fopen('/tmp/dbx_rm_clear.lock', 'c'); if (!$lk || !flock($lk, LOCK_EX | LOCK_NB)) { bl_out(false, ['error' => bl_tr('rm.err.clearing')]); }
        $want = implode(' ', $sel);
        if (trim(preg_replace('/\s+/', ' ', (string)($_POST['confirm'] ?? ''))) !== $want) { bl_out(false, ['error' => bl_tr('rm.err.type', ['w' => $want])]); }
        if ($s['parity'] !== '') { bl_out(false, ['error' => bl_tr('rm.err.cannot', ['why' => $s['parity']])]); }
        if ($s['busy'] !== '') { bl_out(false, ['error' => bl_tr('rm.err.cannot', ['why' => $s['busy']])]); }
        if ($s['balance']) { bl_out(false, ['error' => bl_tr('err.running')]); }
        if ($s['shares']) { bl_out(false, ['error' => bl_tr('rm.err.step2')]); }
        if (!($s['check']['ok'] ?? false)) { bl_out(false, ['error' => bl_tr('rm.err.step4')]); }
        foreach ($sel as $d) { // checked again now: something may have arrived since the check
            $r = rm_scan($d);
            if ($r['n'] !== 0) { $st['check'] = ['t' => time(), 'ok' => false, 'res' => [$d => $r]]; bl_json_write(RM_STATE, $st, 0600); bl_out(false, ['error' => bl_tr('rm.err.not_empty_now', ['d' => strtoupper($d), 'n' => $r['n']])]); }
            if (!$s['disks'][$d]['mounted']) { bl_out(false, ['error' => bl_tr('rm.err.not_mounted', ['d' => strtoupper($d)])]); }
            if ($s['disks'][$d]['zero'] && $s['disks'][$d]['zero']['run']) { bl_out(false, ['error' => bl_tr('rm.err.clearing_d', ['d' => strtoupper($d)])]); }
            if ($s['disks'][$d]['zero'] && $s['disks'][$d]['zero']['done']) { bl_out(false, ['error' => bl_tr('rm.err.cleared', ['d' => strtoupper($d)])]); }
            // started, dd not there yet (umount, stand-in image): wait until the log shows how it ended; a run that stopped may start again
            if (in_array($d, (array)($st['clear']['disks'] ?? []), true) && !$s['disks'][$d]['zero'] && time() - (int)($st['clear']['t'] ?? 0) < 600) {
                $lg = (string)@file_get_contents('/tmp/dbx_clear_' . (int)substr($d, 4) . '.log'); $lg = substr($lg, (int)strrpos($lg, 'start '));
                if (strpos($lg, 'FAILED') === false && strpos($lg, 'dd ended') === false) { bl_out(false, ['error' => bl_tr('rm.err.starting', ['d' => strtoupper($d)])]); }
            }
            if (rm_dev($d) === '') { bl_out(false, ['error' => bl_tr('rm.err.nodev', ['d' => strtoupper($d)])]); }
        }
        foreach ($sel as $d) {
            $n = (int)substr($d, 4); $dev = rm_dev($d); $mnt = bl_mnt() . '/' . $d; $img = '/tmp/xmini' . $n . '.img'; $log = '/tmp/dbx_clear_' . $n . '.log';
            // unmount; a small XFS image takes the mount point (Unraid 6.12+ cannot stop the array otherwise); then zeros through
            // the md device, so parity is updated and stays valid. dd ends with 'No space left on device' at the end: that is the normal end.
            $sh = 'exec >>' . escapeshellarg($log) . ' 2>&1; echo "start $(date)";'
                . ' umount ' . escapeshellarg($mnt) . ' || { echo "FAILED: could not unmount ' . $mnt . ', nothing was changed"; exit 1; };'
                . ' rm -f ' . escapeshellarg($img) . '; truncate -s 400M ' . escapeshellarg($img) . ' && mkfs.xfs -f -q ' . escapeshellarg($img)
                . ' && mount -o loop ' . escapeshellarg($img) . ' ' . escapeshellarg($mnt) . ' || { echo "FAILED: the stand-in image could not be mounted; the disk is unmounted, not cleared"; exit 1; };'
                . ' echo "writing zeros to ' . $dev . '"; dd if=/dev/zero of=' . escapeshellarg($dev) . ' bs=1M; echo "dd ended (exit $?) $(date)"';
            $tm = getenv('DBX_MNT');
            if ($tm !== false && $tm !== '') { @file_put_contents($log, "test mode, not run: " . $sh . "\n", FILE_APPEND); continue; } // tests: never run it
            @exec('setsid nohup /bin/bash -c ' . escapeshellarg($sh) . ' >/dev/null 2>&1 &');
        }
        $st['clear'] = ['t' => time(), 'disks' => $sel];
        bl_json_write(RM_STATE, $st, 0600);
        @file_put_contents(BL_LOG, date('Y-m-d H:i') . ' clearing started: ' . $want . "\n", FILE_APPEND);
        bl_out(true);
    }
    if ($op === 'rm_log') {
        $o = [];
        foreach ($sel as $d) { $f = '/tmp/dbx_clear_' . (int)substr($d, 4) . '.log'; $o[$d] = is_file($f) ? substr((string)@file_get_contents($f), -2000) : ''; }
        bl_out(true, ['log' => $o]);
    }
    if ($op === 'rm_reset') {
        foreach ($s['disks'] as $d => $i) { if ($i['zero'] && $i['zero']['run']) { bl_out(false, ['error' => bl_tr('rm.err.clearing')]); } }
        @unlink(RM_STATE);
        bl_out(true);
    }
    bl_out(false, ['error' => 'unknown action']);
}
