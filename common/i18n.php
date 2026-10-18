<?php
// Dictionaries of the pages and tiles. Each plugin keeps its texts in <plugdir>/lang/<code>.json:
// en.json is the source (every key, with a note for the translator), <code>.json a translation of it.
// The language is the shared setting of lang.php (Follow Unraid or a chosen one); a missing file, key or
// form falls back to English, so a partial translation never breaks a page.
//   entry:        "key": {"text": "Hide", "note": "button ..."}
//   plural entry: "key": {"one": "{n} torrent", "other": "{n} torrents", "note": "..."}
//   placeholders: {name} in the text, filled from $vars / vars
// PHP: $T = mt_i18n_t('/usr/local/emhttp/plugins/<id>/lang'); echo $T('key', ['n' => 3]);
// JS:  echo mt_i18n_js($dir, 'mon'); then MTL.t('mon', 'key', {n: 3}) (pages wrap it as T('key'))
if (!function_exists('mt_i18n')) {

require_once __DIR__ . '/lang.php';

function mt_i18n($dir) {
    static $cache = [];
    if (isset($cache[$dir])) { return $cache[$dir]; }
    $read = function ($f) { $j = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null; return is_array($j) ? $j : []; };
    $en = $read($dir . '/en.json');
    $code = function_exists('mt_lang_ui_want') ? mt_lang_ui_want() : mt_lang_ui();   // an older lang.php of another plugin may have been loaded first
    $tr = $code !== 'en' && preg_match('/^[a-z]{2}$/', $code) ? $read($dir . '/' . $code . '.json') : [];
    $out = [];
    foreach ($en as $k => $v) {
        if ($k === '' || $k[0] === '_' || !is_array($v)) { continue; }
        $e = [];
        foreach (['text', 'one', 'other'] as $f) {
            if (!isset($v[$f])) { continue; }
            $t = $tr[$k][$f] ?? null;
            $e[$f] = (is_string($t) && trim($t) !== '') ? $t : (string)$v[$f];
        }
        $out[$k] = $e;
    }
    return $cache[$dir] = $out;
}

function mt_i18n_fmt($e, $vars) {
    if ($e === null) { return null; }
    if (isset($e['other'])) { $n = (float)($vars['n'] ?? 0); $s = (abs($n) == 1 && isset($e['one'])) ? $e['one'] : $e['other']; }
    else { $s = $e['text'] ?? ''; }
    return preg_replace_callback('/\{([a-z0-9_]+)\}/', function ($m) use ($vars) { return array_key_exists($m[1], $vars) ? (string)$vars[$m[1]] : $m[0]; }, $s);
}

// a translate function for one plugin's dictionary; an unknown key shows as itself (easy to spot)
function mt_i18n_t($dir) {
    $tab = mt_i18n($dir);
    return function ($key, $vars = []) use ($tab) { $s = mt_i18n_fmt($tab[$key] ?? null, (array)$vars); return $s === null ? $key : $s; };
}

// the same dictionary for the page's JavaScript: MTL.t(name, key, vars). $only limits it to some keys (a tile on the
// Dashboard or a header button does not need the texts of the tool pages): 'group.' takes every key that starts with
// it, anything else one exact key
function mt_i18n_js($dir, $name, $only = null) {
    $tab = mt_i18n($dir);
    if (is_array($only)) {
        $tab = array_filter($tab, function ($k) use ($only) {
            foreach ($only as $o) { if ($k === $o || (substr($o, -1) === '.' && strpos($k, $o) === 0)) { return true; } }
            return false;
        }, ARRAY_FILTER_USE_KEY);
    }
    return '<script>window.MTL=window.MTL||{d:{},t:function(n,k,v){var e=(this.d[n]||{})[k],s;if(!e){return k;}v=v||{};'
        . 'if(e.other!==undefined){var c=Math.abs(+v.n||0);s=(c===1&&e.one!==undefined)?e.one:e.other;}else{s=e.text||"";}'
        . 'return s.replace(/\{([a-z0-9_]+)\}/g,function(a,b){return v.hasOwnProperty(b)?String(v[b]):a;});}};'
        . 'MTL.d[' . json_encode((string)$name) . ']=' . json_encode((object)$tab, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . ';</script>';
}

}
