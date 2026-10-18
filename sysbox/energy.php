<?php
// Smart socket (Shelly Gen2 and newer) helpers: wall power and a monthly energy total.

// Returns [array|null, error text]
function sx_socket_fetch($host, $id) {
    if (!preg_match('/^[A-Za-z0-9.\-]+$/', (string)$host)) { return [null, 'invalid socket address']; }
    $ch = curl_init('http://' . $host . '/rpc/Switch.GetStatus?id=' . (int)$id);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 3]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_errno($ch);
    curl_close($ch);
    if ($err) { return [null, 'socket unreachable']; }
    if ($code === 401 || $code === 403) { return [null, 'the socket asks for a password (not supported)']; }
    if ($code !== 200) { return [null, 'socket answered HTTP ' . $code]; }
    $d = json_decode((string)$body, true);
    if (!is_array($d) || !isset($d['apower'])) { return [null, 'unexpected answer from the socket']; }
    $total = isset($d['aenergy']['total']) ? (float)$d['aenergy']['total'] : null;
    return [['power' => (float)$d['apower'], 'total_wh' => $total, 'output' => !empty($d['output'])], ''];
}

// The socket's type for the tile, from its model ("PlugSG3" -> "Shelly Plug S Gen3").
// Asked at most once an hour; the answer is kept in /tmp.
function sx_socket_name($host) {
    $f = '/tmp/sysbox_socket_name.json';
    $c = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
    if (is_array($c) && ($c['host'] ?? '') === $host && time() - (int)($c['t'] ?? 0) < 3600) { return (string)$c['name']; }
    if (!preg_match('/^[A-Za-z0-9.\-]+$/', (string)$host)) { return ''; }
    $ch = curl_init('http://' . $host . '/rpc/Shelly.GetDeviceInfo');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 3]);
    $d = json_decode((string)curl_exec($ch), true);
    $ok = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
    curl_close($ch);
    if (!$ok || !is_array($d)) { return is_array($c) ? (string)$c['name'] : ''; }
    $name = '';
    if (!empty($d['app'])) {
        $m = preg_replace('/G(\d+)$/', ' Gen$1', (string)$d['app']);   // PlugSG3 -> PlugS Gen3
        $m = preg_replace('/([a-z])([A-Z])/', '$1 $2', $m);              // PlugS -> Plug S
        $name = 'Shelly ' . $m;
    }
    $name = function_exists('mb_substr') ? mb_substr($name, 0, 40) : substr($name, 0, 40);
    @file_put_contents($f, json_encode(['host' => $host, 't' => time(), 'name' => $name]));
    return $name;
}

// Energy used since the 1st of the month, in Wh. The socket keeps a running counter; the counter value at
// the start of the month is remembered on the flash drive. It is written only when the month changes or
// the counter is reset, never on every reading.
function sx_energy_month_wh($total) {
    $sf    = '/boot/config/plugins/sysbox/energy.json';
    $lf    = '/tmp/sysbox_energy_last.json';
    $month = date('Y-m');
    $st    = is_file($sf) ? json_decode((string)@file_get_contents($sf), true) : null;
    $last  = is_file($lf) ? json_decode((string)@file_get_contents($lf), true) : null;
    $dirty = false;

    if (!is_array($st) || !isset($st['month']) || !isset($st['base'])) {
        $st = ['month' => $month, 'base' => $total, 'carry' => 0.0];
        $dirty = true;
    } elseif ((string)$st['month'] !== $month) {
        $st = ['month' => $month, 'base' => $total, 'carry' => 0.0];
        $dirty = true;
    }

    // The counter went backwards: it was reset (or the socket was replaced). Keep what was counted so far.
    $ref = (is_array($last) && isset($last['total'])) ? (float)$last['total'] : (float)$st['base'];
    if ($total < $ref - 0.001) {
        $st['carry'] = (float)($st['carry'] ?? 0) + max(0.0, $ref - (float)$st['base']);
        $st['base']  = 0.0;
        $dirty = true;
    }

    if ($dirty) {
        $tmp = $sf . '.tmp';
        if (@file_put_contents($tmp, json_encode($st)) !== false) {
            if (!@rename($tmp, $sf)) { @file_put_contents($sf, json_encode($st)); @unlink($tmp); }
        }
    }
    @file_put_contents($lf, json_encode(['total' => $total, 't' => time()]));
    return max(0.0, (float)($st['carry'] ?? 0) + $total - (float)$st['base']);
}

// Daily consumption (Wh per day) for the last 40 days, built from the socket's counter. Works on its own running
// total, so a counter reset does not lose the day. Updated every 10 minutes by sample.php; kept in /tmp and copied
// to the flash drive once an hour.
function sx_energy_days_update($total) {
    $tf = '/tmp/sysbox_edays.json';
    $ff = '/boot/config/plugins/sysbox/energy_days.json';
    $st = null;
    foreach ([$tf, $ff] as $f) {
        if (is_file($f)) { $j = json_decode((string)@file_get_contents($f), true); if (is_array($j) && isset($j['days'])) { $st = $j; break; } }
    }
    if (!is_array($st)) { $st = ['last' => null, 'days' => [], 'flushed' => 0]; }
    $today = date('Y-m-d');
    if ($st['last'] !== null) {
        $d = ($total >= (float)$st['last']) ? $total - (float)$st['last'] : $total;   // a smaller counter means it was reset
        $st['days'][$today] = (float)($st['days'][$today] ?? 0) + max(0.0, $d);
    } else {
        $st['days'][$today] = (float)($st['days'][$today] ?? 0);
    }
    $st['last'] = $total;
    ksort($st['days']);
    $st['days'] = array_slice($st['days'], -40, null, true);
    @file_put_contents($tf, json_encode($st));
    if (time() - (int)($st['flushed'] ?? 0) >= 3600) {
        $st['flushed'] = time();
        $tmp = $ff . '.tmp';
        if (@file_put_contents($tmp, json_encode($st)) !== false) { if (!@rename($tmp, $ff)) { @file_put_contents($ff, json_encode($st)); @unlink($tmp); } }
        @file_put_contents($tf, json_encode($st));
    }
}

// Today's consumption (Wh): what the 10-minute sampler counted since midnight plus the counter's rise since its last sample.
// Read-only: the sampler alone writes the daily file. Null before the sampler has run.
function sx_energy_today_wh($total) {
    foreach (['/tmp/sysbox_edays.json', '/boot/config/plugins/sysbox/energy_days.json'] as $f) {
        if (!is_file($f)) { continue; }
        $j = json_decode((string)@file_get_contents($f), true);
        if (!is_array($j) || !isset($j['days'])) { continue; }
        $wh = (float)($j['days'][date('Y-m-d')] ?? 0);
        if ($j['last'] !== null && $total >= (float)$j['last']) { $wh += $total - (float)$j['last']; }
        return $wh;
    }
    return null;
}

// Average daily use in Wh over up to the last $n complete days (today is left out); [average Wh, days counted] or null
function sx_energy_days_avg($n = 7) {
    foreach (['/tmp/sysbox_edays.json', '/boot/config/plugins/sysbox/energy_days.json'] as $f) {
        if (!is_file($f)) { continue; }
        $j = json_decode((string)@file_get_contents($f), true);
        if (!is_array($j) || empty($j['days'])) { continue; }
        $vals = [];
        for ($i = 1; $i <= $n; $i++) {
            $k = date('Y-m-d', strtotime("-$i day"));
            if (isset($j['days'][$k])) { $vals[] = (float)$j['days'][$k]; }
        }
        if ($vals) { return [array_sum($vals) / count($vals), count($vals)]; }
    }
    return null;
}
