<?php
require_once __DIR__ . '/lib.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');

function db_out($d) { echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); exit; }

function db_zero_text($z) {
    $pct = $z['size'] > 0 ? min(100, $z['pos'] / $z['size'] * 100) : 0;
    if (!$z['running']) { return !empty($z['done']) ? 'zeroed · ready to remove' : 'zeroing stopped at ' . number_format($pct, 1) . '%'; }
    $t = 'zeroing ' . number_format($pct, 1) . '%';
    if ($z['rate'] > 0) {
        $left = ($z['size'] - $z['pos']) / $z['rate']; $h = (int)floor($left / 3600); $mi = (int)ceil(($left - $h * 3600) / 60);
        $t .= ' · ' . db_rate($z['rate']) . ' · ' . ($h ? $h . ' h ' . $mi . ' m' : max(1, $mi) . ' m') . ' left';
    }
    return $t;
}

// ---- I/O rates from /proc/diskstats, between two requests ------------------------------------
function db_io_rates() {
    $now = microtime(true);
    $cur = [];
    foreach ((array)@file('/proc/diskstats', FILE_IGNORE_NEW_LINES) as $l) {
        $p = preg_split('/\s+/', trim((string)$l));
        if (count($p) >= 10) { $cur[$p[2]] = [(float)$p[5], (float)$p[9]]; }   // sectors read / written
    }
    $f = '/tmp/diskbox_io.json';
    $prev = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
    $rates = (is_array($prev) && isset($prev['rates'])) ? $prev['rates'] : [];
    $save = false;
    if (is_array($prev) && isset($prev['t'], $prev['d'])) {
        $dt = $now - (float)$prev['t'];
        if ($dt >= 2.0) {
            $rates = [];
            foreach ($cur as $n => $c) {
                if (isset($prev['d'][$n])) {
                    $rates[$n] = [max(0.0, ($c[0] - $prev['d'][$n][0]) * 512 / $dt), max(0.0, ($c[1] - $prev['d'][$n][1]) * 512 / $dt)];
                }
            }
            $save = true;
        }
    } else { $save = true; }
    if ($save) { @file_put_contents($f, json_encode(['t' => $now, 'd' => $cur, 'rates' => $rates])); }
    return $rates;
}

function db_rate($b) {
    if ($b >= 1e6) { return number_format($b / 1e6, $b >= 1e8 ? 0 : 1, '.', '') . ' MB/s'; }
    return max(1, (int)round($b / 1e3)) . ' KB/s';
}

// ---- ZFS pool health, cached for a minute -----------------------------------------------------
function db_zpool() {
    $f = '/tmp/diskbox_zpool.json';
    if (is_file($f) && (time() - filemtime($f)) < 60) {
        $j = json_decode((string)@file_get_contents($f), true);
        if (is_array($j)) { return $j; }
    }
    $out = [];
    $lines = [];
    @exec('zpool list -H -o name,health 2>/dev/null', $lines);
    foreach ($lines as $l) {
        $p = preg_split('/\s+/', trim($l));
        if (count($p) >= 2) { $out[$p[0]] = $p[1]; }
    }
    @file_put_contents($f, json_encode($out));
    return $out;
}

function db_state($status) {
    if ($status === 'DISK_OK') { return 'ok'; }
    if ($status === 'DISK_DSBL' || $status === 'DISK_DSBL_NEW') { return 'disabled'; }
    if ($status === 'DISK_NP_DSBL' || $status === 'DISK_NP_MISSING') { return 'missing'; }
    if ($status === 'DISK_NEW') { return 'new'; }
    return 'invalid';
}

function db_tlevel($temp, $ssd, $thr) {
    if ($temp === null) { return 'mu'; }
    $hot = $ssd ? $thr['hotssd'] : $thr['hot'];
    $max = $ssd ? $thr['maxssd'] : $thr['max'];
    if ($max > 0 && $temp >= $max) { return 'rd'; }
    if ($hot > 0 && $temp >= $hot) { return 'a'; }
    return 'g';
}

