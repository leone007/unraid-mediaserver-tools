<?php
// Diskbox Balance: plan and move whole titles (a downloads folder and its hardlinked media files)
// between array disks, keeping the hardlinks. Used by balance.php (web) and balance_run.php (background).
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/i18n.php';
// texts the Balance page and the tile show (lang/en.json); the run log stays in English
function bl_tr($k, $v = []) { static $t = null; if ($t === null) { $t = mt_i18n_t(__DIR__ . '/lang'); } return $t($k, $v); }

// DBX_BL_DIR (tests only): keep the settings and the /tmp files in that folder instead
$bl_t = getenv('DBX_BL_DIR'); $bl_t = ($bl_t !== false && $bl_t !== '') ? rtrim($bl_t, '/') : '';
define('BL_CFG',    $bl_t !== '' ? $bl_t . '/balance.json' : '/boot/config/plugins/diskbox/balance.json');
define('BL_LOG',    $bl_t !== '' ? $bl_t . '/balance.log' : '/boot/config/plugins/diskbox/balance.log');
$bl_t = $bl_t !== '' ? $bl_t : '/tmp';
define('BL_PLAN',   $bl_t . '/diskbox_balance_plan.json');
define('BL_PLANF',  $bl_t . '/diskbox_balance_planfiles.json');
define('BL_JOB',    $bl_t . '/diskbox_balance_job.json');
define('BL_STATUS', $bl_t . '/diskbox_balance_status.json');
define('BL_STOP',   $bl_t . '/diskbox_balance_stop');
define('BL_LOCK',   $bl_t . '/diskbox_balance.lock');
unset($bl_t);
// Seedbox keeps replaced or deleted downloads in this folder inside each downloads folder (purged after some days)
const BL_TRASH  = '.seedbox_trash';

function bl_mnt() { $m = getenv('DBX_MNT'); return ($m !== false && $m !== '') ? rtrim($m, '/') : '/mnt'; }
function bl_rsync() { $r = getenv('DBX_RSYNC'); return ($r !== false && $r !== '') ? $r : 'rsync'; }

function bl_json_read($f, $def = null) {
    if (!is_file($f)) { return $def; }
    $j = json_decode((string)@file_get_contents($f), true);
    return is_array($j) ? $j : $def;
}
function bl_json_write($f, $data, $mode = 0644) {
    $dir = dirname($f);
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    $tmp = $f . '.tmp' . getmypid();
    if (@file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)) === false) { return false; }
    if (!@rename($tmp, $f)) { @unlink($tmp); return false; }
    @chmod($f, $mode);
    return true;
}

function bl_cfg() {
    $c = ['pairs' => [['dl' => 'data/downloads/FILM', 'media' => 'data/media/film'], ['dl' => 'data/downloads/SOROZAT', 'media' => 'data/media/sorozat']],
          'target' => '', 'strategy' => 'old', 'disks' => [], 'mode' => 'balance', 'drain' => []];
    $x = bl_json_read(BL_CFG, []);
    foreach (['pairs', 'target', 'strategy', 'disks', 'mode', 'drain'] as $k) { if (isset($x[$k])) { $c[$k] = $x[$k]; } }
    return $c;
}

// a path relative to the disk root: no leading slash, no .. and no control characters
function bl_path_ok($p) {
    $p = trim((string)$p, " \t/");
    if ($p === '' || preg_match('/[\x00-\x1f\\\\]/', $p)) { return false; }
    foreach (explode('/', $p) as $seg) { if ($seg === '' || $seg === '.' || $seg === '..') { return false; } }
    return $p;
}

// array data disks that can take part: name => [name, label, size, free, used, pct]
function bl_disks() {
    $names = [];
    $t = getenv('DBX_DISKS');
    if ($t !== false && $t !== '') { $names = explode(',', $t); }
    else {
        foreach (db_disks() as $k => $d) {
            if (preg_match('/^disk\d+$/', $k) && ($d['type'] ?? '') === 'Data' && ($d['status'] ?? '') === 'DISK_OK') { $names[] = $k; }
        }
    }
    usort($names, function ($a, $b) { return (int)substr($a, 4) <=> (int)substr($b, 4); });
    $out = [];
    foreach ($names as $n) {
        $m = bl_mnt() . '/' . $n;
        if (!is_dir($m)) { continue; }
        $size = (float)@disk_total_space($m); $free = (float)@disk_free_space($m);
        $fk = getenv('DBX_FAKE');
        if ($fk !== false && $fk !== '') { $fj = json_decode($fk, true); if (isset($fj[$n])) { $size = (float)$fj[$n][0]; $free = $size - (float)$fj[$n][1]; } }
        if ($size <= 0) { continue; }
        $out[$n] = ['name' => $n, 'label' => strtoupper($n), 'size' => $size, 'free' => $free, 'used' => $size - $free, 'pct' => ($size - $free) / $size * 100];
    }
    return $out;
}

