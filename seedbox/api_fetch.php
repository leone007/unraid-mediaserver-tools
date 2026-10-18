<?php
header('Content-Type: application/json');
header('Cache-Control: no-store');

$cfg = [
    'host' => '127.0.0.1', 'port' => '8080', 'user' => '', 'pass' => '', 'webui_url' => '',
    'rows' => '6', 'max_attention' => '15', 'show_bar' => '1',
    'col_dl' => '1', 'col_ul' => '1', 'col_eta' => '1',
];
$file = '/boot/config/plugins/qbittorrent_widget/settings.cfg';
if (is_file($file)) {
    $c = @parse_ini_file($file, false, INI_SCANNER_RAW); // RAW: a password may hold ${...} or backslashes; the normal mode would expand them
    if (is_array($c)) { $cfg = array_merge($cfg, $c); }
}

function qbt_flag($v) {
    return !in_array(strtolower(trim((string)$v)), ['0', 'no', 'false', 'off', ''], true);
}

function qbt_dur($s) {
    $s = max(0, (int)$s);
    $d = intdiv($s, 86400);
    $h = intdiv($s % 86400, 3600);
    $m = intdiv($s % 3600, 60);
    if ($d > 0) { return $d . 'd ' . $h . 'h'; }
    if ($h > 0) { return $h . 'h ' . str_pad((string)$m, 2, '0', STR_PAD_LEFT) . 'm'; }
    if ($m > 0) { return $m . 'm'; }
    return $s . 's';
}

$host = preg_match('/^[A-Za-z0-9.\-]+$/', (string)$cfg['host']) ? $cfg['host'] : '127.0.0.1';
$port = (int)$cfg['port'];
if ($port < 1 || $port > 65535) { $port = 8080; }
$rows     = max(1, min(50, (int)$cfg['rows']));
$maxAtt   = max(1, min(50, (int)$cfg['max_attention']));
$webui    = trim((string)$cfg['webui_url']);
if (!preg_match('#^https?://[^\s"<>]+$#i', $webui)) { $webui = ''; }

$base = "http://$host:$port";
require_once __DIR__ . '/moveprog.php';
require_once __DIR__ . '/i18n.php';
$qx_T = mt_i18n_t(__DIR__ . '/lang');   // texts shown on the tile (lang/en.json)
$jar  = '/tmp/qbt_widget_cookie.txt';

// UI preferences are returned with every response, even errors
$ui = [
    'cols' => [
        'dl'  => qbt_flag($cfg['col_dl']),
        'ul'  => qbt_flag($cfg['col_ul']),
        'eta' => qbt_flag($cfg['col_eta']),
    ],
    'bar'  => qbt_flag($cfg['show_bar']),
    'port' => $port,
    'url'  => $webui,
];

function out($arr) {
    global $ui;
    $arr['ui'] = $ui;
    echo json_encode($arr, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
    exit;
}

function qbt($url, $base, $jar, $post = null) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT        => 3,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_HTTPHEADER     => ['Referer: ' . $base, 'Origin: ' . $base],
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    }
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_errno($ch);
    curl_close($ch);
    @chmod($jar, 0600);
    return [$body, $code, $err];
}

$did_login = false;

// GET an API path; logs in on 401/403 and retries once. After a failed login it backs
// off for 5 minutes, because qBittorrent bans clients after repeated failed logins.
function qbt_api($path) {
    global $base, $jar, $cfg, $did_login;
    $mark = '/tmp/qbt_widget_authfail';
    $sig  = md5($cfg['user'] . '|' . $cfg['pass'] . '|' . $base);

    list($b, $c, $e) = qbt($base . $path, $base, $jar);
    if ($e || ($c !== 401 && $c !== 403)) { return [$b, $c, $e]; }
    if ($cfg['user'] === '') { return ['', 403, 0]; }
    if (is_file($mark) && trim((string)@file_get_contents($mark)) === $sig && (time() - (int)@filemtime($mark)) < 300) {
        return ['', 403, 0];
    }
    if ($did_login) { return ['', 403, 0]; }
    $did_login = true;

    list($lb, $lc, $le) = qbt($base . '/api/v2/auth/login', $base, $jar,
        http_build_query(['username' => $cfg['user'], 'password' => $cfg['pass']]));
    if ($le) { return ['', 0, $le]; }
    if ($lc !== 200 || trim((string)$lb) !== 'Ok.') {
        @file_put_contents($mark, $sig);
        @chmod($mark, 0600);
        return ['', 403, 0];
    }
    @unlink($mark);
    return qbt($base . $path, $base, $jar);
}

