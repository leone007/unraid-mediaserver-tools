<?php
// Diskbox Discord alerts: texts (Hungarian and English) and message builders.

function dbn_t($lang, $k, $a = []) {
    static $T = [
      'hu' => [
        'unprot' => 'Az array nincs védve', 'unprot_d' => 'Nincs működő parity lemez: ha most kiesik egy lemez, az adat elveszhet.',
        'emul' => 'Lemez emulálva: %s', 'emul_d' => 'A lemez hiányzik vagy le van tiltva, az adatát az Unraid a parity-ből számolja. Cseréld ki mielőbb.',
        'inval' => 'Lemezhiba az arrayben: %s', 'inval_d' => 'A lemez állapota hibás.',
        'degr' => 'Csökkent parity védelem', 'degr_d' => 'Az egyik parity lemez nem működik rendesen.',
        'rebuild' => 'Az array újraépül (%s)', 'rebuild_d' => 'Amíg ez tart, az array nincs teljesen védve.',
        'arr_ok' => 'Az array újra védett', 'arr_ok_d' => 'A parity védelem helyreállt.',
        'hot' => '%s túl meleg', 'warm' => '%s melegszik', 'cool' => '%s lehűlt',
        'temp' => 'Hőmérséklet', 'limits' => 'Határok', 'limits_v' => 'meleg %s °C · kritikus %s °C', 'cool_d' => 'Már %s °C.',
        'fill' => '%s majdnem megtelt: %s%%', 'fill_c' => '%s megtelt: %s%%', 'fill_ok' => '%s már nem tele (%s%%)',
        'used' => 'Használt', 'free' => 'Szabad', 'thr' => 'Határ',
        'smart_f' => '%s: SMART hiba', 'smart_w' => '%s: SMART figyelmeztetés',
        'smart_fd' => 'A lemez állapotellenőrzése hibát jelzett. Mentsd le az adatait, és cseréld ki.',
        'smart_wd' => 'A lemez SMART-adataiban romlás látszik. Figyeld, és készülj a cserére.',
        'disk' => 'Lemez', 'attrs' => 'Érintett attribútumok', 'nvme' => 'NVMe állapotnapló',
        'pre' => 'Pre-fail', 'old' => 'Old age', 'raw' => 'nyers', 'was' => 'előző', 'val' => 'érték', 'worst' => 'legrosszabb', 'thresh' => 'küszöb',
        'failing' => 'A KÜSZÖB ALATT', 'healthfail' => 'A SMART állapotellenőrzés: HIBÁS',
        'smart_legend' => 'Pre-fail: a romló érték közelgő meghibásodást jelez. Old age: üzemi, elhasználódást mérő számláló.',
        'pool_bad' => '%s pool: %s', 'pool_bad_d' => 'A pool nem ONLINE állapotú, a redundancia vagy az adat veszélyben lehet.', 'pool_ok' => '%s pool újra ONLINE',
        'pc_start' => '%s elindult', 'pc_end' => '%s befejeződött', 'pc_cancel' => '%s megszakítva', 'pc_err' => '%s hibákkal fejeződött be',
        'pc_check' => 'Parity ellenőrzés', 'pc_rebuild' => 'Lemez újraépítés', 'pc_sync' => 'Paritásépítés', 'pc_clear' => 'Lemez törlés',
        'duration' => 'Időtartam', 'speed' => 'Sebesség', 'errors' => 'Hibák', 'at' => 'Mikor',
        'wk' => 'Heti összefoglaló', 'array' => 'Array', 'hottest' => 'Legmelegebb', 'fullest' => 'Legtelítettebb', 'lastpc' => 'Utolsó parity ellenőrzés',
        'alerts7' => 'Riasztás az elmúlt 7 napban', 'none' => 'nincs', 'smartw' => 'SMART figyelmeztetés', 'table' => 'Lemezek',
        'protected' => 'védett', 'sample' => 'MINTA',
        'ack_head' => 'Elfogadott SMART figyelmeztetések', 'ack_on' => 'elfogadva', 'ack_same' => 'azóta változatlan', 'ack_days' => '%s napja', 'ack_today' => 'ma',
        'sev' => 'Súlyosság', 'sev_fast' => 'GYORS ROMLÁS', 'sev_slow' => 'lassú romlás', 'worse' => 'Romlás',
        'sev_fast_d' => 'Rövid időn belül romlott tovább: ez sürgős lehet.', 'sev_slow_d' => 'Hosszabb idő alatt romlott tovább: figyeld, de nem sürgős.',
        'since_ack' => 'az elfogadás óta', 'since_alert' => 'az előző riasztás óta', 'within' => 'alatt', 'test_t' => 'Diskbox teszt', 'test_d' => 'A Discord-értesítés működik.',
        'stopped' => 'leállítva', 'total' => 'Összes tárhely',
      ],
      'en' => [
        'unprot' => 'The array is not protected', 'unprot_d' => 'There is no working parity disk: if a disk fails now, data can be lost.',
        'emul' => 'Disk emulated: %s', 'emul_d' => 'The disk is missing or disabled, so Unraid rebuilds its data from parity. Replace it soon.',
        'inval' => 'Disk error in the array: %s', 'inval_d' => 'The disk is in a bad state.',
        'degr' => 'Parity protection reduced', 'degr_d' => 'One of the parity disks is not working properly.',
        'rebuild' => 'The array is rebuilding (%s)', 'rebuild_d' => 'Until it finishes the array is not fully protected.',
        'arr_ok' => 'The array is protected again', 'arr_ok_d' => 'Parity protection is back.',
        'hot' => '%s is too hot', 'warm' => '%s is running hot', 'cool' => '%s has cooled down',
        'temp' => 'Temperature', 'limits' => 'Limits', 'limits_v' => 'hot %s °C · critical %s °C', 'cool_d' => 'Now %s °C.',
        'fill' => '%s is almost full: %s%%', 'fill_c' => '%s is full: %s%%', 'fill_ok' => '%s is no longer full (%s%%)',
        'used' => 'Used', 'free' => 'Free', 'thr' => 'Limit',
        'smart_f' => '%s: SMART failure', 'smart_w' => '%s: SMART warning',
        'smart_fd' => 'The drive reported a failed health check. Back up its data and replace it.',
        'smart_wd' => 'The drive\'s SMART data shows deterioration. Keep an eye on it and plan a replacement.',
        'disk' => 'Disk', 'attrs' => 'Attributes involved', 'nvme' => 'NVMe health log',
        'pre' => 'Pre-fail', 'old' => 'Old age', 'raw' => 'raw', 'was' => 'was', 'val' => 'value', 'worst' => 'worst', 'thresh' => 'threshold',
        'failing' => 'BELOW THE THRESHOLD', 'healthfail' => 'SMART health check: FAILED',
        'smart_legend' => 'Pre-fail: a failing value predicts imminent failure. Old age: an ordinary usage or wear counter.',
        'pool_bad' => '%s pool: %s', 'pool_bad_d' => 'The pool is not ONLINE; redundancy or data may be at risk.', 'pool_ok' => 'Pool %s is ONLINE again',
        'pc_start' => '%s started', 'pc_end' => '%s finished', 'pc_cancel' => '%s cancelled', 'pc_err' => '%s finished with errors',
        'pc_check' => 'Parity check', 'pc_rebuild' => 'Disk rebuild', 'pc_sync' => 'Parity sync', 'pc_clear' => 'Disk clearing',
        'duration' => 'Duration', 'speed' => 'Speed', 'errors' => 'Errors', 'at' => 'When',
        'wk' => 'Weekly summary', 'array' => 'Array', 'hottest' => 'Hottest', 'fullest' => 'Fullest', 'lastpc' => 'Last parity check',
        'alerts7' => 'Alerts in the last 7 days', 'none' => 'none', 'smartw' => 'SMART warnings', 'table' => 'Disks',
        'protected' => 'protected', 'sample' => 'SAMPLE',
        'ack_head' => 'Acknowledged SMART warnings', 'ack_on' => 'acknowledged', 'ack_same' => 'unchanged since', 'ack_days' => '%s days ago', 'ack_today' => 'today',
        'sev' => 'Severity', 'sev_fast' => 'RAPID DETERIORATION', 'sev_slow' => 'slow deterioration', 'worse' => 'Change',
        'sev_fast_d' => 'It got worse again within a short time: this may be urgent.', 'sev_slow_d' => 'It got worse again over a longer time: keep an eye on it, not urgent.',
        'since_ack' => 'since it was acknowledged', 'since_alert' => 'since the previous alert', 'within' => 'in', 'test_t' => 'Diskbox test', 'test_d' => 'Discord notifications work.',
        'stopped' => 'stopped', 'total' => 'Total capacity',
      ],
    ];
    $s = $T[$lang][$k] ?? ($T['en'][$k] ?? $k);
    return $a ? vsprintf($s, $a) : $s;
}

