<?php
// Shared Discord bot setting of the media server tools (Seedbox, Sysbox; item 95): one file on the flash drive with the
// bot token and the owner's Discord user id, so the bot is set once and every plugin's messages can get reactions.
//   token = the bot token (Discord Developer Portal, your app, Bot, Reset Token); never sent to a page
//   user  = the owner's user id: only this user's reactions count
// Before this file existed the bot lived in the Seedbox settings (tools.json bot_token, bot_user): while the file is
// missing those are used, and the first save writes the file (from then on an empty token in it means none).
// The bot has no gateway connection: messages go out through the plugin's webhook with ?wait=true, the bot adds the
// reactions and reads them back over REST, so it needs only View Channel, Add Reactions and Read Message History.
// Every plugin ships its own copy of this file; the guard keeps a second copy from being declared again.
if (!function_exists('mt_bot_cfg')) {

require_once __DIR__ . '/lang.php';

function mt_bot_file() { return getenv('MT_BOT_FILE') ?: '/boot/config/plugins/mediaserver-tools/discord_bot.cfg'; }

function mt_bot_cfg() {
    $f = mt_bot_file();
    if (is_file($f)) {
        $c = @parse_ini_file($f, false, INI_SCANNER_RAW);
        $c = is_array($c) ? $c : [];
        return ['token' => trim((string)($c['token'] ?? ''), "\"' "), 'user' => trim((string)($c['user'] ?? ''), "\"' "), 'src' => 'shared'];
    }
    $j = @json_decode((string)@file_get_contents(getenv('MT_BOT_SEEDBOX') ?: '/boot/config/plugins/qbittorrent_widget/tools.json'), true);
    $j = is_array($j) ? $j : [];
    return ['token' => trim((string)($j['bot_token'] ?? '')), 'user' => trim((string)($j['bot_user'] ?? '')), 'src' => 'seedbox'];
}
function mt_bot_ready() { $c = mt_bot_cfg(); return $c['token'] !== '' && preg_match('/^\d{15,25}$/', $c['user']) === 1; }

// $token: empty keeps the saved one, - removes it. Returns '' or the dictionary key of the error.
function mt_bot_save($token, $user) {
    $old = mt_bot_cfg();
    $token = trim((string)$token); $user = trim((string)$user);
    if ($token === '-') { $token = ''; }
    elseif ($token === '') { $token = $old['token']; }
    elseif (!preg_match('/^[A-Za-z0-9_\-.]{50,100}$/', $token)) { return 'bot.e.token'; }
    if ($user !== '' && !preg_match('/^\d{15,25}$/', $user)) { return 'bot.e.user'; }
    $f = mt_bot_file();
    @mkdir(dirname($f), 0777, true);
    if (@file_put_contents($f . '.tmp', 'token="' . $token . "\"\nuser=\"" . $user . "\"\n") === false || !@rename($f . '.tmp', $f)) { return 'bot.e.write'; }
    @chmod($f, 0600);
    return '';
}

// the texts of this file (Discord messages in the Discord language, page messages in the page language)
function mt_bot_dir() { return is_dir(__DIR__ . '/mtlang') ? __DIR__ . '/mtlang' : __DIR__ . '/lang'; }
function mt_bot_t($key, $vars = [], $discord = false) {
    static $tabs = [];
    $code = $discord ? mt_lang_discord() : mt_lang_ui();
    if (!isset($tabs[$code])) {
        $rd = function ($f) { $j = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null; return is_array($j) ? $j : []; };
        $en = $rd(mt_bot_dir() . '/en.json'); $tr = $code !== 'en' ? $rd(mt_bot_dir() . '/' . $code . '.json') : [];
        $tabs[$code] = [];
        foreach ($en as $k => $v) { if (is_array($v) && isset($v['text'])) { $t = $tr[$k]['text'] ?? ''; $tabs[$code][$k] = is_string($t) && trim($t) !== '' ? $t : (string)$v['text']; } }
    }
    $s = $tabs[$code][$key] ?? $key;
    return preg_replace_callback('/\{([a-z0-9_]+)\}/', function ($m) use ($vars) { return array_key_exists($m[1], $vars) ? (string)$vars[$m[1]] : $m[0]; }, $s);
}

// the Discord webhook of the plugin this copy belongs to (the bot reacts under that plugin's messages)
function mt_bot_hook() {
    if (getenv('MT_BOT_HOOK') !== false) { return (string)getenv('MT_BOT_HOOK'); }
    $p = basename(__DIR__);
    if ($p === 'qbittorrent_widget') {
        $j = @json_decode((string)@file_get_contents('/boot/config/plugins/qbittorrent_widget/tools.json'), true);
        return is_array($j) ? trim((string)($j['dc_hook'] ?? '')) : '';
    }
    $c = @parse_ini_file('/boot/config/plugins/' . $p . '/settings.cfg', false, INI_SCANNER_RAW);
    return is_array($c) ? trim((string)($c['dc_hook'] ?? ''), "\"' ") : '';
}

// one HTTP request: [code, body, error]
function mt_bot_http($method, $url, $headers, $body = null) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_HTTPHEADER => $headers]);
    if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
    $b = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $err = $b === false ? curl_error($ch) : '';
    curl_close($ch);
    return [$code, (string)$b, $err];
}
// Discord REST as the bot: [code, json, error]; one wait on a rate limit
function mt_bot_api($method, $path, $body = null) {
    $base = getenv('MT_BOT_API') ?: 'https://discord.com/api/v10';
    $tok = mt_bot_cfg()['token'];
    for ($try = 0; $try < 2; $try++) {
        $h = ['Authorization: Bot ' . $tok, 'User-Agent: DiscordBot (mediaserver-tools, 1)', 'Content-Type: application/json'];
        [$code, $b, $err] = mt_bot_http($method, $base . $path, $h, $body !== null ? json_encode($body, JSON_UNESCAPED_UNICODE) : ($method === 'PUT' ? '' : null));
        if ($err !== '') { return [0, null, $err]; }
        $j = json_decode($b, true);
        if ($code === 429 && $try === 0) { $w = is_array($j) ? (float)($j['retry_after'] ?? 1) : 1.0; if ($w <= 5) { usleep((int)(($w + 0.2) * 1e6)); continue; } }
        if ($code >= 400) { return [$code, $j, 'Discord HTTP ' . $code . (is_array($j) && !empty($j['message']) ? ': ' . $j['message'] : '')]; }
        return [$code, $j, ''];
    }
    return [429, null, 'Discord rate limit'];
}

