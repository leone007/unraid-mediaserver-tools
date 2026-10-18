<?php
// Discord notifier helpers (Streambox): Hungarian cards for new movies and seasons.

const NF_FIELDS = 'Overview,Genres,People,MediaStreams,MediaSources,DateCreated,OriginalTitle,ProductionYear';

function nf_cfg() {
    $c = [
        'jf_host' => '127.0.0.1', 'jf_port' => '8096', 'jf_key' => '', 'jf_url' => '',
        'notify' => '0', 'dc_movies' => '', 'dc_series' => '', 'notify_settle' => '5', 'notify_wait' => '30',
        'weekly' => '0', 'dc_weekly' => '', 'weekly_day' => '5', 'weekly_hour' => '18',
    ];
    $f = '/boot/config/plugins/streambox/settings.cfg';
    if (is_file($f)) {
        $x = @parse_ini_file($f);
        if (is_array($x)) { $c = array_merge($c, $x); }
    }
    return $c;
}

function nf_on($v) { return in_array(strtolower(trim((string)$v)), ['1', 'yes', 'true', 'on'], true); }

function nf_hook($u) {
    $u = trim((string)$u);
    return preg_match('#^https://(?:discord|discordapp)\.com/api/webhooks/\d{15,25}/[A-Za-z0-9_\-]{20,120}$#', $u) ? $u : '';
}

function nf_log($m) {
    $f = '/tmp/streambox_notify.log';
    $lines = is_file($f) ? (array)@file($f, FILE_IGNORE_NEW_LINES) : [];
    $lines[] = date('Y-m-d H:i:s') . ' ' . $m;
    @file_put_contents($f, implode("\n", array_slice($lines, -100)) . "\n");
}

// ---- Jellyfin ---------------------------------------------------------------------------------
function nf_get($cfg, $path) {
    $ch = curl_init('http://' . $cfg['jf_host'] . ':' . (int)$cfg['jf_port'] . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => ['Authorization: MediaBrowser Token="' . $cfg['jf_key'] . '"', 'Accept: */*'],
    ]);
    $b = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($code === 200 && is_string($b)) ? $b : null;
}

function nf_json($cfg, $path) {
    $b = nf_get($cfg, $path);
    if ($b === null) { return null; }
    $d = json_decode($b, true);
    return is_array($d) ? $d : null;
}

function nf_item($cfg, $id) {
    if ($id === '' || !preg_match('/^[0-9a-fA-F\-]{32,36}$/', $id)) { return null; }
    $d = nf_json($cfg, '/Items?Ids=' . rawurlencode($id) . '&Fields=' . NF_FIELDS);
    return (is_array($d) && !empty($d['Items'][0])) ? $d['Items'][0] : null;
}

function nf_image($cfg, $id, $type, $w) {
    $p = '/Items/' . rawurlencode($id) . '/Images/' . $type . ($type === 'Backdrop' ? '/0' : '')
       . '?maxWidth=' . (int)$w . '&quality=90&format=Jpg';
    $b = nf_get($cfg, $p);
    return ($b !== null && strlen($b) > 500 && strlen($b) < 7000000) ? $b : null;
}

function nf_info($cfg) {
    static $info = null;
    if ($info === null) {
        $d = nf_json($cfg, '/System/Info/Public');
        $info = ['Version' => (string)($d['Version'] ?? ''), 'Id' => (string)($d['Id'] ?? '')];
    }
    return $info;
}

function nf_link($cfg, $id) {
    $base = rtrim(trim((string)$cfg['jf_url']), '/');
    if ($base === '') { return ''; }
    $info = nf_info($cfg);
    return $base . '/web/#/details?id=' . $id . ($info['Id'] !== '' ? '&serverId=' . $info['Id'] : '');
}

function nf_ts($iso) {
    $t = strtotime(preg_replace('/\.\d+/', '', (string)$iso));
    return $t === false ? 0 : (int)$t;
}

function nf_ready($item) {
    return $item !== null && trim((string)($item['Overview'] ?? '')) !== '' && !empty($item['ImageTags']['Primary']);
}

// ---- text helpers -----------------------------------------------------------------------------
function nf_trunc($s, $max) {
    $s = trim((string)preg_replace('/\s+/u', ' ', (string)$s));
    if (mb_strlen($s) <= $max) { return $s; }
    $c = mb_substr($s, 0, $max);
    $p = mb_strrpos($c, ' ');
    if ($p !== false && $p > $max * 0.6) { $c = mb_substr($c, 0, $p); }
    return rtrim($c, " \t,;:.-") . "\u{2026}";
}