// kind: 'alert' | 'resolved'
function dbn_e_array($lang, $state, $names, $kind, $extra = '') {
    $n = implode(', ', $names);
    if ($kind === 'resolved') { return dbn_embed('g', '🟢 ' . dbn_t($lang, 'arr_ok'), dbn_t($lang, 'arr_ok_d')); }
    if ($state === 'unprotected') { return dbn_embed('rd', '🔴 ' . dbn_t($lang, 'unprot'), dbn_t($lang, 'unprot_d')); }
    if ($state === 'emulated')    { return dbn_embed('rd', '🔴 ' . dbn_t($lang, 'emul', [$n]), dbn_t($lang, 'emul_d')); }
    if ($state === 'invalid')     { return dbn_embed('rd', '🔴 ' . dbn_t($lang, 'inval', [$n]), dbn_t($lang, 'inval_d')); }
    if ($state === 'rebuild' || $state === 'clear') { return dbn_embed('a', '🟡 ' . dbn_t($lang, 'rebuild', [$extra]), dbn_t($lang, 'rebuild_d')); }
    return dbn_embed('a', '🟡 ' . dbn_t($lang, 'degr'), dbn_t($lang, 'degr_d'));
}

function dbn_e_temp($lang, $label, $temp, $hot, $max, $level, $kind, $info = '') {
    if ($kind === 'resolved') { return dbn_embed('g', '🟢 ' . dbn_t($lang, 'cool', [$label]), dbn_t($lang, 'cool_d', [$temp])); }
    $t = $level === 'rd' ? dbn_t($lang, 'hot', [$label]) : dbn_t($lang, 'warm', [$label]);
    return dbn_embed($level, ($level === 'rd' ? '🔴 🌡️ ' : '🟡 🌡️ ') . $t, '', [
        [dbn_t($lang, 'temp'), $temp . ' °C', true], [dbn_t($lang, 'limits'), dbn_t($lang, 'limits_v', [(int)$hot, (int)$max]), true], [dbn_t($lang, 'disk'), $info]]);
}