function db_health($name, $label, $d, $smart, &$attn) {
    $lvl = 'unk';
    $title = 'SMART data not collected yet';
    $s = $smart[$name] ?? null;
    if (is_array($s)) {
        $lvl = 'ok';
        $title = 'SMART passed';
        $iss = db_smart_issues($name, $s, $GLOBALS['dbx_ack']);
        $w = [];
        foreach ($iss['attrs'] as $x) { $w[] = $x[0] . ' ' . $x[1] . ': ' . $x[3]; }
        foreach ($iss['nvme'] as $x) { $w[] = $x; }
        $failedAttr = false;
        foreach ($iss['attrs'] as $x) { if ($x[7]) { $failedAttr = true; } }
        if ($iss['health']) {
            $lvl = 'rd'; $title = 'SMART health check failed';
            $attn[] = ['rd', $label, 'SMART health check failed', '', ''];
        } elseif ($w) {
            $lvl = $failedAttr ? 'rd' : 'a'; $title = implode(', ', $w);
            $attn[] = [$lvl, $label, $failedAttr ? 'SMART attribute failing' : 'SMART warning', implode(' · ', array_slice($w, 0, 2)), $name];
        } elseif ($iss['hidden'] > 0) {
            $title = 'SMART warning acknowledged';
        }
    }
    $errs = (int)($d['numErrors'] ?? 0);
    if ($errs > 0 && $lvl !== 'rd') {
        $lvl = 'a';
        $title = $errs . ' read/write errors';
        $attn[] = ['a', $label, $errs . ' read/write errors', ''];
    }
    return [$lvl, $title];
}

function db_row($name, $d, $kind, $rates, $smart, $thr, $started, &$attn, $zero = []) {
    $label = strtoupper($name);
    $dev = (string)($d['device'] ?? '');
    $spun = (($d['spundown'] ?? '0') === '1');
    $temp = is_numeric($d['temp'] ?? '') ? (int)$d['temp'] : null;
    $ssd = (($d['rotational'] ?? '1') === '0');
    $state = db_state((string)($d['status'] ?? ''));
    $emul = ($kind === 'data' && ($state === 'disabled' || $state === 'missing'));

    // usage
    $fsSize = (float)($d['fsSize'] ?? 0) * 1024;
    $fsUsed = (float)($d['fsUsed'] ?? 0) * 1024;
    $size = $fsSize > 0 ? $fsSize : (float)($d['size'] ?? 0) * 1024;
    $used = null; $pct = null; $ulevel = '';
    if ($kind !== 'parity' && $fsSize > 0) {
        $used = $fsUsed;
        $pct = $fsUsed / $fsSize * 100;
        if ($thr['crit'] > 0 && $pct >= $thr['crit']) { $ulevel = 'rd'; }
        elseif ($thr['warn'] > 0 && $pct >= $thr['warn']) { $ulevel = 'a'; }
        if ($ulevel !== '') { $attn[] = [$ulevel, $label, 'is ' . number_format($pct, 1) . '% full', ($fsSize - $fsUsed) > 0 ? '@FREE@' . ($fsSize - $fsUsed) : '']; }
    }

    // activity text
    $io = 'idle';
    if (isset($rates[$dev])) {
        $r = $rates[$dev];
        $parts = [];
        if ($r[0] >= 1024) { $parts[] = 'reading ' . db_rate($r[0]); }
        if ($r[1] >= 1024) { $parts[] = 'writing ' . db_rate($r[1]); }
        if ($parts) { $io = implode(' · ', $parts); }
    }
    if ($state !== 'ok') {
        $sub = ($kind === 'data') ? ($state === 'new' ? 'new disk' : ($state === 'invalid' ? 'invalid' : 'emulated')) : ($state === 'new' ? 'new disk' : ($state === 'missing' ? 'missing' : ($state === 'invalid' ? 'invalid' : 'disabled')));
    } elseif ($started && $kind === 'data' && ($d['fsStatus'] ?? 'Mounted') !== 'Mounted' && ($d['fsStatus'] ?? '') !== '') {
        $sub = 'unmountable';
        $attn[] = ['rd', $label, 'is unmountable', (string)$d['fsStatus']];
    } else {
        $sub = $spun ? 'spun down' : $io;
        if ($kind === 'parity') { $sub = 'valid · ' . $sub; }
    }
    // a disk being zeroed for removal: its progress replaces the activity text and the usage bar
    $z = ($kind === 'data') ? ($zero[$name] ?? null) : null;
    if ($z) { $sub = db_zero_text($z); $used = $z['pos']; if ($z['size'] > 0) { $size = $z['size']; } /* the device, not the stand-in image mounted on it */ $pct = $z['size'] > 0 ? min(100, $z['pos'] / $z['size'] * 100) : 0; $ulevel = ''; }
    $tlevel = db_tlevel($temp, $ssd, $thr);
    if ($temp !== null && ($tlevel === 'rd' || $tlevel === 'a')) {
        $attn[] = [$tlevel, $label, $tlevel === 'rd' ? 'is too hot' : 'is running hot', $temp . '°C'];
    }
    if ($emul) { $attn[] = ['rd', $label, 'is emulated', 'the disk is missing or disabled; its data is rebuilt from parity']; }
    elseif ($state === 'invalid') { $attn[] = ['rd', $label, 'is invalid', (string)($d['status'] ?? '')]; }

    list($hl, $ht) = db_health($name, $label, $d, $smart, $attn);
    $hsmart = [$hl, $ht]; // the SMART result alone, shown again while the disk is being built
    if ($emul || $state === 'invalid') { $hl = 'rd'; $ht = $sub; }
    if ($state === 'missing' || $state === 'disabled') { $hl = 'rd'; $ht = $sub; }

    return [
        'name' => $name, 'label' => $label, 'kind' => $kind, 'state' => $state, 'emulated' => $emul,
        'dot' => ($state !== 'ok' && $state !== 'new') ? 'rd' : ($spun ? 'off' : 'on'),
        'sub' => $sub, 'fs' => strtoupper((string)($d['fsType'] ?? '')),
        'size' => $size, 'used' => $used, 'pct' => $pct, 'ulevel' => $ulevel, 'zero' => $z ? ($z['running'] ? 'run' : (!empty($z['done']) ? 'done' : 'stop')) : '', 'devices' => max(1, (int)($d['devices'] ?? 1)),
        'temp' => $temp, 'tlevel' => $tlevel, 'health' => [$hl, $ht], 'hsmart' => $hsmart,
        'link' => '/Main/Device?name=' . rawurlencode($name),
    ];
}

