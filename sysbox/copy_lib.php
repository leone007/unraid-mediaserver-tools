<?php
// Snapshots copies (Sysbox, item 105): the daily snapshot copied to an array disk (rsync, one browsable folder per day,
// unchanged files hardlinked to the day before) and, weekly, an encrypted copy off the server (restic, SFTP or S3).
// Only folders this code made are removed (date names inside the dataset folder); a restore is a click on the page.
require_once __DIR__ . '/snap_lib.php';

const CP_RESTIC_VER = '0.19.1';
const CP_RESTIC_SHA = 'f415415624dcc452f2a02b8c33641791a8c6d6d3b65bbb3543fcf9a25151585c';   // restic_0.19.1_linux_amd64.bz2 (SHA256SUMS of the release)

// a message is [dictionary key, values] (or a list of them): cp_t() renders it for the page, cp_t(.., true) for Discord
function cp_t($m, $dc = false) {
    if (is_string($m)) { return $m; }
    if (isset($m[0]) && is_string($m[0])) { return $dc ? sx_trd($m[0], (array)($m[1] ?? [])) : sx_tr($m[0], (array)($m[1] ?? [])); }
    return implode("\n", array_map(function ($x) use ($dc) { return cp_t($x, $dc); }, (array)$m));
}
function cp_env($n, $def) { $v = getenv($n); return ($v !== false && $v !== '') ? rtrim($v, '/') : $def; }
function cp_disks_root() { return cp_env('SX_SNAP_DISKS', '/mnt'); }          // tests: a fake /mnt with disk1.. folders
function cp_user_root() { return cp_env('SX_SNAP_USER', '/mnt/user'); }
function cp_shares_ini() { return cp_env('SX_SHARES_INI', '/usr/local/emhttp/state/shares.ini'); }
function cp_state_file() { return snp_dir() . '/copies_state.json'; }
function cp_secret_file() { return snp_dir() . '/offsite_secret.json'; }
function cp_key_file() { return snp_dir() . '/offsite_ssh_ed25519'; }
function cp_known_file() { return snp_dir() . '/offsite_known_hosts'; }
function cp_job_file() { return snp_tmp() . '/sysbox_snap_job.json'; }

function cp_state() { $s = json_decode((string)@file_get_contents(cp_state_file()), true); return is_array($s) ? $s : []; }
function cp_state_set($k, $v) { $s = cp_state(); $s[$k] = $v; snp_json_write(cp_state_file(), $s); }
function cp_secret() { $s = json_decode((string)@file_get_contents(cp_secret_file()), true); return is_array($s) ? $s : []; }
function cp_secret_write($s) { $ok = snp_json_write(cp_secret_file(), $s); @chmod(cp_secret_file(), 0600); return $ok; }
// the restic password: made once, never changed (the off-site copies cannot be read without it)
function cp_password() {
    $s = cp_secret();
    if (empty($s['pw'])) {
        $a = 'abcdefghjkmnpqrstuvwxyz23456789'; $p = [];
        for ($g = 0; $g < 6; $g++) { $w = ''; for ($i = 0; $i < 5; $i++) { $w .= $a[random_int(0, strlen($a) - 1)]; } $p[] = $w; }
        $s['pw'] = implode('-', $p); $s['pw_made'] = time(); cp_secret_write($s);
    }
    return (string)$s['pw'];
}

// one dataset's folder name inside the target: cache/docker -> cache_docker
function cp_tag($ds) { return str_replace('/', '_', $ds); }
function cp_date_ok($d) { return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d); }
function cp_dir_ok($p) { return (bool)preg_match('#^/mnt/user/[A-Za-z0-9_.\- ]+(?:/[A-Za-z0-9_.\- ]+)*$#', (string)$p) && strpos($p, '..') === false; }
// the folder names to leave out, as rsync and restic patterns (names only: transcodes, docker.img, *.tmp)
function cp_skip_list($s) {
    $out = [];
    foreach (preg_split('/[,\n]+/', (string)$s) as $x) { $x = trim($x); if ($x !== '' && preg_match('/^[A-Za-z0-9_.*?\-]{1,64}$/', $x)) { $out[] = $x; } }
    return array_values(array_unique($out));
}
function cp_skip_args($cfg) { $a = ''; foreach (cp_skip_list($cfg['skip']) as $x) { $a .= ' --exclude=' . escapeshellarg($x); } return $a; }