// ---- Fetch ------------------------------------------------------------
list($body, $code, $err) = qbt_api('/api/v2/torrents/info');
if ($err) { out(['ok' => false, 'error' => 'unreachable']); }
if ($code === 401 || $code === 403) { out(['ok' => false, 'error' => 'auth']); }
if ($code !== 200) { out(['ok' => false, 'error' => 'http_' . $code]); }

$data = json_decode((string)$body, true);
if (!is_array($data)) { out(['ok' => false, 'error' => 'bad_response']); }

// Connection status + global speeds (ignore failures)
$conn = null;
$global = null;
list($gb, $gc, $ge) = qbt_api('/api/v2/transfer/info');
if (!$ge && $gc === 200) {
    $g = json_decode((string)$gb, true);
    if (is_array($g)) {
        $conn   = isset($g['connection_status']) ? (string)$g['connection_status'] : null;
        $global = ['dl' => (int)($g['dl_info_speed'] ?? 0), 'ul' => (int)($g['up_info_speed'] ?? 0)];
    }
}
// ---- Classify ---------------------------------------------------------
$now = time();
$att = [];
$xfer = [];
$counts = ['total' => 0, 'seeding' => 0, 'downloading' => 0, 'busy' => 0];

foreach ($data as $t) {
    $state = (string)($t['state'] ?? '');
    $name  = (string)($t['name'] ?? '?');
    $hash  = (string)($t['hash'] ?? '');
    $prog  = (float)($t['progress'] ?? 0);
    $dl    = (int)($t['dlspeed'] ?? 0);
    $up    = (int)($t['upspeed'] ?? 0);
    if ($dl < 50) { $dl = 0; }   // a few bytes per second is protocol chatter, not a transfer
    if ($up < 50) { $up = 0; }
    $counts['total']++;

    $issue = null;   // [severity, kind, badge, reason]
    switch ($state) {
        case 'error':
            $issue = ['red', 'error', $qx_T('iss.error'), $qx_T('iss.error_why')];
            break;
        case 'missingFiles':
            $issue = ['red', 'missing', $qx_T('iss.missing'), $qx_T('iss.missing_why')];
            break;
        case 'pausedUP':
        case 'stoppedUP':
            $issue = ['red', 'paused', ($state === 'pausedUP') ? $qx_T('iss.paused') : $qx_T('iss.stopped'), $qx_T('iss.not_seeding')];
            break;
        case 'pausedDL':
        case 'stoppedDL':
            $issue = ['red', 'paused', ($state === 'pausedDL') ? $qx_T('iss.paused') : $qx_T('iss.stopped'), $qx_T('iss.dl_stopped')];
            break;
        case 'queuedDL':
        case 'queuedUP':
            $issue = ['amber', 'queued', $qx_T('iss.queued'), $qx_T('iss.queued_why')];
            break;
        case 'unknown':
            $issue = ['red', 'unknown', $qx_T('iss.unknown'), $qx_T('iss.unknown_why')];
            break;
    }

    if ($issue !== null) {
        $att[] = ['name' => $name, 'sev' => $issue[0], 'kind' => $issue[1], 'badge' => $issue[2],
                  'reason' => $issue[3], 'hash' => $hash];
        continue;
    }

    // Healthy: counted, and listed only while actually moving data
    if (in_array($state, ['uploading', 'stalledUP', 'forcedUP'], true)) { $counts['seeding']++; }
    if (in_array($state, ['downloading', 'forcedDL', 'metaDL', 'forcedMetaDL', 'stalledDL'], true)) { $counts['downloading']++; }

    $busyLabel = null;
    if (in_array($state, ['checkingDL', 'checkingUP', 'checkingResumeData'], true)) { $busyLabel = 'Checking'; }
    elseif ($state === 'moving') { $busyLabel = 'Moving'; }
    elseif ($state === 'allocating') { $busyLabel = 'Allocating'; }
    if ($busyLabel !== null) {
        $counts['busy']++;
        $bp = $prog; $be = 8640000; $bw = false;
        if ($state === 'moving') { [$bp, $be, $bw] = st_move_info($t); if ($be < 0) { $be = 8640000; } } // a move has no percentage of its own: measured at the destination
        $xfer[] = [
            'name'      => $name,
            'progress'  => $bp,
            'dl'        => 0,
            'ul'        => 0,
            'eta'       => $be,
            'size'      => (int)($t['size'] ?? 0),
            'completed' => (int)($t['completed'] ?? 0),
            'dir'       => 'busy',
            'label'     => $busyLabel,
            'wait'      => $bw,
        ];
        continue;
    }

    if ($dl > 0 || $up > 0) {
        $xfer[] = [
            'name'      => $name,
            'progress'  => $prog,
            'dl'        => $dl,
            'ul'        => $up,
            'eta'       => (int)($t['eta'] ?? 8640000),
            'size'      => (int)($t['size'] ?? 0),
            'completed' => (int)($t['completed'] ?? 0),
            'dir'       => ($dl > 0) ? 'down' : 'up',
        ];
    }
}