function dbn_e_fill($lang, $label, $pct, $used, $size, $level, $kind) {
    $p = number_format($pct, 1, '.', '');
    if ($kind === 'resolved') { return dbn_embed('g', '🟢 ' . dbn_t($lang, 'fill_ok', [$label, $p])); }
    return dbn_embed($level, ($level === 'rd' ? '🔴 💾 ' : '🟡 💾 ') . dbn_t($lang, $level === 'rd' ? 'fill_c' : 'fill', [$label, $p]), '', [
        [dbn_t($lang, 'used'), dbn_fmt_bytes($used) . ' / ' . dbn_fmt_bytes($size), true], [dbn_t($lang, 'free'), dbn_fmt_bytes(max(0, $size - $used)), true]]);
}

// $attrs: [[id, name, prefail(bool), raw, prevraw|null, value, worst, thresh, failed(bool)], ...]
function dbn_e_smart($lang, $label, $info, $attrs, $healthFailed, $nvme, $level, $sev = '', $trend = []) {
    $lines = [];
    foreach ($attrs as $a) {
        $type = $a[2] ? dbn_t($lang, 'pre') : dbn_t($lang, 'old');
        $s = '`' . $a[0] . '` ' . $a[1] . ' · **' . $type . '** · ' . dbn_t($lang, 'raw') . ' ' . $a[3];
        if ($a[4] !== null && (int)$a[4] !== (int)$a[3]) { $s .= ' (' . dbn_t($lang, 'was') . ' ' . $a[4] . ')'; }
        $s .= ' · ' . dbn_t($lang, 'val') . ' ' . $a[5] . ' / ' . dbn_t($lang, 'worst') . ' ' . $a[6] . ' / ' . dbn_t($lang, 'thresh') . ' ' . $a[7];
        if ($a[8]) { $s .= ' · **' . dbn_t($lang, 'failing') . '**'; }
        $lines[] = $s;
    }
    $fields = [[dbn_t($lang, 'disk'), $info]];
    if ($healthFailed) { $fields[] = ['SMART', '**' . dbn_t($lang, 'healthfail') . '**']; }
    if ($lines) { $fields[] = [dbn_t($lang, 'attrs'), implode("\n", $lines)]; }
    if ($nvme) { $fields[] = [dbn_t($lang, 'nvme'), implode("\n", $nvme)]; }
    if ($trend) { array_splice($fields, 1, 0, [[dbn_t($lang, 'worse'), implode("\n", $trend)]]); }
    if ($sev !== '') { array_splice($fields, 1, 0, [[dbn_t($lang, 'sev'), '**' . dbn_t($lang, $sev === 'fast' ? 'sev_fast' : 'sev_slow') . '** · ' . dbn_t($lang, $sev === 'fast' ? 'sev_fast_d' : 'sev_slow_d')]]); }
    $fields[] = ['​', '*' . dbn_t($lang, 'smart_legend') . '*'];
    $title = ($level === 'rd' ? '🔴 ' : '🟡 ') . dbn_t($lang, $level === 'rd' ? 'smart_f' : 'smart_w', [$label]);
    if ($sev !== '') { $title .= ' · ' . dbn_t($lang, $sev === 'fast' ? 'sev_fast' : 'sev_slow'); }
    return dbn_embed($level, $title, dbn_t($lang, $level === 'rd' ? 'smart_fd' : 'smart_wd'), $fields);
}

