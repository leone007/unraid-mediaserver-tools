<?php
// Hungarian descriptions (item 147): new movies and episodes whose Jellyfin description is English or empty, or whose
// episode title is only "N. epizód" or the English one, are translated by Claude Code in the claude container (claude -p,
// the owner's plan), after a wait (TMDb often gets the Hungarian text within a day). Suggest mode (default): the translation
// waits on the settings page for Write; Automatic mode (the owner's deliberate exception to the click rule, 2026-11-08):
// written at once. Every write backs up the original (Overview, Name, LockedFields) in the state and locks the changed
// field; Undo puts it back. Rules of the translation: the hu-overview skill (owner's style).
require_once __DIR__ . '/week_lib.php';   // wk_cfg, wk_http, wk_jf, wk_son, wk_sonarr, wk_tz
require_once __DIR__ . '/lang.php';       // mt_lang_discord

const HU_CT = 'claude';
const HU_KEEP_DAYS = 30;      // finished items stay in the state this long (the page shows the last 7 days)
const HU_LOOK_DAYS = 14;      // only items added to Jellyfin in the last 14 days are looked at
const HU_BATCH = 10;          // items per Claude run
const HU_TRIES = 3;           // a failing item is tried this many times (an hour apart)

function hu_cfg() {
    $c = array_merge(['hu_on' => '1', 'hu_mode' => 'suggest', 'hu_ov' => '1', 'hu_ti' => '1', 'hu_mv' => '1', 'hu_wait' => '12', 'hu_lim' => '30',
                      'dc_hu' => ''], wk_cfg());
    $c['hu_mode'] = $c['hu_mode'] === 'auto' ? 'auto' : 'suggest';
    return $c;
}
function hu_state_file() { return getenv('SB_HU_STATE') ?: '/boot/config/plugins/streambox/hu_state.json'; }
function hu_state() {
    $j = json_decode((string)@file_get_contents(hu_state_file()), true);
    $s = is_array($j) ? $j : [];
    return array_merge(['items' => [], 'day' => '', 'count' => 0, 'err' => '', 'err_t' => 0, 'run_t' => 0, 'dc_day' => ''], $s);
}
// every read-change-write of the state (cron run, page buttons, Discord line) holds this short lock; never around the Claude call
function hu_lock() {
    static $h = null;
    if ($h === null) { $h = @fopen((getenv('SB_HU_STATE') ? hu_state_file() . '.lock' : '/tmp/streambox_hu_state.lock'), 'c') ?: false; }
    if ($h) { flock($h, LOCK_EX); }
    return $h;
}
function hu_unlock($h) { if ($h) { flock($h, LOCK_UN); } }
function hu_state_save($s) {
    $f = hu_state_file(); $tmp = $f . '.tmp';
    if (@file_put_contents($tmp, json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) !== false) { @rename($tmp, $f); }
}
// Hungarian text: ő or ű (only Hungarian has them), else more Hungarian than English small words; one é or ü alone is
// not enough ("café", "Pokémon", German names in an English text); no small word at all: two accents
function hu_is_hu($t) {
    $t = (string)$t;
    if (preg_match('/[őűŐŰ]/u', $t)) { return true; }
    if (!preg_match('/[áéíóöúüÁÉÍÓÖÚÜ]/u', $t)) { return false; }
    $w = function ($re) use ($t) { return preg_match_all('/(?<![\\p{L}])(?:' . $re . ')(?![\\p{L}])/iu', $t); };
    $hu = $w('az|és|hogy|egy|nem|meg|de|is|már|még|aki|ami|amikor|után|között|ő|ez|azt|majd|pedig|csak|mint|vagy');
    $en = $w('the|and|of|to|in|with|his|her|he|she|is|an|for|on|when|their|they|from|who|that');
    return ($hu > 0 && $hu >= $en) || ($en === 0 && preg_match_all('/[áéíóöúüÁÉÍÓÖÚÜ]/u', $t) >= 2);   // short: "Luke új búvóhelyet keres.
}
function hu_generic($name) { return (bool)preg_match('/^\d+\. epizód$/u', trim((string)$name)); }
function hu_en_generic($t) { $t = trim((string)$t); return $t === '' || strtoupper($t) === 'TBA' || (bool)preg_match('/^(Episode|Ep\.?)\s*#?\s*\d+$/i', $t); }

