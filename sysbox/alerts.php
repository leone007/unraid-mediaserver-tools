<?php
// Sysbox Discord alerts: texts (Hungarian and English), data readers and message builders.

function sxn_t($lang, $k, $a = []) {
    static $T = [
      'hu' => [
        'ct_down' => '%s leállt', 'ct_down_d' => 'A konténer váratlanul leállt.', 'ct_up' => '%s újra fut',
        'exit' => 'Kilépési kód', 'oom' => 'Elfogyott a memória (OOM)', 'yes' => 'igen', 'status' => 'Állapot',
        'ct_loop' => '%s újra és újra újraindul', 'ct_loop_d' => '%s újraindulás az elmúlt %s percben.', 'ct_calm' => '%s már nem indul újra',
        'ct_unh' => '%s nem egészséges', 'ct_unh_d' => 'A konténer állapotellenőrzése hibát jelez.', 'ct_ok' => '%s újra egészséges',
        'ram_w' => 'Sok memória használt: %s%%', 'ram_c' => 'Majdnem elfogyott a memória: %s%%', 'ram_ok' => 'A memória használata rendben (%s%%)',
        'ram_d' => 'A ZFS cache nélkül számolva, mert az átadja a helyét a programoknak.', 'ram' => 'Memória', 'free' => 'Szabad', 'zfs' => 'ZFS cache',
        'st_w' => '%s telik: %s%%', 'st_c' => '%s majdnem megtelt: %s%%', 'st_ok' => '%s már nem tele (%s%%)',
        'used' => 'Használt', 'freev' => 'Szabad',
        'cpu_w' => 'A CPU melegszik: %s °C', 'cpu_c' => 'A CPU túl meleg: %s °C', 'cpu_ok' => 'A CPU lehűlt (%s °C)', 'limits' => 'Határok', 'limits_v' => 'figyelmeztetés %s °C · kritikus %s °C',
        'boot' => 'A szerver újraindult', 'boot_d' => 'Indulás ideje: %s.', 'seen' => 'Utoljára látva', 'upnow' => 'Üzemidő most', 'offline' => 'Kimaradás kb.',
        'wk' => 'Heti összefoglaló', 'uptime' => 'Üzemidő', 'reboots' => 'Újraindulás (7 nap)', 'alerts' => 'Riasztás (7 nap)', 'none' => 'nincs',
        'cpu_t' => 'CPU hőmérséklet', 'conts' => 'Konténerek', 'running' => 'fut', 'top' => 'Legtöbb memória', 'stor' => 'Tárhely', 'energy' => 'Áramfogyasztás (e hónap)',
        'cost' => 'költség', 'avg_day' => 'Átlagos napi fogyasztás', 'per_day' => 'nap', 'day_avg' => 'napi átlag', 'sample' => 'MINTA', 'test_t' => 'Sysbox teszt', 'test_d' => 'A Discord-értesítés működik.',
      ],
      'en' => [
        'ct_down' => '%s stopped', 'ct_down_d' => 'The container stopped unexpectedly.', 'ct_up' => '%s is running again',
        'exit' => 'Exit code', 'oom' => 'Ran out of memory (OOM)', 'yes' => 'yes', 'status' => 'Status',
        'ct_loop' => '%s keeps restarting', 'ct_loop_d' => '%s restarts in the last %s minutes.', 'ct_calm' => '%s has stopped restarting',
        'ct_unh' => '%s is unhealthy', 'ct_unh_d' => 'The container\'s health check is failing.', 'ct_ok' => '%s is healthy again',
        'ram_w' => 'High memory use: %s%%', 'ram_c' => 'Memory is almost exhausted: %s%%', 'ram_ok' => 'Memory use is fine again (%s%%)',
        'ram_d' => 'Counted without the ZFS cache, which gives way to applications.', 'ram' => 'Memory', 'free' => 'Free', 'zfs' => 'ZFS cache',
        'st_w' => '%s is filling up: %s%%', 'st_c' => '%s is almost full: %s%%', 'st_ok' => '%s is no longer full (%s%%)',
        'used' => 'Used', 'freev' => 'Free',
        'cpu_w' => 'The CPU is running hot: %s °C', 'cpu_c' => 'The CPU is too hot: %s °C', 'cpu_ok' => 'The CPU has cooled down (%s °C)', 'limits' => 'Limits', 'limits_v' => 'warning %s °C · critical %s °C',
        'boot' => 'The server restarted', 'boot_d' => 'Started at %s.', 'seen' => 'Last seen', 'upnow' => 'Uptime now', 'offline' => 'Down for about',
        'wk' => 'Weekly summary', 'uptime' => 'Uptime', 'reboots' => 'Restarts (7 days)', 'alerts' => 'Alerts (7 days)', 'none' => 'none',
        'cpu_t' => 'CPU temperature', 'conts' => 'Containers', 'running' => 'running', 'top' => 'Most memory', 'stor' => 'Storage', 'energy' => 'Energy (this month)',
        'cost' => 'cost', 'avg_day' => 'Average daily use', 'per_day' => 'day', 'day_avg' => 'day average', 'sample' => 'SAMPLE', 'test_t' => 'Sysbox test', 'test_d' => 'Discord notifications work.',
      ],
    ];
    $s = $T[$lang][$k] ?? ($T['en'][$k] ?? $k);
    return $a ? vsprintf($s, $a) : $s;
}

