<?php
// Diskbox helpers: settings, Unraid state files, thresholds.
const DB_CFG = '/boot/config/plugins/diskbox/settings.cfg';
const DB_SMART = '/tmp/diskbox_smart.json';

function db_cfg() {
    $c = ['warn_use' => '', 'crit_use' => '', 'hot_temp' => '', 'max_temp' => '', 'hot_ssd' => '', 'max_ssd' => '', 'smart' => '1',
          'dc_hook' => '', 'dc_lang' => 'hu', 'dc_ping' => '', 'n_array' => '1', 'n_temp' => '1', 'n_smart' => '1', 'n_fill' => '1', 'n_fill_warn' => '85', 'n_fill_crit' => '95',
          'n_pool' => '1', 'n_parity' => '1', 'n_utest' => '1', 'n_remind' => '24', 'wk_on' => '1', 'wk_day' => '7', 'wk_hour' => '18'];
    if (is_file(DB_CFG)) {
        $x = @parse_ini_file(DB_CFG);
        if (is_array($x)) { $c = array_merge($c, $x); }
    }
    return $c;
}

function db_on($v) { return !in_array(strtolower(trim((string)$v)), ['0', 'no', 'false', 'off', ''], true); }

function db_disks() {
    $ini = @parse_ini_file('/var/local/emhttp/disks.ini', true);
    $out = [];
    if (!is_array($ini)) { return $out; }
    foreach ($ini as $k => $d) {
        if (is_array($d)) { $out[trim((string)$k, '"')] = $d; }
    }
    return $out;
}

function db_var() {
    $v = @parse_ini_file('/var/local/emhttp/var.ini');
    return is_array($v) ? $v : [];
}

// What a running resync does and which disks it works on, from mdResyncAction ("check P Q", "recon Q", "recon D1",
// "recon P D2", "clear"): kind = check | sync (only parity disks are built) | rebuild (a data disk is rebuilt) | clear
function db_resync_kind($act) {
    $w = preg_split('/\s+/', trim((string)$act));
    $t = [];
    foreach (array_slice($w, 1) as $x) {
        if ($x === 'P') { $t[] = 'parity'; }
        elseif ($x === 'Q') { $t[] = 'parity2'; }
        elseif (preg_match('/^D(\d+)$/', $x, $m)) { $t[] = 'disk' . $m[1]; }
    }
    $a = (string)($w[0] ?? '');
    if (strpos($a, 'clear') === 0) { $kind = 'clear'; }
    elseif (strpos($a, 'recon') === 0) {
        $kind = 'sync';
        foreach ($t as $n) { if (strpos($n, 'parity') !== 0) { $kind = 'rebuild'; } }
        if (!$t) { $kind = 'rebuild'; }
    } else { $kind = 'check'; }
    return [$kind, $t];
}

// A slot counts as used when a disk is (or was) assigned to it
function db_assigned($d) {
    return trim((string)($d['idSb'] ?? '')) !== '' || trim((string)($d['id'] ?? '')) !== '';
}

// Thresholds: the page's own overrides first, then Unraid's display settings, then defaults. 0 = off.
function db_thr($cfg) {
    $d = @parse_ini_file('/boot/config/plugins/dynamix/dynamix.cfg', true);
    $disp = (is_array($d) && isset($d['display']) && is_array($d['display'])) ? $d['display'] : [];
    $pick = function ($mine, $key, $def) use ($disp) {
        $m = trim((string)$mine);
        if ($m !== '' && is_numeric($m)) { $v = (float)$m; }
        else { $u = $disp[$key] ?? ''; $v = is_numeric($u) ? (float)$u : (float)$def; }
        return $v;
    };
    return [
        'warn'   => $pick($cfg['warn_use'], 'warning', 70),
        'crit'   => $pick($cfg['crit_use'], 'critical', 90),
        'hot'    => $pick($cfg['hot_temp'], 'hot', 45),
        'max'    => $pick($cfg['max_temp'], 'max', 55),
        'hotssd' => $pick($cfg['hot_ssd'] ?? '', 'hotssd', 60),
        'maxssd' => $pick($cfg['max_ssd'] ?? '', 'maxssd', 70),
    ];
}

