<?php
// Claude Guard morning brief (Sysbox, item 95 B): one Discord message every morning with the daily report, also when
// everything is fine. Claude's summary and findings on top, then parts made from the server's own data (no Claude):
// today's episodes (Streambox), downloads (Seedbox), waiting updates (Update manager), yesterday's network
// (Sysbox network meter) and, on the 1st of the month, last month's power (Sysbox energy). A part whose plugin or data
// is missing is left out. Only reads: the Streambox and Seedbox tile endpoints are run as CLI scripts (GET only).
require_once __DIR__ . '/guard_lib.php';
require_once __DIR__ . '/net_lib.php';
require_once __DIR__ . '/discord.php';

const GD_BRIEF_PARTS = ['b_ep', 'b_dl', 'b_upd', 'b_net', 'b_pow'];

// texts in the Discord language (lang/<code>.json)
function gd_bt($k, $v = []) { return sxnet_dt(mt_lang_discord(), $k, $v); }
function gd_bnum($x, $dec = 1) { return mt_lang_discord() === 'hu' ? number_format((float)$x, $dec, ',', ' ') : number_format((float)$x, $dec, '.', ','); }
function gd_bbytes($b) {
    $u = ['B', 'KB', 'MB', 'GB', 'TB']; $i = 0; $b = (float)$b;
    while ($b >= 1000 && $i < 4) { $b /= 1000; $i++; }
    return gd_bnum($b, $i >= 3 ? 1 : 0) . ' ' . $u[$i];
}
function gd_bshort($s, $n = 48) { $s = (string)$s; return mb_strlen($s) > $n ? mb_substr($s, 0, $n - 1) . '…' : $s; }

// another plugin's tile endpoint as a CLI script: its JSON or null (missing plugin, error, timeout)
function gd_bjson($envKey, $script) {
    $e = getenv($envKey);
    if ($e !== false && $e !== '') { $j = json_decode((string)@file_get_contents($e), true); return is_array($j) ? $j : null; }   // tests: a JSON file
    if (!is_file($script)) { return null; }
    $to = sx_bin('timeout'); $out = []; $rc = 0;
    @exec(($to !== '' ? $to . ' 45 ' : '') . escapeshellarg(sx_php()) . ' -d display_errors=0 ' . escapeshellarg($script) . ' 2>/dev/null', $out, $rc);
    $j = json_decode(implode("\n", $out), true);
    return $rc === 0 && is_array($j) ? $j : null;
}

// 📺 today's episodes from Streambox's This week's episodes
function gd_brief_ep($now) {
    $w = gd_bjson('SX_BR_WEEK', '/usr/local/emhttp/plugins/streambox/week.php');
    if (!$w || empty($w['ok'])) { return null; }
    $today = date('Y-m-d', $now); $rows = [];
    foreach ((array)($w['days'] ?? []) as $d) { foreach ((array)($d['rows'] ?? []) as $r) { if (date('Y-m-d', (int)$r['air']) === $today) { $rows[] = $r; } } }
    if (!$rows) { return gd_bt('brief.ep_none'); }
    $out = [];
    foreach (array_slice($rows, 0, 8) as $r) {
        $st = (string)($r['st'] ?? '');
        $tail = in_array($st, ['jf', 'file', 'scan', 'imp'], true) ? ' ✅' : ($st === 'dl' ? ' ⬇️ ' . (int)($r['pct'] ?? 0) . ' %' : ($st === 'late' ? ' · ' . gd_bt('brief.ep_late') : ($st === 'fail' ? ' · ' . gd_bt('brief.ep_fail') : '')));
        $out[] = gd_bshort($r['series'] ?? '?', 40) . ' ' . (string)($r['code'] ?? '') . ' (' . date('H:i', (int)$r['air']) . ')' . $tail;
    }
    if (count($rows) > 8) { $out[] = gd_bt('brief.more', ['n' => count($rows) - 8]); }
    return implode("\n", $out);
}