function nf_md($s) { return preg_replace('/([\\\\*_~`|>])/u', '\\\\$1', (string)$s); }

function nf_runtime($ticks) {
    $min = (int)round(((float)$ticks) / 10000000 / 60);
    if ($min <= 0) { return ''; }
    $h = intdiv($min, 60);
    $m = $min % 60;
    if ($h > 0) { return $h . ' óra' . ($m > 0 ? ' ' . $m . ' perc' : ''); }
    return $m . ' perc';
}

function nf_size($bytes) {
    $b = (float)$bytes;
    if ($b <= 0) { return ''; }
    if ($b >= 1073741824) { return number_format($b / 1073741824, 1, ',', '') . ' GB'; }
    return number_format($b / 1048576, 0, ',', '') . ' MB';
}

function nf_genres($list, $max = 4) {
    static $map = [
        'Action' => 'Akció', 'Adventure' => 'Kaland', 'Animation' => 'Animáció', 'Comedy' => 'Vígjáték',
        'Crime' => 'Krimi', 'Documentary' => 'Dokumentum', 'Drama' => 'Dráma', 'Family' => 'Családi',
        'Fantasy' => 'Fantasy', 'History' => 'Történelmi', 'Horror' => 'Horror', 'Music' => 'Zenei',
        'Musical' => 'Musical', 'Mystery' => 'Misztikus', 'Romance' => 'Romantikus',
        'Science Fiction' => 'Sci-fi', 'Sci-Fi & Fantasy' => 'Sci-fi és fantasy', 'TV Movie' => 'TV-film',
        'Thriller' => 'Thriller', 'War' => 'Háborús', 'Western' => 'Western', 'Action & Adventure' => 'Akció és kaland',
        'Kids' => 'Gyerek', 'News' => 'Hírek', 'Reality' => 'Valóságshow', 'Soap' => 'Szappanopera',
        'Talk' => 'Beszélgetős', 'War & Politics' => 'Háború és politika', 'Biography' => 'Életrajzi',
        'Sport' => 'Sport', 'Anime' => 'Anime',
    ];
    $out = [];
    foreach ((array)$list as $g) { $g = (string)$g; $out[] = $map[$g] ?? $g; }
    return implode(', ', array_slice($out, 0, $max));
}

function nf_cast($item) {
    $n = [];
    foreach ((array)($item['People'] ?? []) as $p) {
        if (($p['Type'] ?? '') === 'Actor' && !empty($p['Name'])) { $n[] = (string)$p['Name']; }
        if (count($n) >= 5) { break; }
    }
    return implode(', ', $n);
}

function nf_lang($code) {
    static $map = [
        'eng' => 'angol', 'hun' => 'magyar', 'ger' => 'német', 'deu' => 'német', 'fre' => 'francia', 'fra' => 'francia',
        'spa' => 'spanyol', 'ita' => 'olasz', 'jpn' => 'japán', 'kor' => 'koreai', 'chi' => 'kínai', 'zho' => 'kínai',
        'rus' => 'orosz', 'por' => 'portugál', 'pol' => 'lengyel', 'cze' => 'cseh', 'ces' => 'cseh', 'dut' => 'holland',
        'nld' => 'holland', 'swe' => 'svéd', 'tur' => 'török', 'hin' => 'hindi', 'dan' => 'dán', 'nor' => 'norvég',
        'fin' => 'finn', 'rum' => 'román', 'ron' => 'román', 'slo' => 'szlovák', 'slk' => 'szlovák', 'hrv' => 'horvát',
        'srp' => 'szerb', 'ukr' => 'ukrán', 'ara' => 'arab', 'heb' => 'héber', 'gre' => 'görög', 'ell' => 'görög',
        'tha' => 'thai', 'vie' => 'vietnami', 'bul' => 'bolgár', 'slv' => 'szlovén', 'bos' => 'bosnyák',
        'mac' => 'macedón', 'mkd' => 'macedón', 'alb' => 'albán', 'sqi' => 'albán', 'lit' => 'litván', 'lav' => 'lett',
        'est' => 'észt', 'ice' => 'izlandi', 'isl' => 'izlandi', 'nob' => 'norvég', 'nno' => 'norvég', 'per' => 'perzsa',
        'fas' => 'perzsa', 'ind' => 'indonéz', 'may' => 'maláj', 'msa' => 'maláj', 'cat' => 'katalán', 'baq' => 'baszk',
        'eus' => 'baszk', 'glg' => 'galego', 'afr' => 'afrikaans', 'ben' => 'bengáli', 'tam' => 'tamil', 'tel' => 'telugu',
        'fil' => 'filippínó', 'tgl' => 'filippínó', 'urd' => 'urdu',
    ];
    $c = strtolower(trim((string)$code));
    if ($c === '' || $c === 'und') { return 'ismeretlen'; }
    return $map[$c] ?? $c;
}