$tools = []; $rep = [];
// Season Repacker: seasons being replaced by a season pack (a row while it runs, and for 15 minutes after it is done)
$wd0 = getenv('DBX_ST_DIR'); $wd0 = ($wd0 !== false && $wd0 !== '') ? $wd0 : '/boot/config/plugins/qbittorrent_widget';
$sf0 = getenv('DBX_ST_DIR') ? $wd0 . '/tools_state_live.json' : '/tmp/qbt_tools_state.json'; if (!is_file($sf0)) { $sf0 = $wd0 . '/tools_state.json'; } // the live copy has the fresh progress; the flash copy after a reboot
$ws0 = is_file($sf0) ? json_decode((string)@file_get_contents($sf0), true) : null;
if (is_array($ws0)) {
    $byh = []; foreach ($data as $t) { $byh[strtolower((string)($t['hash'] ?? ''))] = $t; }
    $lbl = ['grabbed' => $qx_T('rep.grabbed'), 'downloading' => $qx_T('rep.downloading'), 'importing' => $qx_T('rep.importing'), 'imported' => $qx_T('rep.imported'), 'done' => $qx_T('rep.done'), 'blocked' => $qx_T('rep.blocked')];
    foreach (array_reverse((array)($ws0['packjobs'] ?? [])) as $pj) {
        $st = (string)($pj['stage'] ?? ''); $fin = in_array($st, ['done', 'failed'], true);
        if ($fin && ($st === 'failed' || time() - (int)($pj['t'] ?? 0) > 900)) { continue; }
        $nm = $pj['title'] . ' S' . str_pad((string)(int)$pj['season'], 2, '0', STR_PAD_LEFT);
        $pt = $byh[strtolower((string)($pj['hash'] ?? ''))] ?? null;
        $pp = $st === 'done' || $st === 'imported' || $st === 'importing' ? 1.0 : ($pt ? (float)$pt['progress'] : 0.0);
        $pw = false; if ($pt && (string)$pt['state'] === 'moving') { [$pp, $pe, $pw] = st_move_info($pt); }
        $badge = $lbl[$st] ?? $st; $col = ['grabbed' => 'grey', 'downloading' => 'orange', 'importing' => 'blue', 'imported' => 'blue', 'done' => 'green', 'blocked' => 'red'][$st] ?? 'grey';
        if ($pt && (string)$pt['state'] === 'moving' && in_array($st, ['grabbed', 'downloading'], true)) { $badge = $qx_T('rep.moving'); $col = 'grey'; }
        $why = $nm;
        if ($pt && in_array($st, ['grabbed', 'downloading'], true)) { $why .= $pw ? ' · ' . $qx_T('rep.wait_move') : ' · ' . number_format($pp * 100, 1, '.', '') . '%'; }
        elseif ($st === 'importing') { $why .= ': ' . $qx_T('rep.wait_sonarr'); }
        elseif ($st === 'blocked') { $why .= ': ' . (string)($pj['msg'] ?? ''); }
        $rep[] = ['name' => 'Season Repacker', 'col' => $col, 'badge' => $badge, 'reason' => $why,
            'tip' => $fin ? $qx_T('rep.tip_done', ['n' => $nm]) : $qx_T('rep.tip_run', ['n' => $nm]), 'href' => '/Settings/SeedboxTools#stt-t-rep'];
    }
}