// the share of the target and the array disk the copies go to. The copies of one dataset stay on one disk (hardlinks);
// a share that lives on a pool is refused (the copy would sit next to what it protects).
function cp_target($cfg, $ds) {
    $p = rtrim((string)$cfg['copy_dir'], '/');
    if (!cp_dir_ok($p)) { return ['err' => ['cpe.dir_bad', []]]; }
    $rel = substr($p, strlen('/mnt/user/')); $share = explode('/', $rel)[0];
    if (!is_dir(cp_user_root() . '/' . $share)) { return ['err' => ['cpe.no_share', ['s' => $share]]]; }
    $ini = is_file(cp_shares_ini()) ? @parse_ini_file(cp_shares_ini(), true) : [];
    $use = strtolower((string)($ini[$share]['useCache'] ?? 'no'));
    if (in_array($use, ['only', 'prefer'], true)) { return ['err' => ['cpe.on_pool', ['s' => $share]]]; }
    $best = null; $have = null;
    foreach (glob(cp_disks_root() . '/disk[0-9]*', GLOB_ONLYDIR) ?: [] as $d) {
        if (!getenv('SX_SNAP_DISKS') && !snp_is_mount($d)) { continue; }
        $free = (float)@disk_free_space($d);
        $dir = $d . '/' . $rel . '/' . cp_tag($ds);
        if (is_dir($dir) && cp_list_dir($dir)) { $have = ['disk' => basename($d), 'dir' => $dir, 'free' => $free]; }
        if (!$best || $free > $best['free']) { $best = ['disk' => basename($d), 'dir' => $dir, 'free' => $free]; }
    }
    $t = $have ?: $best;
    if (!$t) { return ['err' => ['cpe.no_disk', []]]; }
    return $t + ['err' => '', 'share' => $share, 'rel' => $rel];
}
function snp_is_mount($d) { foreach ((array)@file(snp_mounts_file(), FILE_IGNORE_NEW_LINES) as $l) { $f = explode(' ', $l); if (isset($f[1]) && stripcslashes($f[1]) === $d) { return true; } } return false; }
// the finished copies in a dataset folder, oldest first
function cp_list_dir($dir) {
    $out = [];
    foreach (scandir($dir) ?: [] as $n) { if (cp_date_ok($n) && is_dir("$dir/$n")) { $out[] = $n; } }
    sort($out); return $out;
}
// the off-site downloads waiting for a restore
function cp_fetched($cfg, $ds) {
    $t = cp_target($cfg, $ds); if ($t['err'] !== '') { return []; }
    $dir = dirname($t['dir']) . '/restore/' . cp_tag($ds); $out = [];
    foreach (is_dir($dir) ? (scandir($dir) ?: []) : [] as $n) { if (preg_match('/^\d{4}-\d{2}-\d{2}-[0-9a-f]{8}$/', $n) && is_dir("$dir/$n")) { $out[] = $n; } }
    rsort($out); return $out;
}

// where a restore reads from: a snapshot name, copy:<date> (array copy) or off:<date>-<id> (off-site download)
function cp_src_root($ds, $ref) {
    if (strpos($ref, 'copy:') === 0 || strpos($ref, 'off:') === 0) {
        [$kind, $name] = explode(':', $ref, 2);
        $t = cp_target(snp_cfg(), $ds['name']); if ($t['err'] !== '') { return ''; }
        if ($kind === 'copy') { return cp_date_ok($name) ? $t['dir'] . '/' . $name : ''; }
        return preg_match('/^\d{4}-\d{2}-\d{2}-[0-9a-f]{8}$/', $name) ? dirname($t['dir']) . '/restore/' . cp_tag($ds['name']) . '/' . $name : '';
    }
    return rtrim($ds['mnt'], '/') . '/.zfs/snapshot/' . $ref;
}
function cp_src_dir($ds, $ref, $dir) { $r = cp_src_root($ds, $ref); return $r === '' ? '' : $r . substr($dir, strlen(rtrim($ds['mnt'], '/'))); }
function cp_is_copy($ref) { return strpos($ref, 'copy:') === 0 || strpos($ref, 'off:') === 0; }

