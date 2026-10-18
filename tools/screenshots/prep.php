<?php
// php prep.php file.page  -> HTML fragment of the dashboard tile or settings page
$src = file_get_contents($argv[1]);
$body = substr($src, strpos($src, "\n---\n") + 5);
$body = str_replace('/*@@TILE_CSS@@*/', file_get_contents(__DIR__ . '/../../common/tile.css'), $body);
$body = str_replace('/*@@SETTINGS_CSS@@*/', file_get_contents(__DIR__ . '/../../common/settings.css'), $body);
$mytiles = [];
$var = ['csrf_token' => 'x'];
ob_start();
eval('?>' . $body);
$out = ob_get_clean();
$tile = '';
foreach ($mytiles as $t) { $tile .= $t['column2'] ?? ''; }
$tile = preg_replace('/_\((.*?)\)_/', '$1', $tile);
echo $tile !== '' ? '<table class="dash_tile"><colgroup><col></colgroup>' . $tile . '</table>' . $out : $out;
