<?php
// Diskbox Discord helpers: webhook post, embeds, and alert state with de-duplication.
// Alert state lives on the flash drive and is written only when it changes; short-lived counters live in /tmp.
const DBN_STATE = '/boot/config/plugins/diskbox/notify_state.json';
const DBN_TMP   = '/tmp/diskbox_notify_tmp.json';

function dbn_hook_ok($u) { return (bool)preg_match('#^https://(?:discord|discordapp)\.com/api/webhooks/\d{15,25}/[A-Za-z0-9_\-]{20,120}$#', (string)$u); }

require_once __DIR__ . '/lang.php';
// the Discord language is shared by all the plugins (lang.php; Settings, the Language block of any of them)
function dbn_lang($cfg) { return mt_lang_discord(); }

// Returns ['ok'|'retry'|'fail', message]
// The text that pings someone on red alerts: a user ID, "role:<id>", "@here" or "@everyone" (setting dc_ping)
function dbn_ping_text($v) {
    $v = trim((string)$v);
    if (preg_match('/^\d{15,25}$/', $v)) { return '<@' . $v . '>'; }
    if (preg_match('/^role:(\d{15,25})$/', $v, $m)) { return '<@&' . $m[1] . '>'; }
    if ($v === '@here' || $v === '@everyone') { return $v; }
    return '';
}
function dbn_ping_set($v = null) {
    static $p = '';
    if ($v !== null) { $p = dbn_ping_text($v); }
    return $p;
}