// why nothing may move now ('' = it may). $plan: a plan only reads the disks, so a parity check does not stop it (moving waits for its end)
function bl_busy_reason($plan = false) {
    $v = db_var();
    if (getenv('DBX_DISKS') === false || getenv('DBX_DISKS') === '') {
        if (($v['mdState'] ?? '') !== 'STARTED') { return bl_tr('why.not_started'); }
        if (!$plan && (float)($v['mdResync'] ?? 0) > 0) {
            $pos = (float)($v['mdResyncPos'] ?? 0); $size = (float)($v['mdResyncSize'] ?? 0);
            return bl_tr('why.resync') . ($size > 0 ? ' (' . round($pos / $size * 100) . ' %)' : '');
        }
        $m = trim((string)@shell_exec('pgrep -x mover 2>/dev/null'));
        if ($m !== '') { return bl_tr('why.mover'); }
    }
    return '';
}

// the planner holds its own lock while it scans; a plan left 'running' without it died (out of memory, killed; item 104)
function bl_plan_running() {
    $f = @fopen(dirname(BL_PLAN) . '/diskbox_balance_plan.lock', 'c');
    if (!$f) { return false; }
    if (flock($f, LOCK_EX | LOCK_NB)) { flock($f, LOCK_UN); fclose($f); return false; }
    fclose($f);
    return true;
}
function bl_plan_died($p) {
    if (($p['state'] ?? '') === 'running' && time() - (int)($p['t'] ?? 0) > 20 && !bl_plan_running()) { $p['state'] = 'error'; $p['msg'] = bl_tr('plan.died'); }
    return $p;
}
function bl_running() {
    $f = @fopen(BL_LOCK, 'c');
    if (!$f) { return false; }
    if (flock($f, LOCK_EX | LOCK_NB)) { flock($f, LOCK_UN); fclose($f); return false; }
    fclose($f);
    return true;
}

// ---- scanning ---------------------------------------------------------------------------------------
function bl_find($args) {
    $o = (string)@shell_exec('find ' . $args . ' 2>/dev/null');
    return $o === '' ? [] : explode("\0", rtrim($o, "\0"));
}

// first path segment and the rest ('a/b/c' -> ['a', 'b/c'])
function bl_split($rel) {
    $i = strpos($rel, '/');
    return $i === false ? [$rel, ''] : [substr($rel, 0, $i), substr($rel, $i + 1)];
}