// the running copy, off-site copy or download (the page's Activity shows it)
function cp_job($a) { $j = json_decode((string)@file_get_contents(cp_job_file()), true) ?: []; $j = array_merge($j, $a); snp_json_write(cp_job_file(), $j); return $j; }
// runs a command and calls $line for every output line (rsync progress ends its lines with \r); returns the exit code
function cp_stream($cmd, $env, $line) {
    $p = proc_open($cmd . ' 2>&1', [1 => ['pipe', 'w']], $pp, null, $env);
    if (!is_resource($p)) { return 127; }
    $buf = '';
    while (!feof($pp[1])) {
        $c = fread($pp[1], 8192); if ($c === false || $c === '') { if (feof($pp[1])) { break; } usleep(100000); continue; }
        $buf .= $c;
        while (preg_match('/^(.*?)[\r\n]/s', $buf, $m)) { $buf = substr($buf, strlen($m[0])); if (trim($m[1]) !== '') { $line(trim($m[1])); } }
    }
    if (trim($buf) !== '') { $line(trim($buf)); }
    fclose($pp[1]); return proc_close($p);
}
function cp_env_base() { $e = []; foreach (['PATH', 'HOME', 'LANG', 'TZ'] as $k) { if (getenv($k) !== false) { $e[$k] = getenv($k); } } $e['PATH'] = $e['PATH'] ?? '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin'; return $e; }

// ---- the array copy of one dataset from one snapshot
function cp_copy_one($cfg, $ds, $snap, &$msg) {
    $d = snp_dataset($ds); if (!$d) { $msg = ['cpd.ds_nf', ['ds' => $ds]]; return false; }
    $t = cp_target($cfg, $ds); if ($t['err'] !== '') { $msg = $t['err']; return false; }
    $src = rtrim($d['mnt'], '/') . '/.zfs/snapshot/' . $snap['name'];
    if (!is_dir($src)) { $msg = ['cpd.no_src', ['s' => $ds . '@' . $snap['name']]]; return false; }
    $free = (float)@disk_free_space(dirname($t['dir']) ?: '/');
    if ($free > 0 && $free < 20e9) { $msg = ['cpd.full', ['d' => $t['disk']]]; return false; }
    @mkdir($t['dir'], 0775, true);
    $date = date('Y-m-d', $snap['t']); $have = cp_list_dir($t['dir']);
    $prev = ''; foreach ($have as $h) { if ($h < $date) { $prev = $h; } }
    $dest = $t['dir'] . '/' . $date; $work = in_array($date, $have, true) ? $dest : $dest . '.partial';
    $cmd = escapeshellarg(snp_bin('rsync')) . ' -aHAX --numeric-ids --delete --info=progress2,stats2 --no-inc-recursive' . cp_skip_args($cfg)
         . ($prev !== '' ? ' --link-dest=' . escapeshellarg($t['dir'] . '/' . $prev) : '') . ' ' . escapeshellarg($src . '/') . ' ' . escapeshellarg($work . '/');
    $t0 = time(); $last = 0; $st = ['files' => 0, 'bytes' => 0, 'total' => 0]; $tail = [];
    cp_job(['title' => sx_tr('cpj.copying', ['s' => $ds . '@' . $snap['name'], 'd' => $t['disk']]), 'pct' => 0]);
    $rc = cp_stream($cmd, cp_env_base(), function ($l) use (&$last, &$st, &$tail) {
        if (preg_match('/^\s*[\d,.]+\s+(\d+)%/', $l, $m)) { if (time() - $last >= 3) { $last = time(); cp_job(['pct' => (int)$m[1]]); } return; }
        if (preg_match('/^Number of regular files transferred: ([\d,.]+)/', $l, $m)) { $st['files'] = (int)str_replace([',', '.'], '', $m[1]); }
        if (preg_match('/^Total transferred file size: ([\d,.]+)/', $l, $m)) { $st['bytes'] = (float)str_replace([',', '.'], '', $m[1]); }
        if (preg_match('/^Total file size: ([\d,.]+)/', $l, $m)) { $st['total'] = (float)str_replace([',', '.'], '', $m[1]); }
        $tail[] = $l; $tail = array_slice($tail, -4);
    });
    if ($rc !== 0 && $rc !== 24) { $msg = ['cpd.rsync', ['c' => $rc, 'e' => substr(implode(' ', $tail), 0, 200)]]; snp_log("copy $ds@{$snap['name']} FAILED (rsync $rc): " . implode(' ', $tail)); return false; }   // 24: a file vanished, normal
    if ($work !== $dest && !@rename($work, $dest)) { $msg = ['cpd.rename', ['d' => $dest]]; return false; }
    $s = cp_state(); $s['copies'][$ds][$date] = ['files' => $st['files'], 'new' => $st['bytes'], 'total' => $st['total'], 'secs' => time() - $t0, 'disk' => $t['disk'], 'snap' => $snap['name']];
    $s['ok'] = time(); $s['err'] = ''; snp_json_write(cp_state_file(), $s);
    snp_log("copy $ds@{$snap['name']} to {$t['disk']}: {$st['files']} files changed, " . round($st['bytes'] / 1e9, 2) . ' GB written, ' . (time() - $t0) . ' s');
    cp_prune($cfg, $ds, $t['dir']);
    return true;
}
// keep the newest keep_d copies, then the newest one of each week for keep_w weeks; only date-named folders are removed
function cp_prune($cfg, $ds, $dir) {
    $all = cp_list_dir($dir); $keep = array_slice($all, -max(1, (int)$cfg['keep_d'])); $weeks = [];
    foreach (array_reverse($all) as $n) {
        if (in_array($n, $keep, true)) { continue; }
        $w = date('o-W', strtotime($n));
        if (!isset($weeks[$w]) && count($weeks) < (int)$cfg['keep_w']) { $weeks[$w] = $n; $keep[] = $n; }
    }
    foreach ($all as $n) {
        if (in_array($n, $keep, true)) { continue; }
        snp_run('rm -rf ' . escapeshellarg($dir . '/' . $n), $rc);
        snp_log("copy $ds/$n removed (older than kept)" . ($rc ? " FAILED rc $rc" : ''));
        $s = cp_state(); unset($s['copies'][$ds][$n]); snp_json_write(cp_state_file(), $s);
    }
    foreach (glob($dir . '/*.partial', GLOB_ONLYDIR) ?: [] as $p) { if (basename($p) !== date('Y-m-d') . '.partial' && filemtime($p) < time() - 3 * 86400) { snp_run('rm -rf ' . escapeshellarg($p)); } }
}