function dbn_e_pool($lang, $label, $health, $kind) {
    if ($kind === 'resolved') { return dbn_embed('g', '🟢 ' . dbn_t($lang, 'pool_ok', [$label])); }
    return dbn_embed('rd', '🔴 ' . dbn_t($lang, 'pool_bad', [$label, $health]), dbn_t($lang, 'pool_bad_d'));
}

function dbn_pc_name($lang, $action) {
    return dbn_t($lang, ['rebuild' => 'pc_rebuild', 'sync' => 'pc_sync', 'clear' => 'pc_clear'][$action] ?? 'pc_check');
}

function dbn_e_resync_start($lang, $action) {
    return dbn_embed('i', '🔄 ' . dbn_t($lang, 'pc_start', [dbn_pc_name($lang, $action)]));
}

// $r: ['status' => int|null, 'errors' => int|null, 'duration' => seconds|null, 'speed' => text, 'date' => text]
function dbn_e_resync_end($lang, $action, $r) {
    $name = dbn_pc_name($lang, $action);
    $errs = $r['errors'];
    if ($r['status'] !== null && $r['status'] !== 0) { $lvl = 'a'; $t = '⏹️ ' . dbn_t($lang, 'pc_cancel', [$name]); }
    elseif ($errs !== null && $errs > 0) { $lvl = 'rd'; $t = '🔴 ' . dbn_t($lang, 'pc_err', [$name]); }
    else { $lvl = 'g'; $t = '✅ ' . dbn_t($lang, 'pc_end', [$name]); }
    return dbn_embed($lvl, $t, '', [
        [dbn_t($lang, 'duration'), $r['duration'] !== null ? dbn_fmt_dur($r['duration']) : '', true],
        [dbn_t($lang, 'speed'), (string)$r['speed'], true],
        [dbn_t($lang, 'errors'), $errs !== null ? (string)$errs : '', true]]);
}