function dbn_post($hook, $embeds, $content = '', $attempt = 0) {
    static $last = 0.0;
    $gap = microtime(true) - $last;
    if ($gap < 0.5) { usleep((int)((0.5 - $gap) * 1000000)); }
    $am = ['parse' => []];
    if ($content !== '') {
        if (preg_match('/^<@(\d+)>$/', $content, $m)) { $am = ['parse' => [], 'users' => [$m[1]]]; }
        elseif (preg_match('/^<@&(\d+)>$/', $content, $m)) { $am = ['parse' => [], 'roles' => [$m[1]]]; }
        else { $am = ['parse' => ['everyone']]; }
    }
    $pl = ['embeds' => $embeds, 'allowed_mentions' => $am];
    if ($content !== '') { $pl['content'] = $content; }
    $json = json_encode($pl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    $ch = curl_init($hook);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 20,
        CURLOPT_POSTFIELDS => $json, CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_errno($ch);
    curl_close($ch);
    $last = microtime(true);
    if ($err) { return ['retry', 'network error ' . $err]; }
    if ($code === 200 || $code === 204) { return ['ok', '']; }
    if ($code === 429) {
        $d = json_decode((string)$body, true);
        $wait = is_array($d) && isset($d['retry_after']) ? (float)$d['retry_after'] : 2.0;
        if ($attempt < 1 && $wait <= 5.0) { usleep((int)(($wait + 0.3) * 1000000)); return dbn_post($hook, $embeds, $content, $attempt + 1); }
        return ['retry', 'rate limited'];
    }
    if ($code >= 500 || $code === 0) { return ['retry', 'Discord HTTP ' . $code]; }
    $d = json_decode((string)$body, true);
    return ['fail', 'Discord HTTP ' . $code . (is_array($d) && !empty($d['message']) ? ' (' . $d['message'] . ')' : '')];
}

// $level: rd red, a amber, g green, i blue. $fields: [[name, value, inline?], ...]
function dbn_embed($level, $title, $desc = '', $fields = [], $footer = '') {
    $col = ['rd' => 0xE5534B, 'a' => 0xD9921F, 'g' => 0x3FA24A, 'i' => 0x4A90E2];
    $host = @gethostname();
    $e = ['title' => mb_substr($title, 0, 250), 'color' => $col[$level] ?? $col['i'], 'timestamp' => gmdate('c'),
          'footer' => ['text' => trim(($host ? $host . ' · ' : '') . 'Diskbox' . ($footer !== '' ? ' · ' . $footer : ''))]];
    if ($desc !== '') { $e['description'] = mb_substr($desc, 0, 3900); }
    $f = [];
    foreach ($fields as $x) {
        if ($x[1] === '' || $x[1] === null) { continue; }
        $f[] = ['name' => mb_substr((string)$x[0], 0, 250), 'value' => mb_substr((string)$x[1], 0, 1000), 'inline' => !empty($x[2])];
    }
    if ($f) { $e['fields'] = array_slice($f, 0, 24); }
    return $e;
}

function dbn_load($file, $def) {
    $j = is_file($file) ? json_decode((string)@file_get_contents($file), true) : null;
    return is_array($j) ? array_merge($def, $j) : $def;
}
function dbn_state() { return dbn_load(DBN_STATE, ['k' => [], 'log' => [], 'misc' => []]); }
function dbn_tmp() { return dbn_load(DBN_TMP, ['deb' => [], 'x' => []]); }
function dbn_write($file, $data) {
    $dir = dirname($file);
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    $tmp = $file . '.tmp';
    if (@file_put_contents($tmp, json_encode($data)) === false) { return; }
    if (!@rename($tmp, $file)) { @file_put_contents($file, json_encode($data)); @unlink($tmp); }
}

// Remembers one sent alert in the 7-day log (used by the weekly summary)
function dbn_log(&$st, $key, $level) {
    $st['log'][] = [time(), $key, $level];
    $cut = time() - 14 * 86400;
    $st['log'] = array_values(array_filter($st['log'], function ($x) use ($cut) { return $x[0] >= $cut; }));
    if (count($st['log']) > 300) { $st['log'] = array_slice($st['log'], -300); }
}

// One alert with its life cycle. $level: '' (fine), 'a' or 'rd'. $sig: changes when an active alert gets worse in another way.
// $mk('alert'|'resolved') returns an embed. $o: need = consecutive checks before the first alert, resolve = send an all-clear,
// remind = hours between repeats of a red alert (0 = never).
function dbn_track(&$st, &$tm, $key, $level, $sig, $mk, $hook, $o = []) {
    $need = (int)($o['need'] ?? 1); $resolve = $o['resolve'] ?? true; $remind = (int)($o['remind'] ?? 0);
    $cur = $st['k'][$key] ?? null;
    if ($level !== '') {
        $tm['deb'][$key] = (int)($tm['deb'][$key] ?? 0) + 1;
        if ($tm['deb'][$key] < $need) { return; }
        $send = false;
        if ($cur === null) { $send = true; }
        elseif ($level === 'rd' && $cur['l'] === 'a') { $send = true; }
        elseif ((string)$sig !== (string)$cur['s']) { $send = true; }
        elseif ($level === 'rd' && $remind > 0 && time() - (int)$cur['t'] >= $remind * 3600) { $send = true; }
        if ($send) {
            $r = dbn_post($hook, [$mk('alert')], $level === 'rd' ? dbn_ping_set() : '');
            if ($r[0] === 'retry') { $tm['x']['err'] = $r[1]; return; }
            if ($r[0] === 'fail') { $tm['x']['err'] = $r[1]; }
            $st['k'][$key] = ['l' => $level, 's' => (string)$sig, 't' => time()];
            dbn_log($st, $key, $level);
        } elseif ($cur !== null && $cur['l'] !== $level) {
            $st['k'][$key]['l'] = $level;
        }
    } else {
        $tm['deb'][$key] = 0;
        if ($cur !== null) {
            if ($resolve) {
                $r = dbn_post($hook, [$mk('resolved')]);
                if ($r[0] === 'retry') { $tm['x']['err'] = $r[1]; return; }
                if ($r[0] === 'fail') { $tm['x']['err'] = $r[1]; }
            }
            unset($st['k'][$key]);
        }
    }
}

function dbn_cur_level($st, $key) { return $st['k'][$key]['l'] ?? ''; }

function dbn_fmt_bytes($b) {
    $b = (float)$b;
    if ($b >= 1e12) { return number_format($b / 1e12, $b >= 1e13 ? 1 : 2, '.', '') . ' TB'; }
    if ($b >= 1e9) { return number_format($b / 1e9, $b >= 1e11 ? 0 : 1, '.', '') . ' GB'; }
    if ($b >= 1e6) { return number_format($b / 1e6, 0, '.', '') . ' MB'; }
    return number_format($b / 1e3, 0, '.', '') . ' KB';
}

function dbn_fmt_dur($s) {
    $s = (int)$s; $d = intdiv($s, 86400); $h = intdiv($s % 86400, 3600); $m = intdiv($s % 3600, 60);
    return $d > 0 ? $d . ' d ' . $h . ' h' : ($h > 0 ? $h . ' h ' . $m . ' m' : $m . ' m');
}