// Returns [units, skipped, leftovers] for one disk. A unit is the top-level entry of a downloads folder together with
// every other hardlink (in the media folders) of its files. Hardlinked names always stay in one unit, so one rsync -aH
// run recreates them on the target. The Seedbox trash folder (.seedbox_trash) is left out.
// $drain (emptying a disk): the trash items become units too (each item in the trash is one), and the media files that
// belong to no download (no hardlink, or the download is gone; also .nfo, pictures, subtitles) are added to the title
// whose video is in the same media folder, or become a title of their own ("media only"). leftovers: what would still
// stay on the disk (other folders, skipped titles), grouped by folder.
function bl_scan_disk($disk, $pairs, $drain = false) {
    $root = bl_mnt() . '/' . $disk;
    $units = []; $inodeUnits = []; $found = []; $wantInodes = []; $topUnits = []; $loose = [];
    $unit = function ($key, $title, $pair, $pi, $media = false) use (&$units) {
        if (!isset($units[$key])) { $units[$key] = ['title' => $title, 'pair' => $pair, 'files' => [], 'inodes' => [], 'mtime' => 0, 'partial' => false, 'pi' => $pi, 'media' => $media]; }
    };
    foreach ($pairs as $pi => $pr) {
        $dlAbs = $root . '/' . $pr['dl'];
        if (!is_dir($dlAbs)) { continue; }
        foreach (bl_find(escapeshellarg($dlAbs) . ' -mindepth 1 -type f -printf ' . escapeshellarg('%i\t%n\t%s\t%T@\t%P\0')) as $rec) {
            $f = explode("\t", $rec, 5);
            if (count($f) < 5) { continue; }
            [$ino, $nl, $sz, $mt, $rel] = $f;
            [$first, $rest] = bl_split($rel);
            if ($first === BL_TRASH) {
                if (!$drain || $rest === '') { continue; }
                $first = BL_TRASH . '/' . bl_split($rest)[0];
            }
            $key = $pi . '|' . $first;
            $unit($key, $first, basename($pr['dl']), $pi);
            $u = &$units[$key];
            $u['files'][] = [$pr['dl'] . '/' . $rel, $ino];
            $u['inodes'][$ino] = [(int)$nl, (float)$sz];
            $u['mtime'] = max($u['mtime'], (float)$mt);
            if (preg_match('/\.(!qb|!ut|part|crdownload|tmp)$/i', $rel)) { $u['partial'] = true; }
            unset($u);
            $inodeUnits[$ino][$key] = true;
            $found[$ino] = ($found[$ino] ?? 0) + 1;
            $wantInodes[$ino] = true;
        }
    }
    $seen = [];
    foreach ($pairs as $pi => $pr) {
        if ($pr['media'] === '' || !is_dir($root . '/' . $pr['media'])) { continue; }
        if (!$wantInodes && !$drain) { break; }
        foreach (bl_find(escapeshellarg($root . '/' . $pr['media']) . ' -mindepth 1 -type f -printf ' . escapeshellarg('%i\t%n\t%s\t%T@\t%P\0')) as $rec) {
            $f = explode("\t", $rec, 5);
            if (count($f) < 5) { continue; }
            [$ino, $nl, $sz, $mt, $rel] = $f;
            $path = $pr['media'] . '/' . $rel;
            if (isset($seen[$path])) { continue; }
            $seen[$path] = true;
            $top = $pi . '|' . bl_split($rel)[0];
            if (isset($wantInodes[$ino])) {
                $found[$ino] = ($found[$ino] ?? 0) + 1;
                foreach ($inodeUnits[$ino] as $key => $_) { $units[$key]['files'][] = [$path, $ino]; $topUnits[$top][$key] = true; }
            } elseif ($drain) {
                $found[$ino] = ($found[$ino] ?? 0) + 1;
                $loose[$top][] = [$path, $ino, (int)$nl, (float)$sz, (float)$mt];
            }
        }
    }
    // media files without a download: media folders that share a hardlink are joined (they must move together); a group
    // goes into the one title that has files in the same media folder(s), else it is a title of its own
    $par = [];
    $find = function ($t) use (&$par) { while ($par[$t] !== $t) { $t = $par[$t] = $par[$par[$t]]; } return $t; };
    $byIno = [];
    foreach ($loose as $top => $list) {
        $par[$top] = $par[$top] ?? $top;
        foreach ($list as $x) {
            if (isset($byIno[$x[1]])) { $a = $find($byIno[$x[1]]); $b = $find($top); if ($a !== $b) { $par[$b] = $a; } }
            else { $byIno[$x[1]] = $top; }
        }
    }
    $groups = [];
    foreach (array_keys($loose) as $top) { $groups[$find($top)][] = $top; }
    foreach ($groups as $tops) {
        $owners = [];
        foreach ($tops as $top) { foreach ($topUnits[$top] ?? [] as $k => $_) { $owners[$k] = true; } }
        $owners = array_keys($owners);
        $pi = (int)explode('|', $tops[0], 2)[0];
        if (count($owners) === 1) { $key = $owners[0]; }
        else {
            $key = 'm' . $tops[0];
            $unit($key, implode(' + ', array_map(function ($t) { return explode('|', $t, 2)[1]; }, $tops)), basename($pairs[$pi]['media']), $pi, true);
        }
        foreach ($tops as $top) {
            foreach ($loose[$top] as $x) {
                [$path, $ino, $nl, $sz, $mt] = $x;
                $units[$key]['files'][] = [$path, $ino];
                if (!isset($units[$key]['inodes'][$ino])) { $units[$key]['inodes'][$ino] = [$nl, $sz]; }
                $units[$key]['mtime'] = max($units[$key]['mtime'], $mt);
                $inodeUnits[$ino][$key] = true;
            }
        }
    }
    $ok = []; $skipped = []; $taken = [];
    foreach ($units as $key => $u) {
        $size = 0.0; $why = '';
        foreach ($u['inodes'] as $ino => $v) {
            $size += $v[1];
            if (count($inodeUnits[$ino]) > 1) { $why = bl_tr('skip.shares'); }
            elseif ($v[0] != ($found[$ino] ?? 0) && $why === '') { $why = bl_tr('skip.outside'); }
        }
        if ($why === '' && $u['partial']) { $why = bl_tr('skip.partial'); }
        if ($why === '' && $u['mtime'] > time() - 7200) { $why = bl_tr('skip.recent'); }
        $row = ['title' => $u['title'], 'pair' => $u['pair'], 'src' => $disk, 'size' => $size, 'nfiles' => count($u['files']), 'mtime' => (int)$u['mtime']];
        // what kind of files the title holds (extension => count), shown on the page instead of a guess
        $ext = []; foreach ($u['files'] as $x) { $e = strtolower(pathinfo((string)$x[0], PATHINFO_EXTENSION)); $e = $e === '' ? '(none)' : substr($e, 0, 8); $ext[$e] = ($ext[$e] ?? 0) + 1; }
        arsort($ext); $row['exts'] = array_slice($ext, 0, 6, true);
        if ($u['media'] && $u['files']) {
            // a hardlink cannot cross disks: is the same media folder on another array disk too (where the video and its download usually are)?
            $mp = $pairs[$u['pi']]['media'] ?? ''; $rel0 = (string)$u['files'][0][0];
            if ($mp !== '' && strpos($rel0, $mp . '/') === 0) {
                $topDir = $mp . '/' . bl_split(substr($rel0, strlen($mp) + 1))[0]; $also = [];
                foreach (array_keys(bl_disks()) as $od) { if ($od !== $disk && is_dir(bl_mnt() . '/' . $od . '/' . $topDir)) { $also[] = $od; } }
                $row['also'] = $also;
            }
        }
        if ($u['media']) { $row['media'] = true; }
        if ($why !== '') { $row['why'] = $why; $skipped[] = $row; }
        else { $row['files'] = $u['files']; $ok[] = $row; foreach ($u['files'] as $x) { $taken[$x[0]] = true; } }
    }
    return [$ok, $skipped, $drain ? bl_leftovers($disk, $pairs, $taken) : []];
}

