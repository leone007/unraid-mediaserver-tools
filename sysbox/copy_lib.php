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
function cp_dir_ok($p) { return snp_udir_ok($p); }
// the folder names to leave out, as rsync and restic patterns (names only: transcodes, docker.img, *.tmp)
function cp_skip_list($s) {
    $out = [];
    foreach (preg_split('/[,\n]+/', (string)$s) as $x) { $x = trim($x); if ($x !== '' && preg_match('/^[A-Za-z0-9_.*?\-]{1,64}$/', $x)) { $out[] = $x; } }
    return array_values(array_unique($out));
}
function cp_skip_args($cfg) { $a = ''; foreach (cp_skip_list($cfg['skip']) as $x) { $a .= ' --exclude=' . escapeshellarg($x); } return $a; }
// the copy folder and what is in it is never an off-site source (its copies are the containers' data again, restore/ would go round);
// a folder that holds it goes without it (an --exclude of its real path)
function cp_in_copy($cfg, $p) { $c = rtrim((string)$cfg['copy_dir'], '/'); return $c !== '' && ($p === $c || strpos($p, $c . '/') === 0); }
function cp_copy_ex($cfg, $p) { $c = rtrim((string)$cfg['copy_dir'], '/'); return ($c !== '' && strpos($c, rtrim($p, '/') . '/') === 0) ? ' --exclude=' . escapeshellarg(cp_user_root() . substr($c, strlen('/mnt/user'))) : ''; }

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
// restic's error in a few words: the message of its JSON lines, without ssh's warnings ("** ..." lines, the post-quantum notice)
function cp_err_text($lines, $max = 200) {
    $out = [];
    foreach ((array)$lines as $l) {
        $l = trim((string)$l);
        if ($l !== '' && $l[0] === '{') { $j = json_decode($l, true); if (is_array($j)) { $l = (string)($j['message'] ?? ($j['error']['message'] ?? '')); } }
        $l = trim((string)preg_replace('/^subprocess ssh:\s*/', '', $l));
        if ($l === '' || $l[0] === '*' || stripos($l, 'openssh.com/pq') !== false || stripos($l, 'post-quantum') !== false) { continue; }
        if (!in_array($l, $out, true)) { $out[] = $l; }
    }
    return substr(implode(' ', array_slice($out, -2)), 0, $max);
}
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
    if ($rc !== 10) { return ['ok' => false, 'error' => ['cpe.repo', ['e' => cp_err_text(array_slice($out, -8), 300)]]]; }   // 10: no repository there yet
    $cmd = cp_restic_cmd($cfg, 'init', $env, $err); $out = [];
    $rc = cp_stream($cmd, $env, function ($l) use (&$out) { $out[] = $l; });
    if ($rc !== 0) { return ['ok' => false, 'error' => ['cpe.repo', ['e' => cp_err_text(array_slice($out, -8), 300)]]]; }
    snp_log('off-site repository made at ' . cp_off_label($cfg));
    return ['ok' => true, 'msg' => ['cpm.repo_made', []]];
}
// ---- what goes off-site (item 136): the datasets (from their newest snapshot), the chosen share folders and the flash (live)
function cp_flash_root() { return cp_env('SX_FLASH_DIR', '/boot'); }   // tests: a fake flash
// [key, kind ds|dir|flash, name, path]; the key is also the restic tag (ds:<dataset>, dir:<folder>, dir:/boot)
function cp_sources($cfg, $what = 'all') {
    $L = [];
    if ($what !== 'dirs') { foreach ($cfg['datasets'] as $ds) { $L[] = ['key' => 'ds:' . $ds, 'kind' => 'ds', 'name' => $ds, 'path' => '']; } }
    if ($what !== 'ds') {
        if (!empty($cfg['off_flash'])) { $L[] = ['key' => 'dir:/boot', 'kind' => 'flash', 'name' => 'flash', 'path' => cp_flash_root()]; }
        foreach ((array)$cfg['off_dirs'] as $p) { $L[] = ['key' => 'dir:' . $p, 'kind' => 'dir', 'name' => basename($p), 'path' => cp_user_root() . substr($p, strlen('/mnt/user'))]; }
    }
    return $L;
}
function cp_src_find($cfg, $key) { foreach (cp_sources($cfg) as $x) { if ($x['key'] === $key) { return $x; } } return null; }
// the folder name of a source under restore/: cache/docker -> cache_docker, dir:/boot -> flash, dir:/mnt/user/data/FOTO -> data_FOTO
function cp_src_tag($key) {
    if (strpos($key, 'ds:') === 0) { return cp_tag(substr($key, 3)); }
    $p = substr($key, 4); if ($p === '/boot') { return 'flash'; }
    return str_replace([' ', '/'], ['-', '_'], substr($p, strlen('/mnt/user/')));
}
// the downloads of a folder go to <copy folder>/restore/<tag>/ in the user share (a folder is restored by copying it back)
function cp_restore_user($cfg) {
    $p = rtrim((string)$cfg['copy_dir'], '/'); if (!cp_dir_ok($p)) { return ['err' => ['cpe.dir_bad', []]]; }
    $rel = substr($p, strlen('/mnt/user/')); $share = explode('/', $rel)[0];
    if (!is_dir(cp_user_root() . '/' . $share)) { return ['err' => ['cpe.no_share', ['s' => $share]]]; }
    return ['err' => '', 'dir' => cp_user_root() . '/' . $rel . '/restore', 'show' => '/mnt/user/' . $rel . '/restore'];
}
function cp_fetched_dir($cfg, $key) {
    $r = cp_restore_user($cfg); if ($r['err'] !== '') { return []; }
    $dir = $r['dir'] . '/' . cp_src_tag($key); $out = [];
    foreach (is_dir($dir) ? (scandir($dir) ?: []) : [] as $n) { if (preg_match('/^\d{4}-\d{2}-\d{2}-[0-9a-f]{8}$/', $n) && is_dir("$dir/$n")) { $out[] = $n; } }
    rsort($out); return $out;
}
// the folders that can be chosen: each share and its first level (names only, no sizes: that would wake every disk)
function cp_dir_choices() {
    $root = cp_user_root(); $out = [];
    foreach (scandir($root) ?: [] as $s) {
        if ($s[0] === '.' || !is_dir("$root/$s") || !snp_udir_ok("/mnt/user/$s")) { continue; }
        $subs = [];
        foreach (scandir("$root/$s") ?: [] as $x) { if ($x[0] !== '.' && is_dir("$root/$s/$x") && snp_udir_ok("/mnt/user/$s/$x")) { $subs[] = $x; } }
        $out[] = ['share' => $s, 'subs' => array_slice($subs, 0, 300)];
    }
    return $out;
}
// Measure (a click): the size of every source (du, so the disks wake once); the first copy estimate uses it
function cp_size_run($cfg, &$msg) {
    $L = cp_sources($cfg); $n = count($L); $s = cp_state(); $sizes = (array)($s['sizes'] ?? []);
    foreach ($L as $i => $x) {
        if ($x['kind'] === 'ds') { $d = snp_dataset($x['name']); $path = $d ? $d['mnt'] : ''; $ex = ''; foreach (cp_skip_list($cfg['skip']) as $k) { $ex .= ' --exclude=' . escapeshellarg($k); } }
        else { $path = $x['path']; $ex = $x['kind'] === 'dir' ? cp_copy_ex($cfg, substr($x['key'], 4)) : ''; }
        cp_job(['title' => sx_tr('cpj.size', ['s' => $x['kind'] === 'ds' ? $x['name'] : substr($x['key'], 4)]), 'pct' => (int)round(100 * $i / max(1, $n))]);
        if ($path === '' || !is_dir($path)) { continue; }
        $o = snp_run('du -sb' . $ex . ' ' . escapeshellarg($path), $rc);
        $b = 0; foreach ($o as $l) { if (preg_match('/^(\d+)\s/', $l, $m)) { $b = (float)$m[1]; } }
        $sizes[$x['key']] = ['b' => $b, 't' => time()];
    }
    cp_state_set('sizes', $sizes);
    snp_log('off-site sizes measured: ' . implode(', ', array_map(function ($k, $v) { return substr($k, strpos($k, ':') + 1) . ' ' . round($v['b'] / 1e9, 1) . ' GB'; }, array_keys($sizes), $sizes)));
    return true;
}