// ---- Needs attention: sort, connection row, cap ------------------------
usort($att, function ($a, $b) {
    if ($a['sev'] !== $b['sev']) { return ($a['sev'] === 'red') ? -1 : 1; }
    return strcasecmp($a['name'], $b['name']);
});

// Hash is not needed by the browser
foreach ($att as $i => $a) { unset($att[$i]['hash']); }

usort($att, function ($a, $b) {
    if ($a['sev'] !== $b['sev']) { return ($a['sev'] === 'red') ? -1 : 1; }
    return 0;
});

if ($conn === 'firewalled') {
    array_unshift($att, ['name' => $qx_T('iss.conn_name'), 'sev' => 'amber', 'kind' => 'conn',
        'badge' => $qx_T('pill.firewalled'), 'reason' => $qx_T('iss.firewalled_why')]);
} elseif ($conn === 'disconnected') {
    array_unshift($att, ['name' => $qx_T('iss.conn_name'), 'sev' => 'red', 'kind' => 'disc',
        'badge' => $qx_T('pill.disconnected'), 'reason' => $qx_T('iss.disc_why')]);
}

// ---- Seedbox tools block: three permanent rows (Find my torrent, Shape up (was Update me), Season Repacker) -----------------------
$wdir = getenv('DBX_ST_DIR'); $wdir = ($wdir !== false && $wdir !== '') ? $wdir : '/boot/config/plugins/qbittorrent_widget';
$wst = is_file($sf0) ? json_decode((string)@file_get_contents($sf0), true) : null;
if (!is_array($wst)) { $wst = []; }
$tcfg = is_file($wdir . '/tools.json') ? json_decode((string)@file_get_contents($wdir . '/tools.json'), true) : null; if (!is_array($tcfg)) { $tcfg = []; }
$tagOf = function ($k, $def) use ($tcfg) { $v = trim((string)($tcfg[$k] ?? '')); return $v !== '' ? mb_substr($v, 0, 60) : $def; };
$jr = function ($f) { $x = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null; return is_array($x) ? $x : []; };
$ago = function ($t) use ($qx_T) {
    $d = time() - (int)$t; if ($t <= 0) { return ''; }
    if ($d < 90) { return $qx_T('ago.now'); } if ($d < 5400) { return $qx_T('ago.min', ['n' => round($d / 60)]); }
    if ($d < 129600) { return $qx_T('ago.h', ['n' => round($d / 3600)]); } return $qx_T('ago.days', ['n' => round($d / 86400)]);
};
$locked = function ($n) { $f = @fopen('/tmp/qbt_tools_' . $n . '.lock', 'c'); if (!$f) { return false; } $l = !flock($f, LOCK_EX | LOCK_NB); if (!$l) { flock($f, LOCK_UN); } fclose($f); return $l; };
$running = function ($f) { return (($f['state'] ?? '') === 'running' && time() - (int)($f['t'] ?? 0) < 1800) ? (string)($f['msg'] ?? '') : null; };
$have = []; foreach ($data as $t) { $have[strtolower((string)($t['hash'] ?? ''))] = true; }
// Find my torrent: problems of the last scan (live from the result list when it is still there, else the saved summary)
$fnd = $jr('/tmp/qbt_tools_find.json'); $fs = (array)($wst['sum']['find'] ?? []); $ft = (int)($fs['t'] ?? ($wst['watch']['t'] ?? 0));
$fr = 0; $fn = 0; $fp = 0; $fknown = false;
if (($fnd['state'] ?? '') === 'ready' && isset($fnd['items']) && is_array($fnd['items'])) {
    $fknown = true; $ft = max($ft, (int)($fnd['t'] ?? 0));
    foreach ($fnd['items'] as $it) {
        if (isset($it['path']) && !file_exists((string)$it['path'])) { continue; }
        if (!empty($it['pass'])) { $fp++; }
        elseif (!empty($it['old'])) { if (isset($have[strtolower((string)$it['old'])])) { $fr++; } }
        else { $fn++; }
    }
} elseif ($fs || !empty($wst['watch']['t'])) {
    $fknown = true;
    foreach ((array)($wst['watch']['removed'] ?? []) as $g) { if (isset($have[strtolower((string)($g['hash'] ?? ''))])) { $fr++; } }
    $fn = (int)($fs['nosd'] ?? 0); $fp = (int)($fs['pass'] ?? 0);
}
$ftot = $fr + $fn + $fp; $parts = [];
if ($fr) { $parts[] = $qx_T('tl.n_removed', ['n' => $fr]); } if ($fn) { $parts[] = $qx_T('tl.n_not_seeded', ['n' => $fn]); } if ($fp) { $parts[] = $qx_T('tl.n_passkey', ['n' => $fp]); }
if (($rm = $running($fnd)) !== null) { $fb = [$qx_T('tl.scanning'), 'orange', $rm, '']; }
elseif (!$fknown) { $fb = [$qx_T('tl.not_scanned'), 'grey', '']; }
elseif ($ftot > 0) { $fb = [$qx_T('tl.to_fix', ['n' => $ftot]), 'amber', implode(' · ', $parts), $qx_T('tl.scanned', ['t' => $ago($ft)])]; }
else { $fb = [$qx_T('tl.all_seeded'), 'grey', $qx_T('tl.scanned', ['t' => $ago($ft)])]; }
$tools[] = ['_k' => 'find', '_rel' => $fb[1] !== 'grey' || !$fknown, '_t' => $ft, 'name' => 'Find my torrent', 'tag' => $tagOf('tag_find', $qx_T('tl.tag_find')), 'tagtip' => $qx_T('tl.tip_find'),
    'col' => $fb[1], 'badge' => $fb[0], 'reason' => $fb[2], 'when' => $fb[3] ?? '', 'tip' => $qx_T('tl.open', ['n' => 'Find my torrent']), 'href' => '/Settings/SeedboxTools#stt-t-find'];