function nf_acodec($s) {
    $c = strtolower((string)($s['Codec'] ?? ''));
    $prof = (string)($s['Profile'] ?? '');
    switch ($c) {
        case 'ac3': return 'DD';
        case 'eac3': return 'DD+';
        case 'truehd': return 'TrueHD';
        case 'dts':
            if (stripos($prof, 'MA') !== false) { return 'DTS-HD MA'; }
            if (stripos($prof, 'HRA') !== false) { return 'DTS-HD HRA'; }
            if (stripos($prof, 'DTS:X') !== false || stripos($prof, 'X') === 0) { return 'DTS:X'; }
            return 'DTS';
        case 'aac': return 'AAC';
        case 'flac': return 'FLAC';
        case 'opus': return 'Opus';
        case 'mp3': return 'MP3';
        case '': return '';
    }
    return strpos($c, 'pcm') === 0 ? 'PCM' : strtoupper($c);
}

function nf_ch($n) {
    $m = [1 => '1.0', 2 => '2.0', 3 => '2.1', 4 => '4.0', 5 => '5.0', 6 => '5.1', 7 => '6.1', 8 => '7.1'];
    return $m[(int)$n] ?? '';
}

// "angol · DD+ 5.1 Atmos" lines plus "felirat: magyar (kényszerített), angol"
function nf_audio_sub($item) {
    $aud = [];
    $subs = [];
    foreach ((array)($item['MediaStreams'] ?? []) as $s) {
        $t = $s['Type'] ?? '';
        if ($t === 'Audio') {
            $atmos = stripos((string)($s['DisplayTitle'] ?? '') . ' ' . (string)($s['Profile'] ?? ''), 'atmos') !== false;
            $lang = nf_lang($s['Language'] ?? '');
            $tech = trim(nf_acodec($s) . ' ' . nf_ch($s['Channels'] ?? 0) . ($atmos ? ' Atmos' : ''));
            $line = $lang . ($tech !== '' ? ' · ' . $tech : '');
            if (!in_array($line, $aud, true)) { $aud[] = $line; }
        } elseif ($t === 'Subtitle') {
            // forced (foreign-parts-only) subtitles are not announced
            $hay = (string)($s['Title'] ?? '') . ' ' . (string)($s['DisplayTitle'] ?? '');
            if (!empty($s['IsForced']) || stripos($hay, 'forced') !== false || stripos($hay, 'kényszer') !== false) { continue; }
            $subs[nf_lang($s['Language'] ?? '')] = true;
        }
    }
    $lines = array_slice($aud, 0, 3);
    if ($subs) {
        $labels = array_keys($subs);
        // Hungarian first, the rest in file order
        usort($labels, function ($a, $b) { return (($a === 'magyar') ? 0 : 1) <=> (($b === 'magyar') ? 0 : 1); });
        $lines[] = 'felirat: ' . implode(', ', array_slice($labels, 0, 6));
    }
    return $lines ? implode("\n", $lines) : '';
}