function db_smart() {
    if (!is_file(DB_SMART)) { return []; }
    $j = json_decode((string)@file_get_contents(DB_SMART), true);
    return (is_array($j) && isset($j['disks']) && is_array($j['disks'])) ? $j['disks'] : [];
}

// ---- acknowledged SMART warnings -------------------------------------------------------------------
const DB_ACK = '/boot/config/plugins/diskbox/ack.json';

function db_ack() {
    $j = is_file(DB_ACK) ? json_decode((string)@file_get_contents(DB_ACK), true) : null;
    return is_array($j) ? $j : [];
}

function db_ack_write($ack) {
    $dir = dirname(DB_ACK);
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    $tmp = DB_ACK . '.tmp';
    if (@file_put_contents($tmp, json_encode($ack)) === false) { return false; }
    if (!@rename($tmp, DB_ACK)) { @file_put_contents(DB_ACK, json_encode($ack)); @unlink($tmp); }
    @chmod(DB_ACK, 0600);
    return true;
}

// SMART problems of one disk that still count: an acknowledged attribute counts again only when its raw value rises
// above what it was when it was acknowledged. A failed health check cannot be acknowledged.
// Returns ['attrs' => [[id, name, prefail, raw, value, worst, thresh, failed, ackedRaw|null], ...], 'nvme' => [text, ...], 'health' => bool, 'hidden' => int]
function db_smart_issues($name, $s, $ack) {
    $out = ['attrs' => [], 'nvme' => [], 'health' => false, 'hidden' => 0];
    if (!is_array($s)) { return $out; }
    $a = $ack[$name] ?? null;
    $araw = (is_array($a) && isset($a['raw']) && is_array($a['raw'])) ? $a['raw'] : [];
    foreach ($s['attrs'] ?? [] as $x) {
        $id = (string)$x[0];
        $base = array_key_exists($id, $araw) ? (int)$araw[$id] : null;
        if ($base === null || (int)$x[3] > $base) { $x[8] = $base; $out['attrs'][] = $x; } else { $out['hidden']++; }
    }
    $an = (is_array($a) && isset($a['nvme']) && is_array($a['nvme'])) ? $a['nvme'] : [];
    $chk = function ($key, $val, $min, $label) use (&$out, $an) {
        if ($val >= $min && ($val > (int)($an[$key] ?? ($min - 1)))) { $out['nvme'][] = $label . ': ' . $val; } elseif ($val >= $min) { $out['hidden']++; }
    };
    $chk('warn', (int)($s['nvme_warn'] ?? 0), 1, 'critical_warning');
    $chk('media', (int)($s['nvme_media'] ?? 0), 1, 'media_errors');
    $chk('used', (int)($s['nvme_used'] ?? 0), 90, 'percentage_used');
    if (array_key_exists('ok', $s) && $s['ok'] === false) { $out['health'] = true; }
    return $out;
}

// The command line PHP (PHP_BINARY is php-fpm when this runs inside the web server)
function db_php() { return is_executable('/usr/bin/php') ? '/usr/bin/php' : 'php'; }