// ---- readers -----------------------------------------------------------------------------------
// name => [restart count, state, exit code, oom killed, health] or null when Docker is not available
function sxn_inspect() {
    $d = sx_docker();
    if ($d === '') { return null; }
    list($rc, $ids) = sx_run(escapeshellarg($d) . ' ps -aq --no-trunc', 8);
    if ($rc !== 0) { return null; }
    $ids = array_values(array_filter(array_map('trim', explode("\n", $ids)), function ($x) { return preg_match('/^[0-9a-f]{12,64}$/', $x); }));
    if (!$ids) { return []; }
    $fmt = "{{.Name}}|{{.RestartCount}}|{{.State.Status}}|{{.State.ExitCode}}|{{.State.OOMKilled}}|{{if .State.Health}}{{.State.Health.Status}}{{else}}-{{end}}";
    list($rc, $txt) = sx_run(escapeshellarg($d) . ' inspect -f ' . escapeshellarg($fmt) . ' ' . implode(' ', array_map('escapeshellarg', $ids)), 15);
    if ($rc !== 0) { return null; }
    $out = [];
    foreach (explode("\n", $txt) as $l) {
        $p = explode('|', trim($l));
        if (count($p) < 6) { continue; }
        $n = ltrim($p[0], '/');
        if (!sx_valid_name($n)) { continue; }
        $out[$n] = ['restart' => (int)$p[1], 'state' => $p[2], 'exit' => (int)$p[3], 'oom' => ($p[4] === 'true'), 'health' => $p[5]];
    }
    return $out;
}