// ---- restic (off-site)
// the pinned restic: downloaded once to the flash (checked against its SHA256), unpacked to RAM
function cp_restic(&$err) {
    $t = getenv('SX_RESTIC_BIN'); if ($t !== false && $t !== '') { return $t; }
    $bin = '/tmp/sysbox_restic/restic-' . CP_RESTIC_VER;
    if (is_executable($bin)) { return $bin; }
    $bz = snp_dir() . '/restic_' . CP_RESTIC_VER . '_linux_amd64.bz2';
    if (!is_file($bz) || hash_file('sha256', $bz) !== CP_RESTIC_SHA) {
        $url = 'https://github.com/restic/restic/releases/download/v' . CP_RESTIC_VER . '/restic_' . CP_RESTIC_VER . '_linux_amd64.bz2';
        snp_run('curl -fsSL -m 120 -o ' . escapeshellarg($bz . '.tmp') . ' ' . escapeshellarg($url), $rc);
        if ($rc !== 0 || hash_file('sha256', $bz . '.tmp') !== CP_RESTIC_SHA) { @unlink($bz . '.tmp'); $err = ['cpe.restic_dl', []]; return ''; }
        @rename($bz . '.tmp', $bz);
    }
    @mkdir(dirname($bin), 0700, true);
    snp_run('bunzip2 -c ' . escapeshellarg($bz) . ' > ' . escapeshellarg($bin . '.tmp') . ' && chmod 700 ' . escapeshellarg($bin . '.tmp') . ' && mv ' . escapeshellarg($bin . '.tmp') . ' ' . escapeshellarg($bin), $rc);
    if ($rc !== 0) { $err = ['cpe.restic_dl', []]; return ''; }
    return $bin;
}
function cp_off_ok($cfg) {
    if ($cfg['off'] === 'sftp') { return (bool)preg_match('#^[A-Za-z0-9_.\-]+@[A-Za-z0-9.\-]+:/?[A-Za-z0-9_.\-/ ]*$#', (string)$cfg['off_sftp']); }
    if ($cfg['off'] === 's3') { return (bool)preg_match('#^https://[A-Za-z0-9.\-]+(?::\d+)?/[A-Za-z0-9_.\-]+(?:/[A-Za-z0-9_.\-]+)*$#', (string)$cfg['off_s3']); }
    return false;
}
// the repository address shown on the page (no keys)
function cp_off_label($cfg) { return $cfg['off'] === 'sftp' ? 'sftp:' . $cfg['off_sftp'] : ($cfg['off'] === 's3' ? 's3:' . $cfg['off_s3'] : ''); }
// the private SSH key for SFTP: made on the flash once, copied to RAM with 0600 for ssh
function cp_ssh_key() {
    $k = cp_key_file();
    if (!is_file($k)) {
        $tmp = snp_tmp() . '/sysbox_k' . getmypid(); @unlink($tmp); @unlink($tmp . '.pub');
        snp_run('ssh-keygen -q -t ed25519 -N "" -C sysbox-offsite@' . escapeshellarg(php_uname('n')) . ' -f ' . escapeshellarg($tmp), $rc);
        if ($rc !== 0) { return ''; }
        @copy($tmp, $k); @copy($tmp . '.pub', $k . '.pub'); @unlink($tmp); @unlink($tmp . '.pub'); @chmod($k, 0600);
    }
    $run = snp_tmp() . '/sysbox_offsite_key'; @copy($k, $run); @chmod($run, 0600);
    return $run;
}
function cp_ssh_pub() { return is_file(cp_key_file() . '.pub') ? trim((string)file_get_contents(cp_key_file() . '.pub')) : ''; }
// restic command line and environment (password and keys only in the environment, never on the command line)
function cp_restic_cmd($cfg, $args, &$env, &$err) {
    $r = cp_restic($err); if ($r === '') { return ''; }
    if (!cp_off_ok($cfg)) { $err = ['cpe.off_addr', []]; return ''; }
    $env = cp_env_base(); $env['RESTIC_PASSWORD'] = cp_password(); $sec = cp_secret(); $o = '';
    if ($cfg['off'] === 'sftp') {
        $key = cp_ssh_key(); if ($key === '') { $err = ['cpe.ssh_key', []]; return ''; }
        [$uh] = explode(':', $cfg['off_sftp'], 2);
        $ssh = 'ssh -p ' . (int)$cfg['off_port'] . ' -i ' . $key . ' -o BatchMode=yes -o StrictHostKeyChecking=accept-new -o UserKnownHostsFile=' . cp_known_file() . ' ' . $uh . ' -s sftp';
        $o = ' -o ' . escapeshellarg('sftp.command=' . $ssh);
    } else {
        if (empty($sec['s3_id']) || empty($sec['s3_key'])) { $err = ['cpe.s3_keys', []]; return ''; }
        $env['AWS_ACCESS_KEY_ID'] = $sec['s3_id']; $env['AWS_SECRET_ACCESS_KEY'] = $sec['s3_key'];
    }
    return escapeshellarg($r) . ' -r ' . escapeshellarg(cp_off_label($cfg)) . $o . ' --no-cache ' . $args;
}
// Test and set up (a click): reach the repository; make it when it does not exist yet
function cp_off_test($cfg) {
    $cmd = cp_restic_cmd($cfg, 'cat config', $env, $err); if ($cmd === '') { return ['ok' => false, 'error' => $err]; }
    $out = []; $rc = cp_stream($cmd, $env, function ($l) use (&$out) { $out[] = $l; });
    if ($rc === 0) { return ['ok' => true, 'msg' => ['cpm.repo_ok', []]]; }
    if ($rc !== 10) { return ['ok' => false, 'error' => ['cpe.repo', ['e' => substr(implode(' ', array_slice($out, -3)), 0, 300)]]]; }   // 10: no repository there yet
    $cmd = cp_restic_cmd($cfg, 'init', $env, $err); $out = [];
    $rc = cp_stream($cmd, $env, function ($l) use (&$out) { $out[] = $l; });
    if ($rc !== 0) { return ['ok' => false, 'error' => ['cpe.repo', ['e' => substr(implode(' ', array_slice($out, -3)), 0, 300)]]]; }
    snp_log('off-site repository made at ' . cp_off_label($cfg));
    return ['ok' => true, 'msg' => ['cpm.repo_made', []]];
}
// the off-site versions (newest first)
function cp_off_list($cfg) {
    $cmd = cp_restic_cmd($cfg, 'snapshots --json --tag sysbox', $env, $err); if ($cmd === '') { return ['ok' => false, 'error' => $err]; }
    $out = []; $rc = cp_stream($cmd, $env, function ($l) use (&$out) { $out[] = $l; });
    $j = null; foreach ($out as $l) { if ($l !== '' && $l[0] === '[') { $j = json_decode($l, true); } }
    if ($rc !== 0 || !is_array($j)) { return ['ok' => false, 'error' => ['cpe.repo', ['e' => substr(implode(' ', array_slice($out, -3)), 0, 300)]]]; }
    $L = [];
    foreach ($j as $s) {
        $ds = ''; foreach ((array)($s['tags'] ?? []) as $tg) { if (strpos($tg, 'ds:') === 0) { $ds = substr($tg, 3); } }
        $L[] = ['id' => substr((string)$s['id'], 0, 8), 'time' => strtotime((string)$s['time']), 'ds' => $ds, 'path' => (string)($s['paths'][0] ?? ''), 'size' => (float)($s['summary']['total_bytes_processed'] ?? 0)];
    }
    usort($L, function ($a, $b) { return $b['time'] <=> $a['time']; });
    return ['ok' => true, 'list' => $L];
}
// the weekly off-site copy of each dataset's newest snapshot, then the old versions forgotten
function cp_off_run($cfg, &$msg) {
    $bad = [];
    foreach ($cfg['datasets'] as $ds) {
        $d = snp_dataset($ds); $sn = $d ? snp_snaps($ds) : []; $snap = $sn ? end($sn) : null;
        if (!$snap) { $bad[] = ['cpd.no_snap', ['ds' => $ds]]; continue; }
        $src = rtrim($d['mnt'], '/') . '/.zfs/snapshot/' . $snap['name'];
        $cmd = cp_restic_cmd($cfg, 'backup --json --host ' . escapeshellarg(php_uname('n')) . ' --tag sysbox --tag ' . escapeshellarg('ds:' . $ds) . cp_skip_args($cfg) . ' ' . escapeshellarg($src), $env, $err);
        if ($cmd === '') { $bad[] = $err; break; }
        cp_job(['title' => sx_tr('cpj.off', ['s' => $ds . '@' . $snap['name']]), 'pct' => 0]);
        $last = 0; $sum = null; $tail = []; $t0 = time();
        $rc = cp_stream($cmd, $env, function ($l) use (&$last, &$sum, &$tail) {
            $j = ($l !== '' && $l[0] === '{') ? json_decode($l, true) : null;
            if (is_array($j) && ($j['message_type'] ?? '') === 'status') { if (time() - $last >= 3) { $last = time(); cp_job(['pct' => (int)round(100 * (float)($j['percent_done'] ?? 0))]); } return; }
            if (is_array($j) && ($j['message_type'] ?? '') === 'summary') { $sum = $j; return; }
            $tail[] = $l; $tail = array_slice($tail, -3);
        });
        if ($rc !== 0 && $rc !== 3) { $bad[] = ['cpd.off_fail', ['ds' => $ds, 'e' => substr(implode(' ', $tail), 0, 200)]]; snp_log("off-site $ds FAILED (restic $rc): " . implode(' ', $tail)); continue; }   // 3: some files unreadable, the rest is saved
        $sent = (float)($sum['data_added_packed'] ?? $sum['data_added'] ?? 0);
        $s = cp_state(); $s['off_ok'] = time(); $s['off_err'] = ''; $s['off_last'][$ds] = ['sent' => $sent, 'files' => (int)($sum['total_files_processed'] ?? 0), 'secs' => time() - $t0, 'snap' => $snap['name']]; snp_json_write(cp_state_file(), $s);
        snp_log("off-site $ds@{$snap['name']}: " . round($sent / 1e9, 2) . ' GB sent, ' . (time() - $t0) . ' s');
        $cmd = cp_restic_cmd($cfg, 'forget --prune --host ' . escapeshellarg(php_uname('n')) . ' --tag ' . escapeshellarg('ds:' . $ds) . ' --keep-weekly ' . max(1, (int)$cfg['off_keep']), $env, $err);
        $tail = []; $rc = cp_stream($cmd, $env, function ($l) use (&$tail) { $tail[] = $l; $tail = array_slice($tail, -3); });
        if ($rc !== 0) { snp_log("off-site $ds forget FAILED (restic $rc): " . implode(' ', $tail)); }
    }
    if ($bad) { cp_state_set('off_err', cp_t($bad)); $msg = $bad; return false; }
    return true;
}
// downloads one off-site version next to the array copies (restore/<dataset>/<date>-<id>), for a restore from the page
function cp_off_fetch($cfg, $id, &$msg) {
    $L = cp_off_list($cfg); if (!$L['ok']) { $msg = $L['error']; return false; }
    $v = null; foreach ($L['list'] as $x) { if ($x['id'] === $id) { $v = $x; } }
    if (!$v || $v['ds'] === '' || !in_array($v['ds'], $cfg['datasets'], true)) { $msg = ['cpe.no_version', []]; return false; }
    $t = cp_target($cfg, $v['ds']); if ($t['err'] !== '') { $msg = $t['err']; return false; }
    $dest = dirname($t['dir']) . '/restore/' . cp_tag($v['ds']) . '/' . date('Y-m-d', $v['time']) . '-' . $id;
    @mkdir($dest, 0775, true);
    $cmd = cp_restic_cmd($cfg, 'restore --json ' . escapeshellarg($id . ':' . $v['path']) . ' --target ' . escapeshellarg($dest), $env, $err);
    if ($cmd === '') { $msg = $err; return false; }
    cp_job(['title' => sx_tr('cpj.fetch', ['v' => date('Y-m-d', $v['time']), 'ds' => $v['ds']]), 'pct' => 0]);
    $last = 0; $tail = [];
    $rc = cp_stream($cmd, $env, function ($l) use (&$last, &$tail) {
        $j = ($l !== '' && $l[0] === '{') ? json_decode($l, true) : null;
        if (is_array($j) && ($j['message_type'] ?? '') === 'status') { if (time() - $last >= 3) { $last = time(); cp_job(['pct' => (int)round(100 * (float)($j['percent_done'] ?? 0))]); } return; }
        if (!is_array($j)) { $tail[] = $l; $tail = array_slice($tail, -3); }
    });
    if ($rc !== 0) { $msg = ['cpe.repo', ['e' => substr(implode(' ', $tail), 0, 300)]]; snp_log("off-site download $id FAILED (restic $rc)"); return false; }
    snp_log("off-site version $id ({$v['ds']}) downloaded to $dest");
    return true;
}