// Everything on the disk that the plan does not move, grouped (a title inside a configured folder, else the first two
// folder levels), largest first. Hardlinked names are counted once.
function bl_leftovers($disk, $pairs, $taken) {
    $roots = [];
    foreach ($pairs as $pr) {
        $roots[] = $pr['dl'] . '/' . BL_TRASH; $roots[] = $pr['dl'];
        if ($pr['media'] !== '') { $roots[] = $pr['media']; }
    }
    usort($roots, function ($a, $b) { return strlen($b) <=> strlen($a); });
    $g = []; $ino = [];
    foreach (bl_find(escapeshellarg(bl_mnt() . '/' . $disk) . ' -mindepth 1 -type f -printf ' . escapeshellarg('%i\t%s\t%P\0')) as $rec) {
        $f = explode("\t", $rec, 3);
        if (count($f) < 3 || isset($taken[$f[2]])) { continue; }
        $p = $f[2]; $grp = '';
        foreach ($roots as $r) {
            if (strpos($p, $r . '/') === 0) { $grp = $r . '/' . bl_split(substr($p, strlen($r) + 1))[0]; break; }
        }
        if ($grp === '') { $s = explode('/', $p); $grp = count($s) > 2 ? $s[0] . '/' . $s[1] : $p; }
        if (!isset($g[$grp])) { $g[$grp] = ['disk' => $disk, 'path' => $grp, 'size' => 0.0, 'n' => 0]; }
        $g[$grp]['n']++;
        if (!isset($ino[$f[0]])) { $ino[$f[0]] = true; $g[$grp]['size'] += (float)$f[1]; }
    }
    usort($g, function ($a, $b) { return $b['size'] <=> $a['size']; });
    return array_values($g);
}

function bl_plan_status($state, $msg = '', $extra = []) {
    bl_json_write(BL_PLAN, array_merge(['state' => $state, 't' => time(), 'msg' => $msg], $extra));
}