// ---- builders (kind: 'alert' | 'resolved') ------------------------------------------------------
function sxn_e_down($lang, $name, $exit, $oom, $kind) {
    if ($kind === 'resolved') { return sxn_embed('g', '🟢 ' . sxn_t($lang, 'ct_up', [$name])); }
    return sxn_embed('rd', '🔴 🐳 ' . sxn_t($lang, 'ct_down', [$name]), sxn_t($lang, 'ct_down_d'), [
        [sxn_t($lang, 'exit'), (string)$exit, true], [sxn_t($lang, 'oom'), $oom ? sxn_t($lang, 'yes') : '', true]]);
}
function sxn_e_loop($lang, $name, $n, $min, $exit, $kind) {
    if ($kind === 'resolved') { return sxn_embed('g', '🟢 ' . sxn_t($lang, 'ct_calm', [$name])); }
    return sxn_embed('rd', '🔴 🔁 ' . sxn_t($lang, 'ct_loop', [$name]), sxn_t($lang, 'ct_loop_d', [$n, $min]), [[sxn_t($lang, 'exit'), (string)$exit, true]]);
}
function sxn_e_unhealthy($lang, $name, $kind) {
    if ($kind === 'resolved') { return sxn_embed('g', '🟢 ' . sxn_t($lang, 'ct_ok', [$name])); }
    return sxn_embed('a', '🟡 🩺 ' . sxn_t($lang, 'ct_unh', [$name]), sxn_t($lang, 'ct_unh_d'));
}
function sxn_e_ram($lang, $m, $level, $kind) {
    $p = number_format($m['pct'], 0, '.', '');
    if ($kind === 'resolved') { return sxn_embed('g', '🟢 ' . sxn_t($lang, 'ram_ok', [$p])); }
    return sxn_embed($level, ($level === 'rd' ? '🔴 🧠 ' : '🟡 🧠 ') . sxn_t($lang, $level === 'rd' ? 'ram_c' : 'ram_w', [$p]), sxn_t($lang, 'ram_d'), [
        [sxn_t($lang, 'used'), sxn_fmt_bytes($m['app']) . ' / ' . sxn_fmt_bytes($m['total']), true],
        [sxn_t($lang, 'free'), sxn_fmt_bytes($m['avail']), true], [sxn_t($lang, 'zfs'), sxn_fmt_bytes($m['arc']), true]]);
}
function sxn_e_storage($lang, $label, $f, $level, $kind) {
    $p = number_format($f['pct'], 0, '.', '');
    if ($kind === 'resolved') { return sxn_embed('g', '🟢 ' . sxn_t($lang, 'st_ok', [$label, $p])); }
    return sxn_embed($level, ($level === 'rd' ? '🔴 💾 ' : '🟡 💾 ') . sxn_t($lang, $level === 'rd' ? 'st_c' : 'st_w', [$label, $p]), '', [
        [sxn_t($lang, 'used'), sxn_fmt_bytes($f['used']) . ' / ' . sxn_fmt_bytes($f['size']), true], [sxn_t($lang, 'freev'), sxn_fmt_bytes($f['size'] - $f['used']), true]]);
}
function sxn_e_cpu($lang, $temp, $warn, $crit, $level, $kind) {
    if ($kind === 'resolved') { return sxn_embed('g', '🟢 ' . sxn_t($lang, 'cpu_ok', [$temp])); }
    return sxn_embed($level, ($level === 'rd' ? '🔴 🌡️ ' : '🟡 🌡️ ') . sxn_t($lang, $level === 'rd' ? 'cpu_c' : 'cpu_w', [$temp]), '', [
        [sxn_t($lang, 'limits'), sxn_t($lang, 'limits_v', [(int)$warn, (int)$crit])]]);
}
function sxn_e_boot($lang, $bootTs, $seenTs) {
    $f = [[sxn_t($lang, 'upnow'), sxn_fmt_dur(max(0, time() - $bootTs)), true]];
    if ($seenTs > 0) {
        $f[] = [sxn_t($lang, 'seen'), date('Y-m-d H:i', $seenTs), true];
        $gap = $bootTs - $seenTs;
        if ($gap > 0) { $f[] = [sxn_t($lang, 'offline'), '≤ ' . sxn_fmt_dur($gap), true]; }
    }
    return sxn_embed('i', '🔌 ' . sxn_t($lang, 'boot'), sxn_t($lang, 'boot_d', [date('Y-m-d H:i', $bootTs)]), $f);
}

// Top containers by memory right now (name => bytes), best effort
function sxn_top_mem($n = 3) {
    $d = sx_docker();
    if ($d === '') { return []; }
    list($rc, $txt) = sx_run(escapeshellarg($d) . " stats --no-stream --format '{{.Name}}|{{.MemUsage}}'", 20);
    if ($rc !== 0) { return []; }
    $out = [];
    foreach (explode("\n", $txt) as $l) {
        $p = explode('|', trim($l));
        if (count($p) < 2 || !preg_match('/^([\d.]+)\s*([KMGT]?i?B)/i', $p[1], $m)) { continue; }
        $mul = ['b' => 1, 'kib' => 1024, 'mib' => 1048576, 'gib' => 1073741824, 'tib' => 1099511627776, 'kb' => 1e3, 'mb' => 1e6, 'gb' => 1e9, 'tb' => 1e12];
        $out[$p[0]] = (float)$m[1] * ($mul[strtolower($m[2])] ?? 1);
    }
    arsort($out);
    return array_slice($out, 0, $n, true);
}

