<?php
require_once __DIR__ . '/i18n.php';
// the socket notes are shown as the tooltip of Socket · n/a on the tile (dictionary lang/en.json)
function sb_energy_t($k, $v = []) { static $t = null; if ($t === null) { $t = mt_i18n_t(__DIR__ . '/lang'); } return $t($k, $v); }
// Smart socket (Shelly Gen2 and newer) helpers: wall power and a monthly energy total.

// Returns [array|null, error text]
function sb_socket_fetch($host, $id) {
    if (!preg_match('/^[A-Za-z0-9.\-]+$/', (string)$host)) { return [null, sb_energy_t('sock.invalid')]; }
    $ch = curl_init('http://' . $host . '/rpc/Switch.GetStatus?id=' . (int)$id);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 3]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_errno($ch);
    curl_close($ch);
    if ($err) { return [null, sb_energy_t('sock.down')]; }
    if ($code === 401 || $code === 403) { return [null, sb_energy_t('sock.password')]; }
    if ($code !== 200) { return [null, sb_energy_t('sock.http', ['code' => $code])]; }
    $d = json_decode((string)$body, true);
    if (!is_array($d) || !isset($d['apower'])) { return [null, sb_energy_t('sock.bad')]; }
    $total = isset($d['aenergy']['total']) ? (float)$d['aenergy']['total'] : null;
    return [['power' => (float)$d['apower'], 'total_wh' => $total, 'output' => !empty($d['output'])], ''];
}

// Energy used since the 1st of the month, in Wh. The socket keeps a running counter; the counter value at
// the start of the month is remembered on the flash drive. It is written only when the month changes or
// the counter is reset, never on every reading.
function sb_energy_month_wh($total) {
    $sf    = '/boot/config/plugins/streambox/energy.json';
    $lf    = '/tmp/streambox_energy_last.json';
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
        $st['base']  = 0.0;   // the counter restarted from zero
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