// ---- array disks being zeroed for removal (dd onto /dev/mdNp1), read only from /proc ------------
// Progress = write position of dd's output descriptor; speed from the previous sample. Read-only: nothing is started here.
// Is the rest of a zeroing run really zero? Reads 1 MiB at three places of the part after the last sample (its start, its middle,
// the end of the device). true = all zero, false = data found, null = could not read (checked again at the next refresh)
function db_zero_check($dev, $last, $size) {
    $f = @fopen($dev, 'rb'); if (!$f) { return null; }
    $mb = 1048576; $ok = true;
    foreach ([$last, $last + ($size - $last) / 2, $size - $mb] as $at) {
        $at = max(0, min($size - $mb, floor($at / 4096) * 4096));
        if (@fseek($f, (int)$at) !== 0) { fclose($f); return null; }
        $b = @fread($f, $mb);
        if ($b === false || strlen($b) !== $mb) { fclose($f); return null; }
        if (trim($b, "\0") !== '') { $ok = false; break; }
    }
    fclose($f);
    return $ok;
}
function db_zeroing($procDir = '/proc', $sysDir = '/sys/class/block', $state = '/tmp/diskbox_zero.json', $devDir = '/dev') {
    $now = microtime(true);
    $prev = @json_decode((string)@file_get_contents($state), true); if (!is_array($prev)) { $prev = []; }
    $out = []; $seen = [];
    foreach ((array)@glob($procDir . '/[0-9]*', GLOB_ONLYDIR) as $pd) {
        $cmd = (string)@file_get_contents($pd . '/cmdline');
        if ($cmd === '' || strpos($cmd, 'dd') === false) { continue; }
        $args = explode("\0", rtrim($cmd, "\0"));
        if (basename((string)$args[0]) !== 'dd') { continue; }
        $of = ''; foreach ($args as $a) { if (strpos($a, 'of=') === 0) { $of = substr($a, 3); } }
        if (!preg_match('#^/dev/md(\d+)(p1)?$#', $of, $m)) { continue; }
        $name = 'disk' . (int)$m[1]; $blk = 'md' . (int)$m[1] . (isset($m[2]) ? 'p1' : '');
        $pos = null;
        foreach ((array)@glob($pd . '/fd/*') as $fd) {
            if (@readlink($fd) === $of && preg_match('/^pos:\s+(\d+)/m', (string)@file_get_contents($pd . '/fdinfo/' . basename($fd)), $mm)) { $pos = (float)$mm[1]; }
        }
        if ($pos === null) { continue; }
        $size = (float)@file_get_contents($sysDir . '/' . $blk . '/size') * 512;
        $rate = 0.0; $p = $prev[$name] ?? null;
        if (is_array($p) && $now - (float)$p['t'] >= 3 && $pos >= (float)$p['pos']) { $rate = ($pos - (float)$p['pos']) / ($now - (float)$p['t']); }
        elseif (is_array($p) && isset($p['rate'])) { $rate = (float)$p['rate']; }
        $keep = (is_array($p) && $now - (float)$p['t'] < 3) ? $p : ['t' => $now, 'pos' => $pos];
        $seen[$name] = array_merge(['t' => $keep['t'], 'pos' => $keep['pos']], ['rate' => $rate, 'size' => $size, 'last' => $pos, 'lt' => $now, 'blk' => $blk]);
        $out[$name] = ['running' => true, 'pos' => $pos, 'size' => $size, 'rate' => $rate];
    }
    // a finished run: dd is gone, the last position reached the end of the device. The last sample is taken at a widget
    // refresh, so it can stop short of the end: from 99 % on, the rest is checked once by reading it (see db_zero_check)
    foreach ($prev as $name => $p) {
        if (isset($seen[$name]) || !is_array($p)) { continue; }
        if ($now - (float)($p['lt'] ?? 0) > 7 * 86400) { continue; }
        $n = (int)substr($name, 4); $blk = (string)($p['blk'] ?? (is_dir($sysDir . '/md' . $n . 'p1') ? 'md' . $n . 'p1' : 'md' . $n));
        $cur = (float)@file_get_contents($sysDir . '/' . $blk . '/size') * 512;
        if ($cur <= 0 || abs($cur - (float)($p['size'] ?? 0)) > 0) { continue; } // the disk is gone or another disk is in its slot: forget it
        $size = (float)$p['size']; $last = (float)($p['last'] ?? 0);
        $done = $last >= $size - 1048576;
        if (!$done && $last >= $size * 0.99) {
            if (!isset($p['vfy'])) { $p['vfy'] = db_zero_check($devDir . '/' . $blk, $last, $size); }
            $done = $p['vfy'] === true;
        }
        $p['blk'] = $blk;
        $seen[$name] = $p;
        $out[$name] = ['running' => false, 'pos' => (float)($p['last'] ?? 0), 'size' => (float)($p['size'] ?? 0), 'rate' => 0.0, 'done' => $done];
    }
    if ($seen || $prev) { @file_put_contents($state, json_encode($seen)); }
    return $out;
}