// ---- main -------------------------------------------------------------------------------------
$cfg = db_cfg();
$thr = db_thr($cfg);
$disks = db_disks();
$var = db_var();
if (!$disks) { db_out(['ok' => false, 'error' => 'nodata']); }

$started = (($var['mdState'] ?? '') === 'STARTED');
$rates = db_io_rates();
$smart = db_smart();
$zp = db_zpool();
$attn = [];
$dbx_ack = db_ack();

$parityRows = []; $dataRows = []; $pools = [];
$parityAssigned = 0; $parityOk = 0; $emulated = 0; $invalid = 0;
$sizeSum = 0.0; $usedSum = 0.0; $fsSet = []; $hottest = null;

$zero = db_zeroing();
foreach ($disks as $key => $d) {
    $type = (string)($d['type'] ?? '');
    $name = (string)($d['name'] ?? $key);
    if ($type === 'Flash') { continue; }

    if ($type === 'Parity' || $type === 'Data') {
        if (!db_assigned($d)) { continue; }
        $kind = ($type === 'Parity') ? 'parity' : 'data';
        $row = db_row($name, $d, $kind, $rates, $smart, $thr, $started, $attn, $zero);
        if ($kind === 'parity') {
            $parityAssigned++;
            if ($row['state'] === 'ok' || $row['state'] === 'new') { if ($row['state'] === 'ok') { $parityOk++; } }
            $parityRows[] = $row;
        } else {
            if ($row['emulated']) { $emulated++; }
            if ($row['state'] === 'invalid') { $invalid++; }
            if ($row['fs'] !== '') { $fsSet[$row['fs']] = true; }
            if ($row['zero'] === '') { // a disk being zeroed for removal is not counted in the array usage
                if ($row['used'] !== null) { $usedSum += $row['used']; }
                $sizeSum += (float)((float)($d['fsSize'] ?? 0) * 1024);
            }
            $dataRows[] = $row;
        }
        if ($row['temp'] !== null) { $hottest = ($hottest === null) ? $row['temp'] : max($hottest, $row['temp']); }
    } elseif ($type === 'Cache') {
        if (!db_assigned($d) || trim((string)($d['fsType'] ?? '')) === '') { continue; }
        $row = db_row($name, $d, 'pool', $rates, $smart, $thr, $started, $attn);
        if (strtolower($row['fs']) === 'zfs' && isset($zp[$name]) && $zp[$name] !== 'ONLINE') {
            $row['health'] = ['rd', 'ZFS pool ' . $zp[$name]];
            $attn[] = ['rd', $row['label'], 'pool is ' . strtolower($zp[$name]), ''];
        }
        $pools[] = $row;
        if ($row['temp'] !== null) { $hottest = ($hottest === null) ? $row['temp'] : max($hottest, $row['temp']); }
    }
}