// Builds the plan and writes BL_PLAN (what the page shows) and BL_PLANF (file lists, server side only).
function bl_make_plan() {
    $cfg = bl_cfg();
    $all = bl_disks();
    $want = array_filter((array)$cfg['disks'], 'is_string');
    $disks = $want ? array_intersect_key($all, array_flip($want)) : $all;
    if (count($disks) < 2) { bl_plan_status('error', bl_tr('plan.two_disks')); return; }
    $pairs = [];
    foreach ((array)$cfg['pairs'] as $p) {
        $dl = bl_path_ok($p['dl'] ?? ''); $md = trim((string)($p['media'] ?? '')) === '' ? '' : bl_path_ok($p['media']);
        if ($dl !== false && $md !== false) { $pairs[] = ['dl' => $dl, 'media' => $md]; }
    }
    if (!$pairs) { bl_plan_status('error', bl_tr('plan.no_pair')); return; }
    if (in_array($cfg['mode'] ?? '', ['drain', 'remove'], true)) { bl_plan_drain($cfg, $disks, $pairs); return; }

    $sumSize = 0; $sumUsed = 0;
    foreach ($disks as $d) { $sumSize += $d['size']; $sumUsed += $d['used']; }
    $avg = $sumUsed / $sumSize * 100;
    $req = trim((string)$cfg['target']) === '' ? null : (float)$cfg['target'];
    $note = '';
    if ($req === null || $req < $avg) {
        $T = $avg;
        if ($req !== null) { $note = bl_tr('plan.target_raised', ['avg' => number_format($avg, 1), 'req' => number_format($req, 0)]); }
    } else { $T = $req; }
    $need = []; $excess = [];
    foreach ($disks as $n => $d) {
        $tb = $T / 100 * $d['size'];
        $excess[$n] = max(0, $d['used'] - $tb);
        $need[$n] = max(0, $tb - $d['used']);
    }
    $totalExcess = array_sum($excess);
    if ($totalExcess < 0.005 * $sumSize) { bl_plan_status('ready', bl_tr('plan.balanced'), ['target' => $T, 'avg' => $avg, 'note' => $note, 'disks' => array_values($disks), 'units' => [], 'skipped' => []]); bl_json_write(BL_PLANF, []); return; }

    bl_plan_status('running', bl_tr('bal.scanning_long'));
    $donors = array_keys(array_filter($excess, function ($x, $n) use ($disks) { return $x >= 0.005 * $disks[$n]['size']; }, ARRAY_FILTER_USE_BOTH));
    usort($donors, function ($a, $b) use ($excess) { return $excess[$b] <=> $excess[$a]; });
    $units = []; $skipped = [];
    $strategy = ($cfg['strategy'] === 'big') ? 'big' : 'old';
    $dnr = array_keys(array_filter($need, function ($x) { return $x > 0; }));
    foreach ($donors as $dn) {
        bl_plan_status('running', 'Scanning ' . strtoupper($dn) . '...');
        [$ok, $sk] = bl_scan_disk($dn, $pairs);
        foreach ($sk as $s) { $skipped[] = $s; }
        usort($ok, function ($a, $b) use ($strategy) { return $strategy === 'big' ? ($b['size'] <=> $a['size']) : ($a['mtime'] <=> $b['mtime']); });
        $ex = $excess[$dn];
        foreach ($ok as $u) {
            $u['sel'] = false; $u['dst'] = '';
            $tol = 0;
            if ($ex > 0) {
                $best = ''; $bn = 0;
                foreach ($dnr as $rn) {
                    if ($rn === $dn) { continue; }
                    $tol = 0.01 * $disks[$rn]['size'];
                    if ($need[$rn] + $tol >= $u['size'] && $need[$rn] > $bn) { $best = $rn; $bn = $need[$rn]; }
                }
                if ($best !== '') { $u['sel'] = true; $u['dst'] = $best; $need[$best] -= $u['size']; $ex -= $u['size']; }
            }
            $units[] = $u;
        }
    }
    // unselected titles get the neediest other disk as a default destination
    bl_plan_publish($units, $skipped, $disks, ['target' => $T, 'avg' => $avg, 'req' => $req, 'note' => $note, 'strategy' => $strategy]);
}

