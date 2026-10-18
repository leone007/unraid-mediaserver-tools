<?php
// Shared language setting of the media server tools (Sysbox, Diskbox, Seedbox, Streambox): one file on the
// flash drive that every plugin reads, so changing it on any settings page changes it for all of them.
//   ui      = auto (follow Unraid's language, Settings, Display Settings) | en
//   discord = auto (follow Unraid) | en | hu
// A language the pages or Discord are not translated to falls back to English. Every plugin ships its own
// copy of this file; the guard keeps a second copy from being declared again in the same request.
if (!function_exists('mt_lang_cfg')) {

define('MT_LANG_FILE', '/boot/config/plugins/mediaserver-tools/lang.cfg');

function mt_lang_have($what) { return $what === 'discord' ? ['en', 'hu'] : ['en']; }

// Unraid's display language as a locale ('' = English, the built-in one)
function mt_lang_unraid() {
    $d = @parse_ini_file('/boot/config/plugins/dynamix/dynamix.cfg', true);
    return (is_array($d) && isset($d['display']) && is_array($d['display'])) ? trim((string)($d['display']['locale'] ?? '')) : '';
}
function mt_lang_code($locale) { $c = strtolower(substr((string)$locale, 0, 2)); return preg_match('/^[a-z]{2}$/', $c) ? $c : 'en'; }
function mt_lang_name($code) {
    $n = ['en' => 'English', 'hu' => 'Magyar', 'de' => 'Deutsch', 'fr' => 'Français', 'es' => 'Español', 'it' => 'Italiano', 'nl' => 'Nederlands',
          'pl' => 'Polski', 'pt' => 'Português', 'ru' => 'Русский', 'zh' => '中文', 'ja' => '日本語', 'ko' => '한국어', 'cs' => 'Čeština', 'sv' => 'Svenska'];
    return $n[$code] ?? $code;
}

// Before the shared setting existed every plugin had its own Discord language, Hungarian by default: a server
// that already sends Discord messages keeps the language it had; a new install starts in English.
function mt_lang_legacy() {
    $ini = function ($f) { $c = is_file($f) ? @parse_ini_file($f, false, INI_SCANNER_RAW) : false; return is_array($c) ? $c : null; };
    foreach (['/boot/config/plugins/sysbox/settings.cfg', '/boot/config/plugins/diskbox/settings.cfg'] as $f) {
        $c = $ini($f);
        if ($c === null) { continue; }
        if (isset($c['dc_lang'])) { return trim((string)$c['dc_lang'], "\"' ") === 'en' ? 'en' : 'hu'; }
        if (trim((string)($c['dc_hook'] ?? ''), "\"' ") !== '') { return 'hu'; }
    }
    $j = @json_decode((string)@file_get_contents('/boot/config/plugins/qbittorrent_widget/tools.json'), true);
    if (is_array($j)) {
        if (isset($j['dc_lang'])) { return $j['dc_lang'] === 'en' ? 'en' : 'hu'; }
        if (trim((string)($j['dc_hook'] ?? '')) !== '') { return 'hu'; }
    }
    $c = $ini('/boot/config/plugins/streambox/settings.cfg');
    if ($c !== null && (trim((string)($c['dc_movies'] ?? ''), "\"' ") !== '' || trim((string)($c['dc_series'] ?? ''), "\"' ") !== '')) { return 'hu'; }
    return 'en';
}

function mt_lang_cfg() {
    $c = ['ui' => 'auto', 'discord' => ''];
    $f = is_file(MT_LANG_FILE) ? @parse_ini_file(MT_LANG_FILE) : false;
    if (is_array($f)) {
        if (($f['ui'] ?? '') === 'auto' || in_array($f['ui'] ?? '', mt_lang_have('ui'), true)) { $c['ui'] = $f['ui']; }
        if (in_array($f['discord'] ?? '', ['auto', 'en', 'hu'], true)) { $c['discord'] = $f['discord']; }
    }
    if ($c['discord'] === '') { $c['discord'] = mt_lang_legacy(); }
    return $c;
}

// The language the pages and tiles ask for (the dictionaries of i18n.php fall back to English per text)
function mt_lang_ui_want() {
    $c = mt_lang_cfg();
    return $c['ui'] === 'auto' ? mt_lang_code(mt_lang_unraid()) : $c['ui'];
}
// The language the pages and tiles use, and the one Discord messages use (always one that exists)
function mt_lang_ui() {
    $c = mt_lang_cfg();
    $w = $c['ui'] === 'auto' ? mt_lang_code(mt_lang_unraid()) : $c['ui'];
    return in_array($w, mt_lang_have('ui'), true) ? $w : 'en';
}
function mt_lang_discord() {
    static $l = null;
    if ($l !== null) { return $l; }
    $c = mt_lang_cfg();
    $w = $c['discord'] === 'auto' ? mt_lang_code(mt_lang_unraid()) : $c['discord'];
    return $l = in_array($w, mt_lang_have('discord'), true) ? $w : 'en';
}

function mt_lang_save($ui, $discord) {
    if (($ui !== 'auto' && !in_array($ui, mt_lang_have('ui'), true)) || !in_array($discord, ['auto', 'en', 'hu'], true)) { return false; }
    @mkdir(dirname(MT_LANG_FILE), 0777, true);
    $tmp = MT_LANG_FILE . '.tmp';
    if (@file_put_contents($tmp, 'ui="' . $ui . "\"\ndiscord=\"" . $discord . "\"\n") === false) { return false; }
    return @rename($tmp, MT_LANG_FILE);
}

}