// no parity disk at all: a placeholder row so the missing protection is visible
if ($started && !$parityRows && $dataRows) {
    $parityRows[] = ['name' => 'parity', 'label' => 'PARITY', 'kind' => 'parity', 'state' => 'missing', 'emulated' => false, 'dot' => 'rd',
        'sub' => 'missing · no disk assigned', 'fs' => '', 'size' => 0, 'used' => null, 'pct' => null, 'ulevel' => '',
        'temp' => null, 'tlevel' => 'mu', 'health' => ['rd', 'no parity disk'], 'link' => '/Main'];
}

// resync (parity check / rebuild)
$resync = null;
if ($started && (float)($var['mdResync'] ?? 0) > 0) {
    $size = (float)($var['mdResyncSize'] ?? 0);
    $pos = (float)($var['mdResyncPos'] ?? 0);
    $dt = (float)($var['mdResyncDt'] ?? 0);
    $db = (float)($var['mdResyncDb'] ?? 0);
    $spd = ($dt > 0) ? $db / $dt : 0.0;      // KiB/s
    list($kind, $targets) = db_resync_kind($var['mdResyncAction'] ?? 'check');
    $resync = [
        'action' => $kind, 'targets' => $targets,
        'pct' => $size > 0 ? min(100, $pos / $size * 100) : 0,
        'speed' => $spd * 1024,
        'left' => ($spd > 0 && $size > $pos) ? (int)(($size - $pos) / $spd) : null,
    ];
}

// protection chip
$chip = ['g', 'PARITY PROTECTED'];
$arrState = 'ok';
if (!$started) { $chip = ['mu', 'STOPPED']; $arrState = 'stopped'; }
else {
    $rebuilding = ($resync && $resync['action'] !== 'check' && $resync['action'] !== 'clear');
    // a parity disk being built (a new Parity 2, a replaced parity) is invalid until the sync ends: that is the sync, not an error
    $syncing = ($resync && $resync['action'] === 'sync');
    if ($syncing) { foreach ($parityRows as $pr) { if (in_array($pr['name'], $resync['targets'], true) && $pr['state'] !== 'ok') { $parityAssigned--; } } }
    if ($parityAssigned === 0 || $parityOk === 0) { $chip = ['rd', 'NOT PROTECTED']; $arrState = 'unprotected'; }
    elseif ($emulated > 0) { $chip = ['rd', 'DISK EMULATED']; $arrState = 'emulated'; }
    elseif ($invalid > 0) { $chip = ['rd', 'DISK ERROR']; $arrState = 'invalid'; }
    elseif ($parityOk < $parityAssigned) { $chip = ['a', 'PARITY DEGRADED']; $arrState = 'degraded'; }
    // clearing a newly added data disk leaves the parity valid and the array protected: no chip, no amber state;
    // its progress is on the disk's row and in the header summary
    if ($resync && $resync['action'] !== 'clear' && ($arrState === 'ok' || $arrState === 'degraded' || $rebuilding)) {
        $lab = ['rebuild' => 'REBUILD', 'sync' => 'PARITY-SYNC', 'clear' => 'CLEARING'][$resync['action']] ?? 'PARITY CHECK';
        $chip = [$syncing ? 'bl' : 'a', $lab . ' ' . round($resync['pct']) . '%'];
        $arrState = $resync['action'];
    }
    if ($arrState === 'unprotected') { $attn[] = ['rd', 'Array is not protected', '', $parityAssigned === 0 ? 'no parity disk assigned' : 'the parity disk is missing or disabled']; }
    elseif ($syncing && $parityOk === 0) { $attn[] = ['rd', 'Array is not protected', '', 'until the parity sync ends']; }
}

