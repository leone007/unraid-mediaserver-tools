<?php
// Sysbox Safe shutdown: what is still using the array and pool disks, and the state of the stop run.
// Used by shutdown.php (web) and shutdown_run.php (background). The shutdown itself is Unraid's own (Boot.php).
require_once __DIR__ . '/lib.php';

// tests only: SX_SD_DIR keeps the state files in that folder, SX_SD_MNT replaces /mnt, SX_SD_VIRSH a fake virsh
$sd_t = getenv('SX_SD_DIR');
define('SD_DIR', ($sd_t !== false && $sd_t !== '') ? rtrim($sd_t, '/') : '/tmp');
unset($sd_t);
define('SD_STATUS', SD_DIR . '/sysbox_shutdown.json');
define('SD_LOCK',   SD_DIR . '/sysbox_shutdown.lock');
define('SD_CANCEL', SD_DIR . '/sysbox_shutdown_cancel');
const SD_QB_WAIT = 300;    // seconds qBittorrent gets to close its files
const SD_CT_WAIT = 60;     // seconds the other containers get
const SD_VM_WAIT = 180;    // seconds the VMs get to shut down

function sd_mnt() { $m = getenv('SX_SD_MNT'); return ($m !== false && $m !== '') ? rtrim($m, '/') : '/mnt'; }

// a path on the array or a pool (not Unassigned Devices or remote shares, which Unraid does not stop with the array)
function sd_watched($p) {
    $b = sd_mnt() . '/';
    if (strpos($p, $b) !== 0) { return false; }
    $first = explode('/', substr($p, strlen($b)), 2)[0];
    return $first !== '' && !in_array($first, ['disks', 'remotes', 'addons', 'rootshare'], true);
}

// processes Unraid stops by itself during the array stop, or that must not be killed by hand
function sd_protect($comm, $cmd, $pid) {
    if ($pid <= 2) { return sx_tr('prot.system'); }
    if (preg_match('/^(emhttpd?|shfs|dockerd|containerd|containerd-shim.*|docker-proxy|runc|libvirtd|virtlogd|virtlockd|qemu-system.*|smbd|nmbd|winbindd|rpc\..*|nfsd.*|mountd)$/', $comm)) { return sx_tr('prot.unraid'); }
    if ($comm === 'mover' || $comm === 'move' || strpos($cmd, '/usr/local/sbin/mover') !== false) { return sx_tr('prot.mover'); }
    if ($comm === 'rsync' && is_file('/tmp/diskbox_balance_status.json') && strpos((string)@file_get_contents('/tmp/diskbox_balance_status.json'), '"running"') !== false) { return sx_tr('prot.balance'); }
    return '';
}

// container id -> name, for the processes that run inside a container
function sd_container_names() {
    $d = sx_docker();
    if ($d === '') { return []; }
    [$rc, $txt] = sx_run(escapeshellarg($d) . " ps --no-trunc --format '{{.ID}} {{.Names}}'", 8);
    $m = [];
    foreach (explode("\n", (string)$txt) as $l) { $x = explode(' ', trim($l), 2); if (count($x) === 2) { $m[$x[0]] = $x[1]; } }
    return $m;
}

