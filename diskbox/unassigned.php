<?php
// Started by cron every 5 minutes: reads the unassigned disks (devs.ini) with smartctl into DB_UA for the dashboard tile:
// model, serial, power-on hours, temperature, SMART health and the self-test (the % left while one runs, else the last result).
// A sleeping disk is never woken (-n standby): its last reading stays. When a test that was running is over (passed, aborted
// or failed), one Discord message goes to the Diskbox webhook (setting n_utest, on by default). Read-only towards the disks.
if (PHP_SAPI !== 'cli') { exit(1); } // cron only, not a web page
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/discord.php';
require_once __DIR__ . '/alerts.php';

$lock = @fopen('/tmp/diskbox_ua.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { exit(0); }
$bin = trim((string)@shell_exec('command -v smartctl 2>/dev/null'));
if ($bin === '') { $bin = '/usr/sbin/smartctl'; }
if (!is_executable($bin)) { exit(0); }

$prev = db_ua();
$res = [];
foreach (db_devs() as $d) {
    $dev = preg_replace('/[^A-Za-z0-9]/', '', (string)$d['device']);
    if ($dev === '') { continue; }
    $key = (string)($d['id'] ?? $dev);
    if (($d['spundown'] ?? '0') === '1') { if (isset($prev[$key])) { $res[$key] = $prev[$key]; $res[$key]['sleep'] = true; } continue; }
    $cmd = escapeshellcmd($bin) . ' -n standby -j -i -H -A -c -l selftest ';
    $e = db_ua_entry(json_decode((string)@shell_exec($cmd . escapeshellarg('/dev/' . $dev) . ' 2>/dev/null'), true));
    // a USB enclosure that smartctl does not recognise by itself: try the SAT pass-through
    if ($e === null && ($d['transport'] ?? '') === 'usb') { $e = db_ua_entry(json_decode((string)@shell_exec($cmd . '-d sat ' . escapeshellarg('/dev/' . $dev) . ' 2>/dev/null'), true)); }
    if ($e === null) { if (isset($prev[$key])) { $res[$key] = $prev[$key]; $res[$key]['sleep'] = true; } continue; }
    $e['since'] = !empty($e['test']['run']) ? (int)($prev[$key]['since'] ?? time()) : 0;   // when this tile first saw the test running
    $res[$key] = $e;
}
$tmp = DB_UA . '.tmp';
if (@file_put_contents($tmp, json_encode(['ts' => time(), 'disks' => $res])) !== false) { @rename($tmp, DB_UA); }

// ---- Discord: a test that was running is over ----------------------------------------------------------
$cfg = db_cfg();
$hook = (string)$cfg['dc_hook'];
if (!db_on($cfg['n_utest']) || !dbn_hook_ok($hook)) { exit(0); }
$lang = dbn_lang($cfg);
$sf = '/boot/config/plugins/diskbox/ua_state.json';
$run = is_file($sf) ? json_decode((string)@file_get_contents($sf), true) : null;
$run = is_array($run) ? $run : [];   // serial => [since, model] of the tests seen running
$changed = false;
$T = [
    'hu' => ['ok' => 'SMART %s teszt rendben: %s', 'ab' => 'SMART %s teszt megszakadt: %s', 'fail' => 'SMART %s teszt HIBÁS: %s',
             'ab_d' => 'A teszt nem futott végig. USB-házban gyakori, hogy a ház elaltatja a lemezt; indítsd újra, és figyeld.',
             'fail_d' => 'A lemez a saját önellenőrzésén elbukott. Ne tegyél rá adatot; használt lemeznél kérd vissza a vételárat.',
             'res' => 'Eredmény', 'dur' => 'Időtartam', 'hours' => 'Üzemóra', 'sect' => 'Áthelyezett / függő', 'extended' => 'kiterjesztett', 'short' => 'rövid'],
    'en' => ['ok' => 'SMART %s test passed: %s', 'ab' => 'SMART %s test aborted: %s', 'fail' => 'SMART %s test FAILED: %s',
             'ab_d' => 'The test did not run to the end. In a USB enclosure the enclosure often puts the disk to sleep; start it again and watch it.',
             'fail_d' => 'The disk failed its own self-test. Do not put data on it; for a used disk, ask for a refund.',
             'res' => 'Result', 'dur' => 'Duration', 'hours' => 'Power-on hours', 'sect' => 'Reallocated / pending', 'extended' => 'extended', 'short' => 'short'],
][$lang];
foreach ($res as $e) {
    $sn = $e['serial'];
    if (!empty($e['test']['run'])) {
        if (!isset($run[$sn])) { $run[$sn] = [(int)$e['since'], $e['model']]; $changed = true; }
        continue;
    }
    if (!isset($run[$sn]) || !empty($e['sleep']) || !$e['last']) { continue; }   // asleep: wait until it can be read again
    $L = $e['last'];
    $name = $e['model'] . ' ' . $sn;
    $type = $T[$L['type']] ?? $L['type'];
    if ($L['status'] === 'passed') { $lv = 'g'; $t = '✅ ' . sprintf($T['ok'], $type, $name); $desc = ''; }
    elseif ($L['status'] === 'aborted') { $lv = 'a'; $t = '⏹️ ' . sprintf($T['ab'], $type, $name); $desc = $T['ab_d']; }
    else { $lv = 'rd'; $t = '🔴 ' . sprintf($T['fail'], $type, $name); $desc = $T['fail_d']; }
    $since = (int)$run[$sn][0];
    $emb = dbn_embed($lv, $t, $desc, [
        [$T['res'], $L['text'] . ($L['status'] !== 'passed' && $L['left'] !== null ? ' (' . $L['left'] . '%)' : ''), false],
        [$T['dur'], $since > 0 ? dbn_fmt_dur(time() - $since) : '', true],
        [$T['hours'], $e['hours'] !== null ? number_format($e['hours'], 0, '.', ' ') : '', true],
        [$T['sect'], (int)($e['realloc'] ?? 0) . ' / ' . (int)($e['pending'] ?? 0), true]]);
    list($r, $msg) = dbn_post($hook, [$emb], $lv === 'rd' ? dbn_ping_set($cfg['dc_ping']) : '');
    if ($r === 'retry') { break; }
    unset($run[$sn]);
    $changed = true;
}
// a disk that was unplugged while its test ran: forget it after a day
foreach ($run as $sn => $x) {
    $seen = false;
    foreach ($res as $e) { if ($e['serial'] === $sn) { $seen = true; } }
    if (!$seen && time() - (int)$x[0] > 86400) { unset($run[$sn]); $changed = true; }
}
if ($changed) { @file_put_contents($sf . '.tmp', json_encode($run)); @rename($sf . '.tmp', $sf); }

// ---- Discord: a UD Preclear that was running is over (item 63) -------------------------------------------
$pf = '/boot/config/plugins/diskbox/pc_state.json';
$pcs = is_file($pf) ? json_decode((string)@file_get_contents($pf), true) : null;
$pcs = is_array($pcs) ? $pcs : [];   // device => [since, name] of the preclears seen running
$pch = false;
$P = [
    'hu' => ['ok' => 'Preclear kész: %s', 'fail' => 'Preclear HIBÁS: %s', 'stop' => 'Preclear leállt: %s',
             'ok_d' => 'A lemez végig olvasva és kinullázva, hiba nélkül. Tartaléknak vagy a tömbbe mehet.',
             'fail_d' => 'A preclear elbukott. Nézd meg a jelentését (Tools, Preclear), és ne tegyél rá adatot.',
             'stop_d' => 'A preclear nem futott végig (leállították, vagy a gép újraindult).', 'res' => 'Eredmény', 'dur' => 'Időtartam'],
    'en' => ['ok' => 'Preclear finished: %s', 'fail' => 'Preclear FAILED: %s', 'stop' => 'Preclear stopped: %s',
             'ok_d' => 'The disk was read and zeroed end to end without an error. It can go on the shelf or into the array.',
             'fail_d' => 'The preclear failed. Read its report (Tools, Preclear) and do not put data on the disk.',
             'stop_d' => 'The preclear did not run to the end (it was stopped, or the server restarted).', 'res' => 'Result', 'dur' => 'Duration'],
][$lang];
foreach (db_devs() as $d) {
    $dev = preg_replace('/[^A-Za-z0-9]/', '', (string)$d['device']); $pc = db_preclear($dev);
    $e = $res[(string)($d['id'] ?? $dev)] ?? null; $name = $e ? $e['model'] . ' ' . $e['serial'] : (string)($d['id'] ?? $dev);
    if ($pc && $pc['run']) { if (!isset($pcs[$dev])) { $pcs[$dev] = [time(), $name]; $pch = true; } continue; }
    if (!isset($pcs[$dev])) { continue; }
    if (!$pc) { unset($pcs[$dev]); $pch = true; continue; }   // its status was cleared before we saw the end
    if ($pc['fail']) { $lv = 'rd'; $t = '🔴 ' . sprintf($P['fail'], $name); $desc = $P['fail_d']; }
    elseif ($pc['done']) { $lv = 'g'; $t = '✅ ' . sprintf($P['ok'], $name); $desc = $P['ok_d']; }
    else { $lv = 'a'; $t = '⏹️ ' . sprintf($P['stop'], $name); $desc = $P['stop_d']; }
    $emb = dbn_embed($lv, $t, $desc, [[$P['res'], $pc['text'], false], [$P['dur'], dbn_fmt_dur(max(0, $pc['t'] - (int)$pcs[$dev][0])), true]]);
    list($r, $msg) = dbn_post($hook, [$emb], $lv === 'rd' ? dbn_ping_set($cfg['dc_ping']) : '');
    if ($r === 'retry') { break; }
    unset($pcs[$dev]); $pch = true;
}
if ($pch) { @file_put_contents($pf . '.tmp', json_encode($pcs)); @rename($pf . '.tmp', $pf); }
