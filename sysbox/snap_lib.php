<?php
// Snapshots (Sysbox): daily ZFS snapshots of the pool datasets that hold the containers' data, and the restore of one container
// from a snapshot. The whole dataset is never rolled back. Only the daily "auto-" snapshots are removed by themselves (beyond
// "keep" days); a manual one stays until it is deleted with a click.
require_once __DIR__ . '/lib.php';

function snp_dir() { $d = getenv('SX_SNAP_DIR'); return ($d !== false && $d !== '') ? rtrim($d, '/') : '/boot/config/plugins/sysbox'; }  // tests: SX_SNAP_DIR
function snp_tmp() { $d = getenv('SX_SNAP_DIR'); return ($d !== false && $d !== '') ? rtrim($d, '/') : '/tmp'; }
function snp_cfg_file() { return snp_dir() . '/snapshots.json'; }
function snp_log_file() { return snp_dir() . '/snapshots.log'; }
function snp_state_file() { return snp_dir() . '/snapshots_state.json'; }
function snp_run_file() { return snp_tmp() . '/sysbox_snap_run.json'; }
function snp_lock_file() { return snp_tmp() . '/sysbox_snap.lock'; }

// zfs, zpool, rsync: tests put fakes in SX_SNAP_BIN
function snp_bin($n) {
    $t = getenv('SX_SNAP_BIN');
    if ($t !== false && $t !== '' && is_executable("$t/$n")) { return "$t/$n"; }
    return sx_bin($n);
}
function snp_run($cmd, &$rc = null) { $o = []; @exec($cmd . ' 2>&1', $o, $rc); return $o; }

function snp_name_ok($n) { return (bool)preg_match('/^[A-Za-z0-9_.:\-]{1,100}$/', (string)$n); }
function snp_ds_ok($d) { return (bool)preg_match('#^[A-Za-z0-9_.\-]+(?:/[A-Za-z0-9_.\-]+)*$#', (string)$d); }

function snp_defaults() { return ['on' => 1, 'datasets' => [], 'hour' => 3, 'min' => 30, 'keep' => 7, 'maxfill' => 85, 'discord' => 1]; }
function snp_cfg() {
    $c = snp_defaults();
    $x = is_file(snp_cfg_file()) ? json_decode((string)@file_get_contents(snp_cfg_file()), true) : null;
    if (is_array($x)) { foreach ($c as $k => $v) { if (array_key_exists($k, $x)) { $c[$k] = $x[$k]; } } }
    else { foreach (snp_datasets() as $d) { if (preg_match('#/docker$#', $d['name'])) { $c['datasets'][] = $d['name']; } } } // first run: the docker dataset
    $c['datasets'] = array_values(array_filter((array)$c['datasets'], 'snp_ds_ok'));
    return $c;
}
function snp_json_write($f, $data) {
    $tmp = $f . '.tmp' . getmypid();
    if (@file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) === false) { return false; }
    return @rename($tmp, $f);
}
function snp_log($line) {
    $f = snp_log_file(); $l = is_file($f) ? file($f, FILE_IGNORE_NEW_LINES) : [];
    $l[] = date('Y-m-d H:i') . ' ' . $line;
    @file_put_contents($f, implode("\n", array_slice($l, -200)) . "\n");
    @exec('logger -t sysbox-snapshots ' . escapeshellarg($line) . ' >/dev/null 2>&1');
}