// the off-site versions (newest first); src is the source key (old versions without a tag of their own count as datasets)
function cp_off_list($cfg) {
    $cmd = cp_restic_cmd($cfg, 'snapshots --json --tag sysbox', $env, $err); if ($cmd === '') { return ['ok' => false, 'error' => $err]; }
    $out = []; $rc = cp_stream($cmd, $env, function ($l) use (&$out) { $out[] = $l; });
    $j = null; foreach ($out as $l) { if ($l !== '' && $l[0] === '[') { $j = json_decode($l, true); } }
    if ($rc !== 0 || !is_array($j)) { return ['ok' => false, 'error' => ['cpe.repo', ['e' => cp_err_text(array_slice($out, -8), 300)]]]; }
    $L = [];
    foreach ($j as $s) {
        $ds = ''; $src = '';
        foreach ((array)($s['tags'] ?? []) as $tg) { if (strpos($tg, 'ds:') === 0) { $ds = substr($tg, 3); $src = $tg; } if (strpos($tg, 'dir:') === 0) { $src = $tg; } }
        $L[] = ['id' => substr((string)$s['id'], 0, 8), 'time' => strtotime((string)$s['time']), 'ds' => $ds, 'src' => $src, 'path' => (string)($s['paths'][0] ?? ''), 'size' => (float)($s['summary']['total_bytes_processed'] ?? 0)];
    }
    usort($L, function ($a, $b) { return $b['time'] <=> $a['time']; });
    return ['ok' => true, 'list' => $L];
}
// a source's last result in the state (the page shows it per row)
function cp_off_mark($key, $a) { $s = cp_state(); $s['off_last'][$key] = array_merge((array)($s['off_last'][$key] ?? []), $a); snp_json_write(cp_state_file(), $s); }
// the off-site copy: $what = all (Off-site now, the weekly run without nightly folders), ds (weekly) or dirs (nightly).
// A dataset is read from its newest snapshot, a folder and the flash as they are. Then the old versions of that source are
// forgotten: grouped by host and tags, because a dataset's path changes with every snapshot name.
function cp_off_run($cfg, &$msg, $what = 'all', &$short = '') {
    $bad = []; $gen = []; $nbad = 0; $host = php_uname('n'); $L = cp_sources($cfg, $what);
    foreach ($L as $src) {
        $key = $src['key'];
        if ($src['kind'] === 'ds') {
            $ds = $src['name']; $d = snp_dataset($ds); $sn = $d ? snp_snaps($ds) : []; $snap = $sn ? end($sn) : null;
            if (!$snap) { $e = ['cpd.no_snap', ['ds' => $ds]]; $bad[] = $e; $nbad++; cp_off_mark($key, ['err' => cp_t($e), 'err_t' => time()]); continue; }
            $path = rtrim($d['mnt'], '/') . '/.zfs/snapshot/' . $snap['name']; $label = $ds . '@' . $snap['name']; $sname = $snap['name'];
            $tags = ' --tag ' . escapeshellarg($key) . cp_skip_args($cfg); $ex = ''; $keep = ' --keep-weekly ' . max(1, (int)$cfg['off_keep']);
        } else {
            $path = $src['path']; $label = substr($key, 4); $sname = '';
            if (!is_dir($path)) { $e = ['cpd.no_dir', ['d' => $label]]; $bad[] = $e; $nbad++; cp_off_mark($key, ['err' => cp_t($e), 'err_t' => time()]); continue; }
            if ($src['kind'] === 'dir' && cp_in_copy($cfg, $label)) { $e = ['cpe.folder_copy', ['d' => $label]]; $bad[] = $e; $nbad++; cp_off_mark($key, ['err' => cp_t($e), 'err_t' => time()]); continue; }
            $tags = ' --tag ' . escapeshellarg($key); $ex = $src['kind'] === 'dir' ? cp_copy_ex($cfg, $label) : '';
            $keep = (!empty($cfg['off_night']) ? ' --keep-daily ' . max(1, (int)$cfg['off_daily']) : '') . ' --keep-weekly ' . max(1, (int)$cfg['off_keep']);
        }
        $cmd = cp_restic_cmd($cfg, 'backup --json --host ' . escapeshellarg($host) . ' --tag sysbox' . $tags . $ex . ' ' . escapeshellarg($path), $env, $err);
        if ($cmd === '') { $bad[] = $err; $gen[] = $err; break; }
        cp_job(['title' => sx_tr('cpj.off', ['s' => $label]), 'pct' => 0, 'cur' => $key, 'done_b' => 0, 'total_b' => 0, 'eta' => -1]);
        $last = 0; $sum = null; $tail = []; $t0 = time();
        $rc = cp_stream($cmd, $env, function ($l) use (&$last, &$sum, &$tail) {
            $j = ($l !== '' && $l[0] === '{') ? json_decode($l, true) : null;
            if (is_array($j) && ($j['message_type'] ?? '') === 'status') {
                if (time() - $last >= 3) { $last = time(); cp_job(['pct' => (int)round(100 * (float)($j['percent_done'] ?? 0)), 'done_b' => (float)($j['bytes_done'] ?? 0), 'total_b' => (float)($j['total_bytes'] ?? 0), 'eta' => (int)($j['seconds_remaining'] ?? -1)]); }
                return;
            }
            if (is_array($j) && ($j['message_type'] ?? '') === 'summary') { $sum = $j; return; }
            $tail[] = $l; $tail = array_slice($tail, -8);
        });
        if ($rc !== 0 && $rc !== 3) {   // 3: some files unreadable, the rest is saved
            $e = ['cpd.off_fail', ['ds' => $label, 'e' => cp_err_text($tail)]]; $bad[] = $e; $nbad++;
            cp_off_mark($key, ['err' => cp_t($e), 'err_t' => time()]); snp_log("off-site $label FAILED (restic $rc): " . cp_err_text($tail, 500)); continue;
        }
        $sent = (float)($sum['data_added_packed'] ?? $sum['data_added'] ?? 0);
        $s = cp_state(); $s['off_ok'] = time(); $s['off_err'] = '';
        $s['off_last'][$key] = ['t' => time(), 'sent' => $sent, 'total' => (float)($sum['total_bytes_processed'] ?? 0), 'files' => (int)($sum['total_files_processed'] ?? 0), 'secs' => time() - $t0, 'snap' => $sname, 'err' => ''];
        if ($src['kind'] === 'ds') { unset($s['off_last'][$src['name']]); }   // the key before item 136 was the dataset name
        snp_json_write(cp_state_file(), $s);
        snp_log("off-site $label: " . round($sent / 1e9, 2) . ' GB sent, ' . (time() - $t0) . ' s');
        $cmd = cp_restic_cmd($cfg, 'forget --prune --group-by host,tags --host ' . escapeshellarg($host) . ' --tag ' . escapeshellarg($key) . $keep, $env, $err);
        $tail = []; $rc = cp_stream($cmd, $env, function ($l) use (&$tail) { $tail[] = $l; $tail = array_slice($tail, -8); });
        if ($rc !== 0) { snp_log("off-site $label forget FAILED (restic $rc): " . cp_err_text($tail, 500)); }
    }
    // the page and Activity get one short line (the rows below show each source's error); Discord gets every error
    if ($bad) { $sum = array_merge($gen, $nbad ? [['cpd.off_sum', ['n' => $nbad, 'all' => count($L)]]] : []); cp_state_set('off_err', cp_t($sum)); $short = cp_t($sum); $msg = $bad; return false; }
    return true;
}
// the sources taken off the list that still have a result (and maybe versions) off-site: a grey row with a Delete button
function cp_off_gone($cfg) {
    $cur = array_column(cp_sources($cfg), 'key'); $L = [];
    foreach ((array)(cp_state()['off_last'] ?? []) as $k => $l) { if (preg_match('/^(ds|dir):./', (string)$k) && !in_array($k, $cur, true)) { $L[$k] = (array)$l; } }
    return $L;
}
// Delete from off-site (a click with a confirm): every version of one source that is no longer chosen, then prune
function cp_off_forget($cfg, $key, &$msg) {
    if (!array_key_exists($key, cp_off_gone($cfg))) { $msg = ['cpe.not_gone', []]; return false; }
    $label = substr($key, strpos($key, ':') + 1);
    cp_job(['title' => sx_tr('cpj.forget', ['s' => $label]), 'pct' => 0]);
    $cmd = cp_restic_cmd($cfg, 'snapshots --json --host ' . escapeshellarg(php_uname('n')) . ' --tag ' . escapeshellarg('sysbox,' . $key), $env, $err); if ($cmd === '') { $msg = $err; return false; }
    $out = []; $rc = cp_stream($cmd, $env, function ($l) use (&$out) { $out[] = $l; });
    $j = null; foreach ($out as $l) { if ($l !== '' && $l[0] === '[') { $j = json_decode($l, true); } }
    if ($rc !== 0 || !is_array($j)) { $msg = ['cpe.repo', ['e' => cp_err_text(array_slice($out, -8), 300)]]; return false; }
    $ids = []; foreach ($j as $x) { if (preg_match('/^[0-9a-f]{64}$/', (string)($x['id'] ?? ''))) { $ids[] = $x['id']; } }
    if ($ids) {
        cp_job(['pct' => 30]);
        $cmd = cp_restic_cmd($cfg, 'forget --prune ' . implode(' ', $ids), $env, $err); if ($cmd === '') { $msg = $err; return false; }
        $tail = []; $rc = cp_stream($cmd, $env, function ($l) use (&$tail) { $tail[] = $l; $tail = array_slice($tail, -8); });
        if ($rc !== 0) { $msg = ['cpe.repo', ['e' => cp_err_text($tail, 300)]]; snp_log("off-site $label delete FAILED (restic $rc): " . cp_err_text($tail, 500)); return false; }
    }
    $s = cp_state(); unset($s['off_last'][$key], $s['sizes'][$key]); snp_json_write(cp_state_file(), $s);
    snp_log("off-site $label: " . count($ids) . ' versions deleted by hand');
    return true;
}
// downloads one off-site version: a dataset next to the array copies (restore/<dataset>/<date>-<id>, then restored like an array
// copy from the page), a folder or the flash to <copy folder>/restore/<tag>/<date>-<id> (copied back by hand). Nothing is overwritten.
function cp_off_fetch($cfg, $id, &$msg) {
    $L = cp_off_list($cfg); if (!$L['ok']) { $msg = $L['error']; return false; }
    $v = null; foreach ($L['list'] as $x) { if ($x['id'] === $id) { $v = $x; } }
    if (!$v) { $msg = ['cpe.no_version', []]; return false; }
    if (strpos($v['src'], 'dir:') === 0) {
        $r = cp_restore_user($cfg); if ($r['err'] !== '') { $msg = $r['err']; return false; }
        $dest = $r['dir'] . '/' . cp_src_tag($v['src']) . '/' . date('Y-m-d', $v['time']) . '-' . $id; $what = substr($v['src'], 4);
    } else {
        if ($v['ds'] === '' || !in_array($v['ds'], $cfg['datasets'], true)) { $msg = ['cpe.no_version', []]; return false; }
        $t = cp_target($cfg, $v['ds']); if ($t['err'] !== '') { $msg = $t['err']; return false; }
        $dest = dirname($t['dir']) . '/restore/' . cp_tag($v['ds']) . '/' . date('Y-m-d', $v['time']) . '-' . $id; $what = $v['ds'];
    }
    if (is_dir($dest)) { $msg = ['cpe.fetched_have', ['d' => $dest]]; return false; }
    @mkdir($dest, 0775, true);
    $cmd = cp_restic_cmd($cfg, 'restore --json ' . escapeshellarg($id . ':' . $v['path']) . ' --target ' . escapeshellarg($dest), $env, $err);
    if ($cmd === '') { $msg = $err; return false; }
    cp_job(['title' => sx_tr('cpj.fetch', ['v' => date('Y-m-d', $v['time']), 'ds' => $what]), 'pct' => 0]);
    $last = 0; $tail = [];
    $rc = cp_stream($cmd, $env, function ($l) use (&$last, &$tail) {
        $j = ($l !== '' && $l[0] === '{') ? json_decode($l, true) : null;
        if (is_array($j) && ($j['message_type'] ?? '') === 'status') { if (time() - $last >= 3) { $last = time(); cp_job(['pct' => (int)round(100 * (float)($j['percent_done'] ?? 0))]); } return; }
        if (!is_array($j) || ($j['message_type'] ?? '') !== 'status') { $tail[] = $l; $tail = array_slice($tail, -8); }
    });
    if ($rc !== 0) { $msg = ['cpe.repo', ['e' => cp_err_text($tail, 300)]]; snp_log("off-site download $id FAILED (restic $rc)"); return false; }
    snp_log("off-site version $id ($what) downloaded to $dest");
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
    // one row per off-site source: its last result, its measured size, its downloads (folders)
    $last = (array)($s['off_last'] ?? []); $sizes = (array)($s['sizes'] ?? []); $srcs = []; $ru = cp_restore_user($cfg);
    foreach (cp_sources($cfg) as $x) {
        $l = $last[$x['key']] ?? ($x['kind'] === 'ds' ? ($last[$x['name']] ?? null) : null);
        $srcs[] = ['key' => $x['key'], 'kind' => $x['kind'], 'name' => $x['name'], 'path' => $x['kind'] === 'ds' ? $x['name'] : substr($x['key'], 4), 'last' => $l, 'size' => $sizes[$x['key']] ?? null,
                   'fetched' => $x['kind'] === 'ds' ? [] : cp_fetched_dir($cfg, $x['key']), 'fdir' => $ru['err'] === '' ? $ru['show'] . '/' . cp_src_tag($x['key']) : ''];
    }
    $gone = [];
    foreach (cp_off_gone($cfg) as $k => $l) { $p = substr($k, strpos($k, ':') + 1); $gone[] = ['key' => $k, 'kind' => strpos($k, 'ds:') === 0 ? 'ds' : ($p === '/boot' ? 'flash' : 'dir'), 'name' => basename($p), 'path' => $p, 'last' => $l]; }
    $sec = cp_secret();
    return ['ds' => $ds, 'srcs' => $srcs, 'gone' => $gone, 'ok' => (int)($s['ok'] ?? 0), 'err' => (string)($s['err'] ?? ''), 'off_ok' => (int)($s['off_ok'] ?? 0), 'off_err' => (string)($s['off_err'] ?? ''),
            'off_last' => $s['off_last'] ?? new stdClass(), 'off_label' => cp_off_label($cfg), 'pub' => $cfg['off'] === 'sftp' ? cp_ssh_pub() : '',
            'has_pw' => !empty($sec['pw']), 's3_set' => !empty($sec['s3_id']) && !empty($sec['s3_key']),
            'job' => json_decode((string)@file_get_contents(cp_job_file()), true) ?: ['state' => 'idle']];
}