// "2160p · HEVC · Dolby Vision · 12,4 GB"
function nf_quality($item, $size = null) {
    $v = null;
    foreach ((array)($item['MediaStreams'] ?? []) as $s) { if (($s['Type'] ?? '') === 'Video') { $v = $s; break; } }
    $parts = [];
    if ($v) {
        $w = (int)($v['Width'] ?? 0);
        $h = (int)($v['Height'] ?? 0);
        if ($w >= 3200 || $h >= 2000) { $parts[] = '2160p'; }
        elseif ($w >= 1800 || $h >= 1000) { $parts[] = '1080p'; }
        elseif ($w >= 1200 || $h >= 700) { $parts[] = '720p'; }
        elseif ($h > 0) { $parts[] = 'SD'; }
        $codec = strtolower((string)($v['Codec'] ?? ''));
        $cm = ['hevc' => 'HEVC', 'h264' => 'H.264', 'av1' => 'AV1', 'vp9' => 'VP9', 'mpeg2video' => 'MPEG-2'];
        if ($codec !== '') { $parts[] = $cm[$codec] ?? strtoupper($codec); }
        $r = (string)($v['VideoRangeType'] ?? '');
        if (stripos($r, 'DOVI') === 0) { $parts[] = 'Dolby Vision' . (stripos($r, 'HDR10') !== false ? ' / HDR10' : ''); }
        elseif ($r === 'HDR10Plus') { $parts[] = 'HDR10+'; }
        elseif ($r === 'HDR10') { $parts[] = 'HDR10'; }
        elseif ($r === 'HLG') { $parts[] = 'HLG'; }
    }
    if ($size === null) { $size = (float)($item['MediaSources'][0]['Size'] ?? 0); }
    $sz = nf_size($size);
    if ($sz !== '') { $parts[] = $sz; }
    return implode(' · ', $parts);
}

function nf_field($name, $value, $inline) {
    $value = trim((string)$value);
    if ($value === '') { return null; }
    return ['name' => $name, 'value' => mb_substr($value, 0, 1000), 'inline' => (bool)$inline];
}

// ---- cards ------------------------------------------------------------------------------------
function nf_build($cfg, $o) {
    $desc = '';
    if ($o['orig'] !== '') { $desc .= '*' . nf_md($o['orig']) . "*\n"; }
    if ($o['overview'] !== '') { $desc .= nf_md(nf_trunc($o['overview'], 280)) . "\n"; }
    if ($o['url'] !== '') { $desc .= "\n[Megnyitás a Jellyfinben](" . $o['url'] . ')'; }
    $info = nf_info($cfg);
    $host = strtoupper((string)(gethostname() ?: 'SERVER'));
    $emb = [
        'author'      => ['name' => $o['author']],
        'title'       => mb_substr($o['title'], 0, 256),
        'description' => trim($desc),
        'color'       => 6967248,
        'fields'      => array_values(array_filter($o['fields'])),
        'footer'      => ['text' => $host . ($info['Version'] !== '' ? ' · Jellyfin ' . $info['Version'] : '')],
    ];
    if ($o['ts'] > 0) { $emb['timestamp'] = gmdate('c', $o['ts']); }
    if ($o['url'] !== '') { $emb['url'] = $o['url']; }
    return ['payload' => ['username' => 'Streambox', 'embeds' => [$emb]], 'poster' => $o['poster'], 'backdrop' => $o['backdrop']];
}

function nf_card_movie($cfg, $m, $preview = false) {
    $name = (string)($m['Name'] ?? '?');
    $orig = (string)($m['OriginalTitle'] ?? '');
    if ($orig !== '' && mb_strtolower($orig) === mb_strtolower($name)) { $orig = ''; }
    $id = (string)$m['Id'];
    return nf_build($cfg, [
        'author'   => ($preview ? 'ELŐNÉZET · ' : '') . 'Új film',
        'title'    => $name . (!empty($m['ProductionYear']) ? ' (' . (int)$m['ProductionYear'] . ')' : ''),
        'orig'     => $orig,
        'overview' => (string)($m['Overview'] ?? ''),
        'url'      => nf_link($cfg, $id),
        'ts'       => nf_ts($m['DateCreated'] ?? ''),
        'fields'   => [
            nf_field('Hossz', nf_runtime($m['RunTimeTicks'] ?? 0), true),
            nf_field('Minőség', nf_quality($m), true),
            nf_field('Műfaj', nf_genres($m['Genres'] ?? []), true),
            nf_field('Hang · felirat', nf_audio_sub($m), false),
            nf_field('Szereplők', nf_cast($m), false),
        ],
        'poster'   => nf_image($cfg, $id, 'Primary', 400),
        'backdrop' => !empty($m['BackdropImageTags']) ? nf_image($cfg, $id, 'Backdrop', 1280) : null,
    ]);
}

