<?php
// Web endpoint of the Claude button in the Unraid header (ClaudeButton.page). POST op=open:
// starts the claude container if it is stopped, starts Claude Code with Remote Control in the tmux session 'main' if it is not
// running there yet (claude --continue: the last conversation goes on), and returns the address of the session on claude.ai.
// Nothing is stopped or changed in a running session. Only fixed commands, no input from the request reaches the shell.
require_once __DIR__ . '/lib.php';

const CL_CONTAINER = 'claude';
const CL_USER      = 'claude';
const CL_HOME      = '/home/claude';
const CL_DIR       = '/work/unraid-mediaserver-tools';
const CL_TMUX      = 'main';
const CL_HOME_URL  = 'https://claude.ai/code';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { sx_json(['ok' => false, 'error' => 'method'], 405); }
sx_guard();

// Plan usage for the button's tooltip (op=usage, item 62): the status line script in the container (tools/claude_statusline.sh)
// writes ~/usage/hist.log (a line per change: time, 7-day %, its reset, 5-hour %, its reset) and touches ~/usage/seen.
// Read-only: the files are found through the container's /home/claude mount and only read here.
function cl_usage_dir($d) {
    $src = trim((string)@shell_exec(escapeshellarg($d) . ' inspect -f ' . escapeshellarg('{{range .Mounts}}{{if eq .Destination "' . CL_HOME . '"}}{{.Source}}{{end}}{{end}}') . ' ' . CL_CONTAINER . ' 2>/dev/null'));
    return $src !== '' ? $src . '/usage' : '';
}
function cl_usage($dir, $now) {
    $rows = [];
    foreach ((@file($dir . '/hist.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) as $l) {
        $p = preg_split('/\s+/', trim($l));
        if (count($p) >= 3 && is_numeric($p[0]) && is_numeric($p[1]) && is_numeric($p[2])) {
            $rows[] = [(int)$p[0], (float)$p[1], (int)$p[2], isset($p[3]) && is_numeric($p[3]) ? (float)$p[3] : null, isset($p[4]) && is_numeric($p[4]) ? (int)$p[4] : null];
        }
    }
    if (!$rows) { return null; }
    $last = end($rows);
    $seen = max($last[0], (int)@filemtime($dir . '/seen'));
    $week = $last[1]; $reset = $last[2]; $start = $reset - 7 * 86400;
    // today: the rises since local midnight; a new weekly window counts from 0. Unknown when the first sample of the day
    // has no earlier sample of the same window to compare with (the first day of logging).
    $mid = strtotime('today', $now); $today = 0.0; $known = true; $prev = null;
    foreach ($rows as $r) {
        if ($r[0] <= $mid) { $prev = $r; continue; }
        if ($prev !== null && $prev[2] === $r[2]) { $today += max(0, $r[1] - $prev[1]); }
        elseif ($r[2] - 7 * 86400 >= $mid - 60) { $today += $r[1]; }   // the window started today
        else { $known = false; }
        $prev = $r;
    }
    $days = max(1, (min($now, $reset) - $start) / 86400);
    $five = ($last[3] !== null && $last[4] !== null && $last[4] > $now) ? ['pct' => $last[3], 'reset' => $last[4]] : null;
    return ['week' => $week, 'reset' => $reset, 'expired' => $now >= $reset, 'today' => $known ? round($today, 1) : null,
            'avg' => round($week / $days, 1), 'quota' => round(100 / 7, 1), 'seen' => $seen, 'five' => $five, 'now' => $now];
}
if (($_POST['op'] ?? '') === 'usage') {
    $d = sx_docker();
    $dir = $d !== '' ? cl_usage_dir($d) : '';
    $u = $dir !== '' ? cl_usage($dir, time()) : null;
    $exp = $d !== '' ? cl_creds_exp($d) : null;
    sx_json($u ? ['ok' => true, 'login_exp' => $exp] + $u : ['ok' => false, 'login_exp' => $exp, 'error' => sx_tr('cl.e_nodata')]);
}

function cl_run($cmd, &$rc = null) { $o = []; @exec($cmd . ' 2>&1', $o, $rc); return trim(implode("\n", $o)); }
function cl_exec($d, $args, &$rc = null) {
    return cl_run(escapeshellarg($d) . ' exec -u ' . CL_USER . ' -e HOME=' . CL_HOME . ' -w ' . escapeshellarg(CL_DIR) . ' ' . CL_CONTAINER . ' ' . $args, $rc);
}
// the last claude.ai session address the terminal printed (Remote Control prints it when it starts)
function cl_url($d) {
    $t = cl_exec($d, 'tmux capture-pane -p -J -S -3000 -t ' . CL_TMUX);
    return preg_match_all('#https://claude\.ai/code[A-Za-z0-9/_\-?=&.%]*#', $t, $m) ? end($m[0]) : '';
}

// ---- login renewal (Claude Guard page, the "Claude login" band): the refresh token date, and a guided /login in the running
// session. The date is read from ~/.claude/.credentials.json through the /home/claude mount; only the number leaves this file,
// never a token. The keys go to the tmux session with send-keys -l (literal), the code is checked against a fixed alphabet.
function cl_creds_exp($d) {
    $u = cl_usage_dir($d);
    if ($u === '') { return null; }
    $j = json_decode((string)@file_get_contents(substr($u, 0, -strlen('/usage')) . '/.claude/.credentials.json'), true);
    $rt = is_array($j) ? ($j['claudeAiOauth']['refreshTokenExpiresAt'] ?? null) : null;
    return is_numeric($rt) ? (int)floor($rt / 1000) : null;
}
function cl_pane($d) { return cl_exec($d, 'tmux capture-pane -p -J -S -300 -t ' . CL_TMUX); }
// the newest OAuth link in the pane; a link the screen wrapped hard (item 145) is joined from the following URL-only lines
function cl_oauth($t) {
    $L = preg_split('/\r?\n/', $t); $url = '';
    foreach ($L as $i => $l) {
        if (!preg_match('#https://claude\.ai/oauth/authorize\S*#', $l, $m)) { continue; }
        $u = $m[0];
        for ($k = $i + 1; $k < count($L); $k++) {
            $n = trim($L[$k], " \t|\u{2502}");
            if ($n === '' || !preg_match('#^[A-Za-z0-9%&=_\-.~+/:?]+$#', $n)) { break; }
            $u .= $n;
        }
        $url = $u;
    }
    return $url;
}
function cl_running($d) {
    cl_exec($d, 'tmux has-session -t ' . CL_TMUX, $rc);
    return $rc === 0 && stripos(cl_exec($d, 'tmux display-message -p -t ' . CL_TMUX . ' ' . escapeshellarg('#{pane_current_command}')), 'claude') !== false;
}
function cl_keys($d, $text, $enter = true) {
    cl_exec($d, 'tmux send-keys -t ' . CL_TMUX . ' -l ' . escapeshellarg($text));
    if ($enter) { usleep(300000); cl_exec($d, 'tmux send-keys -t ' . CL_TMUX . ' Enter'); }
}
$op = (string)($_POST['op'] ?? '');
if (in_array($op, ['login_status', 'login_start', 'login_code', 'login_cancel'], true)) {
    $d = sx_docker();
    if ($d === '') { sx_json(['ok' => false, 'error' => sx_tr('cl.e_docker')]); }
    $base = ['now' => time(), 'exp' => cl_creds_exp($d)];
    if ($op === 'login_status') { sx_json(['ok' => true, 'running' => cl_running($d)] + $base); }
    if (!cl_running($d)) { sx_json(['ok' => false, 'error' => sx_tr('cl.e_notrun')] + $base); }
    if ($op === 'login_cancel') { cl_exec($d, 'tmux send-keys -t ' . CL_TMUX . ' Escape'); sx_json(['ok' => true] + $base); }
    if ($op === 'login_start') {
        $before = cl_oauth(cl_pane($d));
        cl_keys($d, '/login');
        $menu = false;
        for ($i = 0; $i < 20; $i++) {
            sleep(1); $t = cl_pane($d); $u = cl_oauth($t);
            if ($u !== '' && $u !== $before) { sx_json(['ok' => true, 'url' => $u] + $base); }
            // the login method menu: the first item (Claude account with subscription) is selected; Enter takes it
            if (!$menu && preg_match('/Claude account with subscription|Select login method/i', $t)) { cl_exec($d, 'tmux send-keys -t ' . CL_TMUX . ' Enter'); $menu = true; }
        }
        sx_json(['ok' => false, 'error' => sx_tr('cl.e_nolink')] + $base);
    }
    // login_code
    $code = trim((string)($_POST['code'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9#_\-.~]{8,600}$/', $code)) { sx_json(['ok' => false, 'error' => sx_tr('cl.e_badcode')] + $base); }
    $exp0 = $base['exp'];
    cl_keys($d, $code);
    for ($i = 0; $i < 25; $i++) {
        sleep(1);
        $exp = cl_creds_exp($d);
        if ($exp !== null && $exp !== $exp0) {
            sleep(2); cl_keys($d, '/remote-control');
            $url = ''; for ($k = 0; $k < 12 && $url === ''; $k++) { sleep(1); $url = cl_url($d); }
            sx_json(['ok' => true, 'renewed' => true, 'exp' => $exp, 'now' => time(), 'url' => $url]);
        }
        if (preg_match('/OAuth error|Invalid code|Login failed|authentication failed/i', cl_exec($d, 'tmux capture-pane -p -J -S -20 -t ' . CL_TMUX))) { break; }
    }
    sx_json(['ok' => false, 'error' => sx_tr('cl.e_codefail')] + $base);
}

$d = sx_docker();
if ($d === '') { sx_json(['ok' => false, 'error' => sx_tr('cl.e_docker')]); }
$host = preg_replace('/[^A-Za-z0-9_.\-]/', '', (string)gethostname()) ?: 'unraid';
$steps = [];

// 1. the container
$st = cl_run(escapeshellarg($d) . ' inspect -f {{.State.Running}} ' . CL_CONTAINER, $rc);
if ($rc !== 0) { sx_json(['ok' => false, 'error' => sx_tr('cl.e_noct', ['c' => CL_CONTAINER])]); }
if ($st !== 'true') {
    cl_run(escapeshellarg($d) . ' start ' . CL_CONTAINER, $rc);
    if ($rc !== 0) { sx_json(['ok' => false, 'error' => sx_tr('cl.e_nostart', ['c' => CL_CONTAINER])]); }
    for ($i = 0; $i < 20 && cl_run(escapeshellarg($d) . ' inspect -f {{.State.Running}} ' . CL_CONTAINER) !== 'true'; $i++) { sleep(1); }
    $steps[] = 'container started';
}

// 2. the tmux session and Claude Code in it
$cmd = 'claude --continue --remote-control ' . $host;
cl_exec($d, 'tmux has-session -t ' . CL_TMUX, $rc);
if ($rc !== 0) {
    cl_exec($d, 'tmux new-session -d -s ' . CL_TMUX . ' ' . escapeshellarg($cmd), $rc);
    if ($rc !== 0) { sx_json(['ok' => false, 'error' => sx_tr('cl.e_tmux')]); }
    $steps[] = 'Claude started with Remote Control';
} else {
    $cur = cl_exec($d, 'tmux display-message -p -t ' . CL_TMUX . ' ' . escapeshellarg('#{pane_current_command}'));
    if (stripos($cur, 'claude') === false) {
        // the session is there but Claude is not running in it (a plain shell): start it in the repo folder. The container's
        // own session starts in /work, and there --continue finds no conversation and the folder trust question waits for a key.
        cl_exec($d, 'tmux send-keys -t ' . CL_TMUX . ' ' . escapeshellarg('cd ' . CL_DIR . ' && ' . $cmd) . ' Enter');
        $steps[] = 'Claude started with Remote Control in the existing session';
    }
}

// 3. the address: Remote Control prints it within a few seconds after a start
$url = cl_url($d);
for ($i = 0; $steps && $url === '' && $i < 15; $i++) { sleep(1); $url = cl_url($d); }
sx_json(['ok' => true, 'url' => $url !== '' ? $url : CL_HOME_URL, 'exact' => $url !== '',
         'msg' => $steps ? implode(', ', $steps) : 'Claude is already running']);