// ---- unassigned disks (item 58) ------------------------------------------------------------------------
// unassigned.php (cron) reads every unassigned disk with smartctl into DB_UA; api_fetch.php shows them in their own band.
const DB_UA = '/tmp/diskbox_ua.json';

function db_devs() {
    $ini = @parse_ini_file('/var/local/emhttp/devs.ini', true);
    $out = [];
    if (!is_array($ini)) { return $out; }
    foreach ($ini as $k => $d) { if (is_array($d) && !empty($d['device'])) { $out[$k] = $d; } }
    return $out;
}

// UD Preclear (unassigned.devices.preclear) of an unassigned disk: its status file /tmp/preclear/preclear_stat_<sdX> holds
// "sdX|NN|text|pid"; the run is alive while /proc/<pid> exists. Progress text: "Zeroing: 34% @ 190 MB/s (1:02:03)", with
// " (1 of 2)" after the step name for more cycles. Steps skipped by --skip-preread / --skip-postread are read from its
// command line. Read-only. Returns null when the disk has no preclear status.
function db_preclear($dev, $dir = '/tmp/preclear', $proc = '/proc') {
    $f = $dir . '/preclear_stat_' . preg_replace('/[^A-Za-z0-9]/', '', (string)$dev);
    if (!is_file($f)) { return null; }
    $p = explode('|', trim((string)@file_get_contents($f)));
    if (count($p) < 3) { return null; }
    $text = trim($p[2]); $pid = (int)($p[3] ?? 0);
    $run = $pid > 0 && is_dir($proc . '/' . $pid);
    $o = ['run' => $run, 'text' => $text, 't' => (int)@filemtime($f), 'step' => '', 'pct' => null, 'speed' => null, 'elapsed' => '',
          'paused' => false, 'n' => 0, 'of' => 0, 'fail' => (bool)preg_match('/failed|FAIL|Error|error|Aborted/', $text),
          'done' => (bool)preg_match('/Finished Successfully|Precleared!|verification successful/i', $text)];
    if (preg_match('/^(Pre-Read|Post-Read|Verify Zeroing|Zeroing|Erasing)(?: \(\d+ of \d+\))?: (?:(\d+)% @ (\d+) MB\/s \(([^)]*)\)|(PAUSED|QUEUED))/', $text, $m)) {
        $o['step'] = $m[1];
        if (($m[5] ?? '') !== '') { $o['paused'] = true; } else { $o['pct'] = (int)$m[2]; $o['speed'] = (int)$m[3]; $o['elapsed'] = $m[4]; }
    }
    if ($run && $o['step'] !== '') {
        $cl = str_replace("\0", ' ', (string)@file_get_contents($proc . '/' . $pid . '/cmdline'));
        $steps = array_values(array_filter([strpos($cl, '--skip-preread') === false ? 'Pre-Read' : '', 'Zeroing', strpos($cl, '--skip-postread') === false ? 'Post-Read' : '']));
        $i = array_search($o['step'], $steps, true);
        if ($i !== false) { $o['n'] = $i + 1; $o['of'] = count($steps); }
    }
    return $o;
}
// "1:02:03" or "62:03" -> seconds
function db_hms($s) { $x = array_map('intval', explode(':', (string)$s)); $t = 0; foreach ($x as $v) { $t = $t * 60 + $v; } return $t; }