// Last line of Unraid's parity history: date|seconds|speed|exit status|errors|...
function dbn_last_parity() {
    $f = '/boot/config/parity-checks.log';
    $res = ['status' => null, 'errors' => null, 'duration' => null, 'speed' => '', 'date' => ''];
    if (!is_file($f)) { return $res; }
    $lines = array_values(array_filter(array_map('trim', (array)@file($f, FILE_IGNORE_NEW_LINES))));
    if (!$lines) { return $res; }
    $p = explode('|', end($lines));
    if (count($p) >= 5) {
        $res['date'] = trim($p[0]);
        $res['duration'] = is_numeric($p[1]) ? (int)$p[1] : null;
        $res['speed'] = is_numeric(trim($p[2])) && (float)$p[2] > 0 ? dbn_fmt_bytes((float)$p[2]) . '/s' : trim($p[2]);   // bytes per second in the log
        $res['status'] = is_numeric($p[3]) ? (int)$p[3] : null;
        $res['errors'] = is_numeric($p[4]) ? (int)$p[4] : null;
    }
    return $res;
}

// Result of the check that just ended: md's own fields in var.ini are set the moment it stops; the line in
// parity-checks.log can come later, and reading it too early reported the previous check (2026-10-15)
function dbn_resync_result($v) {
    $t0 = (int)($v['sbSynced'] ?? 0); $t1 = (int)($v['sbSynced2'] ?? 0);
    if ($t1 > $t0 && $t0 > 0 && $t1 >= time() - 6 * 3600 && isset($v['sbSyncErrs'])) {
        $d = $t1 - $t0; $size = (float)($v['mdResyncSize'] ?? 0) * 1024;
        return ['status' => (int)($v['sbSyncExit'] ?? 0), 'errors' => (int)$v['sbSyncErrs'], 'duration' => $d,
                'speed' => $size > 0 && $d > 0 ? dbn_fmt_bytes($size / $d) . '/s' : '', 'date' => date('Y-m-d H:i', $t1)];
    }
    return dbn_last_parity();
}