// The Test button of the block: the token works, a message goes out with this plugin's webhook, the bot reacts under it
// and reads the reaction back (the three permissions). Nothing waits for the owner. Returns [ok, page text].
function mt_bot_test($plugin) {
    $c = mt_bot_cfg();
    if ($c['token'] === '') { return [false, mt_bot_t('bot.t.no_token')]; }
    [$code, $me, $e] = mt_bot_api('GET', '/users/@me');
    if ($e !== '') { return [false, mt_bot_t('bot.t.token', ['e' => $e])]; }
    $name = (string)($me['username'] ?? 'bot');
    $hook = mt_bot_hook();
    if ($hook === '') { return [false, mt_bot_t('bot.t.no_hook', ['p' => $plugin])]; }
    $emb = ['title' => mt_bot_t('bot.d.title', ['p' => $plugin], true), 'description' => mt_bot_t('bot.d.desc', ['bot' => $name], true), 'color' => 0x9A7DE6];
    [$hc, $hb, $he] = mt_bot_http('POST', $hook . (strpos($hook, '?') === false ? '?' : '&') . 'wait=true', ['Content-Type: application/json'], json_encode(['embeds' => [$emb]], JSON_UNESCAPED_UNICODE));
    $msg = json_decode($hb, true);
    if ($he !== '' || $hc >= 400 || !is_array($msg) || empty($msg['id'])) { return [false, mt_bot_t('bot.t.hook', ['e' => $he !== '' ? $he : 'HTTP ' . $hc])]; }
    $path = '/channels/' . (string)($msg['channel_id'] ?? '') . '/messages/' . $msg['id'] . '/reactions/' . rawurlencode("\u{2705}");
    [$rc, $rj, $re] = mt_bot_api('PUT', $path . '/@me');
    if ($re !== '') { return [false, mt_bot_t('bot.t.react', ['e' => $re])]; }
    [$gc, $gj, $ge] = mt_bot_api('GET', $path . '?limit=10');
    if ($ge !== '' || !is_array($gj)) { return [false, mt_bot_t('bot.t.read', ['e' => $ge !== '' ? $ge : 'HTTP ' . $gc])]; }
    return [true, trim(mt_bot_t('bot.t.ok', ['bot' => $name]) . ($c['user'] === '' ? ' ' . mt_bot_t('bot.t.no_user') : ''))];
}

}