// rows while a check / parity sync / rebuild / clearing runs (item 68): the disk being built shows its progress in blue instead of a red
// 'invalid'; a check marks the parity disks it reads; a parity disk the sync does not touch stays 'valid'
$clearing = [];
if ($resync) {
    $tg = $resync['targets'];
    $tail = ' · ' . db_rate($resync['speed']);
    if ($resync['left'] !== null) {
        $h = intdiv($resync['left'], 3600); $m = intdiv($resync['left'] % 3600, 60);
        $tail .= ' · ' . ($h > 0 ? $h . ' h ' : '') . $m . ' m left';
    }
    $built = [];
    foreach ([&$parityRows, &$dataRows] as &$rows) {
        foreach ($rows as &$r) {
            $mine = in_array($r['name'], $tg, true);
            if ($resync['action'] === 'check') {
                if ($r['kind'] === 'parity' && $r['state'] === 'ok' && (!$tg || $mine)) {
                    $r['sub'] = 'checking' . $tail; $r['rpct'] = round($resync['pct'], 1); $r['rstill'] = $resync['speed'] <= 0;
                }
            } elseif ($mine && ($resync['action'] === 'sync' || $resync['action'] === 'rebuild')) {
                $r['sub'] = ($r['kind'] === 'parity' ? 'building' : 'rebuilding') . $tail;
                $r['build'] = 1; $r['dot'] = 'on'; $r['health'] = $r['hsmart'];
                $r['rpct'] = round($resync['pct'], 1); $r['rstill'] = $resync['speed'] <= 0;
                $built[$r['label']] = $r['kind'];
            } elseif ($resync['action'] === 'clear' && $r['kind'] === 'data' && $r['state'] === 'new') {
                // "clear" names no disk: Unraid zeroes the newly added data disk(s), outside parity (zeros do not change it)
                $r['sub'] = 'clearing' . $tail;
                $r['build'] = 1; $r['blab'] = 'cleared'; $r['dot'] = 'on';
                $r['rpct'] = round($resync['pct'], 1); $r['rstill'] = $resync['speed'] <= 0;
                $clearing[] = $r['label'];
            }
        }
        unset($r);
    }
    unset($rows);
    // its 'is invalid' / 'is emulated' attention line goes; a data disk being rebuilt keeps one amber line
    if ($built) {
        $attn = array_values(array_filter($attn, function ($x) use ($built) {
            return !(isset($built[$x[1]]) && in_array($x[2], ['is invalid', 'is emulated'], true));
        }));
        foreach ($built as $lab => $k) { if ($k === 'data') { $attn[] = ['a', $lab, 'is being rebuilt from parity', round($resync['pct']) . '%']; } }
    }
}