// the filesystems of the ZFS pools: name, used, available, mount point
function snp_datasets() {
    $z = snp_bin('zfs'); if ($z === '') { return []; }
    $out = [];
    foreach (snp_run(escapeshellarg($z) . ' list -H -p -o name,used,avail,mountpoint -t filesystem') as $l) {
        $f = explode("\t", $l);
        if (count($f) < 4 || !snp_ds_ok($f[0]) || (strpos($f[3], '/mnt/') !== 0 && !getenv('SX_SNAP_BIN'))) { continue; } // tests: the fake tree is elsewhere
        $out[] = ['name' => $f[0], 'used' => (float)$f[1], 'avail' => (float)$f[2], 'mnt' => $f[3]];
    }
    return $out;
}
function snp_dataset($name) { foreach (snp_datasets() as $d) { if ($d['name'] === $name) { return $d; } } return null; }
// the snapshots of one dataset, oldest first: short name, space held, creation time
function snp_snaps($ds) {
    $z = snp_bin('zfs'); if ($z === '' || !snp_ds_ok($ds)) { return []; }
    $out = [];
    foreach (snp_run(escapeshellarg($z) . ' list -H -p -t snapshot -o name,used,creation -s creation -d 1 ' . escapeshellarg($ds)) as $l) {
        $f = explode("\t", $l);
        if (count($f) < 3 || strpos($f[0], $ds . '@') !== 0) { continue; }
        $n = substr($f[0], strlen($ds) + 1);
        $out[] = ['name' => $n, 'used' => (float)$f[1], 't' => (int)$f[2], 'auto' => (bool)preg_match('/^auto-\d{4}-\d{2}-\d{2}$/', $n)];
    }
    return $out;
}
function snp_pool_fill($ds) {
    $p = snp_bin('zpool'); if ($p === '') { return -1; }
    $o = snp_run(escapeshellarg($p) . ' list -H -o capacity ' . escapeshellarg(explode('/', $ds)[0]));
    return isset($o[0]) && is_numeric(rtrim($o[0], '%')) ? (int)rtrim($o[0], '%') : -1;
}
function snp_make($ds, $name) {
    if (!snp_ds_ok($ds) || !snp_name_ok($name)) { return 'bad name'; }
    foreach (snp_snaps($ds) as $s) { if ($s['name'] === $name) { return 'already exists'; } }
    $o = snp_run(escapeshellarg(snp_bin('zfs')) . ' snapshot ' . escapeshellarg($ds . '@' . $name), $rc);
    return $rc === 0 ? '' : (trim(implode(' ', $o)) ?: 'zfs snapshot failed');
}
function snp_destroy($ds, $name) {
    if (!snp_ds_ok($ds) || !snp_name_ok($name)) { return 'bad name'; }
    $have = false; foreach (snp_snaps($ds) as $s) { if ($s['name'] === $name) { $have = true; } }
    if (!$have) { return 'no such snapshot'; }
    $o = snp_run(escapeshellarg(snp_bin('zfs')) . ' destroy ' . escapeshellarg($ds . '@' . $name), $rc);
    return $rc === 0 ? '' : (trim(implode(' ', $o)) ?: 'zfs destroy failed');
}

// a container's folders inside a dataset: its bind mounts (a /mnt/user/<share>/... path is looked up on the pool)
function snp_real($src, $ds) {
    $src = rtrim((string)$src, '/');
    if (strpos($src, '/mnt/user/') === 0) {
        // /mnt/user/docker/jellyfin is /mnt/cache/docker/jellyfin when the dataset cache/docker is the share "docker"
        $parts = explode('/', substr($src, strlen('/mnt/user/')), 2);
        if ($parts[0] === basename($ds['mnt'])) { return rtrim($ds['mnt'], '/') . (isset($parts[1]) && $parts[1] !== '' ? '/' . $parts[1] : ''); }
    }
    return $src;
}
function snp_containers($ds) {
    $d = sx_docker(); if ($d === '' || !$ds) { return []; }
    $root = rtrim($ds['mnt'], '/') . '/'; $out = [];
    foreach (snp_run(escapeshellarg($d) . " ps -a --format '{{.Names}}|{{.State}}'") as $l) {
        [$n, $st] = array_pad(explode('|', trim($l)), 2, '');
        if (!sx_valid_name($n)) { continue; }
        $src = snp_run(escapeshellarg($d) . " inspect -f '{{range .Mounts}}{{.Source}}\n{{end}}' " . escapeshellarg($n));
        $dirs = [];
        foreach ($src as $s) {
            $r = snp_real(trim($s), $ds);
            if ($r !== '' && strpos($r . '/', $root) === 0 && $r . '/' !== $root && strpos($r, '/.zfs/') === false) { $dirs[$r] = true; }
        }
        if ($dirs) { $out[] = ['name' => $n, 'state' => $st, 'dirs' => array_keys($dirs)]; }
    }
    usort($out, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
    return $out;
}
// the mount table (tests: SX_SNP_MOUNTS)
function snp_mounts_file() { $t = getenv('SX_SNP_MOUNTS'); return ($t !== false && $t !== '') ? $t : '/proc/mounts'; }
// where the snapshot copy of a live folder is
function snp_snapdir($ds, $snap, $dir) { $root = rtrim($ds['mnt'], '/'); return $root . '/.zfs/snapshot/' . $snap . substr($dir, strlen($root)); }

// rsync dry run: how many files differ from the snapshot (shown before a restore)
function snp_diff_count($ds, $snap, $dirs) {
    $r = snp_bin('rsync'); $n = 0;
    foreach ($dirs as $dir) {
        $from = snp_snapdir($ds, $snap, $dir);
        if (!is_dir($from)) { continue; }
        $o = snp_run(escapeshellarg($r) . ' -anHAX --checksum --delete --out-format=%n ' . escapeshellarg($from . '/') . ' ' . escapeshellarg($dir . '/'));
        foreach ($o as $l) { if ($l !== '' && $l !== './' && substr($l, -1) !== '/') { $n++; } }
    }
    return $n;
}

function snp_discord($level, $title, $desc) {
    $cfg = snp_cfg(); if (empty($cfg['discord'])) { return; }
    $sx = sx_cfg(); $hook = (string)($sx['dc_hook'] ?? '');
    if ($hook === '' || !function_exists('sxn_post')) { return; }
    sxn_post($hook, [sxn_embed($level, $title, $desc)]);
}
