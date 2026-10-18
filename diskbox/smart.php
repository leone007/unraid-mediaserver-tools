<?php
// Started by cron every 20 minutes: collect SMART health without waking sleeping disks.
if (PHP_SAPI !== 'cli') { exit(1); } // cron only, not a web page
require_once __DIR__ . '/lib.php';

$cfg = db_cfg();
if (!db_on($cfg['smart'])) { exit(0); }
$bin = trim((string)@shell_exec('command -v smartctl 2>/dev/null'));
if ($bin === '') { $bin = '/usr/sbin/smartctl'; }
if (!is_executable($bin)) { exit(0); }

$lock = @fopen('/tmp/diskbox_smart.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { exit(0); }

$prev = is_file(DB_SMART) ? json_decode((string)@file_get_contents(DB_SMART), true) : null;
$res = (is_array($prev) && isset($prev['disks']) && is_array($prev['disks'])) ? $prev['disks'] : [];

foreach (db_disks() as $name => $d) {
    $type = $d['type'] ?? '';
    if (!in_array($type, ['Parity', 'Data', 'Cache'], true)) { continue; }
    $dev = preg_replace('/[^A-Za-z0-9]/', '', (string)($d['device'] ?? ''));
    if ($dev === '' || ($d['spundown'] ?? '0') === '1') { continue; }

    // -n standby: never wake a sleeping disk
    $out = @shell_exec(escapeshellcmd($bin) . ' -n standby -j -H -A ' . escapeshellarg('/dev/' . $dev) . ' 2>/dev/null');
    $j = json_decode((string)$out, true);
    if (!is_array($j) || !isset($j['smart_status'])) { continue; }

    $e = ['t' => time(), 'ok' => !empty($j['smart_status']['passed'])];
    if (!empty($j['ata_smart_attributes']['table'])) {
        foreach ($j['ata_smart_attributes']['table'] as $a) {
            $id = (int)($a['id'] ?? 0);
            $raw = (int)($a['raw']['value'] ?? 0);
            if ($id === 5)   { $e['realloc'] = $raw; }
            if ($id === 197) { $e['pending'] = $raw; }
            if ($id === 198) { $e['uncorr'] = $raw; }
            // attributes worth a Discord alert: error counters above zero, or any value at or below its threshold.
            // Pre-fail / old age comes from the attribute's own flag.
            $wf = trim((string)($a['when_failed'] ?? ''));
            $failed = ($wf !== '' && $wf !== '-');
            if ($failed || ($raw > 0 && in_array($id, [5, 10, 184, 187, 196, 197, 198, 199], true))) {
                $e['attrs'][] = [$id, (string)($a['name'] ?? ('attr ' . $id)), !empty($a['flags']['prefail']), $raw,
                                 (int)($a['value'] ?? 0), (int)($a['worst'] ?? 0), (int)($a['thresh'] ?? 0), $failed];
            }
        }
    }
    if (!empty($j['nvme_smart_health_information_log'])) {
        $n = $j['nvme_smart_health_information_log'];
        $e['nvme_warn'] = (int)($n['critical_warning'] ?? 0);
        $e['nvme_media'] = (int)($n['media_errors'] ?? 0);
        $e['nvme_used'] = (int)($n['percentage_used'] ?? 0);
    }
    $res[$name] = $e;
}

$tmp = DB_SMART . '.tmp';
if (@file_put_contents($tmp, json_encode(['ts' => time(), 'disks' => $res])) !== false) { @rename($tmp, DB_SMART); }