// Unassigned disks (item 58): Unraid's devs.ini for presence, temperature and transport, DB_UA (unassigned.php) for the rest
$ua = []; $uaRun = 0; $pcRun = 0; $uaInfo = db_ua();
foreach (db_devs() as $k => $d) {
    $id = (string)($d['id'] ?? $d['device']);
    $e = $uaInfo[$id] ?? null;
    $usb = (($d['transport'] ?? '') === 'usb');
    $temp = is_numeric($d['temp'] ?? '') ? (int)$d['temp'] : ($e['temp'] ?? null);
    $spun = (($d['spundown'] ?? '0') === '1');
    $label = $e ? $e['model'] : preg_replace('/_[^_]+$/', '', $id);
    $smartIn = $e ? ['ua:' . $id => ['t' => $e['t'], 'ok' => $e['ok'] !== false, 'attrs' => $e['attrs'], 'realloc' => $e['realloc'] ?? 0, 'pending' => $e['pending'] ?? 0, 'uncorr' => $e['uncorr'] ?? 0]] : [];
    list($hl, $ht) = db_health('ua:' . $id, $label . ($e ? ' ' . $e['serial'] : ''), $d, $smartIn, $attn);
    $lv = 'mu'; $sub = []; $tp = null; $tl = '';
    if ($e) {
        $sub[] = $e['serial'];
        if ($e['hours'] !== null) { $sub[] = number_format($e['hours'], 0, '.', ' ') . ' h'; }
        $T = $e['test']; $L = $e['last'];
        if (!empty($T['run'])) {
            $lv = 'bl'; $uaRun++; $tp = max(0, min(100, 100 - (int)$T['left'])); $tl = $T['left'] . '% left';
            $sub[] = 'SMART test running';
        } elseif ($L) {
            $ago = ($L['hours'] !== null && $e['hours'] !== null) ? max(0, $e['hours'] - $L['hours']) : null;
            $when = $ago === null ? '' : ($ago < 48 ? ' · ' . $ago . ' h ago' : ' · ' . intdiv($ago, 24) . ' d ago');
            if ($L['status'] === 'passed') { $lv = 'g'; $sub[] = $L['type'] . ' test passed' . $when; }
            elseif ($L['status'] === 'aborted') { $lv = 'a'; $sub[] = $L['type'] . ' test aborted' . ($L['left'] !== null ? ' at ' . $L['left'] . '% left' : '') . $when; }
            else { $lv = 'rd'; $sub[] = $L['type'] . ' test failed: ' . $L['text']; $attn[] = ['rd', $label . ' ' . $e['serial'], 'failed its SMART self-test', $L['text']]; }
        }
        if ($spun || !empty($e['sleep'])) { $sub[] = 'asleep'; }
    } else { $sub[] = $spun ? 'asleep' : 'reading SMART…'; }
    // a UD Preclear on this disk (item 63): it replaces the SMART test line while it runs, and its result stays until cleared
    $pc = db_preclear($d['device']);
    if ($pc) {
        $sub = array_slice($sub, 0, $e ? (($e['hours'] !== null && !$pc['run']) ? 2 : 1) : 0);   // while it runs: serial only, the step line is long
        if ($pc['run'] && $pc['paused']) { $lv = 'a'; $sub[] = 'preclear ' . strtolower($pc['step']) . ' paused'; $tp = $pc['pct']; $tl = 'paused'; }
        elseif ($pc['run'] && $pc['pct'] !== null) {
            $lv = 'bl'; $pcRun++; $tp = $pc['pct']; $tl = $pc['pct'] . '%';
            $el = db_hms($pc['elapsed']); $left = $pc['pct'] > 0 ? (int)round($el * (100 - $pc['pct']) / $pc['pct']) : 0;
            $sub[] = $pc['step'] . ($pc['of'] ? ' ' . $pc['n'] . '/' . $pc['of'] : '') . ' · ' . $pc['speed'] . ' MB/s' .
                     ($left > 0 ? ' · ~' . ($left >= 3600 ? round($left / 3600, 1) . ' h' : max(1, (int)round($left / 60)) . ' min') . ' left' : '');
        }
        elseif ($pc['run']) { $lv = 'bl'; $pcRun++; $sub[] = 'preclear: ' . $pc['text']; }
        elseif ($pc['fail']) { $lv = 'rd'; $sub[] = 'preclear failed: ' . $pc['text']; $attn[] = ['rd', $label . ($e ? ' ' . $e['serial'] : ''), 'preclear failed', $pc['text']]; }
        elseif ($pc['done']) { if ($lv !== 'rd') { $lv = 'g'; } $sub[] = 'precleared · ' . date('M j H:i', $pc['t']); }
        else { $lv = 'a'; $sub[] = 'preclear stopped: ' . $pc['text']; }
    }
    if ($hl === 'rd') { $lv = 'rd'; } elseif ($hl === 'a' && $lv !== 'rd') { $lv = 'a'; }
    $ua[] = ['name' => $id, 'label' => $label, 'kind' => 'ua', 'bus' => $usb ? 'USB' : 'SATA', 'sub' => implode(' · ', $sub),
             'size' => $e && $e['bytes'] > 0 ? $e['bytes'] : (float)($d['sectors'] ?? 0) * (float)($d['sector_size'] ?? 512),
             'lv' => $lv, 'tpct' => $tp, 'tleft' => $tl, 'tlab' => $pc ? 'preclear' : 'test', 'temp' => $temp, 'tlevel' => db_tlevel($temp, ($d['rotational'] ?? '1') === '0', $thr),
             'health' => [$hl, $ht], 'dot' => $spun ? 'off' : 'on', 'link' => '/Main'];
}