// "S01E01 – S01E08", or a list when the episodes are not consecutive
function nf_ep_range($eps) {
    $codes = [];
    $nums = [];
    foreach ($eps as $e) {
        $s = (int)($e['ParentIndexNumber'] ?? 0);
        $n = (int)($e['IndexNumber'] ?? 0);
        $codes[] = [$s, $n];
        $nums[] = $n;
    }
    usort($codes, function ($a, $b) { return [$a[0], $a[1]] <=> [$b[0], $b[1]]; });
    $fmt = function ($c) { return sprintf('S%02dE%02d', $c[0], $c[1]); };
    if (count($codes) === 1) { return $fmt($codes[0]); }
    $contig = true;
    for ($i = 1; $i < count($codes); $i++) {
        if ($codes[$i][0] !== $codes[$i - 1][0] || $codes[$i][1] !== $codes[$i - 1][1] + 1) { $contig = false; break; }
    }
    if ($contig) { return $fmt($codes[0]) . ' – ' . $fmt($codes[count($codes) - 1]); }
    $list = array_map($fmt, array_slice($codes, 0, 8));
    return implode(', ', $list) . (count($codes) > 8 ? ', …' : '');
}

// $eps: the new episodes of one season (full items, with streams)
function nf_card_season($cfg, $series, $season, $eps, $preview = false) {
    usort($eps, function ($a, $b) {
        return [(int)($a['ParentIndexNumber'] ?? 0), (int)($a['IndexNumber'] ?? 0)] <=> [(int)($b['ParentIndexNumber'] ?? 0), (int)($b['IndexNumber'] ?? 0)];
    });
    $first = $eps[0];
    $sn = (int)($first['ParentIndexNumber'] ?? 0);
    $name = (string)($series['Name'] ?? ($first['SeriesName'] ?? '?'));
    $orig = (string)($series['OriginalTitle'] ?? '');
    if ($orig !== '' && mb_strtolower($orig) === mb_strtolower($name)) { $orig = ''; }
    $sid = (string)($series['Id'] ?? ($first['SeriesId'] ?? ''));
    $seasonId = (string)($season['Id'] ?? '');

    $hasFirst = false;
    $total = 0.0;
    $ts = 0;
    foreach ($eps as $e) {
        if ((int)($e['IndexNumber'] ?? 0) === 1) { $hasFirst = true; }
        $total += (float)($e['MediaSources'][0]['Size'] ?? 0);
        $ts = max($ts, nf_ts($e['DateCreated'] ?? ''));
    }
    $count = count($eps);
    $label = $hasFirst ? 'Új évad' : 'Új epizódok';
    $seasonTxt = $sn === 0 ? 'Különlegességek' : $sn . '. évad';

    $overview = trim((string)($season['Overview'] ?? ''));
    if ($overview === '') { $overview = (string)($series['Overview'] ?? ''); }

    $posterId = ($season && !empty($season['ImageTags']['Primary'])) ? $seasonId : $sid;
    $rt = nf_runtime($first['RunTimeTicks'] ?? 0);
    $epTxt = $count . ' új epizód: ' . nf_ep_range($eps);
    $sz = nf_size($total);

    return nf_build($cfg, [
        'author'   => ($preview ? 'ELŐNÉZET · ' : '') . $label,
        'title'    => $name . (!empty($series['ProductionYear']) ? ' (' . (int)$series['ProductionYear'] . ')' : '') . ' · ' . $seasonTxt,
        'orig'     => $orig,
        'overview' => $overview,
        'url'      => nf_link($cfg, $seasonId !== '' ? $seasonId : $sid),
        'ts'       => $ts,
        'fields'   => [
            nf_field('Epizódok', $epTxt, false),
            nf_field('Hossz', $rt !== '' ? 'epizódonként kb. ' . $rt : '', true),
            nf_field('Minőség', nf_quality($first, 0) . ($sz !== '' ? ' · összesen ' . $sz : ''), true),
            nf_field('Műfaj', nf_genres($series['Genres'] ?? []), true),
            nf_field('Hang · felirat', nf_audio_sub($first), false),
            nf_field('Szereplők', nf_cast($series), false),
        ],
        'poster'   => $posterId !== '' ? nf_image($cfg, $posterId, 'Primary', 400) : null,
        'backdrop' => ($sid !== '' && !empty($series['BackdropImageTags'])) ? nf_image($cfg, $sid, 'Backdrop', 1280) : null,
    ]);
}

