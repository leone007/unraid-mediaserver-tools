<?php
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/i18n.php';
$sbx_T = mt_i18n_t(__DIR__ . '/lang');   // texts shown on the tile and the settings page

$cfg = [
    'jf_host' => '127.0.0.1', 'jf_port' => '8096', 'jf_key' => '', 'jf_url' => '',
    'em_on' => '0', 'em_host' => '127.0.0.1', 'em_port' => '8097', 'em_key' => '',
    'px_on' => '0', 'px_host' => '127.0.0.1', 'px_port' => '32400', 'px_token' => '', 'px_dir' => '/mnt/cache/docker/plex',
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

// Media servers whose streams the tile shows (item 92). Emby has the same session API as Jellyfin, under /emby and with its own
// token header. Jellyfin stays the library source (recently added, the user nicknames).
$srv = [];
if ($key !== '') {
    $srv['jf'] = ['base' => $base, 'pre' => '', 'hdr' => 'Authorization: MediaBrowser Token="' . $key . '"'];
}
$emKey = (string)$cfg['em_key'];
if (sb_flag($cfg['em_on']) && $emKey !== '') {
    $emHost = preg_match('/^[A-Za-z0-9.\-]+$/', (string)$cfg['em_host']) ? $cfg['em_host'] : '127.0.0.1';
    $emPort = (int)$cfg['em_port'];
    if ($emPort < 1 || $emPort > 65535) { $emPort = 8097; }
    $srv['em'] = ['base' => "http://$emHost:$emPort", 'pre' => '/emby', 'hdr' => 'X-Emby-Token: ' . $emKey];
}
// Plex: its own API (/status/sessions), turned into Jellyfin-shaped sessions by sb_plex_sessions(). Without a saved token the
// server owner's token is read from Plex's Preferences.xml in its config folder, so nobody has to copy it.
$preState = [];
if (sb_flag($cfg['em_on']) && $emKey === '') { $preState['em'] = 'nokey'; }
if (sb_flag($cfg['px_on'])) {
    $pxTok = sb_plex_token($cfg);
    if ($pxTok === '') { $preState['px'] = 'notoken'; }
    else {
        $pxHost = preg_match('/^[A-Za-z0-9.\-]+$/', (string)$cfg['px_host']) ? $cfg['px_host'] : '127.0.0.1';
        $pxPort = (int)$cfg['px_port'];
        if ($pxPort < 1 || $pxPort > 65535) { $pxPort = 32400; }
        $srv['px'] = ['base' => "http://$pxHost:$pxPort", 'pre' => '', 'hdr' => 'X-Plex-Token: ' . $pxTok];
    }
}

// where each server's name on the tile links to (its own web UI): host and port as set; a loopback host is replaced by the
// address the browser used for Unraid, Jellyfin keeps its optional public URL
$ui['srv'] = [['id' => 'jf', 'host' => $host, 'port' => $port, 'url' => $link]];
foreach (['em' => 8097, 'px' => 32400] as $sv => $defPort) {
    if (!sb_flag($cfg[$sv . '_on'])) { continue; }
    $h = preg_match('/^[A-Za-z0-9.\-]+$/', (string)$cfg[$sv . '_host']) ? $cfg[$sv . '_host'] : '127.0.0.1';
    $p = (int)$cfg[$sv . '_port'];
    $ui['srv'][] = ['id' => $sv, 'host' => $h, 'port' => ($p < 1 || $p > 65535) ? $defPort : $p, 'url' => ''];
}

function sb_plex_token($cfg) {
    $t = trim((string)($cfg['px_token'] ?? ''));
    if ($t !== '') { return preg_match('/^[A-Za-z0-9_\-]{8,64}$/', $t) ? $t : ''; }
    $dir = rtrim((string)($cfg['px_dir'] ?? ''), '/');
    if (!preg_match('#^/mnt/[^\0]+$#', $dir) || strpos($dir, '..') !== false) { return ''; }
    $x = @file_get_contents($dir . '/Library/Application Support/Plex Media Server/Preferences.xml');
    return ($x !== false && preg_match('/\sPlexOnlineToken="([A-Za-z0-9_\-]{8,64})"/', $x, $m)) ? $m[1] : '';
}

function out($arr) {
    global $ui;
    $arr['ui'] = $ui;
    echo json_encode($arr, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
    exit;
}

if (!$srv && !$preState) { out(['ok' => false, 'error' => 'nokey']); }

function sb_get($path, $sv = 'jf') {
    global $srv;
    if (!isset($srv[$sv])) { return ['', 0, 1]; }
    $ch = curl_init($srv[$sv]['base'] . $srv[$sv]['pre'] . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT        => 4,
        CURLOPT_HTTPHEADER     => [$srv[$sv]['hdr'], 'Accept: application/json'],
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
    global $sbx_T;
    $map = [
        'ContainerNotSupported'        => $sbx_T('why.container'),
        'VideoCodecNotSupported'       => $sbx_T('why.video_codec'),
        'AudioCodecNotSupported'       => $sbx_T('why.audio_codec'),
        'SubtitleCodecNotSupported'    => $sbx_T('why.subtitles'),
        'AudioChannelsNotSupported'    => $sbx_T('why.audio_channels'),
        'ContainerBitrateExceedsLimit' => $sbx_T('why.bitrate_limit'),
        'VideoBitrateNotSupported'     => $sbx_T('why.video_bitrate'),
        'AudioBitrateNotSupported'     => $sbx_T('why.audio_bitrate'),
        'InterlacedVideoNotSupported'  => $sbx_T('why.interlaced'),
        'VideoResolutionNotSupported'  => $sbx_T('why.resolution'),
        'VideoProfileNotSupported'     => $sbx_T('why.video_profile'),
        'VideoLevelNotSupported'       => $sbx_T('why.video_level'),
        'VideoRangeTypeNotSupported'   => $sbx_T('why.hdr_format'),
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

// Plex /status/sessions -> the Jellyfin session shape the loop below reads. Plex gives times in ms, bitrates in kbps.
// Video and audio decisions come from TranscodeSession; the source file is the library item (one cached call per title).
function sb_plex_sessions($j) {
    $list = $j['MediaContainer']['Metadata'] ?? [];
    if (!is_array($list)) { return null; }
    $cf = '/tmp/streambox_px_meta.json';
    $cache = is_file($cf) ? json_decode((string)@file_get_contents($cf), true) : [];
    if (!is_array($cache)) { $cache = []; }
    $dirty = false;
    $out = [];
    foreach ($list as $m) {
        if (!is_array($m)) { continue; }
        $media = $m['Media'][0] ?? [];
        $ts = is_array($m['TranscodeSession'] ?? null) ? $m['TranscodeSession'] : null;
        // the session's Media is the output of a transcode: its size is the target size
        $outW = (int)($media['width'] ?? 0);
        $outH = (int)($media['height'] ?? 0);
        $rk = (string)($m['ratingKey'] ?? '');
        // during a transcode the session's Media describes the output: take the source from the library item
        if ($ts !== null && $rk !== '' && ctype_digit($rk)) {
            if (!isset($cache[$rk]) || (time() - (int)($cache[$rk]['t'] ?? 0)) > 3600) {
                list($b, $code, $err) = sb_get('/library/metadata/' . $rk, 'px');
                $md = (!$err && $code === 200) ? json_decode((string)$b, true) : null;
                $src = $md['MediaContainer']['Metadata'][0]['Media'] ?? null;
                if (is_array($src)) { $cache[$rk] = ['t' => time(), 'm' => $src]; $dirty = true; }
            }
            // the version being played (same Media id), else the first one
            $srcList = array_values(array_filter((array)($cache[$rk]['m'] ?? []), 'is_array'));
            $hit = $srcList ? $srcList[0] : null;
            foreach ($srcList as $cm) {
                if ((string)($cm['id'] ?? '') !== '' && (string)($cm['id'] ?? '') === (string)($media['id'] ?? '')) { $hit = $cm; break; }
            }
            if ($hit !== null) { $media = $hit; }
        }
        $vs = null; $as = null;
        foreach ((array)($media['Part'][0]['Stream'] ?? []) as $st) {
            if (!is_array($st)) { continue; }
            $t = (int)($st['streamType'] ?? 0);
            if ($t === 1 && $vs === null) { $vs = $st; }
            if ($t === 2 && ($as === null || !empty($st['selected']))) { $as = $st; }
        }
        $w = (int)($vs['width'] ?? $media['width'] ?? 0);
        $h = (int)($vs['height'] ?? $media['height'] ?? 0);
        $ms = [];
        $ms[] = ['Type' => 'Video', 'Codec' => (string)($vs['codec'] ?? $media['videoCodec'] ?? ''), 'Width' => $w, 'Height' => $h,
                 'BitRate' => 1000 * (int)($vs['bitrate'] ?? $media['bitrate'] ?? 0),
                 'IsInterlaced' => (($vs['scanType'] ?? '') === 'interlaced')];
        $ms[] = ['Type' => 'Audio', 'Codec' => (string)($as['codec'] ?? $media['audioCodec'] ?? ''),
                 'Channels' => (int)($as['channels'] ?? $media['audioChannels'] ?? 0), 'IsDefault' => true];
        $type = (string)($m['type'] ?? '');
        $live = !empty($m['live']);
        $np = [
            'Name' => (string)($m['title'] ?? '?'),
            'Type' => $live ? 'TvChannel' : ($type === 'episode' ? 'Episode' : ($type === 'movie' ? 'Movie' : ucfirst($type))),
            'SeriesName' => (string)($m['grandparentTitle'] ?? ''),
            'ParentIndexNumber' => isset($m['parentIndex']) ? (int)$m['parentIndex'] : null,
            'IndexNumber' => isset($m['index']) ? (int)$m['index'] : null,
            'ProductionYear' => isset($m['year']) ? (int)$m['year'] : null,
            'RunTimeTicks' => $live ? 0 : 10000 * (int)($m['duration'] ?? 0),
            'Container' => (string)($media['container'] ?? ''),
            'MediaStreams' => $ms,
        ];
        if ($live) {
            // live TV: Plex sends the programme title and no channel name (owner's test 2026-10-29); a channel name when given
            $ch = trim((string)($m['sourceTitle'] ?? ''));
            if ($ch !== '' && $ch !== $np['Name']) { $np['CurrentProgram'] = ['Name' => $np['Name']]; $np['Name'] = $ch; }
        }
        $vd = (string)($ts['videoDecision'] ?? '');
        $ad = (string)($ts['audioDecision'] ?? '');
        $method = ($ts === null) ? 'DirectPlay' : ($vd === 'transcode' ? 'Transcode' : 'DirectStream');
        $ti = null;
        if ($ts !== null) {
            $ti = [
                'IsVideoDirect' => ($vd !== 'transcode'),
                'IsAudioDirect' => ($ad !== 'transcode'),
                'VideoCodec' => (string)($ts['videoCodec'] ?? ''),
                'AudioCodec' => (string)($ts['audioCodec'] ?? ''),
                'Width' => (int)($ts['width'] ?? $outW),
                'Height' => (int)($ts['height'] ?? $outH),
                'Container' => (string)($ts['container'] ?? ''),
                'Bitrate' => 1000 * (int)($m['Session']['bandwidth'] ?? 0),
                // Plex names the hardware encoder (qsv, vaapi ...) when the GPU encodes; live TV was decoded and deinterlaced on
                // the CPU with a VA-API encode (no transcodeHwDecoding, owner's test 2026-10-29)
                'HardwareAccelerationType' => (string)($ts['transcodeHwEncoding'] ?? ''),
                'HwEncodeOnly' => ((string)($ts['transcodeHwEncoding'] ?? '') !== '' && empty($ts['transcodeHwDecoding']) && empty($ts['transcodeHwFullPipeline'])),
                'TranscodeReasons' => [],
            ];
        }
        $pl = is_array($m['Player'] ?? null) ? $m['Player'] : [];
        $out[] = [
            'Id' => (string)($m['Session']['id'] ?? $m['sessionKey'] ?? ''),
            'UserId' => (string)($m['User']['id'] ?? ''),
            'UserName' => (string)($m['User']['title'] ?? '?'),
            'DeviceName' => (string)($pl['title'] ?? $pl['device'] ?? '?'),
            'Client' => trim((string)($pl['product'] ?? '') . ((string)($pl['platform'] ?? '') !== '' ? ' (' . $pl['platform'] . ')' : '')),
            'ApplicationVersion' => (string)($pl['version'] ?? ''),
            'NowPlayingItem' => $np,
            'PlayState' => ['PositionTicks' => 10000 * (int)($m['viewOffset'] ?? 0), 'IsPaused' => (($pl['state'] ?? '') === 'paused'), 'PlayMethod' => $method],
            'TranscodingInfo' => $ti,
        ];
    }
    if ($dirty) {
        foreach ($cache as $k => $v) { if ((time() - (int)($v['t'] ?? 0)) > 86400) { unset($cache[$k]); } }
        @file_put_contents($cf, json_encode($cache));
    }
    return $out;
}

$nick = [];
$nf = '/boot/config/plugins/streambox/nicknames.json';
if (is_file($nf)) {
    $n = json_decode((string)@file_get_contents($nf), true);
    if (is_array($n)) { $nick = $n; }
}

// ---- Sessions -----------------------------------------------------------
$arrow   = " \u{2192} ";
$streams = [];
$users   = [];
$mbps    = 0.0;
$srvMbps = [];   // per media server, for the right side of its section on the tile
$trans   = 0;
$state   = [];   // per server: '' = answered, else the error code

foreach ($srv as $sv => $_) {
    list($b, $code, $err) = sb_get($sv === 'px' ? '/status/sessions' : '/Sessions', $sv);
    $sessions = null;
    if ($err) { $state[$sv] = 'unreachable'; }
    elseif ($code === 401 || $code === 403) { $state[$sv] = 'auth'; }
    elseif ($code !== 200) { $state[$sv] = 'http_' . $code; }
    else {
        $sessions = json_decode((string)$b, true);
        if ($sv === 'px' && is_array($sessions)) { $sessions = sb_plex_sessions($sessions); }
        $state[$sv] = is_array($sessions) ? '' : 'bad_response';
    }
    if (!is_array($sessions)) { continue; }

    foreach ($sessions as $s) {
        $np = $s['NowPlayingItem'] ?? null;
        if (!is_array($np)) { continue; }   // idle client
        $ps = is_array($s['PlayState'] ?? null) ? $s['PlayState'] : [];
        $ti = is_array($s['TranscodingInfo'] ?? null) ? $s['TranscodingInfo'] : null;

        $uid   = (string)($s['UserId'] ?? '');
        $uname = (string)($s['UserName'] ?? '?');
        $short = ($sv === 'jf' && $uid !== '' && isset($nick[$uid]) && trim((string)$nick[$uid]) !== '') ? (string)$nick[$uid] : sb_short($uname);
        $users[mb_strtolower($uname)] = true;   // the same name on two servers is one person

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
                $detail = $sbx_T('det.audio') . $arrow . $audioCodec;
            } else {
                $dstRes = sb_res($ti['Width'] ?? 0, $ti['Height'] ?? 0);
                $dstCodec = strtoupper((string)($ti['VideoCodec'] ?? ''));
                $rs = array_values(array_map('strval', (array)($ti['TranscodeReasons'] ?? [])));
                $interlaced = in_array('InterlacedVideoNotSupported', $rs, true) || ($vs && !empty($vs['IsInterlaced']));
                if ($dstRes !== '' && $srcRes !== '' && $dstRes !== $srcRes) { $detail = $srcRes . $arrow . $dstRes; }
                elseif ($srcCodec !== '' && $dstCodec !== '' && $srcCodec !== $dstCodec) { $detail = $srcCodec . $arrow . $dstCodec; }
                elseif ($interlaced && $dstRes !== '') { $detail = $sbx_T('det.deinterlace') . $arrow . $dstRes; }
                elseif ($dstRes !== '') { $detail = $sbx_T('det.video') . $arrow . $dstRes; }
                elseif ($rs && sb_reason($rs[0]) !== '') { $detail = sb_reason($rs[0]); }
                else { $detail = $sbx_T('det.video_transcode'); }
                // Jellyfin names the acceleration. Emby 4.10 lists the steps in VideoPipelineInfo (its VideoEncoderIsHardware said
                // false during a QuickSync encode, owner's test 2026-10-29): the encoder step counts.
                $hw = ((string)($ti['HardwareAccelerationType'] ?? '') !== '') || !empty($ti['VideoEncoderIsHardware']);
                $encOnly = !empty($ti['HwEncodeOnly']);
                $pipe = array_values(array_filter((array)($ti['VideoPipelineInfo'] ?? []), 'is_array'));
                foreach ($pipe as $st) {
                    if (($st['StepType'] ?? '') === 'Encoder' && !empty($st['IsHardwareContext'])) { $hw = true; }
                }
                // Emby: hardware encoder after a software step (decode, deinterlace) is a partly hardware transcode too
                if ($pipe && $hw) {
                    foreach ($pipe as $st) { if (($st['StepType'] ?? '') !== 'Encoder' && empty($st['IsHardwareContext'])) { $encOnly = true; } }
                }
                $detail .= $hw ? ($encOnly ? " \u{00b7} HW enc" : " \u{00b7} HW") : " \u{00b7} SW";
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
                $line1 = ($srcAudio !== '' ? $srcAudio : $sbx_T('det.audio')) . $arrow . strtoupper((string)$ti['AudioCodec']);
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
        $methodName = ['DirectPlay' => $sbx_T('mode.direct_play'), 'DirectStream' => $sbx_T('mode.direct_stream'), 'Transcode' => $sbx_T('mode.transcode')][$mode];
        $info   = [$sbx_T('info.method', ['m' => $methodName])];
        $srcBits = array_filter([
            $srcCont,
            trim($srcCodec . ($srcRes !== '' ? ' ' . $srcRes : '')),
            trim($srcAudio . ($srcCh > 0 ? ' ' . $srcCh . 'ch' : '')),
        ]);
        if ($srcBits) { $info[] = $sbx_T('info.source', ['s' => implode(" \u{00b7} ", $srcBits)]); }
        if ($ti !== null) {
            $names = [];
            foreach ($rs as $r) { $names[] = sb_why($r); }
            $info[] = $sbx_T('info.reasons', ['r' => $names ? implode(', ', $names) : $sbx_T('info.none_reported')]);
        } else {
            $info[] = $sbx_T('info.no_details');
        }
        $info[] = $sbx_T('info.client', ['c' => trim((string)($s['Client'] ?? '') . ' ' . (string)($s['ApplicationVersion'] ?? ''))]);
        $mbps += $bps / 1000000;
        $srvMbps[$sv] = ($srvMbps[$sv] ?? 0) + $bps / 1000000;

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
                $info[] = $sbx_T('ww.program', ['t' => date('H:i', $st) . " \u{2013} " . date('H:i', $en)]);
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
            'srv'         => $sv,
            'sid'         => (string)($s['Id'] ?? '') !== '' ? $sv . ':' . (string)$s['Id'] : '',
        ];
    }
}

$state += $preState;
// every server failed: the tile shows the error of the first one, as before
$okSrv = array_keys(array_filter($state, function ($e) { return $e === ''; }));
if (!$okSrv) { out(['ok' => false, 'error' => (string)reset($state)]); }
$servers = [];
foreach ($state as $sv => $e) { $servers[] = ['id' => $sv, 'ok' => ($e === ''), 'error' => $e, 'mbps' => round($srvMbps[$sv] ?? 0, 1)]; }

// Order: playing before paused, then by when the stream was first seen (oldest on top). Jellyfin lists the sessions by their last
// activity, which changes with every progress report, so the rows used to swap places (owner, 2026-10-28).
$ff = '/tmp/streambox_first_seen.json';
$seen = is_file($ff) ? json_decode((string)@file_get_contents($ff), true) : [];
if (!is_array($seen)) { $seen = []; }
$now0 = time(); $keep = [];
foreach ($streams as $x) { if ($x['sid'] !== '') { $keep[$x['sid']] = (int)($seen[$x['sid']] ?? $now0); } }
if ($keep != $seen) { @file_put_contents($ff, json_encode($keep)); }
// a tie (streams first seen in the same poll, e.g. after an install) is broken by the session id, which does not change
foreach ($streams as $i => $x) { $streams[$i]['_o'] = [$keep[$x['sid']] ?? $now0, $x['sid'], $i]; unset($streams[$i]['sid']); }
usort($streams, function ($a, $b) {
    if ($a['paused'] !== $b['paused']) { return $a['paused'] ? 1 : -1; }
    return $a['_o'] <=> $b['_o'];
});
foreach ($streams as $i => $x) { unset($streams[$i]['_o']); }
$total   = count($streams);
$more    = max(0, $total - $maxStream);
$streams = array_slice($streams, 0, $maxStream);

// ---- Recently added (cached for a minute) ----------------------------------
$added = [];
if ($addedRows > 0 && ($state['jf'] ?? 'x') === '') {
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
    'servers'       => $servers,
]);