// ⬇️ downloads from the Seedbox tile (qBittorrent) and the Seedbox Tools jobs
function gd_brief_dl() {
    $q = gd_bjson('SX_BR_QB', '/usr/local/emhttp/plugins/qbittorrent_widget/api_fetch.php');
    if ($q === null) { return null; }
    if (($q['conn'] ?? '') !== 'connected') { return gd_bt('brief.dl_noconn'); }
    $out = []; $n = (int)($q['counts']['downloading'] ?? 0);
    $down = array_values(array_filter((array)($q['transfer'] ?? []), function ($t) { return ($t['dir'] ?? '') === 'down'; }));
    if ($n > 0) {
        $l = array_map(function ($t) { return gd_bshort($t['name'] ?? '?') . ' ' . (int)round(100 * (float)($t['progress'] ?? 0)) . ' %'; }, array_slice($down, 0, 3));
        $out[] = gd_bt('brief.dl_n', ['n' => $n]); foreach ($l as $x) { $out[] = '· ' . $x; }
    } else { $out[] = gd_bt('brief.dl_idle'); }
    $red = count(array_filter((array)($q['attention'] ?? []), function ($a) { return ($a['sev'] ?? '') === 'red' && !in_array($a['kind'] ?? '', ['conn', 'disc'], true); }));
    if ((int)($q['attention_total'] ?? 0) > count((array)($q['attention'] ?? []))) { $red = max($red, 1); }
    if ($red) { $out[] = '⚠️ ' . gd_bt('brief.dl_attn', ['n' => $red]); }
    $sb = gd_seedbox();
    if ($sb && $sb['failed_or_blocked']) { $out[] = '⚠️ ' . gd_bt('brief.dl_jobs', ['n' => count($sb['failed_or_blocked'])]); }
    return implode("\n", $out);
}

// 🔄 waiting updates from the Update manager's last check
function gd_brief_upd() {
    $s = upd_json(upd_scan_file(), []);
    if (!$s) { return null; }
    $l = [];
    foreach ((array)($s['rows'] ?? []) as $r) {
        if (($r['state'] ?? '') !== 'update') { continue; }
        $risk = in_array($r['risk'] ?? '', ['green', 'amber', 'red'], true) ? $r['risk'] : 'amber';
        $l[] = ['green' => '🟢', 'amber' => '🟡', 'red' => '🔴'][$risk] . ' ' . (string)$r['name'] . ' (' . gd_bt('brief.risk_' . $risk) . ')';
    }
    foreach ((array)($s['plugins'] ?? []) as $p) { if ((string)($p['new'] ?? '') !== '') { $l[] = '🧩 ' . (string)($p['ours'] ?? $p['id'] ?? '?') . ' ' . (string)$p['new']; } }
    if ((string)($s['os']['new'] ?? '') !== '') { $l[] = '💿 Unraid ' . (string)$s['os']['new']; }
    if (!$l) { return gd_bt('brief.upd_none'); }
    return gd_bt('brief.upd_n', ['n' => count($l)]) . "\n" . implode("\n", array_slice($l, 0, 8)) . (count($l) > 8 ? "\n" . gd_bt('brief.more', ['n' => count($l) - 8]) : '');
}

// 🌐 yesterday's traffic and outages from the network meter
function gd_brief_net($now) {
    if (!sx_on(sx_cfg()['show_net'] ?? '1')) { return null; }
    $h = upd_json(sxnet_hist_file(), []); $L = sxnet_live() ?: [];
    $days = array_merge((array)($h['days'] ?? []), (array)($L['days'] ?? []));
    $y = date('Y-m-d', strtotime('-1 day', $now));
    if (!isset($days[$y])) { return null; }
    $d = $days[$y];
    $line = '↓ ' . gd_bbytes($d[0]) . ' · ↑ ' . gd_bbytes($d[1]);
    $y0 = strtotime($y . ' 00:00'); $y1 = $y0 + 86400; $evs = [];
    foreach (array_merge((array)($h['events'] ?? []), (array)($L['events'] ?? [])) as $e) {
        $s = (int)($e['s'] ?? 0); $en = $e['e'] === null ? $now : (int)$e['e'];
        if ($s < $y1 && $en > $y0) { $evs[$s] = [$s, $en]; }
    }
    if (!$evs) { return $line . ' · ' . gd_bt('brief.net_noout'); }
    ksort($evs);
    $txt = array_map(function ($e) { return date('H:i', $e[0]) . '–' . date('H:i', $e[1]) . ' (' . gd_bt('brief.min', ['n' => max(1, (int)round(($e[1] - $e[0]) / 60))]) . ')'; }, array_slice(array_values($evs), 0, 3));
    return $line . "\n" . gd_bt('brief.net_out', ['n' => count($evs)]) . "\n· " . implode("\n· ", $txt);
}