// Jellyfin item as an object (an empty {} must stay {} when it is sent back: as [] Jellyfin answers 400)
function hu_jf_raw($cfg, $method, $path, $body = null) {
    if ((string)$cfg['jf_key'] === '' || !preg_match('/^[A-Za-z0-9.\-]+$/', (string)$cfg['jf_host'])) { return [0, null]; }
    $ch = curl_init('http://' . $cfg['jf_host'] . ':' . (int)$cfg['jf_port'] . $path);
    $h = ['Authorization: MediaBrowser Token="' . $cfg['jf_key'] . '"', 'Accept: application/json'];
    $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 20, CURLOPT_CUSTOMREQUEST => $method];
    if ($body !== null) { $o[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE); $h[] = 'Content-Type: application/json'; }
    $o[CURLOPT_HTTPHEADER] = $h; curl_setopt_array($ch, $o);
    $b = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$code, is_string($b) ? json_decode($b) : null];
}
// the item; null = Jellyfin says it is not there (404); false = Jellyfin did not answer (restart, network): not the item's fault
function hu_jf_item($cfg, $id) {
    static $uid = '';
    if ($uid === '') {
        [$c, $us] = hu_jf_raw($cfg, 'GET', '/Users');
        foreach ((array)$us as $u) { if (is_object($u) && !empty($u->Policy->IsAdministrator)) { $uid = (string)$u->Id; break; } }
    }
    if ($uid === '') { return false; }
    [$c, $it] = hu_jf_raw($cfg, 'GET', '/Items/' . rawurlencode($id) . '?userId=' . rawurlencode($uid));
    if ($c !== 200 || !is_object($it)) { [$c2, $it] = hu_jf_raw($cfg, 'GET', '/Users/' . rawurlencode($uid) . '/Items/' . rawurlencode($id)); $c = $c2 === 404 ? $c : $c2; }
    if ($c === 200 && is_object($it)) { return $it; }
    return $c === 404 ? null : false;
}