// a path in BL_PLANF: JSON holds only UTF-8, so a name in another encoding (Latin-1, CP1250 accents) is kept as b64:<base64>
// (item 104: it was saved with U+FFFD and never found again)
function bl_path_enc($p) { return mb_check_encoding($p, 'UTF-8') && strpos($p, 'b64:') !== 0 ? $p : 'b64:' . base64_encode($p); }
function bl_path_dec($p) { return strpos((string)$p, 'b64:') === 0 ? (string)base64_decode(substr($p, 4)) : (string)$p; }
// writes BL_PLANF (file lists, server side only) and BL_PLAN (what the page shows)
function bl_plan_publish($units, $skipped, $disks, $extra) {
    usort($units, function ($a, $b) { return ($b['sel'] <=> $a['sel']); });
    $units = array_slice($units, 0, 3000);
    $pub = []; $filesById = [];
    foreach ($units as $i => $u) {
        $id = 'u' . $i;
        $filesById[$id] = array_map(function ($f) { $f[0] = bl_path_enc($f[0]); return $f; }, $u['files']); unset($u['files']);
        if ($u['dst'] === '') {
            $bn = -1; foreach ($disks as $rn => $d) { if ($rn !== $u['src'] && empty($d['drain']) && $d['pct'] < ($bn < 0 ? 1000 : $bn)) { $bn = $d['pct']; $u['dst'] = $rn; } }
        }
        $u['id'] = $id; $pub[] = $u;
    }
    usort($skipped, function ($a, $b) { return $b['size'] <=> $a['size']; });
    bl_json_write(BL_PLANF, $filesById, 0600);
    bl_plan_status('ready', '', array_merge(['disks' => array_values($disks), 'units' => $pub, 'skipped' => array_slice($skipped, 0, 200)], $extra));
}

// Emptying disks: every title on the disks to empty is planned to the other included disks (the one that stays the least
// full gets the next title, largest titles first). What cannot be moved is listed (skipped titles and leftovers).
function bl_plan_drain($cfg, $disks, $pairs) {
    $drain = array_values(array_intersect(array_filter((array)$cfg['drain'], 'is_string'), array_keys($disks)));
    if (!$drain) { bl_plan_status('error', bl_tr('plan.tick_drain')); return; }
    $targets = array_values(array_diff(array_keys($disks), $drain));
    if (!$targets) { bl_plan_status('error', bl_tr('plan.one_stays')); return; }
    $used = []; $room = []; $sumSize = 0; $sumUsed = 0;
    foreach ($disks as $n => $d) { $sumUsed += $d['used']; }
    foreach ($targets as $n) { $used[$n] = $disks[$n]['used']; $room[$n] = $disks[$n]['free'] - max(5e9, 0.01 * $disks[$n]['size']); $sumSize += $disks[$n]['size']; }
    foreach ($drain as $n) { $disks[$n]['drain'] = true; }
    $T = $sumUsed / $sumSize * 100;
    $units = []; $skipped = []; $left = []; $nofit = 0;
    foreach ($drain as $dn) {
        bl_plan_status('running', 'Scanning ' . strtoupper($dn) . '...');
        [$ok, $sk, $lo] = bl_scan_disk($dn, $pairs, true);
        foreach ($sk as $x) { $skipped[] = $x; }
        foreach ($lo as $x) { $left[] = $x; }
        usort($ok, function ($a, $b) { return $b['size'] <=> $a['size']; });
        foreach ($ok as $u) {
            $u['sel'] = false; $u['dst'] = ''; $best = ''; $bp = 1e9;
            foreach ($targets as $n) {
                $p = ($used[$n] + $u['size']) / $disks[$n]['size'];
                if ($room[$n] >= $u['size'] && $p < $bp) { $best = $n; $bp = $p; }
            }
            if ($best !== '') { $u['sel'] = true; $u['dst'] = $best; $used[$best] += $u['size']; $room[$best] -= $u['size']; }
            else { $nofit++; }
            $units[] = $u;
        }
    }
    usort($left, function ($a, $b) { return $b['size'] <=> $a['size']; });
    $note = $nofit ? $nofit . ' title(s) do not fit on the other disks and are not ticked.' : '';
    bl_plan_publish($units, $skipped, $disks, ['mode' => 'drain', 'drain' => $drain, 'target' => min(100, $T), 'avg' => $T, 'req' => null, 'note' => $note,
        'left' => array_slice($left, 0, 300), 'leftSize' => array_sum(array_column($left, 'size'))]);
}