// Every process with an open file, working folder or mapped file on the array or a pool.
function sd_holders() {
    $me = getmypid(); $rows = []; $cn = null;
    foreach ((array)glob('/proc/[0-9]*', GLOB_ONLYDIR) as $pd) {
        $pid = (int)basename($pd);
        if ($pid === $me) { continue; }
        $cmd = trim(str_replace("\0", ' ', (string)@file_get_contents("$pd/cmdline")));
        if ($cmd === '') { continue; }   // kernel thread or gone
        $files = [];
        $cwd = @readlink("$pd/cwd");
        if ($cwd !== false && sd_watched($cwd)) { $files[$cwd . ' (working folder)'] = true; }
        foreach ((array)@scandir("$pd/fd") as $fd) {
            if ($fd === '.' || $fd === '..') { continue; }
            $l = @readlink("$pd/fd/$fd");
            if ($l !== false && sd_watched($l)) { $files[$l] = true; }
        }
        foreach ((array)@file("$pd/maps", FILE_IGNORE_NEW_LINES) as $ln) {
            $p = strpos($ln, '/');
            if ($p !== false) { $f = substr($ln, $p); if (sd_watched($f)) { $files[$f] = true; } }
        }
        if (!$files) { continue; }
        $comm = trim((string)@file_get_contents("$pd/comm"));
        $stat = (string)@file_get_contents("$pd/stat");
        $start = '';
        if (($q = strrpos($stat, ')')) !== false) { $f = preg_split('/\s+/', trim(substr($stat, $q + 1))); $start = (string)($f[19] ?? ''); }
        $ct = '';
        if (preg_match('/docker[-\/]([0-9a-f]{64})/', (string)@file_get_contents("$pd/cgroup"), $m)) {
            if ($cn === null) { $cn = sd_container_names(); }
            $ct = $cn[$m[1]] ?? substr($m[1], 0, 12);
        }
        $st = @stat($pd);
        $user = $st ? (function_exists('posix_getpwuid') ? ((posix_getpwuid($st['uid'])['name'] ?? '') ?: (string)$st['uid']) : (string)$st['uid']) : '';
        $prot = $ct !== '' ? sx_tr('prot.ctr', ['c' => $ct]) : sd_protect($comm, $cmd, $pid);
        $fl = array_keys($files); sort($fl);
        $rows[] = ['pid' => $pid, 'start' => $start, 'comm' => $comm, 'cmd' => mb_substr($cmd, 0, 220), 'user' => $user, 'container' => $ct,
                   'files' => array_slice($fl, 0, 6), 'nfiles' => count($fl), 'protected' => $prot];
    }
    usort($rows, function ($a, $b) { return [$a['protected'] !== '', $a['pid']] <=> [$b['protected'] !== '', $b['pid']]; });
    return $rows;
}

function sd_virsh() {
    $v = getenv('SX_SD_VIRSH');
    if ($v !== false && $v !== '') { return $v; }
    return file_exists('/var/run/libvirt/libvirt-sock') ? sx_bin('virsh') : '';
}
function sd_vms() {
    $v = sd_virsh();
    if ($v === '') { return []; }
    [$rc, $txt] = sx_run(escapeshellarg($v) . ' list --name --state-running', 10);
    if ($rc !== 0) { return []; }
    return array_values(array_filter(array_map('trim', explode("\n", (string)$txt)), 'strlen'));
}

// qBittorrent first (it needs the most time to close ~500 torrents' files)
function sd_is_qb($c) { return (bool)preg_match('/qbittorrent/i', $c['name'] . ' ' . $c['image']); }

function sd_running() {
    $f = @fopen(SD_LOCK, 'c');
    if (!$f) { return false; }
    if (flock($f, LOCK_EX | LOCK_NB)) { flock($f, LOCK_UN); fclose($f); return false; }
    fclose($f);
    return true;
}

function sd_status() {
    $s = is_file(SD_STATUS) ? json_decode((string)@file_get_contents(SD_STATUS), true) : null;
    return is_array($s) ? $s : ['state' => 'idle'];
}
function sd_status_write($s) {
    $tmp = SD_STATUS . '.tmp' . getmypid();
    @file_put_contents($tmp, json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    @rename($tmp, SD_STATUS);
}

// warnings before a shutdown: array state, parity check, mover, Diskbox Balance
function sd_checks() {
    $v = @parse_ini_file('/var/local/emhttp/var.ini');
    $v = is_array($v) ? $v : [];
    $w = [];
    $w[] = ['k' => 'array', 'ok' => true, 'text' => sx_tr('chk.array', ['s' => ($v['mdState'] ?? '?'), 't' => ($v['shutdownTimeout'] ?? '?')])];
    if ((float)($v['mdResync'] ?? 0) > 0) {
        $pct = (float)($v['mdResync'] ?? 0) > 0 ? round((float)($v['mdResyncPos'] ?? 0) / (float)$v['mdResync'] * 100, 1) : 0;
        $w[] = ['k' => 'parity', 'ok' => false, 'text' => sx_tr('chk.parity', ['p' => $pct])];
    }
    if (trim((string)@shell_exec('pgrep -x mover 2>/dev/null')) !== '') { $w[] = ['k' => 'mover', 'ok' => false, 'text' => sx_tr('chk.mover')]; }
    $bl = is_file('/tmp/diskbox_balance.lock') ? @fopen('/tmp/diskbox_balance.lock', 'r') : false;
    if ($bl) {
        if (!flock($bl, LOCK_EX | LOCK_NB)) { $w[] = ['k' => 'balance', 'ok' => false, 'text' => sx_tr('chk.balance')]; }
        else { flock($bl, LOCK_UN); }
        fclose($bl);
    }
    return $w;
}
