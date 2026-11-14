<?php
// Subtitler (item 162): new movies and episodes in Jellyfin without a subtitle in the chosen language (Hungarian by default) go to the
// worker in the claude container (hu-subsync skill, subs_auto.py) through docker exec. Bazarr gets subs_grace minutes
// first; the worker searches feliratok.eu and OpenSubtitles.com and aligns the best one to the video's English timing.
// Copying the subtitle next to the video happens here on the host (the container sees the media read only): by itself
// at subs_auto % and above when subs_copy = auto (the owner's deliberate exception to the click rule, 2026-11-13), else
// on the owner's click. Never over an existing file. Discord: one summary a day after 09:00 (dc_hu), errors at once.
require_once __DIR__ . '/hu_lib.php';   // wk_cfg, wk_http, wk_ts, wk_tz, hu_docker, hu_dc_t, nf_on_hu

const SUBS_CT = 'claude';
const SUBS_LOOK_DAYS = 14;         // Jellyfin items added in the last 14 days are looked at
const SUBS_MEDIA_HOST = '/mnt/user/data';   // /srv/data in the container
const SUBS_MEDIA_CT = '/srv/data';
const SUBS_WORK_CT = '/work/subsync';
// the subtitle language (a setting since 2026-11-13: the repo is public, not every user is Hungarian): ISO 639-1 -> the
// codes Jellyfin or a file name may carry it with (639-2 B and T, the English name); the same list is in subs_auto.py
const SUBS_MODELS = ['claude-opus-5-5', 'claude-sonnet-5-5'];   // the Claude translation's model (a Subtitler setting)
const SUBS_LANGS = ['hu' => 'hun hungarian magyar', 'en' => 'eng english', 'de' => 'ger deu german', 'fr' => 'fre fra french', 'es' => 'spa spanish',
    'it' => 'ita italian', 'nl' => 'dut nld dutch', 'pl' => 'pol polish', 'cs' => 'cze ces czech', 'sk' => 'slo slk slovak', 'ro' => 'rum ron romanian',
    'pt' => 'por portuguese', 'sv' => 'swe swedish', 'da' => 'dan danish', 'no' => 'nor nob norwegian', 'fi' => 'fin finnish', 'el' => 'gre ell greek',
    'tr' => 'tur turkish', 'hr' => 'hrv croatian', 'sr' => 'srp serbian', 'sl' => 'slv slovenian', 'bg' => 'bul bulgarian', 'ru' => 'rus russian', 'uk' => 'ukr ukrainian'];