// Update me
$ucol = 'grey'; $uwhen = '';
$ud = $jr('/tmp/qbt_tools_upd.json'); $us = (array)($wst['sum']['upd'] ?? []);
if (($ud['state'] ?? '') === 'ready' && isset($ud['items']) && is_array($ud['items'])) { $un = count($ud['items']); $ut = max((int)($us['t'] ?? 0), (int)($ud['t'] ?? 0)); }
elseif ($us) { $un = (int)($us['n'] ?? 0); $ut = (int)($us['t'] ?? 0); } else { $un = -1; $ut = 0; }
if (($rm = $running($ud)) !== null) { $ub = [$qx_T('tl.scanning'), $rm]; $ucol = 'orange'; }
elseif ($un < 0) { $ub = [$qx_T('tl.not_scanned'), '']; }
elseif ($un > 0) {
    // the offers come from the background search: torrents of the list with a release that is smaller, a better HDR format or a higher resolution
    $ures = $jr('/tmp/qbt_tools_res.json'); $uoff = 0; $udone = 0;
    foreach ((array)($ud['items'] ?? []) as $ux) {
        $rr = $ures[$ux['hash'] ?? ''] ?? null;
        if (!is_array($rr) || ($rr['state'] ?? '') !== 'ready') { continue; }
        $udone++;
        foreach ((array)($rr['rows'] ?? []) as $rw) { if (!empty($rw['cats']) && ((int)($rw['res'] ?? 0) > 0 || (int)($ux['cur_res'] ?? 0) <= 0)) { $uoff++; break; } }
    }
    $ub = $udone > 0 ? [$qx_T('tl.offers', ['n' => $uoff]), $qx_T('tl.searched', ['d' => $udone, 'n' => $un])] : [$qx_T('tl.to_search', ['n' => $un]), $qx_T('tl.scanned', ['t' => $ago($ut)])];
    if ($udone > 0 && $ut > 0) { $uwhen = $qx_T('tl.scanned', ['t' => $ago($ut)]); } // the reason line shows the search, the scan time goes under it
    if ($uoff > 0) { $ucol = 'green'; }
    elseif ($udone < $un && $locked('updbg')) { $ub = [$qx_T('tl.scanning'), $qx_T('tl.searched', ['d' => $udone, 'n' => $un])]; $ucol = 'orange'; } // the background search is running
}
else { $ub = [$qx_T('tl.nothing_upd'), $qx_T('tl.scanned', ['t' => $ago($ut)])]; }
$tools[] = ['_k' => 'upd', '_rel' => $ucol !== 'grey' || $un < 0, '_t' => $ut, 'name' => 'Shape up', 'tag' => $tagOf('tag_upd', $qx_T('tl.tag_upd')), 'tagtip' => $qx_T('tl.tip_upd'),
    'col' => $ucol ?? 'grey', 'badge' => $ub[0], 'reason' => $ub[1], 'when' => $uwhen, 'tip' => $qx_T('tl.open', ['n' => 'Shape up']), 'href' => '/Settings/SeedboxTools#stt-t-upd'];