// $j: api_fetch.php output; $st: alert state
function dbn_e_weekly($lang, $j, $st, $smartWarn, $ackLines = []) {
    $arr = $j['array'] ?? [];
    $rows = [];
    foreach (array_merge($arr['parity'] ?? [], $arr['disks'] ?? [], $j['pools'] ?? []) as $r) { $rows[] = $r; }
    $tbl = [];
    $hot = null; $full = null;
    foreach ($rows as $r) {
        $line = str_pad($r['label'], 8) . str_pad($r['pct'] !== null ? number_format($r['pct'], 1, '.', '') . '%' : '', 8) . ($r['temp'] !== null ? $r['temp'] . ' °C' : '');
        $tbl[] = rtrim($line);
        if ($r['temp'] !== null && ($hot === null || $r['temp'] > $hot['temp'])) { $hot = $r; }
        if ($r['pct'] !== null && ($full === null || $r['pct'] > $full['pct'])) { $full = $r; }
    }
    $cut = time() - 7 * 86400; $n = 0;
    foreach ($st['log'] ?? [] as $x) { if ($x[0] >= $cut) { $n++; } }
    $pc = dbn_last_parity();
    $pcText = $pc['date'] !== '' ? $pc['date'] . ($pc['errors'] !== null ? ' · ' . dbn_t($lang, 'errors') . ': ' . $pc['errors'] : '') : '–';
    $chip = (string)($arr['chip'][1] ?? '');
    $sum = $j['summary'] ?? [];
    return dbn_embed($chip !== '' && ($arr['chip'][0] ?? '') === 'g' ? 'g' : (($arr['chip'][0] ?? '') === 'rd' ? 'rd' : 'i'), '📊 ' . dbn_t($lang, 'wk'), '```' . "\n" . implode("\n", $tbl) . "\n" . '```', [
        [dbn_t($lang, 'array'), $chip, true],
        [dbn_t($lang, 'used'), dbn_fmt_bytes($sum['used'] ?? 0) . ' / ' . dbn_fmt_bytes($sum['size'] ?? 0), true],
        [dbn_t($lang, 'hottest'), $hot ? $hot['label'] . ' · ' . $hot['temp'] . ' °C' : '–', true],
        [dbn_t($lang, 'fullest'), $full ? $full['label'] . ' · ' . number_format($full['pct'], 1, '.', '') . '%' : '–', true],
        [dbn_t($lang, 'smartw'), $smartWarn > 0 ? (string)$smartWarn : dbn_t($lang, 'none'), true],
        [dbn_t($lang, 'alerts7'), $n > 0 ? (string)$n : dbn_t($lang, 'none'), true],
        [dbn_t($lang, 'lastpc'), $pcText],
        [dbn_t($lang, 'ack_head'), $ackLines ? implode("\n", $ackLines) : '']], dbn_t($lang, 'wk'));
}

// Builds the weekly summary from the live data; null when the data is not available
function dbn_weekly_now($cfg) {
    $raw = (string)@shell_exec(escapeshellarg(db_php()) . ' ' . escapeshellarg(__DIR__ . '/api_fetch.php') . ' 2>/dev/null');
    $j = json_decode($raw, true);
    if (!is_array($j) || empty($j['ok'])) { return null; }
    $sw = 0;
    $ack = db_ack();
    foreach (db_smart() as $nm => $s) { $i = db_smart_issues($nm, $s, $ack); if ($i['attrs'] || $i['nvme'] || $i['health']) { $sw++; } }
    // warnings that were acknowledged and have not changed since: still on the disk, so the summary lists them
    $lang = dbn_lang($cfg);
    $smart = db_smart(); $dk = db_disks(); $lines = [];
    foreach ($ack as $nm => $ae) {
        if (!isset($dk[$nm]) || !isset($smart[$nm])) { continue; }
        $days = (int)floor((time() - (int)($ae['t'] ?? 0)) / 86400);
        $when = date('Y-m-d', (int)($ae['t'] ?? 0)) . ' (' . ($days > 0 ? dbn_t($lang, 'ack_days', [$days]) : dbn_t($lang, 'ack_today')) . ')';
        foreach ($smart[$nm]['attrs'] ?? [] as $x) {
            $base = $ae['raw'][(string)$x[0]] ?? null;
            if ($base === null || (int)$x[3] <= 0 || (int)$x[3] > (int)$base) { continue; }
            $lines[] = '**' . strtoupper($nm) . '** · `' . $x[0] . '` ' . $x[1] . ' · ' . ($x[2] ? dbn_t($lang, 'pre') : dbn_t($lang, 'old')) . ' · ' . dbn_t($lang, 'raw') . ' ' . $x[3] . ' · ' . dbn_t($lang, 'ack_on') . ' ' . $when . ' · ' . dbn_t($lang, 'ack_same');
        }
    }
    return dbn_e_weekly($lang, $j, dbn_state(), $sw, $lines);
}
