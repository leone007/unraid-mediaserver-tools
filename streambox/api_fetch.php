<?php
header('Content-Type: application/json');
header('Cache-Control: no-store');

$cfg = [
    'jf_host' => '127.0.0.1', 'jf_port' => '8096', 'jf_key' => '', 'jf_url' => '',
    'streams_max' => '8', 'added_rows' => '5', 'show_load' => '1',
];
$file = '/boot/config/plugins/streambox/settings.cfg';
if (is_file($file)) {
    $c = @parse_ini_file($file);
    if (is_array($c)) { $cfg = array_merge($cfg, $c); }
}

function sb_flag($v) {
    return !in_array(strtolower(trim((string)$v)), ['0', 'no', 'false', 'off', ''], true);
}

$host = preg_match('/^[A-Za-z0-9.\-]+$/', (string)$cfg['jf_host']) ? $cfg['jf_host'] : '127.0.0.1';
$port = (int)$cfg['jf_port'];
if ($port < 1 || $port > 65535) { $port = 8096; }
$key       = (string)$cfg['jf_key'];
$maxStream = max(1, min(30, (int)$cfg['streams_max']));
$addedRows = max(0, min(20, (int)$cfg['added_rows']));
$link      = trim((string)$cfg['jf_url']);
if (!preg_match('#^https?://[^\s"<>]+$#i', $link)) { $link = ''; }
$base = "http://$host:$port";

$ui = ['port' => $port, 'url' => $link, 'load' => sb_flag($cfg['show_load']), 'added' => $addedRows];