// Season Repacker: the running / finished jobs, or the idle line
if ($rep) { $rep[0]['tag'] = $tagOf('tag_rep', $qx_T('tl.tag_rep')); $rep[0]['tagtip'] = $qx_T('tl.tip_rep'); foreach ($rep as $r) { $tools[] = array_merge(['_k' => 'rep', '_rel' => true, '_t' => time()], $r); } }
else {
    $sp = $jr('/tmp/qbt_tools_sp.json'); $rs = (array)($wst['sum']['rep'] ?? []);
    if (($sp['state'] ?? '') === 'ready' && empty($sp['filter']) && isset($sp['items']) && is_array($sp['items'])) { $rn = count($sp['items']); $rt = max((int)($rs['t'] ?? 0), (int)($sp['t'] ?? 0)); }
    elseif ($rs) { $rn = (int)($rs['n'] ?? 0); $rt = (int)($rs['t'] ?? 0); } else { $rn = -1; $rt = 0; }
    // packs from autobrr waiting for a click (item 6): rows of sp_ab.json not dismissed, younger than 7 days, not being replaced
    $abBusy = []; foreach ((array)($wst['packjobs'] ?? []) as $pj) { if (($pj['stage'] ?? '') !== 'failed') { $abBusy[$pj['key'] ?? ''] = 1; } }
    $abIt = array_values(array_filter((array)($jr($wdir . '/sp_ab.json')['items'] ?? []), function ($x) use ($abBusy) { return empty($x['dismissed']) && time() - (int)($x['t'] ?? 0) < 7 * 86400 && !isset($abBusy[$x['key'] ?? '']); }));
    usort($abIt, function ($a, $b) { return (int)$b['t'] <=> (int)$a['t']; });
    $abN = count($abIt); $abNames = array_map(function ($x) { return $x['title'] . ' S' . str_pad((string)$x['season'], 2, '0', STR_PAD_LEFT); }, array_slice($abIt, 0, 2));
    if (($rm = $running($sp)) !== null) { $rb = [$qx_T('tl.scanning'), 'orange', $rm]; }
    elseif ($abN > 0) { $rb = [$qx_T('tl.packs_ab', ['n' => $abN]), 'green', implode(' · ', $abNames) . ($abN > 2 ? ' · +' . ($abN - 2) : ''), $qx_T('tl.latest', ['t' => $ago((int)$abIt[0]['t'])])]; }
    elseif ($rn < 0) { $rb = [$qx_T('tl.not_scanned'), 'grey', '']; } elseif ($rn > 0) { $rb = [$qx_T('tl.seasons', ['n' => $rn]), 'grey', $qx_T('tl.scanned', ['t' => $ago($rt)])]; } else { $rb = [$qx_T('tl.nothing_rep'), 'grey', $qx_T('tl.scanned', ['t' => $ago($rt)])]; }
    $tools[] = ['_k' => 'rep', '_rel' => $rb[1] !== 'grey' || $rn != 0, '_t' => $rt, 'name' => 'Season Repacker', 'tag' => $tagOf('tag_rep', $qx_T('tl.tag_rep')), 'tagtip' => $qx_T('tl.tip_rep'),
        'col' => $rb[1], 'badge' => $rb[0], 'reason' => $rb[2], 'when' => $rb[3] ?? '', 'tip' => $qx_T('tl.open', ['n' => 'Season Repacker']), 'href' => '/Settings/SeedboxTools#stt-t-rep'];
}