// attention list: red first
$order = ['rd' => 0, 'a' => 1];
usort($attn, function ($a, $b) use ($order) { return ($order[$a[0]] ?? 2) <=> ($order[$b[0]] ?? 2); });
$attOut = [];
foreach ($attn as $a) { $attOut[] = ['level' => $a[0], 'name' => $a[1], 'text' => $a[2], 'small' => $a[3], 'ack' => $a[4] ?? '']; }

// Balance (see balance.php): shown while a move runs and for a day after it ended
$bal = null;
$bsf = '/tmp/diskbox_balance_status.json';
$bs = is_file($bsf) ? json_decode((string)@file_get_contents($bsf), true) : null;
if (is_array($bs) && !empty($bs['state'])) {
    $bst = (string)$bs['state']; $bfin = (int)($bs['finished'] ?? 0); $bmsg = (string)($bs['msg'] ?? '');
    if ($bst === 'running' && time() - (int)@filemtime($bsf) > 180) { $bst = 'error'; $bmsg = 'the move stopped unexpectedly'; $bfin = (int)@filemtime($bsf); }
    if ($bst === 'running' || ($bfin > 0 && time() - $bfin < 86400 && in_array($bst, ['done', 'aborted', 'error'], true))) {
        $nd = 0; $ns = 0; $nf = 0; $nt = 0;
        foreach ((array)($bs['items'] ?? []) as $bi) { $nt++; if (($bi['state'] ?? '') === 'ok') { $nd++; } elseif (($bi['state'] ?? '') === 'skip') { $ns++; } elseif (($bi['state'] ?? '') === 'fail') { $nf++; } }
        $bal = ['state' => $bst, 'total' => (float)($bs['total'] ?? 0), 'done' => (float)($bs['done'] ?? 0) + (float)($bs['curBytes'] ?? 0),
                'n' => $nt, 'ok' => $nd, 'skip' => $ns, 'fail' => $nf, 'started' => (int)($bs['started'] ?? 0), 'finished' => $bfin, 'msg' => $bmsg];
    }
}

$devices = count($parityRows) + count($dataRows) + count($pools);
if (!empty($parityRows) && $parityRows[0]['state'] === 'missing' && $parityRows[0]['sub'] === 'missing · no disk assigned') { $devices--; }
$arrDevices = count($dataRows) + count(array_filter($parityRows, function ($r) { return $r['sub'] !== 'missing · no disk assigned'; }));

db_out([
    'ok' => true, 'started' => $started, 'ts' => time(),
    'summary' => ['devices' => $devices, 'used' => $usedSum, 'size' => $sizeSum, 'hottest' => $hottest,
                  'clearing' => $clearing ? ['disks' => $clearing, 'pct' => round($resync['pct'])] : null],
    'attention' => $attOut,
    'array' => [
        'devices' => $arrDevices, 'fs' => implode(' / ', array_keys($fsSet)), 'used' => $usedSum, 'size' => $sizeSum,
        'pct' => $sizeSum > 0 ? $usedSum / $sizeSum * 100 : 0,
        'chip' => $chip, 'rpct' => ($resync && $resync['action'] !== 'clear') ? round($resync['pct'], 1) : null, 'rstill' => $resync ? $resync['speed'] <= 0 : false, 'state' => $arrState, 'parity' => $parityRows, 'disks' => $dataRows,
        'warn' => $thr['warn'], 'crit' => $thr['crit'],
    ],
    'pools' => $pools,
    'unassigned' => $ua, 'ua_running' => $uaRun, 'pc_running' => $pcRun,
    'balance' => $bal,
]);