function subs_cfg_file() { return getenv('SB_SUBS_CFG') ?: '/boot/config/plugins/streambox/subs.cfg'; }
function subs_cfg() {
    $c = ['subs_on' => '1', 'subs_lang' => 'hu', 'subs_copy' => 'auto', 'subs_auto' => '95', 'subs_grace' => '60', 'subs_src' => 'both', 'subs_min' => '85',
          'subs_osmax' => '8', 'subs_tl' => 'auto', 'subs_tlmax' => '6', 'subs_tlmodel' => 'claude-opus-5-5', 'subs_dir' => '/mnt/user/data/DEV/subsync',
          'subs_script' => '/work/unraid-mediaserver-tools/.claude/skills/hu-subsync/scripts/subs_auto.py'];
    $f = subs_cfg_file();
    if (is_file($f)) { $x = @parse_ini_file($f, false, INI_SCANNER_RAW); if (is_array($x)) { $c = array_merge($c, $x); } }
    $w = wk_cfg();   // Jellyfin address and key, dc_hu
    foreach (['jf_host', 'jf_port', 'jf_key', 'dc_hu'] as $k) { $c[$k] = (string)($w[$k] ?? ''); }
    $c['subs_copy'] = $c['subs_copy'] === 'ask' ? 'ask' : 'auto';
    $c['subs_src'] = $c['subs_src'] === 'feliratok' ? 'feliratok' : 'both';
    if (!isset(SUBS_LANGS[$c['subs_lang']])) { $c['subs_lang'] = 'hu'; }
    if (!in_array($c['subs_tl'], ['auto', 'click', 'off'], true)) { $c['subs_tl'] = 'auto'; }
    if (!in_array($c['subs_tlmodel'], SUBS_MODELS, true)) { $c['subs_tlmodel'] = SUBS_MODELS[0]; }
    return $c;
}
// the settings the page may change, checked; returns the error key or ''
function subs_cfg_save($in) {
    $c = subs_cfg();
    $int = function ($k, $lo, $hi) use ($in, $c) { $v = (int)($in[$k] ?? $c[$k]); return ($v < $lo || $v > $hi) ? null : (string)$v; };
    $lang = (string)($in['subs_lang'] ?? $c['subs_lang']);
    $new = ['subs_on' => nf_on_hu($in['subs_on'] ?? $c['subs_on']) ? '1' : '0', 'subs_lang' => isset(SUBS_LANGS[$lang]) ? $lang : null,
            'subs_copy' => ($in['subs_copy'] ?? $c['subs_copy']) === 'ask' ? 'ask' : 'auto',
            'subs_auto' => $int('subs_auto', 85, 100), 'subs_grace' => $int('subs_grace', 0, 2880),
            'subs_src' => ($in['subs_src'] ?? $c['subs_src']) === 'feliratok' ? 'feliratok' : 'both',
            'subs_min' => $int('subs_min', 50, 100), 'subs_osmax' => $int('subs_osmax', 0, 20),
            'subs_tl' => in_array($in['subs_tl'] ?? $c['subs_tl'], ['auto', 'click', 'off'], true) ? (string)($in['subs_tl'] ?? $c['subs_tl']) : null,
            'subs_tlmax' => $int('subs_tlmax', 0, 50),
            'subs_tlmodel' => in_array($in['subs_tlmodel'] ?? $c['subs_tlmodel'], SUBS_MODELS, true) ? (string)($in['subs_tlmodel'] ?? $c['subs_tlmodel']) : null,
            'subs_dir' => $c['subs_dir'], 'subs_script' => $c['subs_script']];
    foreach ($new as $v) { if ($v === null) { return 'value'; } }
    if ((int)$new['subs_min'] > (int)$new['subs_auto']) { return 'min_auto'; }
    $t = ''; foreach ($new as $k => $v) { $t .= $k . '="' . str_replace('"', '', $v) . '"' . "\n"; }
    return @file_put_contents(subs_cfg_file(), $t) === false ? 'write' : '';
}