// the page's view of the copies (no keys; the password only through its own click)
function cp_view($cfg) {
    $s = cp_state(); $ds = [];
    foreach ($cfg['datasets'] as $n) {
        $t = cp_target($cfg, $n); $L = [];
        foreach ($t['err'] === '' && is_dir($t['dir']) ? array_reverse(cp_list_dir($t['dir'])) : [] as $dt) { $L[] = ['date' => $dt] + (array)($s['copies'][$n][$dt] ?? []); }
        $ds[] = ['name' => $n, 'err' => $t['err'] === '' ? '' : cp_t($t['err']), 'disk' => $t['disk'] ?? '', 'dir' => $t['err'] === '' ? '/mnt/user/' . $t['rel'] . '/' . cp_tag($n) : '', 'free' => $t['free'] ?? 0, 'copies' => $L, 'fetched' => cp_fetched($cfg, $n)];
    }
    $sec = cp_secret();
    return ['ds' => $ds, 'ok' => (int)($s['ok'] ?? 0), 'err' => (string)($s['err'] ?? ''), 'off_ok' => (int)($s['off_ok'] ?? 0), 'off_err' => (string)($s['off_err'] ?? ''),
            'off_last' => $s['off_last'] ?? new stdClass(), 'off_label' => cp_off_label($cfg), 'pub' => $cfg['off'] === 'sftp' ? cp_ssh_pub() : '',
            'has_pw' => !empty($sec['pw']), 's3_set' => !empty($sec['s3_id']) && !empty($sec['s3_key']),
            'job' => json_decode((string)@file_get_contents(cp_job_file()), true) ?: ['state' => 'idle']];
}
