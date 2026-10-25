<?php
// Background worker: php tools_run.php find | upd | updbg | search <hash> | fsearch <id> <key> | sp [series] | spab | tick
if (PHP_SAPI !== 'cli') { exit(1); }
require_once __DIR__ . '/tools_ops.php';
@set_time_limit(0);
@ini_set('memory_limit', '512M');
$mode = $argv[1] ?? '';
if (!in_array($mode, ['find', 'upd', 'updbg', 'search', 'fsearch', 'sp', 'spab', 'tick'], true)) { exit(1); }
$lockName = ($mode === 'search' || $mode === 'fsearch') ? 'search_' . md5((string)($argv[2] ?? '')) : $mode;
$lock = @fopen('/tmp/qbt_tools_' . $lockName . '.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { exit(0); }
try {
    if ($mode === 'find') { st_find_scan(($argv[2] ?? '') === 'auto'); }
    elseif ($mode === 'upd') { st_upd_scan(); }
    elseif ($mode === 'updbg') { st_upd_bg(); }
    elseif ($mode === 'search') { st_upd_search((string)($argv[2] ?? '')); }
    elseif ($mode === 'sp') { st_sp_scan((string)($argv[2] ?? '')); }
    elseif ($mode === 'spab') { st_sp_ab_run(); }
    elseif ($mode === 'fsearch') { st_find_repl_search((string)($argv[2] ?? ''), (string)($argv[3] ?? '')); }
    else { st_tick(); }
} catch (Throwable $e) {
    if ($mode === 'sp') { st_json_write(ST_SP, ['state' => 'error', 't' => time(), 'msg' => 'Unexpected error: ' . $e->getMessage(), 'items' => []]); }
    elseif ($mode === 'find') { st_progress(ST_FIND, 'error', 'Unexpected error: ' . $e->getMessage()); }
    elseif ($mode === 'upd') { st_progress(ST_UPD, 'error', 'Unexpected error: ' . $e->getMessage()); }
    elseif ($mode === 'fsearch' && preg_match('/^f[0-9a-f]{12}$/', (string)($argv[3] ?? ''))) {
        $r = st_json_read(ST_RES, []); $r[(string)$argv[3]] = ['state' => 'error', 't' => time(), 'rows' => [], 'msg' => 'Unexpected error: ' . $e->getMessage(), 'mode' => 'repl']; st_json_write(ST_RES, $r);
    }
    elseif ($mode === 'search') {
        $r = st_json_read(ST_RES, []); $r[(string)($argv[2] ?? '')] = ['state' => 'error', 't' => time(), 'rows' => [], 'msg' => 'Unexpected error: ' . $e->getMessage()]; st_json_write(ST_RES, $r);
    }
}