// ---- paths between the container and the host ---------------------------------------------------------------------------------
function subs_host_path($cfg, $p) {
    $p = (string)$p;
    $w = getenv('SB_SUBS_WORKCT') ?: SUBS_WORK_CT;   // tests: the worker's scratch folder
    if (strpos($p, $w . '/') === 0) { return rtrim($cfg['subs_dir'], '/') . substr($p, strlen($w)); }
    if (strpos($p, SUBS_MEDIA_CT . '/') === 0) { return (getenv('SB_SUBS_MEDIA') ?: SUBS_MEDIA_HOST) . substr($p, strlen(SUBS_MEDIA_CT)); }
    return '';
}
function subs_state($cfg) {
    $j = json_decode((string)@file_get_contents(rtrim($cfg['subs_dir'], '/') . '/auto/state.json'), true);
    $s = is_array($j) ? $j : [];
    return array_merge(['items' => [], 'err' => [], 'run' => null, 't' => 0, 'os_used' => 0], $s);
}
// a click for the worker (it is the only writer of state.json): ops/<time>_<rand>.json
function subs_op($cfg, $op, $id, $extra = []) {
    $d = rtrim($cfg['subs_dir'], '/') . '/auto/ops';
    if (!is_dir($d)) { @mkdir($d, 0777, true); }
    $f = $d . '/' . sprintf('%.6f', microtime(true)) . '_' . bin2hex(random_bytes(3)) . '.json';
    $ok = @file_put_contents($f, json_encode(array_merge(['op' => $op, 'id' => $id], $extra))) !== false;
    if ($ok) { @chmod($f, 0666); }
    return $ok;
}
// clicks the worker has not read yet, by id (the page shows them at once)
function subs_pending($cfg) {
    $out = [];
    foreach ((array)@glob(rtrim($cfg['subs_dir'], '/') . '/auto/ops/*.json') as $f) {
        $o = json_decode((string)@file_get_contents($f), true);
        if (is_array($o) && isset($o['id'], $o['op'])) { $out[(string)$o['id']] = (string)$o['op']; }
    }
    return $out;
}
// the host's own small state: copies made, the Discord days (flash, written only when it changes)
function subs_host_file() { return getenv('SB_SUBS_HOST') ?: '/boot/config/plugins/streambox/subs_host.json'; }
function subs_host() {
    $j = json_decode((string)@file_get_contents(subs_host_file()), true);
    return array_merge(['copied' => [], 'dc_day' => '', 'dc_try' => 0, 'dc_try_day' => '', 'err_day' => []], is_array($j) ? $j : []);
}
function subs_host_save($h) {
    $f = subs_host_file(); $j = json_encode($h, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($j === false || $j === @file_get_contents($f)) { return; }
    if (@file_put_contents($f . '.tmp', $j) !== false) { @rename($f . '.tmp', $f); }
}
function subs_lock() {
    static $h = null;
    if ($h === null) { $h = @fopen(getenv('SB_SUBS_HOST') ? subs_host_file() . '.lock' : '/tmp/streambox_subs_host.lock', 'c') ?: false; }
    if ($h) { flock($h, LOCK_EX); }
    return $h;
}
function subs_unlock($h) { if ($h) { flock($h, LOCK_UN); } }

// ---- copying a subtitle next to its video ----------------------------------------------------------------------------------
// '' or an error code: 'state' (not ready), 'nofile' (our aligned file is gone), 'novideo', 'exists' (a Hungarian .srt is
// already there: never overwritten), 'copy'
function subs_copy($cfg, $x, $auto) {
    if (($x['st'] ?? '') !== 'ready') { return 'state'; }
    $src = subs_host_path($cfg, $x['out'] ?? ''); $vid = subs_host_path($cfg, $x['video'] ?? '');
    if ($src === '' || !is_file($src)) { return 'nofile'; }
    if ($vid === '' || !is_file($vid)) { return 'novideo'; }
    // a Claude translation is named as such (Jellyfin shows it as a subtitle in that language; Bazarr keeps looking for a human one)
    $dst = preg_replace('/\.[A-Za-z0-9]{2,4}$/', '', $vid) . '.' . $cfg['subs_lang'] . ((($x['by'] ?? '') === 'claude') ? '.claude' : '') . '.srt';
    if (file_exists($dst)) { return 'exists'; }
    $tmp = $dst . '.sbtmp';
    if (!@copy($src, $tmp)) { @unlink($tmp); return 'copy'; }
    // link() fails when the name appeared meanwhile: no overwrite even then
    if (!@link($tmp, $dst)) { @unlink($tmp); return file_exists($dst) ? 'exists' : 'copy'; }
    @unlink($tmp);
    @chmod($dst, 0666); @chown($dst, 'nobody'); @chgrp($dst, 'users');
    subs_op($cfg, 'copied', $x['id'], ['auto' => $auto ? 1 : 0]);
    $lk = subs_lock(); $h = subs_host();
    $h['copied'][] = ['id' => $x['id'], 'label' => (string)($x['label'] ?? ''), 't' => time(), 'auto' => $auto ? 1 : 0, 'score' => (int)($x['score'] ?? 0),
                      'claude' => (($x['by'] ?? '') === 'claude') ? 1 : 0];
    $h['copied'] = array_values(array_filter($h['copied'], function ($c) { return ($c['t'] ?? 0) > time() - 30 * 86400; }));
    subs_host_save($h); subs_unlock($lk);
    return '';
}

// ---- Jellyfin: the new items without a Hungarian subtitle ---------------------------------------------------------------------
function subs_jf($cfg, $path) {
    if ((string)$cfg['jf_key'] === '' || !preg_match('/^[A-Za-z0-9.\-]+$/', (string)$cfg['jf_host'])) { return [null, 'nokey']; }
    return wk_http('http://' . $cfg['jf_host'] . ':' . (int)$cfg['jf_port'] . $path, ['Authorization: MediaBrowser Token="' . $cfg['jf_key'] . '"'], 30);
}
function subs_has_lang($item, $lang) {
    $codes = array_merge([$lang], explode(' ', SUBS_LANGS[$lang] ?? ''));
    foreach ((array)($item['MediaStreams'] ?? []) as $s) {
        if (($s['Type'] ?? '') !== 'Subtitle') { continue; }
        $hay = strtolower((string)($s['Title'] ?? '') . ' ' . (string)($s['DisplayTitle'] ?? ''));
        if (!empty($s['IsForced']) || strpos($hay, 'forced') !== false || strpos($hay, 'kényszer') !== false) { continue; }
        $l = strtolower((string)($s['Language'] ?? ''));
        if (in_array($l, $codes, true) || in_array(explode('-', $l)[0], $codes, true)) { return true; }
    }
    return false;
}
// [items for the worker] or null when Jellyfin did not answer
function subs_scan($cfg, $now) {
    [$j] = subs_jf($cfg, '/Items?IncludeItemTypes=Movie,Episode&Recursive=true&SortBy=DateCreated&SortOrder=Descending&Limit=300'
        . '&Fields=Path,MediaStreams,ProviderIds,DateCreated&EnableImages=false');
    if (!is_array($j)) { return null; }
    $imdb = null; $out = [];
    foreach ((array)($j['Items'] ?? []) as $it) {
        $id = (string)($it['Id'] ?? ''); $added = wk_ts($it['DateCreated'] ?? '');
        if (!preg_match('/^[0-9a-f]{32}$/', $id) || !$added || $added < $now - SUBS_LOOK_DAYS * 86400) { continue; }
        if (strpos((string)($it['Path'] ?? ''), '/media/') === false || subs_has_lang($it, $cfg['subs_lang'])) { continue; }
        $ep = ($it['Type'] ?? '') === 'Episode';
        $row = ['id' => $id, 'kind' => $ep ? 'episode' : 'movie', 'path' => (string)$it['Path'], 'added' => $added,
                'name' => (string)($it['Name'] ?? ''), 'year' => (int)($it['ProductionYear'] ?? 0), 'imdb' => (string)($it['ProviderIds']['Imdb'] ?? '')];
        if ($ep) {
            if ($imdb === null) {   // the series' IMDb ids (OpenSubtitles finds episodes by the series id)
                $imdb = [];
                [$sl] = subs_jf($cfg, '/Items?IncludeItemTypes=Series&Recursive=true&Fields=ProviderIds&EnableImages=false');
                foreach ((array)($sl['Items'] ?? []) as $x) { $imdb[(string)($x['Id'] ?? '')] = (string)($x['ProviderIds']['Imdb'] ?? ''); }
            }
            $row += ['series' => (string)($it['SeriesName'] ?? ''), 'season' => (int)($it['ParentIndexNumber'] ?? 0), 'episode' => (int)($it['IndexNumber'] ?? 0),
                     'series_imdb' => $imdb[(string)($it['SeriesId'] ?? '')] ?? ''];
            if ($row['season'] < 1 || $row['episode'] < 1) { continue; }   // specials and unnumbered files: not searched
        }
        $out[] = $row;
    }
    return $out;
}

// ---- the worker ------------------------------------------------------------------------------------------------------------
// [answer array or null, error code '' | 'docker' | 'ctstop' | 'timeout' | 'answer', detail]
function subs_worker($cfg, $items, $now) {
    $in = json_encode(['now' => $now, 'items' => $items, 'cfg' => ['grace_min' => (int)$cfg['subs_grace'], 'min_score' => (int)$cfg['subs_min'],
                       'sources' => $cfg['subs_src'], 'os_max' => (int)$cfg['subs_osmax'], 'budget_s' => 600, 'lang' => $cfg['subs_lang'], 'audio_skip' => 1,
                       'tl' => $cfg['subs_tl'], 'tl_max' => (int)$cfg['subs_tlmax'], 'tl_model' => $cfg['subs_tlmodel']]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $fake = getenv('SB_SUBS_FAKE');   // tests: a command that reads the same stdin (python3 subs_auto.py tick)
    $d = $fake ? '' : hu_docker();
    if (!$fake && $d === '') { return [null, 'docker', '']; }
    if (!$fake) {
        $o = []; @exec(escapeshellarg($d) . ' inspect -f {{.State.Running}} ' . SUBS_CT . ' 2>/dev/null', $o);
        if (trim(implode('', $o)) !== 'true') { return [null, 'ctstop', '']; }
    }
    $pf = tempnam('/tmp', 'sbsubs'); file_put_contents($pf, $in);
    $cmd = $fake ? $fake . ' < ' . escapeshellarg($pf) . ' 2>/dev/null'
         : 'timeout 840 ' . escapeshellarg($d) . ' exec -i -u claude -e HOME=/home/claude -w /home/claude ' . SUBS_CT .
           ' timeout -k 20 800 python3 ' . escapeshellarg($cfg['subs_script']) . ' tick < ' . escapeshellarg($pf) . ' 2>/dev/null';
    $o = []; $rc = 0; @exec($cmd, $o, $rc); @unlink($pf);
    if ($rc === 124) { return [null, 'timeout', '']; }
    $last = json_decode((string)end($o), true);
    if (!is_array($last) || empty($last['ok'])) { return [null, 'answer', mb_substr(implode(' ', $o), 0, 200)]; }
    return [$last, '', ''];
}
function subs_run_file() { return getenv('SB_SUBS_RUN') ?: '/tmp/streambox_subs_run.json'; }

// ---- one run: cron every 15 minutes, or the page's Search now -------------------------------------------------------------
function subs_tick($cfg, $now) {
    if (!nf_on_hu($cfg['subs_on'])) { return; }
    $items = subs_scan($cfg, $now);
    [$ans, $err, $detail] = subs_worker($cfg, $items ?? [], $now);
    @file_put_contents(subs_run_file(), json_encode(['t' => $now, 'jf' => $items === null ? 0 : 1, 'n' => is_array($items) ? count($items) : 0,
                                                     'err' => $err, 'detail' => $detail, 'ans' => $ans]));
    if ($cfg['subs_copy'] === 'auto') {
        $st = subs_state($cfg); $pend = subs_pending($cfg);
        foreach ($st['items'] as $x) {
            // a human subtitle for an episode Claude translated is never copied by itself (owner 2026-11-13: he decides)
            if (($x['st'] ?? '') === 'ready' && (int)($x['score'] ?? 0) >= (int)$cfg['subs_auto'] && !isset($pend[$x['id']]) && empty($x['nocopy']) && empty($x['over_claude'])) {
                subs_copy($cfg, $x, true);
            }
        }
    }
    subs_dc_errors($cfg, $now, $err);
    subs_dc_over($cfg, $now);
    subs_daily($cfg, $now);
}

// ---- Discord (dc_hu, the Discord language) ----------------------------------------------------------------------------------
function subs_dc_send($cfg, $text) {
    $hook = trim((string)$cfg['dc_hu']);
    if (!preg_match('#^https://(?:discord|discordapp)\.com/api/webhooks/\d{15,25}/[A-Za-z0-9_\-]{20,120}$#', $hook)) { return 0; }
    $fake = getenv('SB_SUBS_DC');   // tests: the message goes to this file
    if ($fake) { file_put_contents($fake, $text . "\n---\n", FILE_APPEND); return 204; }
    $ch = curl_init($hook);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['content' => mb_substr($text, 0, 1900), 'allowed_mentions' => ['parse' => []]], JSON_UNESCAPED_UNICODE)]);
    curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return $code;
}
// errors at once, each kind at most once a day: the worker's (OpenSubtitles key or quota, feliratok.eu down) and the run's
function subs_dc_errors($cfg, $now, $runErr) {
    $st = subs_state($cfg); $codes = array_keys((array)$st['err']);
    if (in_array($runErr, ['ctstop', 'timeout', 'answer'], true)) { $codes[] = 'run_' . $runErr; }
    if (!$codes || hu_quiet($now)) { return; }
    $today = date('Y-m-d', $now);
    $lk = subs_lock(); $h = subs_host(); $send = [];
    foreach ($codes as $c) { if (($h['err_day'][$c] ?? '') !== $today) { $send[] = $c; } }
    if (!$send) { subs_unlock($lk); return; }
    $lines = [hu_dc_t('subs.dc_err_head', subs_lang_vars($cfg))];
    foreach ($send as $c) { $lines[] = '• ' . hu_dc_t('subs.err_' . $c); }
    $code = subs_dc_send($cfg, implode("\n", $lines));
    if ($code >= 200 && $code < 300) { foreach ($send as $c) { $h['err_day'][$c] = $today; } }
    subs_host_save($h); subs_unlock($lk);
}
// {lang} and {lang_cap} (sentence start: "Magyar feliratok") in the Discord language
function subs_lang_vars($cfg) { $l = hu_dc_t('subs.lang.' . $cfg['subs_lang']); return ['lang' => $l, 'lang_cap' => mb_strtoupper(mb_substr($l, 0, 1)) . mb_substr($l, 1)]; }
// at once (a decision): a human subtitle turned up for an episode Claude translated; each item once
function subs_dc_over($cfg, $now) {
    if (hu_quiet($now)) { return; }
    $st = subs_state($cfg); $lk = subs_lock(); $h = subs_host(); $new = [];
    $sent = (array)($h['dc_over'] ?? []);
    foreach ($st['items'] as $x) { if (($x['st'] ?? '') === 'ready' && !empty($x['over_claude']) && !isset($sent[$x['id']])) { $new[] = $x; } }
    if (!$new) { subs_unlock($lk); return; }
    $tr = [];
    foreach ($new as $x) { $t = (string)($x['src']['translator'] ?? ''); $tr[] = (string)$x['label'] . ($t !== '' ? ' (' . $t . ')' : ''); }
    $code = subs_dc_send($cfg, hu_dc_t('subs.dc_over_head', subs_lang_vars($cfg)) . "\n" . hu_dc_t('subs.dc_over', ['n' => count($new), 'list' => subs_list($tr)]));
    if (($code >= 200 && $code < 300) || $code === 0) { foreach ($new as $x) { $sent[$x['id']] = $now; } }
    $h['dc_over'] = array_filter($sent, function ($t) use ($now) { return $t > $now - 40 * 86400; });
    subs_host_save($h); subs_unlock($lk);
}
function subs_list($a, $max = 8) { return implode(', ', array_slice($a, 0, $max)) . (count($a) > $max ? ' …' : ''); }
// episodes of one season in one entry: "Tulsa King S01 (9)"
function subs_group_labels($xs) {
    $g = [];
    foreach ($xs as $x) {
        $k = ($x['kind'] ?? '') === 'episode' ? sprintf('%s S%02d', $x['title'] ?? '', (int)($x['season'] ?? 0)) : (string)($x['label'] ?? '');
        $g[$k][] = (string)($x['label'] ?? $k);
    }
    $out = [];
    foreach ($g as $k => $l) { $out[] = count($l) > 1 ? $k . ' (' . count($l) . ')' : $l[0]; }
    return $out;
}
function subs_daily($cfg, $now) {
    $today = date('Y-m-d', $now);
    $h = subs_host();
    if ($h['dc_day'] === $today || (int)date('G', $now) < 9 || hu_quiet($now)) { return; }
    $st = subs_state($cfg); $since = $now - 86400; $pend = subs_pending($cfg);
    $copiedIds = array_flip(array_map(function ($c) { return (string)$c['id']; }, $h['copied']));
    $auto = []; $hand = []; $ready = []; $none = []; $bazarr = []; $cauto = []; $cready = [];
    foreach ($h['copied'] as $c) {
        if (($c['t'] ?? 0) <= $since) { continue; }
        $x = $st['items'][$c['id']] ?? ['label' => $c['label']];
        if (!empty($c['claude']) && !empty($c['auto'])) { $cauto[] = $x; } elseif (!empty($c['auto'])) { $auto[] = $x; } else { $hand[] = $x; }
    }
    foreach ($st['items'] as $x) {
        $s = $x['st'] ?? '';
        if ($s === 'ready' && !isset($pend[$x['id']]) && !isset($copiedIds[$x['id']]) && empty($x['over_claude'])) { if (($x['by'] ?? '') === 'claude') { $cready[] = $x; } else { $ready[] = $x; } }
        elseif (in_array($s, ['nohuman', 'noref', 'tl_wait', 'tl_run'], true) && ($x['found'] ?? 0) > $since) { $none[] = $x; }
        elseif ($s === 'has_hu' && ($x['t'] ?? 0) > $since) { $bazarr[] = $x; }
    }
    $lines = [];
    if ($auto) { $lines[] = hu_dc_t('subs.dc_auto', ['n' => count($auto), 'list' => subs_list(subs_group_labels($auto))]); }
    if ($cauto) { $lines[] = hu_dc_t('subs.dc_claude_auto', ['n' => count($cauto), 'list' => subs_list(subs_group_labels($cauto))]); }
    if ($cready) { $lines[] = hu_dc_t('subs.dc_claude_ready', ['n' => count($cready), 'list' => subs_list(subs_group_labels($cready))]); }
    if ($hand) { $lines[] = hu_dc_t('subs.dc_hand', ['n' => count($hand), 'list' => subs_list(subs_group_labels($hand))]); }
    if ($ready) { $lines[] = hu_dc_t('subs.dc_ready', ['n' => count($ready), 'list' => subs_list(subs_group_labels($ready))]); }
    if ($none) { $lines[] = hu_dc_t('subs.dc_none', ['n' => count($none), 'list' => subs_list(subs_group_labels($none))]); }
    if ($bazarr) { $lines[] = hu_dc_t('subs.dc_bazarr', ['n' => count($bazarr), 'list' => subs_list(subs_group_labels($bazarr))]); }
    $lk = subs_lock(); $h = subs_host();
    if (!$lines) { $h['dc_day'] = $today; subs_host_save($h); subs_unlock($lk); return; }
    $code = subs_dc_send($cfg, hu_dc_t('subs.dc_head', subs_lang_vars($cfg)) . "\n" . implode("\n", $lines));
    // sent, or 4 tries today (429, Discord down, a deleted webhook): done for today; else the next run tries again
    $h['dc_try'] = $h['dc_try_day'] === $today ? (int)$h['dc_try'] + 1 : 1; $h['dc_try_day'] = $today;
    if (($code >= 200 && $code < 300) || $h['dc_try'] >= 4 || $code === 0) { $h['dc_day'] = $today; }
    subs_host_save($h); subs_unlock($lk);
}