function sxn_e_weekly($lang, $cfg, $st) {
    $cut = time() - 7 * 86400; $n = 0; $boots = 0; $by = ['ct' => 0, 'ram' => 0, 'st' => 0, 'cpu' => 0];
    foreach ($st['log'] ?? [] as $x) {
        if ($x[0] < $cut) { continue; }
        if ($x[1] === 'boot') { $boots++; continue; }
        $n++;
        $g = preg_match('/^(down|loop|unh):/', $x[1]) ? 'ct' : (strpos($x[1], 'st:') === 0 ? 'st' : ($x[1] === 'ram' ? 'ram' : ($x[1] === 'cpu_temp' ? 'cpu' : '')));
        if ($g !== '') { $by[$g]++; }
    }
    $up = (int)((float)@file_get_contents('/proc/uptime'));
    $m = sx_mem();
    $lines = [];
    foreach ([['BOOT', '/boot'], ['LOG', '/var/log'], ['DOCKER VDISK', '/var/lib/docker']] as $x) {
        if ($x[1] === '/var/lib/docker' && !sx_is_mounted($x[1])) { continue; }
        $f = sx_fs_use($x[1]);
        if ($f) { $lines[] = str_pad($x[0], 13) . number_format($f['pct'], 0, '.', '') . '%'; }
    }
    list($tp,) = sx_temps();
    $ct = sxn_inspect();
    $run = 0; foreach ((array)$ct as $c) { if ($c['state'] === 'running') { $run++; } }
    $top = [];
    foreach (sxn_top_mem(3) as $name => $b) { $top[] = $name . ' · ' . sxn_fmt_bytes($b); }
    $fields = [
        [sxn_t($lang, 'uptime'), sxn_fmt_dur($up), true],
        [sxn_t($lang, 'reboots'), $boots > 0 ? (string)$boots : sxn_t($lang, 'none'), true],
        [sxn_t($lang, 'alerts'), $n > 0 ? $n . ' (' . implode(', ', array_map(function ($k, $v) { return $k . ' ' . $v; }, array_keys(array_filter($by)), array_filter($by))) . ')' : sxn_t($lang, 'none'), true],
        [sxn_t($lang, 'ram'), number_format($m['pct'], 0, '.', '') . '% · ' . sxn_fmt_bytes($m['app']) . ' / ' . sxn_fmt_bytes($m['total']), true],
        [sxn_t($lang, 'cpu_t'), $tp !== null ? $tp . ' °C' : '', true],
        [sxn_t($lang, 'conts'), $ct !== null ? $run . ' / ' . count($ct) . ' ' . sxn_t($lang, 'running') : '', true],
        [sxn_t($lang, 'top'), implode("\n", $top)],
    ];
    if (trim((string)$cfg['socket_host']) !== '') {
        require_once __DIR__ . '/energy.php';
        list($sk,) = sx_socket_fetch($cfg['socket_host'], (int)$cfg['socket_id']);
        if ($sk && $sk['total_wh'] !== null) {
            $kwh = sx_energy_month_wh($sk['total_wh']) / 1000;
            $t = number_format($kwh, 1, '.', '') . ' kWh';
            if (is_numeric($cfg['kwh_price'])) { $t .= ' · ' . sxn_t($lang, 'cost') . ' ' . number_format($kwh * (float)$cfg['kwh_price'], 0, '.', ' ') . ' ' . $cfg['currency']; }
            $fields[] = [sxn_t($lang, 'energy'), $t];
            sx_energy_days_update($sk['total_wh']);
            $avg = sx_energy_days_avg(7);
            if ($avg === null) {   // no complete day recorded yet: fall back to this month so far
                if ((int)date('j') > 1) { $avg = [$kwh * 1000 / (int)date('j'), 1]; }
            }
            if ($avg !== null) {
                $akwh = $avg[0] / 1000;
                $at = number_format($akwh, 1, '.', '') . ' kWh/' . sxn_t($lang, 'per_day') . ' · ≈ ' . number_format($avg[0] / 24, 0, '.', '') . ' W · ' . $avg[1] . ' ' . sxn_t($lang, 'day_avg');
                if (is_numeric($cfg['kwh_price'])) { $at .= ' · ' . number_format($akwh * (float)$cfg['kwh_price'], 0, '.', ' ') . ' ' . $cfg['currency'] . '/' . sxn_t($lang, 'per_day'); }
                $fields[] = [sxn_t($lang, 'avg_day'), $at];
            }
        }
    }
    return sxn_embed('i', '📊 ' . sxn_t($lang, 'wk'), $lines ? '```' . "\n" . implode("\n", $lines) . "\n" . '```' : '', $fields, sxn_t($lang, 'wk'));
}