// ⚡ last month's power, only on the 1st (owner: no daily power line)
function gd_brief_pow($now, $force = false) {
    if (!$force && (int)date('j', $now) !== 1) { return null; }
    $j = null;
    foreach ([getenv('SX_BR_EDAYS') ?: '/tmp/sysbox_edays.json', '/boot/config/plugins/sysbox/energy_days.json'] as $f) { $x = upd_json($f, []); if (!empty($x['days'])) { $j = $x; break; } }
    if (!$j) { return null; }
    $pm = date('Y-m', strtotime(date('Y-m-01', $now) . ' -1 month')); $wh = 0.0; $n = 0;
    foreach ($j['days'] as $d => $v) { if (strpos((string)$d, $pm) === 0) { $wh += (float)$v; $n++; } }
    if ($n === 0) { return null; }
    $cfg = sx_cfg(); $price = (float)str_replace(',', '.', (string)($cfg['kwh_price'] ?? '')); $kwh = $wh / 1000;
    $line = gd_bt('brief.mon_' . (int)substr($pm, 5, 2)) . ': ' . gd_bnum($kwh, 1) . ' kWh' . ($price > 0 ? ' · ~' . gd_bnum($kwh * $price, 0) . ' ' . (string)($cfg['currency'] ?? '') : '');
    $days = (int)date('t', strtotime($pm . '-01'));
    if ($n < $days) { $line .= ' · ' . gd_bt('brief.pow_days', ['n' => $n, 'd' => $days]); }
    return $line;
}

// the message: [embed] built from a report (daily or manual; error and skipped reports still get the server parts)
function gd_brief_embed($c, $r, $now = null) {
    $now = $now ?? time();
    $bad = array_values(array_filter((array)($r['items'] ?? []), function ($x) { return ($x['level'] ?? '') !== 'green'; }));
    $claude = empty($r['error']) && empty($r['skipped']);
    $v = $claude ? (string)($r['verdict'] ?? 'green') : '';
    $title = '☀️ ' . gd_bt('brief.hello') . ' ' . gd_bt('brief.dow_' . date('w', $now)) . ', ' . gd_bt('brief.date', ['m' => gd_bt('brief.mon_' . date('n', $now)), 'd' => date('j', $now)]);
    if ($bad) { $title .= ' · ' . ($v === 'red' ? '🔴' : '🟡') . ' ' . gd_bt('brief.look', ['n' => count($bad)]); }
    if ($claude) {
        $desc = ['green' => '🟢', 'amber' => '🟡', 'red' => '🔴'][$v] ?? '🟢';
        $desc .= ' ' . trim((string)($r['summary'] ?? ''));
        foreach ($bad as $x) { $desc .= "\n" . ($x['level'] === 'red' ? '🔴' : '🟡') . ' **' . $x['area'] . '**: ' . $x['title'] . ($x['todo'] !== '' ? ' ' . $x['todo'] : ''); }
    } else {
        $desc = '⚪ ' . (!empty($r['skipped']) ? (string)$r['skipped'] : gd_bt('brief.no_claude', ['e' => mb_substr((string)$r['error'], 0, 300)]));
    }
    $parts = [['b_ep', '📺 ' . gd_bt('brief.h_ep'), function () use ($now) { return gd_brief_ep($now); }],
              ['b_dl', '⬇️ ' . gd_bt('brief.h_dl'), function () { return gd_brief_dl(); }],
              ['b_upd', '🔄 ' . gd_bt('brief.h_upd'), function () { return gd_brief_upd(); }],
              ['b_net', '🌐 ' . gd_bt('brief.h_net'), function () use ($now) { return gd_brief_net($now); }],
              ['b_pow', '⚡ ' . gd_bt('brief.h_pow'), function () use ($now) { return gd_brief_pow($now); }]];
    $fields = [];
    foreach ($parts as [$k, $name, $fn]) {
        if (empty($c[$k])) { continue; }
        $t = $fn(); if ($t !== null && $t !== '') { $fields[] = [$name, $t]; }
    }
    $lvl = !$claude ? 'i' : ['green' => 'g', 'amber' => 'a', 'red' => 'rd'][$v];
    return sxn_embed($lvl, $title, mb_substr($desc, 0, 3500), $fields, 'Claude Guard');
}
// sends the brief with the Sysbox webhook: [ok, error]
function gd_brief_send($c, $r) {
    $hook = getenv('SX_BR_HOOK') ?: (string)(sx_cfg()['dc_hook'] ?? '');
    if ($hook === '') { return [false, sx_tr('gd.e_nohook')]; }
    [$st, $e] = sxn_post($hook, [gd_brief_embed($c, $r)]);
    return [$st === 'ok', $e];
}