function out($arr) {
    global $ui;
    $arr['ui'] = $ui;
    echo json_encode($arr, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($key === '') { out(['ok' => false, 'error' => 'nokey']); }

function sb_get($path) {
    global $base, $key;
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT        => 4,
        CURLOPT_HTTPHEADER     => ['Authorization: MediaBrowser Token="' . $key . '"', 'Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_errno($ch);
    curl_close($ch);
    return [$body, $code, $err];
}

function sb_res($w, $h) {
    $w = (int)$w;
    $h = (int)$h;
    if ($w <= 0 && $h <= 0) { return ''; }
    if ($w >= 3200 || $h >= 1800) { return '2160p'; }
    if ($w >= 2400 || $h >= 1300) { return '1440p'; }
    if ($w >= 1800 || $h >= 1000) { return '1080p'; }
    if ($w >= 1200 || $h >= 700)  { return '720p'; }
    if ($w >= 700  || $h >= 460)  { return '480p'; }
    return 'SD';
}

function sb_vres($vs) {
    if (!is_array($vs)) { return ''; }
    $r = sb_res($vs['Width'] ?? 0, $vs['Height'] ?? 0);
    if ($r !== '' && !empty($vs['IsInterlaced']) && substr($r, -1) === 'p') { $r = substr($r, 0, -1) . 'i'; }
    return $r;
}

// "VideoCodecNotSupported" -> "Video codec"
function sb_reason($r) {
    $r = (string)preg_replace('/(NotSupported|ExceedsLimit)$/', '', (string)$r);
    $r = trim(strtolower((string)preg_replace('/(?<!^)(?=[A-Z])/', ' ', $r)));
    return ($r !== '') ? ucfirst($r) : '';
}

// Short human wording for a Jellyfin transcode reason
function sb_why($r) {
    $map = [
        'ContainerNotSupported'        => 'Container',
        'VideoCodecNotSupported'       => 'Video codec',
        'AudioCodecNotSupported'       => 'Audio codec',
        'SubtitleCodecNotSupported'    => 'Subtitles',
        'AudioChannelsNotSupported'    => 'Audio channels',
        'ContainerBitrateExceedsLimit' => 'Bitrate limit',
        'VideoBitrateNotSupported'     => 'Video bitrate',
        'AudioBitrateNotSupported'     => 'Audio bitrate',
        'InterlacedVideoNotSupported'  => 'Interlaced',
        'VideoResolutionNotSupported'  => 'Resolution',
        'VideoProfileNotSupported'     => 'Video profile',
        'VideoLevelNotSupported'       => 'Video level',
        'VideoRangeTypeNotSupported'   => 'HDR format',
    ];
    $r = (string)$r;
    return isset($map[$r]) ? $map[$r] : sb_reason($r);
}

function sb_audio($it) {
    $first = null;
    foreach ((array)($it['MediaStreams'] ?? []) as $ms) {
        if (!is_array($ms) || ($ms['Type'] ?? '') !== 'Audio') { continue; }
        if ($first === null) { $first = $ms; }
        if (!empty($ms['IsDefault'])) { return $ms; }
    }
    return $first;
}

function sb_short($name) {
    $name  = trim((string)$name);
    $parts = preg_split('/[\s._\-@]+/u', $name);
    $first = ($parts && $parts[0] !== '') ? $parts[0] : $name;
    $n = preg_match_all('/./us', $first);
    if ($n > 8) { return (preg_match('/^.{1,8}/us', $first, $m) ? $m[0] : substr($first, 0, 8)) . "\u{2026}"; }
    return $first;
}

function sb_dev($d) {
    $s = trim((string)preg_replace('/\s*\([^)]*\)\s*$/u', '', (string)$d));
    return ($s !== '') ? $s : (string)$d;
}

function sb_title($it) {
    $type = (string)($it['Type'] ?? '');
    $name = (string)($it['Name'] ?? '?');
    $dot  = " \u{00b7} ";
    if ($type === 'Episode') {
        $series = (string)($it['SeriesName'] ?? '');
        $s = $it['ParentIndexNumber'] ?? null;
        $e = $it['IndexNumber'] ?? null;
        $code = ($s !== null && $e !== null) ? sprintf('S%02dE%02d', (int)$s, (int)$e) : '';
        $tail = trim($code . ' ' . $name);
        return ($series !== '') ? $series . $dot . $tail : $tail;
    }
    if ($type === 'Season') {
        $series = (string)($it['SeriesName'] ?? '');
        return ($series !== '') ? $series . $dot . $name : $name;
    }
    if ($type === 'TvChannel' || $type === 'LiveTvChannel') {
        $cp = is_array($it['CurrentProgram'] ?? null) ? trim((string)($it['CurrentProgram']['Name'] ?? '')) : '';
        return ($cp !== '') ? $name . $dot . $cp : $name;
    }
    $y = $it['ProductionYear'] ?? null;
    return $name . ($y ? ' (' . (int)$y . ')' : '');
}

function sb_video($it) {
    foreach ((array)($it['MediaStreams'] ?? []) as $ms) {
        if (is_array($ms) && ($ms['Type'] ?? '') === 'Video') { return $ms; }
    }
    return null;
}

$nick = [];
$nf = '/boot/config/plugins/streambox/nicknames.json';
if (is_file($nf)) {
    $n = json_decode((string)@file_get_contents($nf), true);
    if (is_array($n)) { $nick = $n; }
}

// ---- Sessions -----------------------------------------------------------
list($b, $code, $err) = sb_get('/Sessions');
if ($err) { out(['ok' => false, 'error' => 'unreachable']); }
if ($code === 401 || $code === 403) { out(['ok' => false, 'error' => 'auth']); }
if ($code !== 200) { out(['ok' => false, 'error' => 'http_' . $code]); }
$sessions = json_decode((string)$b, true);
if (!is_array($sessions)) { out(['ok' => false, 'error' => 'bad_response']); }

$arrow   = " \u{2192} ";
$streams = [];
$users   = [];
$mbps    = 0.0;
$trans   = 0;

foreach ($sessions as $s) {
    $np = $s['NowPlayingItem'] ?? null;
    if (!is_array($np)) { continue; }   // idle client
    $ps = is_array($s['PlayState'] ?? null) ? $s['PlayState'] : [];
    $ti = is_array($s['TranscodingInfo'] ?? null) ? $s['TranscodingInfo'] : null;

    $uid   = (string)($s['UserId'] ?? '');
    $uname = (string)($s['UserName'] ?? '?');
    $short = ($uid !== '' && isset($nick[$uid]) && trim((string)$nick[$uid]) !== '') ? (string)$nick[$uid] : sb_short($uname);
    $users[$uname] = true;

    $vs       = sb_video($np);
    $srcRes   = sb_vres($vs);
    $srcCodec = $vs ? strtoupper((string)($vs['Codec'] ?? '')) : '';
    $srcBps   = $vs ? (int)($vs['BitRate'] ?? 0) : 0;
    $cparts   = explode(',', (string)($np['Container'] ?? ''));
    $srcCont  = strtoupper(trim((string)$cparts[0]));
    $as       = sb_audio($np);
    $srcAudio = $as ? strtoupper((string)($as['Codec'] ?? '')) : '';
    $srcCh    = $as ? (int)($as['Channels'] ?? 0) : 0;
    $rs       = ($ti !== null) ? array_values(array_map('strval', (array)($ti['TranscodeReasons'] ?? []))) : [];

    $mode = (string)($ps['PlayMethod'] ?? '');
    if (!in_array($mode, ['DirectPlay', 'DirectStream', 'Transcode'], true)) {
        if ($ti === null) { $mode = 'DirectPlay'; }
        else { $mode = !empty($ti['IsVideoDirect']) ? 'DirectStream' : 'Transcode'; }
    }

    // Only the audio is transcoded and the video stays untouched: treat it as a direct stream
    if ($mode === 'Transcode' && $ti !== null && !empty($ti['IsVideoDirect'])) { $mode = 'DirectStream'; }

    $resLine = $srcRes;
    if ($srcBps > 0) { $resLine .= (($resLine !== '') ? " \u{00b7} " : '') . number_format($srcBps / 1000000, 1) . ' Mbps'; }

    $detail = '';
    $bps    = $srcBps;
    if ($mode === 'Transcode' && $ti !== null) {
        $trans++;
        $audioCodec = strtoupper((string)($ti['AudioCodec'] ?? '?'));
        if (!empty($ti['IsVideoDirect'])) {
            $detail = 'Audio' . $arrow . $audioCodec;
        } else {
            $dstRes = sb_res($ti['Width'] ?? 0, $ti['Height'] ?? 0);
            $dstCodec = strtoupper((string)($ti['VideoCodec'] ?? ''));
            $rs = array_values(array_map('strval', (array)($ti['TranscodeReasons'] ?? [])));
            $interlaced = in_array('InterlacedVideoNotSupported', $rs, true) || ($vs && !empty($vs['IsInterlaced']));
            if ($dstRes !== '' && $srcRes !== '' && $dstRes !== $srcRes) { $detail = $srcRes . $arrow . $dstRes; }
            elseif ($srcCodec !== '' && $dstCodec !== '' && $srcCodec !== $dstCodec) { $detail = $srcCodec . $arrow . $dstCodec; }
            elseif ($interlaced && $dstRes !== '') { $detail = 'Deinterlace' . $arrow . $dstRes; }
            elseif ($dstRes !== '') { $detail = 'Video' . $arrow . $dstRes; }
            elseif ($rs && sb_reason($rs[0]) !== '') { $detail = sb_reason($rs[0]); }
            else { $detail = 'Video transcode'; }
            $detail .= ((string)($ti['HardwareAccelerationType'] ?? '') !== '') ? " \u{00b7} HW" : " \u{00b7} SW";
        }
        if (!empty($ti['Bitrate'])) { $bps = (int)$ti['Bitrate']; }
    } elseif ($mode === 'DirectStream') {
        // First line: why the stream is not a plain direct play. Second line: resolution and bitrate.
        $why = [];
        foreach ($rs as $r) {
            $t = sb_why($r);
            if ($t !== '' && !in_array($t, $why, true)) { $why[] = $t; }
        }
        $conv = '';
        if ($ti !== null) {
            $dstCont = strtoupper((string)($ti['Container'] ?? ''));
            if ($dstCont !== '' && $srcCont !== '' && $dstCont !== $srcCont) { $conv = $srcCont . $arrow . $dstCont; }
        }
        if ($ti !== null && empty($ti['IsAudioDirect']) && !empty($ti['AudioCodec'])) {
            $line1 = ($srcAudio !== '' ? $srcAudio : 'Audio') . $arrow . strtoupper((string)$ti['AudioCodec']);
        } elseif ($why || $conv) {
            $line1 = implode(" \u{00b7} ", array_filter([implode(', ', $why), $conv]));
        } else {
            // Jellyfin reports no reason: show what the file is made of, which usually explains it
            $line1 = implode(" \u{00b7} ", array_filter([$srcCont, $srcCodec, $srcAudio]));
        }
        $detail = trim($line1 . "\n" . $resLine);
    } else {
        // direct play
        $detail = $resLine;
    }

    // Hover text: everything Jellyfin tells us about this stream
    $methodName = ['DirectPlay' => 'Direct play', 'DirectStream' => 'Direct stream', 'Transcode' => 'Transcode'][$mode];
    $info   = ['Method: ' . $methodName];
    $srcBits = array_filter([
        $srcCont,
        trim($srcCodec . ($srcRes !== '' ? ' ' . $srcRes : '')),
        trim($srcAudio . ($srcCh > 0 ? ' ' . $srcCh . 'ch' : '')),
    ]);
    if ($srcBits) { $info[] = 'Source: ' . implode(" \u{00b7} ", $srcBits); }
    if ($ti !== null) {
        $names = [];
        foreach ($rs as $r) { $names[] = sb_why($r); }
        $info[] = 'Reasons: ' . ($names ? implode(', ', $names) : 'none reported');
    } else {
        $info[] = 'Jellyfin reported no transcoding details for this stream';
    }
    $info[] = 'Client: ' . trim((string)($s['Client'] ?? '') . ' ' . (string)($s['ApplicationVersion'] ?? ''));
    $mbps += $bps / 1000000;

    $pos = (int)($ps['PositionTicks'] ?? 0);
    $rt  = (int)($np['RunTimeTicks'] ?? 0);

    // Live TV has no length of its own; the current program from the guide (EPG) gives start and end
    $live = ($rt <= 0);
    $epg  = false;
    $pct  = ($rt > 0) ? max(0, min(1, $pos / $rt)) : 0;
    $left = ($rt > 0) ? max(0, ($rt - $pos) / 10000000) : 0;
    $cp   = is_array($np['CurrentProgram'] ?? null) ? $np['CurrentProgram'] : null;
    if ($live && $cp !== null && !empty($cp['StartDate']) && !empty($cp['EndDate'])) {
        $st = (int)strtotime((string)$cp['StartDate']);
        $en = (int)strtotime((string)$cp['EndDate']);
        if ($st > 0 && $en > $st) {
            $nowTs = time();
            $pct   = max(0, min(1, ($nowTs - $st) / ($en - $st)));
            $left  = max(0, $en - $nowTs);
            $epg   = true;
            $info[] = 'Program: ' . date('H:i', $st) . " \u{2013} " . date('H:i', $en);
        }
    }
    $dev = (string)($s['DeviceName'] ?? '?');

    $streams[] = [
        'title'       => sb_title($np),
        'type'        => (string)($np['Type'] ?? ''),
        'user'        => $short,
        'user_full'   => $uname,
        'device'      => sb_dev($dev),
        'device_full' => $dev . ' ' . "\u{00b7}" . ' ' . (string)($s['Client'] ?? ''),
        'mode'        => $mode,
        'detail'      => $detail,
        'info'        => implode("\n", $info),
        'pct'         => $pct,
        'left'        => $left,
        'paused'      => !empty($ps['IsPaused']),
        'live'        => $live,
        'epg'         => $epg,
    ];
}

usort($streams, function ($a, $b) {
    if ($a['paused'] !== $b['paused']) { return $a['paused'] ? 1 : -1; }
    return 0;
});
$total   = count($streams);
$more    = max(0, $total - $maxStream);
$streams = array_slice($streams, 0, $maxStream);

// ---- Recently added (cached for a minute) ----------------------------------
$added = [];
if ($addedRows > 0) {
    $cf  = '/tmp/streambox_added.json';
    $now = time();
    $c   = is_file($cf) ? json_decode((string)@file_get_contents($cf), true) : null;
    if (is_array($c) && ($now - (int)($c['t'] ?? 0)) < 60 && (int)($c['n'] ?? -1) === $addedRows && is_array($c['items'] ?? null)) {
        $added = $c['items'];
    } else {
        list($ab, $ac, $ae) = sb_get('/Items?SortBy=DateCreated&SortOrder=Descending&Recursive=true&IncludeItemTypes=Movie,Episode,Season&Limit=' . $addedRows . '&Fields=DateCreated,MediaStreams');
        if (!$ae && $ac === 200) {
            $r = json_decode((string)$ab, true);
            foreach ((array)($r['Items'] ?? []) as $it) {
                if (!is_array($it)) { continue; }
                $v = sb_video($it);
                $added[] = [
                    'title'   => sb_title($it),
                    'type'    => (string)($it['Type'] ?? ''),
                    'quality' => $v ? sb_vres($v) : '',
                    'added'   => isset($it['DateCreated']) ? (int)strtotime((string)$it['DateCreated']) : 0,
                ];
            }
            @file_put_contents($cf, json_encode(['t' => $now, 'n' => $addedRows, 'items' => $added], JSON_INVALID_UTF8_SUBSTITUTE));
        } elseif (is_array($c) && is_array($c['items'] ?? null)) {
            $added = $c['items'];   // Jellyfin hiccup: show the last known list
        }
    }
}

out([
    'ok'            => true,
    'streams'       => $streams,
    'streams_total' => $total,
    'more'          => $more,
    'users'         => count($users),
    'mbps'          => round($mbps, 1),
    'transcoding'   => $trans,
    'added'         => $added,
]);