// which rows show (item 54, Seedbox Tools settings 'Show on the Dashboard'): always / only when relevant (something to do,
// a scan running, never scanned) / for N days after a scan (or when relevant) / never. A red or amber row shows in every mode but never.
$tools = array_values(array_filter($tools, function ($x) use ($tcfg) {
    $k = $x['_k'] ?? ''; $m = (string)($tcfg['show_' . $k] ?? 'relevant');
    if ($m === 'never') { return false; }
    if ($m === 'always' || !empty($x['_rel']) || in_array($x['col'] ?? '', ['red', 'amber'], true)) { return true; }
    return $m === 'days' && time() - (int)($x['_t'] ?? 0) < 86400 * max(1, (int)($tcfg['show_' . $k . '_days'] ?? 3));
}));
foreach ($tools as &$x) { unset($x['_k'], $x['_rel'], $x['_t']); } unset($x);

$attTotal = count($att);
$attMore  = max(0, $attTotal - $maxAtt);
$att      = array_slice($att, 0, $maxAtt);

// ---- In transfer: downloads first, then busiest uploads -----------------
$rank = ['down' => 0, 'busy' => 1, 'up' => 2];
usort($xfer, function ($a, $b) use ($rank) {
    if ($a['dir'] !== $b['dir']) { return $rank[$a['dir']] <=> $rank[$b['dir']]; }
    return ($b['dl'] + $b['ul']) <=> ($a['dl'] + $a['ul']);
});
$sumDl = 0;
$sumUl = 0;
foreach ($xfer as $xr) { $sumDl += $xr['dl']; $sumUl += $xr['ul']; }
$global = [
    'dl' => max($sumDl, (int)($global['dl'] ?? 0)),
    'ul' => max($sumUl, (int)($global['ul'] ?? 0)),
];
$xferTotal = count($xfer);
$more      = max(0, $xferTotal - $rows);
$xfer      = array_slice($xfer, 0, $rows);

out([
    'ok'              => true,
    'conn'            => $conn,
    'global'          => $global,
    'counts'          => $counts,
    'attention'       => $att,
    'attention_total' => $attTotal,
    'attention_more'  => $attMore,
    'transfer'        => $xfer,
    'tools'           => $tools,
    'not_seeded'      => $fknown ? $fn : -1,   // folders in the downloads folders that no torrent seeds (last Find my torrent scan)
    'transfer_total'  => $xferTotal,
    'more'            => $more,
]);