// ---- finding the items ------------------------------------------------------------------------------------------------
// Sonarr's English title and description of an episode, by the series' TVDB id (Jellyfin's names are Hungarian)
function hu_sonarr_ep($cfg, $tvdb, $s, $e) {
    static $ser = null, $eps = [];
    $son = wk_sonarr($cfg);
    if ($tvdb === '' || $son[0] === '') { return null; }
    if ($ser === null) {
        $ser = [];
        [$l] = wk_son($son, '/series', 20);
        foreach ((array)$l as $x) { if (!empty($x['tvdbId'])) { $ser[(string)$x['tvdbId']] = (int)$x['id']; } }
    }
    $sid = $ser[$tvdb] ?? 0; if (!$sid) { return null; }
    if (!isset($eps[$sid])) {
        $eps[$sid] = [];
        [$l] = wk_son($son, '/episode?seriesId=' . $sid, 20);
        foreach ((array)$l as $x) { $eps[$sid][(int)$x['seasonNumber'] . '|' . (int)$x['episodeNumber']] = ['t' => (string)($x['title'] ?? ''), 'o' => (string)($x['overview'] ?? '')]; }
    }
    return $eps[$sid][$s . '|' . $e] ?? null;
}
// new candidates: [id => item]; the ones already in the state are left out
function hu_scan($cfg, $st, $now) {
    $types = nf_on_hu($cfg['hu_mv']) ? 'Movie,Episode' : 'Episode';
    [$j] = wk_jf($cfg, '/Items?IncludeItemTypes=' . $types . '&Recursive=true&SortBy=DateCreated&SortOrder=Descending&Limit=300'
        . '&Fields=Overview,LockedFields,DateCreated,ProviderIds&EnableImages=false');
    if (!is_array($j)) { return null; }
    $tv = [];   // Jellyfin series id -> TVDB id
    [$sl] = wk_jf($cfg, '/Items?IncludeItemTypes=Series&Recursive=true&Fields=ProviderIds&EnableImages=false');
    foreach ((array)($sl['Items'] ?? []) as $x) { $tv[(string)($x['Id'] ?? '')] = (string)($x['ProviderIds']['Tvdb'] ?? ''); }
    $wait = max(0, (int)$cfg['hu_wait']) * 3600; $out = [];
    foreach ((array)($j['Items'] ?? []) as $it) {
        $id = (string)($it['Id'] ?? ''); $added = wk_ts($it['DateCreated'] ?? '');
        if ($id === '' || isset($st['items'][$id]) || !$added || $added < $now - HU_LOOK_DAYS * 86400 || $added > $now - $wait) { continue; }
        $lock = (array)($it['LockedFields'] ?? []); $ep = ($it['Type'] ?? '') === 'Episode';
        $ov = trim((string)($it['Overview'] ?? '')); $name = (string)($it['Name'] ?? '');
        $row = ['id' => $id, 'kind' => $ep ? 'episode' : 'movie', 'name_now' => $name, 'en' => $ov, 'src' => '', 'en_title' => '',
                'series' => (string)($it['SeriesName'] ?? ''), 'year' => (int)($it['ProductionYear'] ?? 0), 'code' => '', 'st' => 'new', 'found' => $now, 'tries' => 0];
        if ($ep) {
            $s = (int)($it['ParentIndexNumber'] ?? 0); $e = (int)($it['IndexNumber'] ?? 0);
            $row['code'] = sprintf('S%02dE%02d', $s, $e);
            $so = hu_sonarr_ep($cfg, $tv[(string)($it['SeriesId'] ?? '')] ?? '', $s, $e);
            if ($so) { $row['en_title'] = $so['t']; if ($ov === '') { $row['src'] = $so['o']; } }
        }
        if ($ov !== '' && !hu_is_hu($ov)) { $row['src'] = $ov; }
        $needOv = nf_on_hu($cfg['hu_ov']) && !in_array('Overview', $lock, true) && trim($row['src']) !== '' && !hu_is_hu($ov);
        $needTi = $ep && nf_on_hu($cfg['hu_ti']) && !in_array('Name', $lock, true) && !hu_en_generic($row['en_title'])
                  && (hu_generic($name) || ($name === $row['en_title'] && !hu_is_hu($name)));
        if (!$needOv && !$needTi) { continue; }
        $row['want_ov'] = $needOv; $row['want_ti'] = $needTi;
        $out[$id] = $row;
    }
    return $out;
}
function nf_on_hu($v) { return in_array(strtolower(trim((string)$v)), ['1', 'yes', 'true', 'on'], true); }
function hu_label($x) { return $x['kind'] === 'episode' ? trim($x['series'] . ' ' . $x['code']) : trim($x['name_now'] . ($x['year'] ? ' (' . $x['year'] . ')' : '')); }

