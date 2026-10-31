<?php
// Router part (item 132), the settings page's endpoint: the key line and install command, a new key, and Test and save
// (connects once, trusts the router's identity on this click, saves the connection and lists what the router has).
require_once __DIR__ . '/rtr_lib.php';
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { sx_json(['ok' => false, 'error' => 'method'], 405); }
sx_guard();

function rtr_out($r) { return ['keyline' => rtr_keyline($r), 'install' => rtr_install_cmd($r)]; }
function rtr_restart() {
    @exec('pkill -f ' . escapeshellarg('plugins/sysbox/rtrmon.php') . ' 2>/dev/null');
    if (sx_on(sx_cfg()['show_rtr'] ?? '0')) { @exec('setsid nohup flock -n /tmp/sysbox_rtr.lock ' . escapeshellarg(sx_php()) . ' ' . escapeshellarg(__DIR__ . '/rtrmon.php') . ' >/dev/null 2>&1 </dev/null &'); }
}

$op = (string)($_POST['op'] ?? '');
$r = rtr_check(['host' => $_POST['rtr_host'] ?? '', 'user' => $_POST['rtr_user'] ?? '', 'port' => $_POST['rtr_port'] ?? '22', 'path' => $_POST['rtr_path'] ?? '', 'sec' => $_POST['rtr_sec'] ?? '5']);
if (!is_array($r)) { sx_json(['ok' => false, 'error' => sx_tr('rtr.err_' . $r)], 400); }

if ($op === 'key' || $op === 'newkey') {
    if (!rtr_key_make($op === 'newkey')) { sx_json(['ok' => false, 'error' => sx_tr('rtr.err_keygen')], 500); }
    if ($op === 'newkey') { rtr_restart(); }
    sx_json(['ok' => true] + rtr_out($r));
}
if ($op === 'test') {
    if ($r['host'] === '' || $r['user'] === '') { sx_json(['ok' => false, 'error' => sx_tr('rtr.err_need')], 400); }
    if (!rtr_key_make(false)) { sx_json(['ok' => false, 'error' => sx_tr('rtr.err_keygen')], 500); }
    if (!rtr_cfg_save($r)) { sx_json(['ok' => false, 'error' => sx_tr('save.err_write')], 500); }
    @unlink(rtr_known_file());   // the owner asked for this router now: trust the identity it shows (after a firmware change it is new)
    $s = rtr_snap($r, true);
    rtr_restart();
    if (!$s['ok']) { sx_json(['ok' => false, 'error' => sx_tr('rtr.e_' . $s['err']), 'kind' => $s['err']] + rtr_out($r)); }
    sx_json(['ok' => true, 'modules' => rtr_modules($s['D']), 'name' => $s['D']['name'], 'fw' => $s['D']['fw'], 't' => date('H:i')] + rtr_out($r));
}
sx_json(['ok' => false, 'error' => 'op'], 400);