// ---- weekly summary ---------------------------------------------------------------------------
// Use the server's time zone (Settings, Date and Time) for "Friday 18:00".
function nf_tz() {
    $v = @parse_ini_file('/var/local/emhttp/var.ini');
    $tz = is_array($v) ? (string)($v['timeZone'] ?? '') : '';
    if ($tz !== '' && in_array($tz, timezone_identifiers_list(), true)) { date_default_timezone_set($tz); }
}

function nf_hu_date($ts) {
    static $m = ['jan.', 'febr.', 'márc.', 'ápr.', 'máj.', 'jún.', 'júl.', 'aug.', 'szept.', 'okt.', 'nov.', 'dec.'];
    return $m[(int)date('n', $ts) - 1] . ' ' . (int)date('j', $ts) . '.';
}

// "2160p DV", "1080p", "2160p HDR"
function nf_res_short($item) {
    $parts = explode(' · ', nf_quality($item, 0));
    $out = [];
    if ($parts[0] !== '') { $out[] = $parts[0]; }
    foreach ($parts as $p) {
        if (strpos($p, 'Dolby Vision') === 0) { $out[] = 'DV'; break; }
        if (strpos($p, 'HDR') === 0 || $p === 'HLG') { $out[] = 'HDR'; break; }
    }
    return implode(' ', $out);
}

function nf_stars($item) {
    $r = (float)($item['CommunityRating'] ?? 0);
    return $r > 0 ? '★ ' . number_format($r, 1, ',', '') : '';
}

function nf_title_link($cfg, $name, $id) {
    $n = nf_md(str_replace(['[', ']'], ['(', ')'], (string)$name));
    $url = nf_link($cfg, $id);
    return $url !== '' ? '**[' . $n . '](' . $url . ')**' : '**' . $n . '**';
}

function nf_join_lines($lines, $budget) {
    $out = '';
    $n = 0;
    foreach ($lines as $l) {
        if (mb_strlen($out) + mb_strlen($l) + 40 > $budget) { return rtrim($out) . "\n…és még " . (count($lines) - $n) . ' további'; }
        $out .= $l . "\n";
        $n++;
    }
    return rtrim($out);
}

// Message payload for everything added since $from; null = nothing new, false = Jellyfin could not be read
function nf_weekly($cfg, $from, $now, $preview = false) {
    $md = nf_json($cfg, '/Items?IncludeItemTypes=Movie&Recursive=true&SortBy=DateCreated&SortOrder=Descending&Limit=100&Fields=' . NF_FIELDS);
    $ed = nf_json($cfg, '/Items?IncludeItemTypes=Episode&Recursive=true&SortBy=DateCreated&SortOrder=Descending&Limit=600&Fields=DateCreated');
    if ($md === null && $ed === null) { return false; }

    $mlines = [];
    foreach ((array)($md['Items'] ?? []) as $m) {
        if (nf_ts($m['DateCreated'] ?? '') < $from) { continue; }
        $line = nf_title_link($cfg, $m['Name'] ?? '?', (string)($m['Id'] ?? ''));
        if (!empty($m['ProductionYear'])) { $line .= ' (' . (int)$m['ProductionYear'] . ')'; }
        $bits = array_filter([nf_runtime($m['RunTimeTicks'] ?? 0), nf_res_short($m), nf_genres($m['Genres'] ?? [], 3), nf_stars($m)]);
        $mlines[] = $line . ($bits ? ' · ' . implode(' · ', $bits) : '');
    }

    $groups = [];
    foreach ((array)($ed['Items'] ?? []) as $e) {
        $ts = nf_ts($e['DateCreated'] ?? '');
        if ($ts < $from || empty($e['SeriesId'])) { continue; }
        $k = (string)$e['SeriesId'] . '|' . (int)($e['ParentIndexNumber'] ?? 0);
        if (!isset($groups[$k])) { $groups[$k] = ['eps' => [], 'ts' => 0, 'sid' => (string)$e['SeriesId'], 'name' => (string)($e['SeriesName'] ?? '?')]; }
        $groups[$k]['eps'][] = $e;
        $groups[$k]['ts'] = max($groups[$k]['ts'], $ts);
    }
    uasort($groups, function ($a, $b) { return $b['ts'] <=> $a['ts']; });
    $slines = [];
    $cache = [];
    foreach (array_slice($groups, 0, 25) as $g) {
        if (!array_key_exists($g['sid'], $cache)) { $cache[$g['sid']] = nf_item($cfg, $g['sid']); }
        $ser = $cache[$g['sid']] ?: [];
        $sn = (int)($g['eps'][0]['ParentIndexNumber'] ?? 0);
        $line = nf_title_link($cfg, $ser['Name'] ?? $g['name'], $g['sid']);
        if (!empty($ser['ProductionYear'])) { $line .= ' (' . (int)$ser['ProductionYear'] . ')'; }
        $line .= ' · ' . ($sn === 0 ? 'különlegességek' : $sn . '. évad') . ' · ' . count($g['eps']) . ' új epizód (' . nf_ep_range($g['eps']) . ')';
        $bits = array_filter([nf_genres($ser['Genres'] ?? [], 3), nf_stars($ser)]);
        $slines[] = $line . ($bits ? ' · ' . implode(' · ', $bits) : '');
    }

    $embeds = [];
    if ($mlines) { $embeds[] = ['title' => '🎬 Új filmek (' . count($mlines) . ')', 'description' => nf_join_lines($mlines, 2800), 'color' => 6967248]; }
    if ($slines) { $embeds[] = ['title' => '📺 Új sorozatok és évadok (' . count($slines) . ')', 'description' => nf_join_lines($slines, 2800), 'color' => 6967248]; }
    if (!$embeds) { return null; }
    $info = nf_info($cfg);
    $host = strtoupper((string)(gethostname() ?: 'SERVER'));
    $embeds[count($embeds) - 1]['footer'] = ['text' => $host . ' · ' . nf_hu_date($from) . ' – ' . nf_hu_date($now)];
    return [
        'username' => 'Streambox',
        'content'  => ($preview ? '**ELŐNÉZET** · ' : '') . '🍿 **Hétvégi műsor** – ezek érkeztek a héten, jó szórakozást!',
        'embeds'   => $embeds,
    ];
}

