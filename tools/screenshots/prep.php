<?php
// php prep.php file.page  -> HTML fragment of the dashboard tile or settings page
// The page's own includes (/usr/local/emhttp/plugins/<id>/i18n.php, lang/...) are served from the repository through a
// temporary plugins folder; the language setting is English unless MT_LOCALE is set (for example hu_HU), which plays
// Unraid's display language (a translation file <plugin>/lang/<code>.json is then used where it exists).
$repo = realpath(__DIR__ . '/../..');
$tmp = sys_get_temp_dir() . '/mt_prep_' . getmypid();
$ids = ['sysbox' => 'sysbox', 'diskbox' => 'diskbox', 'streambox' => 'streambox', 'qbittorrent_widget' => 'seedbox', 'mediaserver_monitor' => 'monitor'];
@mkdir($tmp . '/boot/dynamix', 0777, true);
file_put_contents($tmp . '/boot/dynamix/dynamix.cfg', "[display]\nlocale=\"" . (getenv('MT_LOCALE') ?: '') . "\"\n");
foreach ($ids as $id => $dir) {
    @mkdir("$tmp/plugins/$id/lang", 0777, true);
    foreach (glob("$repo/$dir/*.php") ?: [] as $f) { copy($f, "$tmp/plugins/$id/" . basename($f)); }   // the page's own includes (lib.php ...)
    file_put_contents("$tmp/plugins/$id/lang.php", str_replace('/boot/config/plugins', "$tmp/boot", file_get_contents("$repo/common/lang.php")));
    copy("$repo/common/i18n.php", "$tmp/plugins/$id/i18n.php");
    file_put_contents("$tmp/plugins/$id/lang_block.php", str_replace("/boot/config/plugins", "$tmp/boot", file_get_contents("$repo/common/lang_block.php")));
    foreach (['bot.php', 'bot_block.php'] as $f) { file_put_contents("$tmp/plugins/$id/$f", str_replace('/boot/config/plugins', "$tmp/boot", file_get_contents("$repo/common/$f"))); }
    @mkdir("$tmp/plugins/$id/mtlang"); foreach (glob("$repo/common/lang/*.json") ?: [] as $f) { copy($f, "$tmp/plugins/$id/mtlang/" . basename($f)); }
    foreach (glob("$repo/$dir/lang/*.json") ?: [] as $f) { copy($f, "$tmp/plugins/$id/lang/" . basename($f)); }
}
// MT_TEST_DICT=streambox=/path/hu.json adds a test translation that is not in the repository
if (getenv('MT_TEST_DICT') && preg_match('/^([a-z_]+)=(.+)$/', getenv('MT_TEST_DICT'), $m)) { copy($m[2], "$tmp/plugins/{$m[1]}/lang/" . basename($m[2])); }
$src = file_get_contents($argv[1]);
$body = substr($src, strpos($src, "\n---\n") + 5);
$body = str_replace('/*@@TILE_CSS@@*/', file_get_contents($repo . '/common/tile.css'), $body);
$body = str_replace('/*@@SETTINGS_CSS@@*/', file_get_contents($repo . '/common/settings.css'), $body);
$body = str_replace('/usr/local/emhttp/plugins/', $tmp . '/plugins/', $body);
$mytiles = [];
$var = ['csrf_token' => 'x'];
ob_start();
eval('?>' . $body);
$out = ob_get_clean();
$tile = '';
foreach ($mytiles as $t) { $tile .= $t['column2'] ?? ''; }
$tile = preg_replace('/_\((.*?)\)_/', '$1', $tile);
echo $tile !== '' ? '<table class="dash_tile"><colgroup><col></colgroup>' . $tile . '</table>' . $out : $out;
exec('rm -rf ' . escapeshellarg($tmp));