function db_ua() {
    $j = is_file(DB_UA) ? json_decode((string)@file_get_contents(DB_UA), true) : null;
    return (is_array($j) && isset($j['disks']) && is_array($j['disks'])) ? $j['disks'] : [];
}

// One disk from `smartctl -j -i -H -A -c -l selftest`: identity, health (same attribute rules as smart.php) and the self-test:
// test.run with the % left while one runs, last = the newest finished test of the log (passed / aborted / failed).
function db_ua_entry($j) {
    if (!is_array($j) || empty($j['serial_number'])) { return null; }
    // the model as people know it: 'WDC WD180EDGZ-11B2DA0' -> 'WD180EDGZ'
    $model = trim((string)($j['model_name'] ?? ($j['model_family'] ?? '?')));
    $model = preg_replace('/^WDC[ _]/', '', $model);
    if (preg_match('/^(WD\w+?)-\w+$/', $model, $m)) { $model = $m[1]; }
    $e = ['t' => time(), 'model' => $model, 'serial' => (string)$j['serial_number'],
          'bytes' => (float)($j['user_capacity']['bytes'] ?? 0), 'hours' => isset($j['power_on_time']['hours']) ? (int)$j['power_on_time']['hours'] : null,
          'temp' => isset($j['temperature']['current']) ? (int)$j['temperature']['current'] : null,
          'ok' => isset($j['smart_status']['passed']) ? (bool)$j['smart_status']['passed'] : null, 'attrs' => [], 'test' => null, 'last' => null];
    foreach ((array)($j['ata_smart_attributes']['table'] ?? []) as $a) {
        $id = (int)($a['id'] ?? 0); $raw = (int)($a['raw']['value'] ?? 0);
        if ($id === 5)   { $e['realloc'] = $raw; }
        if ($id === 197) { $e['pending'] = $raw; }
        if ($id === 198) { $e['uncorr'] = $raw; }
        $wf = trim((string)($a['when_failed'] ?? ''));
        $failed = ($wf !== '' && $wf !== '-');
        if ($failed || ($raw > 0 && in_array($id, [5, 10, 184, 187, 196, 197, 198, 199], true))) {
            $e['attrs'][] = [$id, (string)($a['name'] ?? ('attr ' . $id)), !empty($a['flags']['prefail']), $raw,
                             (int)($a['value'] ?? 0), (int)($a['worst'] ?? 0), (int)($a['thresh'] ?? 0), $failed];
        }
    }
    $sv = $j['ata_smart_data']['self_test']['status']['value'] ?? null;
    if ($sv !== null && ((int)$sv >> 4) === 15) {
        $left = $j['ata_smart_data']['self_test']['status']['remaining_percent'] ?? (((int)$sv & 15) * 10);
        $e['test'] = ['run' => true, 'left' => (int)$left];
    }
    foreach ((array)($j['ata_smart_self_test_log']['standard']['table'] ?? []) as $t) {
        $v = (int)($t['status']['value'] ?? -1);
        if (($v >> 4) === 15) { continue; }   // the running one
        $kind = ($v >> 4) === 0 ? 'passed' : ((($v >> 4) === 1 || ($v >> 4) === 2) ? 'aborted' : 'failed');
        $type = (string)($t['type']['string'] ?? '');
        $e['last'] = ['status' => $kind, 'text' => (string)($t['status']['string'] ?? ''), 'type' => stripos($type, 'extended') !== false ? 'extended' : (stripos($type, 'short') !== false ? 'short' : strtolower($type)),
                      'hours' => isset($t['lifetime_hours']) ? (int)$t['lifetime_hours'] : null, 'left' => isset($t['status']['remaining_percent']) ? (int)$t['status']['remaining_percent'] : (isset($t['remaining_percent']) ? (int)$t['remaining_percent'] : null)];
        break;
    }
    return $e;
}