// ---- Claude ---------------------------------------------------------------------------------------------------------
function hu_docker() {
    $t = getenv('SB_HU_DOCKER'); if ($t !== false && $t !== '') { return $t; }   // tests: a fake docker
    if (!file_exists('/var/run/docker.sock')) { return ''; }
    foreach (['/usr/bin/docker', '/usr/local/bin/docker'] as $d) { if (is_executable($d)) { return $d; } }
    return '';
}
function hu_prompt($batch) {
    $items = [];
    foreach ($batch as $x) {
        $i = ['id' => $x['id'], 'kind' => $x['kind'], 'title' => $x['kind'] === 'episode' ? $x['series'] : $x['name_now']];
        if ($x['kind'] === 'episode') { $i['episode'] = $x['code']; $i['english_title'] = $x['en_title']; }
        else { $i['year'] = $x['year']; }
        if ($x['want_ov']) { $i['english_description'] = $x['src']; }
        $i['translate'] = array_values(array_filter([$x['want_ov'] ? 'description' : '', $x['want_ti'] ? 'title' : '']));
        $items[] = $i;
    }
    return "You translate movie and TV episode descriptions and episode titles from English into Hungarian for a home Jellyfin "
        . "server. Series names are given as Jellyfin shows them (often already Hungarian). If you do not know a series or movie well, "
        . "look it up briefly with WebSearch first (plot, characters, the Hungarian title and terms used in Hungarian), so names and "
        . "terms are right. For an episode read its plot summary on IMDb too (if IMDb does not answer: TVDB, Rotten Tomatoes or a recap).\n\nStyle (the owner's rules):\n"
        . "- Short, natural, everyday Hungarian. Keep the content of the English text, add no facts. A side detail may go if it makes the sentence heavy.\n"
        . "- Plain words: \"neonáci\" (not a long paraphrase), \"fekete\" for African American. U.S. Marshal = \"rendőrbíró\". Names stay as they are.\n"
        . "- \"Season premiere\" = \"Évadnyitó rész.\", \"Season finale\" = \"Évadzáró rész.\", \"Series finale\" = \"Sorozatzáró rész.\"; season is always \"évad\", never \"szezon\".\n"
        . "- Aphoristic originals stay aphorisms. A text cut off with \"...\" is finished from the plot, without invented detail.\n"
        . "- Titles: always a Hungarian translation; proper nouns stay as they are (place and person names, invented words); hour titles in 24 h form (7:00 ... 21:00).\n"
        . "- No AI-sounding Hungarian (keep the facts, say them plainly; hype in the English text is toned down): no sales words (lenyűgöző, lebilincselő, "
        . "izgalmas utazás, sorsfordító, epikus, felejthetetlen); no \"nemcsak/nem csupán X, hanem Y\" the original does not have; no inflated stakes "
        . "(mindent megváltoztat, élete legnagyobb próbája); no dramatic closing line or fragment (\"Semmi sem lesz már a régi.\") and no closing question "
        . "(\"Vajon sikerül neki?\"); no trailing -va/-ve clause (\", ezzel is rávilágítva...\", \", megmutatva, hogy...\"); no forced list of three "
        . "(szerelem, árulás és bosszú); no dash as a connector (comma or a new sentence); \"van/lesz\" instead of \"szolgál valamiként\", \"rendelkezik\"; "
        . "not every sentence opening the same way.\n\n"
        . "Answer with JSON only, no other text: an array with one object per item: {\"id\": \"...\", \"hu\": \"<Hungarian description>\", \"hu_name\": \"<Hungarian title>\"}; "
        . "leave out hu when the item does not ask for a description and hu_name when it does not ask for a title.\n\nItems:\n"
        . json_encode($items, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
// the first JSON array of objects in Claude's text; the text may have words or a "Sources: [title](url)" list around it
function hu_json_list($text) {
    $n = strlen($text);
    for ($a = strpos($text, '['); $a !== false; $a = strpos($text, '[', $a + 1)) {
        if (!preg_match('/\G\[\s*\{/', $text, $m, 0, $a)) { continue; }
        $d = 0; $q = false;
        for ($i = $a; $i < $n; $i++) {
            $ch = $text[$i];
            if ($q) { if ($ch === '\\') { $i++; } elseif ($ch === '"') { $q = false; } continue; }
            if ($ch === '"') { $q = true; } elseif ($ch === '[' || $ch === '{') { $d++; }
            elseif (($ch === ']' || $ch === '}') && --$d === 0) {
                $j = json_decode(substr($text, $a, $i - $a + 1), true);
                if (is_array($j) && isset($j[0]['id'])) { return $j; }
                break;
            }
        }
    }
    return null;
}
// [answers by id or null, error code: '' | 'docker' | 'ctstop' | 'login' | 'limit' | 'timeout' | 'answer', detail]
function hu_ask($batch) {
    $fake = getenv('SB_HU_FAKE');
    if ($fake !== false && $fake !== '') { $text = (string)@file_get_contents($fake); $rc = 0; }
    else {
        $d = hu_docker(); if ($d === '') { return [null, 'docker', '']; }
        $o = []; @exec(escapeshellarg($d) . ' inspect -f {{.State.Running}} ' . HU_CT . ' 2>/dev/null', $o);
        if (trim(implode('', $o)) !== 'true') { return [null, 'ctstop', '']; }
        $pf = tempnam('/tmp', 'sbhu'); file_put_contents($pf, hu_prompt($batch));
        // timeout inside the container too: the outer one only ends docker exec, claude would run on (its 124 comes back through exec)
        // the prompt carries outside text (TMDb, Sonarr): Claude gets WebSearch only, no MCP server or claude.ai connector (Drive, Docs)
        $cmd = 'timeout 900 ' . escapeshellarg($d) . ' exec -i -u claude -e HOME=/home/claude -e ENABLE_CLAUDEAI_MCP_SERVERS=false -w /home/claude ' . HU_CT .
               ' timeout -k 20 870 claude -p --tools WebSearch --allowedTools WebSearch --strict-mcp-config --disallowedTools ' . escapeshellarg('mcp__*') .
               ' --no-session-persistence --output-format text < ' . escapeshellarg($pf) . ' 2>&1';
        $o = []; @exec($cmd, $o, $rc); @unlink($pf);
        $text = trim(implode("\n", $o));
    }
    if (preg_match('/not logged in|please run \/login|invalid api key|oauth token has expired/i', $text)) { return [null, 'login', mb_substr($text, 0, 200)]; }
    if ($rc === 124) { return [null, 'timeout', '']; }
    $j = hu_json_list($text);
    if ($j === null) {
        // the plan's usage limit: not the items' fault, they wait (hu_tick pauses an hour)
        if (preg_match('/usage limit|limit reached|hit your (?:usage )?limit|out of (?:extra )?usage|rate_limit_error/i', $text)) { return [null, 'limit', mb_substr($text, 0, 200)]; }
        return [null, 'answer', mb_substr($text, 0, 200)];
    }
    $out = [];
    foreach ($j as $x) {
        if (!is_array($x) || !isset($x['id'])) { continue; }
        $hu = trim((string)($x['hu'] ?? '')); $hn = trim((string)($x['hu_name'] ?? ''));
        $out[(string)$x['id']] = ['hu' => mb_substr($hu, 0, 2000), 'hu_name' => mb_substr($hn, 0, 200)];
    }
    return [$out, '', ''];
}

// ---- writing and undo -----------------------------------------------------------------------------------------------
// writes one translated item into Jellyfin; returns '' or an error code ('gone', 'changed', 'jf' = no answer, 'http NNN')
function hu_write($cfg, &$x) {
    $it = hu_jf_item($cfg, $x['id']);
    if ($it === false) { return 'jf'; }
    if (!$it) { return 'gone'; }
    $ov = trim((string)($it->Overview ?? '')); $name = (string)($it->Name ?? '');
    if (($x['want_ov'] && $ov !== trim($x['en'])) || ($x['want_ti'] && $name !== $x['name_now'])) { return 'changed'; }
    $lock = array_values((array)($it->LockedFields ?? []));
    $x['backup'] = ['Overview' => $it->Overview ?? '', 'Name' => $name, 'LockedFields' => $lock, 'LockData' => $it->LockData ?? false];
    if ($x['want_ov'] && ($x['hu'] ?? '') !== '') { $it->Overview = $x['hu']; if (!in_array('Overview', $lock, true)) { $lock[] = 'Overview'; } }
    if ($x['want_ti'] && ($x['hu_name'] ?? '') !== '' && $x['hu_name'] !== $name) { $it->Name = $x['hu_name']; if (!in_array('Name', $lock, true)) { $lock[] = 'Name'; } }
    $it->LockedFields = $lock;
    [$c] = hu_jf_raw($cfg, 'POST', '/Items/' . rawurlencode($x['id']), $it);
    return ($c >= 200 && $c < 300) ? '' : 'http ' . $c;
}
function hu_undo($cfg, &$x) {
    if (empty($x['backup'])) { return 'nobackup'; }
    $it = hu_jf_item($cfg, $x['id']);
    if ($it === false) { return 'jf'; }
    if (!$it) { return 'gone'; }
    $b = $x['backup'];
    $it->Overview = $b['Overview']; $it->Name = $b['Name']; $it->LockedFields = $b['LockedFields']; $it->LockData = $b['LockData'];
    [$c] = hu_jf_raw($cfg, 'POST', '/Items/' . rawurlencode($x['id']), $it);
    return ($c >= 200 && $c < 300) ? '' : 'http ' . $c;
}

// ---- one run (cron every 15 minutes, or the Retry button) ---------------------------------------------------------------
function hu_quiet($now) { $h = (int)date('G', $now); return $h >= 22 || $h < 6; }
function hu_tick($cfg, $now, $force = false) {
    $new = (nf_on_hu($cfg['hu_on']) && ($force || !hu_quiet($now))) ? hu_scan($cfg, hu_state(), $now) : null;   // Jellyfin and Sonarr calls, outside the lock
    $lk = hu_lock();
    $st = hu_state();
    $today = date('Y-m-d', $now);
    if ($st['day'] !== $today) { $st['day'] = $today; $st['count'] = 0; }
    foreach ($st['items'] as $id => $x) {   // forget finished items after 30 days, a run that died stays 'run' no longer than an hour
        if (in_array($x['st'], ['done', 'skip', 'undone', 'gone'], true) && ($x['t'] ?? $x['found']) < $now - HU_KEEP_DAYS * 86400) { unset($st['items'][$id]); }
        elseif ($x['st'] === 'run' && ($x['t'] ?? 0) < $now - 3600) { $st['items'][$id]['st'] = 'new'; }
    }
    if (!nf_on_hu($cfg['hu_on']) || (!$force && hu_quiet($now))) { hu_state_save($st); hu_unlock($lk); return $st; }
    if (!$force && $st['err'] === 'limit' && $st['err_t'] > $now - 3600) { hu_state_save($st); hu_unlock($lk); return $st; }   // usage limit: an hour's pause
    if (is_array($new)) { $st['items'] += $new; }
    $left = max(0, (int)$cfg['hu_lim'] - (int)$st['count']);
    $batch = [];
    foreach ($st['items'] as $id => $x) {
        if (count($batch) >= min($left, HU_BATCH)) { break; }
        if ($x['st'] === 'new' || ($x['st'] === 'fail' && ($x['tries'] ?? 0) < HU_TRIES && ($x['t'] ?? 0) < $now - 3600)) { $batch[$id] = $x; }
    }
    if (!$batch) { hu_state_save($st); hu_unlock($lk); return $st; }
    foreach ($batch as $id => $x) { $st['items'][$id]['st'] = 'run'; $st['items'][$id]['t'] = $now; }
    $st['run_t'] = $now; hu_state_save($st); hu_unlock($lk);
    [$ans, $err, $detail] = hu_ask($batch);
    $lk = hu_lock();
    $st = hu_state();   // the page may have changed something meanwhile
    if ($ans === null) {
        $st['err'] = $err; $st['err_t'] = $now; $st['err_detail'] = $detail;
        foreach ($batch as $id => $x) {
            if (!isset($st['items'][$id])) { continue; }
            // a login, limit or container problem is not the item's fault: it waits without using a try
            $wait = in_array($err, ['login', 'limit', 'docker', 'ctstop'], true);
            $st['items'][$id]['st'] = $wait ? 'new' : 'fail';
            if (!$wait) { $st['items'][$id]['tries'] = ($x['tries'] ?? 0) + 1; }
            $st['items'][$id]['t'] = $now;
        }
        hu_state_save($st); hu_unlock($lk); return $st;
    }
    $st['err'] = ''; $st['err_detail'] = '';
    foreach ($batch as $id => $x) {
        if (!isset($st['items'][$id]) || $st['items'][$id]['st'] !== 'run') { continue; }   // changed meanwhile
        $a = $ans[$id] ?? null; $y = &$st['items'][$id];
        if (!$a || ($y['want_ov'] && $a['hu'] === '' && (!$y['want_ti'] || $a['hu_name'] === ''))) { $y['st'] = 'fail'; $y['tries'] = ($y['tries'] ?? 0) + 1; $y['t'] = $now; unset($y); continue; }
        $y['hu'] = $a['hu']; $y['hu_name'] = $a['hu_name']; $y['t'] = $now; $st['count']++;
        if ($cfg['hu_mode'] === 'auto') {
            // a write that did not work keeps the translation: the item waits on the page for Write, it does not go back to Claude
            $e = hu_write($cfg, $y);
            $y['st'] = $e === '' ? 'done' : ($e === 'changed' || $e === 'gone' ? 'gone' : 'ready');
            if ($e === '') { $y['auto'] = 1; } else { $y['werr'] = $e; }
        } else { $y['st'] = 'ready'; }
        unset($y);
    }
    hu_state_save($st); hu_unlock($lk);
    return $st;
}

// ---- Discord: one line a day (own webhook dc_hu, empty: none), in the Discord language ----------------------------------------------------
function hu_dc_t($key, $vars = []) {
    static $tab = null;
    if ($tab === null) {
        $rd = function ($f) { $j = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null; return is_array($j) ? $j : []; };
        $en = $rd(__DIR__ . '/lang/en.json'); $tr = mt_lang_discord() === 'en' ? [] : $rd(__DIR__ . '/lang/' . mt_lang_discord() . '.json');
        $tab = ['en' => $en, 'tr' => $tr];
    }
    $e = $tab['en'][$key] ?? null; if (!$e) { return $key; }
    $t = $tab['tr'][$key] ?? [];
    $n = (float)($vars['n'] ?? 0);
    $f = isset($e['other']) ? ((abs($n) == 1 && isset($e['one'])) ? 'one' : 'other') : 'text';
    $s = (is_string($t[$f] ?? null) && trim($t[$f]) !== '') ? $t[$f] : (string)($e[$f] ?? '');
    return preg_replace_callback('/\{([a-z0-9_]+)\}/', function ($m) use ($vars) { return array_key_exists($m[1], $vars) ? (string)$vars[$m[1]] : $m[0]; }, $s);
}
function hu_daily($cfg, $now) {
    $st = hu_state();
    $today = date('Y-m-d', $now);
    if ($st['dc_day'] === $today || (int)date('G', $now) < 9 || hu_quiet($now)) { return; }
    $hook = trim((string)$cfg['dc_hu']);
    if (!preg_match('#^https://(?:discord|discordapp)\.com/api/webhooks/\d{15,25}/[A-Za-z0-9_\-]{20,120}$#', $hook)) { return; }
    $ready = []; $done = [];
    foreach ($st['items'] as $x) {
        if ($x['st'] === 'ready') { $ready[] = hu_label($x); }
        elseif ($x['st'] === 'done' && !empty($x['auto']) && ($x['t'] ?? 0) > $now - 86400) { $done[] = hu_label($x); }
    }
    if (!$ready && !$done && $st['err'] !== 'login') { $lk = hu_lock(); $st = hu_state(); $st['dc_day'] = $today; hu_state_save($st); hu_unlock($lk); return; }
    $lines = [];
    if ($done) { $lines[] = hu_dc_t('hu.dc_done', ['n' => count($done), 'list' => implode(', ', array_slice($done, 0, 8)) . (count($done) > 8 ? ' …' : '')]); }
    if ($ready) { $lines[] = hu_dc_t('hu.dc_ready', ['n' => count($ready), 'list' => implode(', ', array_slice($ready, 0, 8)) . (count($ready) > 8 ? ' …' : '')]); }
    if ($st['err'] === 'login') { $lines[] = hu_dc_t('hu.dc_login'); }
    $ch = curl_init($hook);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['content' => implode("\n", $lines), 'allowed_mentions' => ['parse' => []]], JSON_UNESCAPED_UNICODE)]);
    curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    // sent, or 4 tries today (429, Discord down, a deleted webhook): done for today; else the next run (15 min) tries again
    $lk = hu_lock(); $st = hu_state();
    $st['dc_try'] = ($st['dc_try_day'] ?? '') === $today ? (int)($st['dc_try'] ?? 0) + 1 : 1; $st['dc_try_day'] = $today;
    if (($code >= 200 && $code < 300) || $st['dc_try'] >= 4) { $st['dc_day'] = $today; }
    hu_state_save($st); hu_unlock($lk);
}