// ---- Discord ----------------------------------------------------------------------------------
// Returns ['ok'|'retry'|'fail', message]
function nf_send($hook, $payload, $poster = null, $backdrop = null, $attempt = 0) {
    $payload['allowed_mentions'] = ['parse' => []];
    $tmp = [];
    $files = [];
    if ($poster !== null && isset($payload['embeds'][0])) {
        $payload['embeds'][0]['thumbnail'] = ['url' => 'attachment://poster.jpg'];
        $files['poster.jpg'] = $poster;
    }
    if ($backdrop !== null && isset($payload['embeds'][0])) {
        $payload['embeds'][0]['image'] = ['url' => 'attachment://backdrop.jpg'];
        $files['backdrop.jpg'] = $backdrop;
    }
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    $ch = curl_init($hook);
    $opt = [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 40];
    if ($files) {
        $fields = ['payload_json' => $json];
        $i = 0;
        foreach ($files as $fname => $data) {
            $p = tempnam('/tmp', 'sbn');
            file_put_contents($p, $data);
            $tmp[] = $p;
            $fields['files[' . $i . ']'] = new CURLFile($p, 'image/jpeg', $fname);
            $i++;
        }
        $opt[CURLOPT_POSTFIELDS] = $fields;
    } else {
        $opt[CURLOPT_POSTFIELDS] = $json;
        $opt[CURLOPT_HTTPHEADER] = ['Content-Type: application/json'];
    }
    curl_setopt_array($ch, $opt);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_errno($ch);
    curl_close($ch);
    foreach ($tmp as $p) { @unlink($p); }

    if ($err) { return ['retry', 'network error ' . $err]; }
    if ($code === 200 || $code === 204) { return ['ok', '']; }
    if ($code === 429) {
        $d = json_decode((string)$body, true);
        $wait = is_array($d) && isset($d['retry_after']) ? (float)$d['retry_after'] : 2.0;
        if ($attempt < 1 && $wait <= 5.0) {
            usleep((int)(($wait + 0.3) * 1000000));
            return nf_send($hook, $payload, $poster, $backdrop, $attempt + 1);
        }
        return ['retry', 'rate limited'];
    }
    if ($code >= 500 || $code === 0) { return ['retry', 'Discord HTTP ' . $code]; }
    $d = json_decode((string)$body, true);
    return ['fail', 'Discord HTTP ' . $code . (is_array($d) && !empty($d['message']) ? ' (' . $d['message'] . ')' : '')];
}
