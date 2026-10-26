<?php
// Sysbox power outage on Discord (item 99). The UPS runs on battery for the set wait -> a message (it pings, also at
// night), its values updated every minute; the owner's 🛑 under it asks for ✅, and the ✅ runs Safe shutdown (qBittorrent
// first) and then powers the server off. qBittorrent stops by itself a few percent before the NUT plugin's own shutdown
// level (the owner allowed this one step without a click, 2026-10-26). A message when the power is back, or when the
// server is up again after an outage. NUT is read directly (upsd, port 3493): the NUT plugin keeps doing its own shutdown
// and nothing here changes it. The outage record lives on the flash drive (it has to outlive a shutdown) and is written
// only when something happens (the last-seen time at most every 5 minutes); short-lived things are in /tmp.
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/energy.php';
require_once __DIR__ . '/discord.php';
require_once __DIR__ . '/shutdown_lib.php';
require_once __DIR__ . '/bot.php';

const UPS_STOP = "\u{1F6D1}";
const UPS_OK = "\u{2705}";
const UPS_CONFIRM_TTL = 120;   // seconds the ✅ question waits

function ups_file() { return getenv('SX_UPS_FILE') ?: '/boot/config/plugins/sysbox/ups_event.json'; }
function ups_tmp_file() { return getenv('SX_UPS_TMP') ?: '/tmp/sysbox_ups_tmp.json'; }
function ups_load() { $j = is_file(ups_file()) ? json_decode((string)@file_get_contents(ups_file()), true) : null; return (is_array($j) ? $j : []) + ['ev' => null, 'last' => null]; }
function ups_tmp() { $j = is_file(ups_tmp_file()) ? json_decode((string)@file_get_contents(ups_tmp_file()), true) : null; return is_array($j) ? $j : []; }
function ups_boot_id() { $b = getenv('SX_UPS_BOOT'); return $b !== false ? $b : trim((string)@file_get_contents('/proc/sys/kernel/random/boot_id')); }

// The NUT plugin's shutdown rule: ['mode' => batt_level|batt_timer|sec_timer|none|'', 'level' => percent or null]
function ups_nut_rule() {
    $c = @parse_ini_file(getenv('SX_NUT_CFG') ?: '/boot/config/plugins/nut-dw/nut-dw.cfg', false, INI_SCANNER_RAW);
    if (!is_array($c)) { return ['mode' => '', 'level' => null]; }
    $mode = trim((string)($c['SHUTDOWN'] ?? 'sec_timer'), "\"' "); $lv = trim((string)($c['BATTERYLEVEL'] ?? ''), "\"' ");
    return ['mode' => $mode, 'level' => ($mode === 'batt_level' && is_numeric($lv)) ? (int)$lv : null];
}

function ups_rt($sec) { if ($sec === null) { return '–'; } $m = (int)floor($sec / 60); return intdiv($m, 60) . ':' . str_pad((string)($m % 60), 2, '0', STR_PAD_LEFT); }
function ups_pct($v) { return $v === null ? '–' : (string)round($v); }

// ---- Discord over the webhook (?wait=true gives the message id the bot reacts under) ---------------------------------
function ups_hook_url($hook, $msg = '') {
    $p = explode('?', $hook, 2);   // a webhook with ?thread_id=...: the message path goes before the query
    return $p[0] . ($msg !== '' ? '/messages/' . $msg : '') . (isset($p[1]) ? '?' . $p[1] : '');
}
function ups_ping($cfg) {
    $p = sxn_ping_text($cfg['dc_ping'] ?? '');
    if ($p === '' && preg_match('/^\d{15,25}$/', mt_bot_cfg()['user'])) { $p = '<@' . mt_bot_cfg()['user'] . '>'; }
    return $p;
}
function ups_payload($embed, $ping) {
    $am = ['parse' => []];
    if (preg_match('/^<@(\d+)>$/', $ping, $m)) { $am['users'] = [$m[1]]; } elseif (preg_match('/^<@&(\d+)>$/', $ping, $m)) { $am['roles'] = [$m[1]]; } elseif ($ping !== '') { $am['parse'] = ['everyone']; }
    $pl = ['embeds' => [$embed], 'allowed_mentions' => $am];
    if ($ping !== '') { $pl['content'] = $ping; }
    return json_encode($pl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}
// [message id, channel id, error]; an empty id with an error means try again on the next step
function ups_post($hook, $embed, $ping = '') {
    $u = ups_hook_url($hook); $u .= (strpos($u, '?') === false ? '?' : '&') . 'wait=true';
    [$code, $b, $err] = mt_bot_http('POST', $u, ['Content-Type: application/json'], ups_payload($embed, $ping));
    $m = json_decode($b, true);
    if ($err !== '' || $code >= 400 || !is_array($m) || empty($m['id'])) { return ['', '', $err !== '' ? $err : 'Discord HTTP ' . $code]; }
    return [(string)$m['id'], (string)($m['channel_id'] ?? ''), ''];
}
function ups_edit($hook, $mid, $embed) {
    if ($mid === '') { return; }
    $pl = json_encode(['embeds' => [$embed]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    mt_bot_http('PATCH', ups_hook_url($hook, $mid), ['Content-Type: application/json'], $pl);
}
function ups_react($ch, $mid, $em) { if ($ch !== '' && $mid !== '') { mt_bot_api('PUT', '/channels/' . $ch . '/messages/' . $mid . '/reactions/' . rawurlencode($em) . '/@me'); } }
// did the owner react with $em? null when the bot could not tell
function ups_owner_reacted($ch, $mid, $em) {
    $me = mt_bot_cfg()['user'];
    if ($ch === '' || $mid === '' || !preg_match('/^\d{15,25}$/', $me)) { return null; }
    [$c, $j, $e] = mt_bot_api('GET', '/channels/' . $ch . '/messages/' . $mid . '/reactions/' . rawurlencode($em) . '?limit=100');
    if ($e !== '' || !is_array($j)) { return null; }
    return in_array($me, array_map(function ($u) { return (string)($u['id'] ?? ''); }, $j), true);
}
function ups_bot_on($cfg) { return sx_on($cfg['ups_sd']) && mt_bot_ready(); }

// ---- the messages (Discord language) -----------------------------------------------------------------------------------
function ups_fields($U, $sock) {
    $f = [[sx_trd('ups.d.battery'), ups_pct($U['charge']) . ' %', true], [sx_trd('ups.d.runtime'), '~' . ups_rt($U['runtime']), true],
          [sx_trd('ups.d.load'), ($U['watts'] === null ? '–' : round($U['watts']) . ' W') . ($U['load'] !== null ? ' (' . round($U['load']) . ' %)' : ''), true]];
    if ($sock !== null) { $f[] = [sx_trd('ups.d.socket'), sx_trd($sock ? 'ups.d.sock_up' : 'ups.d.sock_down'), true]; }
    return $f;
}
// the first message: live while on battery, grey when closed ($closed = the footer text)
function ups_e_main($ev, $U, $cfg, $rule, $sock, $closed = '', $now = null) {
    $now = $now ?? time();
    $desc = sx_trd('ups.d.since', ['t' => date('H:i:s', (int)$ev['since']), 'd' => sxn_fmt_dur(max(60, $now - (int)$ev['since']))]);
    if ($closed !== '') { $foot = $closed; }
    else {
        $foot = ups_bot_on($cfg) ? sx_trd(sx_on($cfg['ups_confirm']) ? 'ups.d.how_stop_ok' : 'ups.d.how_stop') : sx_trd('ups.d.how_page');
        if ($rule['level'] !== null && $U['charge'] !== null && $U['runtime'] !== null && $U['charge'] > $rule['level']) {
            $eta = $now + (int)($U['runtime'] * ($U['charge'] - $rule['level']) / max(1, $U['charge']));
            $foot .= ' · ' . sx_trd('ups.d.nut_eta', ['l' => $rule['level'], 't' => date('H:i', $eta)]);
        } elseif ($rule['level'] !== null) { $foot .= ' · ' . sx_trd('ups.d.nut_at', ['l' => $rule['level']]); }
    }
    $e = sxn_embed('rd', '⚡ ' . sx_trd('ups.d.ob_t', ['h' => (string)@gethostname()]), $desc, ups_fields($U, $sock), $foot);
    if ($closed !== '') { $e['color'] = 0x80848E; }
    return $e;
}
// Safe shutdown progress, from its status file (Discord language; the page keeps the technical log)
function ups_e_sd($by) {
    $r = sd_status(); $st = (string)($r['state'] ?? ''); $stopped = (array)($r['stopped'] ?? []);
    $qb = count(array_filter($stopped, function ($n) { return stripos((string)$n, 'qbittorrent') !== false; }));
    $l = [];
    if ($qb) { $l[] = '✅ ' . sx_trd('ups.d.p_qb_ok'); } elseif ($st === 'running') { $l[] = '⏳ ' . sx_trd('ups.d.p_qb_wait'); }
    if (count($stopped) > $qb) { $l[] = '✅ ' . sx_trd('ups.d.p_n', ['n' => count($stopped) - $qb]); }
    if ($st === 'error' || ($st === 'off' && preg_match('/^FAILED|^still running/m', implode("\n", (array)($r['log'] ?? []))))) { $l[] = '⚠️ ' . sx_trd('ups.d.p_err'); }
    if ($st === 'off') { $l[] = '⏻ ' . sx_trd('ups.d.p_off'); }
    $t = $st === 'off' ? 'ups.d.sd_off' : ($st === 'cancelled' ? 'ups.d.sd_cancel' : 'ups.d.sd_t');
    $e = sxn_embed($st === 'cancelled' ? 'i' : 'a', '🛑 ' . sx_trd($t, ['by' => $by]), implode("\n", $l), [], sx_trd('ups.d.sd_page'));
    return [$e, $st . '|' . count($stopped)];
}
function ups_array_started() { $v = @parse_ini_file(getenv('SX_UPS_VAR') ?: '/var/local/emhttp/var.ini'); return is_array($v) && strtoupper((string)($v['mdState'] ?? '')) === 'STARTED'; }

// what the tile and the Discord summary keep of a finished outage
function ups_summary($ev, $end, $how, $minch) {
    return ['since' => (int)$ev['since'], 'end' => (int)$end, 'ch0' => $ev['ch0'], 'min' => $minch, 'how' => $how,
            'sent' => !empty($ev['sent']), 'off' => !empty($ev['sd']) ? 'discord' : (!empty($ev['lb']) ? 'nut' : ''), 'qb' => (int)($ev['qb'] ?? 0)];
}

function ups_spawn($mode, $off, $by) {
    $cmd = escapeshellarg(sx_php()) . ' ' . escapeshellarg(__DIR__ . '/shutdown_run.php') . ' ' . $mode . ' ' . ($off ? 'off' : 'keep') . ' ' . $by;
    @exec('setsid nohup ' . $cmd . ' >/dev/null 2>&1 &');
}

// One look at the UPS. Returns true while it runs on battery (the tick then looks again in 15 seconds).
function ups_step($cfg, $now = null) {
    $now = $now ?? time();
    $S = ups_load(); $S0 = json_encode($S); $T = ups_tmp(); $T0 = json_encode($T);
    $hook = (string)$cfg['dc_hook'];
    $dc = sx_on($cfg['ups_dc']) && sxn_hook_ok($hook);
    $bid = ups_boot_id(); $ping = $dc ? ups_ping($cfg) : '';
    [$U, ] = sx_ups_fetch((string)$cfg['ups_host'], (int)$cfg['ups_port'], (string)$cfg['ups_name']);
    $rule = ups_nut_rule();
    $done = function () use (&$S, $S0, &$T, $T0) {
        if (json_encode($S) !== $S0) { sxn_write(ups_file(), $S); }
        if (json_encode($T) !== $T0) { @file_put_contents(ups_tmp_file(), json_encode($T)); }
    };

    // the server is up again after an outage it did not see the end of (it was shut down, or it crashed)
    $ev = $S['ev'];
    if ($ev && (string)($ev['boot'] ?? '') !== $bid) {
        if (!empty($ev['sent']) && $dc) {
            $how = !empty($ev['sd']) ? sx_trd('ups.d.off_dc', ['t' => date('H:i', (int)$ev['sd'])]) : (!empty($ev['lb']) ? sx_trd('ups.d.off_nut', ['t' => date('H:i', (int)$ev['lb']), 'l' => $rule['level'] ?? '?']) : sx_trd('ups.d.off_unknown', ['t' => date('H:i', (int)($ev['seen'] ?? $ev['since']))]));
            $f = [[sx_trd('ups.d.outage'), date('Y-m-d H:i', (int)$ev['since']), true], [sx_trd('ups.d.off'), $how, true],
                  [sx_trd('ups.d.clean'), sx_trd(is_file(getenv('SX_UPS_FORCESYNC') ?: '/boot/config/forcesync') ? 'ups.d.unclean' : 'ups.d.clean_y'), false]];
            if ($U !== null) { $f[] = [sx_trd('ups.d.battery_now'), ups_pct($U['charge']) . ' % · ' . sx_trd($U['ob'] ? 'ups.d.still_ob' : 'ups.d.charging'), true]; }
            if (!ups_array_started()) { $f[] = ['⚠️ ' . sx_trd('ups.d.arr_t'), sx_trd('ups.d.arr_d'), false]; }
            if (!empty($ev['qb']) || !empty($ev['sd'])) { $f[] = [sx_trd('ups.d.cts'), sx_trd('ups.d.cts_d'), false]; }
            [$mid, , $err] = ups_post($hook, sxn_embed('a', '🔌 ' . sx_trd('ups.d.boot_t', ['h' => (string)@gethostname(), 't' => date('H:i', $now - (int)((float)@file_get_contents('/proc/uptime')))]), '', $f), $ping);
            if ($mid === '') { $done(); return false; }   // no network yet: the next minute tries again
        }
        $S['last'] = ups_summary($ev, (int)($ev['seen'] ?? $ev['since']), 'boot', $ev['min'] ?? $ev['ch0']); $S['ev'] = null; $ev = null; $T = [];
    }
    if ($U === null) { $done(); return false; }   // NUT does not answer: nothing to decide on

    $sock = null;
    if (trim((string)$cfg['socket_host']) !== '' && $U['ob']) {
        if (!isset($T['sock_t']) || $now - (int)$T['sock_t'] >= 60) { [$sk, ] = sx_socket_fetch((string)$cfg['socket_host'], (int)$cfg['socket_id']); $T['sock'] = $sk !== null; $T['sock_t'] = $now; }
        $sock = (bool)$T['sock'];
    }

    if ($U['ob']) {
        if (!$ev) { $ev = ['since' => $now, 'ch0' => $U['charge'], 'min' => $U['charge'], 'boot' => $bid, 'seen' => $now, 'sent' => 0]; $T = []; }
        if ($U['charge'] !== null && ($ev['min'] === null || $U['charge'] < $ev['min'] - 4)) { $ev['min'] = $U['charge']; }   // in 5 % steps on the flash
        if ($now - (int)($ev['seen'] ?? 0) >= 300) { $ev['seen'] = $now; }
        $T['min'] = min((float)($T['min'] ?? 100), (float)($U['charge'] ?? 100));

        // the first message after the wait
        if (empty($ev['sent']) && $now - (int)$ev['since'] >= (int)$cfg['ups_wait'] && $dc) {
            [$mid, $ch, $err] = ups_post($hook, ups_e_main($ev, $U, $cfg, $rule, $sock, '', $now), $ping);
            if ($mid !== '') {
                $ev['sent'] = $now; $ev['mid'] = $mid; $ev['ch'] = $ch; $T['edit'] = $now;
                if (ups_bot_on($cfg)) { ups_react($ch, $mid, UPS_STOP); }
                sxn_guard_event('ups', 'rd', ups_e_main($ev, $U, $cfg, $rule, $sock, '', $now));
            }
        } elseif (!empty($ev['sent']) && sx_on($cfg['ups_live']) && $now - (int)($T['edit'] ?? 0) >= 60 && empty($ev['sd'])) {
            ups_edit($hook, (string)$ev['mid'], ups_e_main($ev, $U, $cfg, $rule, $sock, '', $now)); $T['edit'] = $now;
        }

        // 🛑 -> (✅) -> Safe shutdown with power off
        if (!empty($ev['sent']) && $dc && ups_bot_on($cfg) && empty($ev['sd'])) {
            if (empty($ev['want'])) {
                if (!empty($ev['cmid'])) {
                    if ($now - (int)$ev['ct'] > UPS_CONFIRM_TTL) {
                        ups_edit($hook, (string)$ev['cmid'], sxn_embed('i', sx_trd('ups.d.ask_gone'), sx_trd('ups.d.ask_again')));
                        $ev['cmid'] = ''; $T['hold'] = true;
                    } elseif (ups_owner_reacted((string)$ev['ch'], (string)$ev['cmid'], UPS_OK) === true) { $ev['want'] = $now; }
                } else {
                    $r = ups_owner_reacted((string)$ev['ch'], (string)$ev['mid'], UPS_STOP);
                    if ($r === false) { $T['hold'] = false; }   // the 🛑 was taken back: a new one asks again
                    elseif ($r === true && empty($T['hold'])) {
                        if (sx_on($cfg['ups_confirm'])) {
                            [$cm, , ] = ups_post($hook, sxn_embed('a', sx_trd('ups.d.ask_t', ['h' => (string)@gethostname()]), sx_trd('ups.d.ask_d', ['s' => UPS_CONFIRM_TTL / 60])), $ping);
                            if ($cm !== '') { $ev['cmid'] = $cm; $ev['ct'] = $now; ups_react((string)$ev['ch'], $cm, UPS_OK); }
                        } else { $ev['want'] = $now; }
                    }
                }
            }
            // a qBittorrent stop of the UPS may still run: the shutdown starts after it
            if (!empty($ev['want']) && !sd_running()) {
                ups_spawn('stop', true, 'discord');
                $ev['sd'] = $now;
                [$pe, $pk] = ups_e_sd('Discord');
                [$pm, , ] = ups_post($hook, $pe);
                $ev['pmid'] = $pm; $T['sdk'] = $pk;
                ups_edit($hook, (string)$ev['mid'], ups_e_main($ev, $U, $cfg, $rule, $sock, sx_trd('ups.d.closed_sd', ['t' => date('H:i', $now)]), $now));
            }
        }
        if (!empty($ev['sd']) && !empty($ev['pmid']) && $dc) {
            [$e, $k] = ups_e_sd('Discord');
            if ($k !== ($T['sdk'] ?? '')) { ups_edit($hook, (string)$ev['pmid'], $e); $T['sdk'] = $k; }
        }

        // qBittorrent stops a few percent before the NUT plugin shuts the server down (needs its Battery Level mode)
        $gap = (int)$cfg['ups_qb'];
        if ($gap > 0 && $rule['level'] !== null && $U['charge'] !== null && $U['charge'] <= $rule['level'] + $gap && empty($ev['qb']) && empty($ev['sd']) && empty($ev['want'])) {
            if (!sd_running()) {
                $qbs = array_filter(sx_ps(true), function ($c) { return $c['state'] === 'running' && sd_is_qb($c); });
                $ev['qb'] = $now;
                if ($qbs) {
                    ups_spawn('stopqb', false, 'ups');
                    if ($dc) { [$qm, , ] = ups_post($hook, sxn_embed('a', '⚙️ ' . sx_trd('ups.d.qb_t', ['p' => ups_pct($U['charge'])]), sx_trd('ups.d.qb_d', ['l' => $rule['level']]))); $ev['qmid'] = $qm; }
                }
            }
        }
        // the NUT plugin shuts down now (its level, or the UPS says low battery, or upsmon set forced shutdown)
        if (empty($ev['lb']) && ($U['lb'] || preg_match('/\bFSD\b/', $U['status']) || ($rule['level'] !== null && $U['charge'] !== null && $U['charge'] <= $rule['level']))) {
            $ev['lb'] = $now;
            if ($dc) {
                ups_post($hook, sxn_embed('rd', '🔋 ' . sx_trd('ups.d.lb_t', ['p' => ups_pct($U['charge'])]), sx_trd('ups.d.lb_d', ['d' => sxn_fmt_dur(max(60, $now - (int)$ev['since']))])), $ping);
                if (!empty($ev['sent']) && empty($ev['sd'])) { ups_edit($hook, (string)$ev['mid'], ups_e_main($ev, $U, $cfg, $rule, $sock, sx_trd('ups.d.closed_nut', ['t' => date('H:i', $now)]), $now)); }
            }
        }
        $S['ev'] = $ev; $done();
        return true;
    }

    // on line again
    if ($ev) {
        $min = min((float)($ev['min'] ?? 100), (float)($T['min'] ?? 100));
        if (!empty($ev['sent']) && $dc) {
            $d = sx_trd('ups.d.back_d', ['d' => sxn_fmt_dur(max(60, $now - (int)$ev['since'])), 'a' => ups_pct($ev['ch0']), 'b' => ups_pct($min)]);
            if (!empty($ev['sd'])) { $d .= "\n" . sx_trd('ups.d.back_sd'); }
            elseif (!empty($ev['lb'])) { $d .= "\n" . sx_trd('ups.d.back_lb'); }
            else { $d .= ' ' . sx_trd('ups.d.back_ran') . (!empty($ev['qb']) ? "\n" . sx_trd('ups.d.back_qb') : ''); }
            [$mid, , $err] = ups_post($hook, sxn_embed('g', '✅ ' . sx_trd('ups.d.back_t', ['t' => date('H:i', $now)]), $d), '');
            if ($mid === '') { $done(); return false; }
            if (empty($ev['sd'])) { ups_edit($hook, (string)$ev['mid'], ups_e_main($ev, $U, $cfg, $rule, null, sx_trd('ups.d.closed_back', ['t' => date('H:i', $now)]), $now)); }
            if (!empty($ev['cmid'])) { ups_edit($hook, (string)$ev['cmid'], sxn_embed('i', sx_trd('ups.d.ask_gone'), sx_trd('ups.d.back_t', ['t' => date('H:i', $now)]))); }
        }
        $S['last'] = ups_summary($ev, $now, empty($ev['sent']) ? 'blip' : 'back', $min);
        $S['ev'] = null; $T = [];
    }
    $done();
    return false;
}

// The tile's line under the UPS figures: [kind, values] or null. ob/sd/qb while on battery, boot after a restart
// (the array not started yet), last for 7 days after an outage.
function ups_tile($now = null) {
    $now = $now ?? time(); $S = ups_load(); $ev = $S['ev']; $l = $S['last'];
    if ($ev && (string)($ev['boot'] ?? '') === ups_boot_id()) {
        if (!empty($ev['sd'])) { return ['k' => 'sd', 't' => (int)$ev['sd']]; }
        if (!empty($ev['qb'])) { return ['k' => 'qb', 't' => (int)$ev['qb'], 'd' => $now - (int)$ev['since']]; }
        return ['k' => 'ob', 'd' => $now - (int)$ev['since'], 'sent' => (int)($ev['sent'] ?? 0)];
    }
    if (!is_array($l) || $now - (int)$l['end'] > 7 * 86400) { return null; }
    if ($l['how'] === 'boot' && !ups_array_started() && $now - (int)$l['end'] < 86400) { return ['k' => 'boot', 'off' => (string)$l['off']]; }
    return ['k' => $l['how'] === 'blip' ? 'blip' : 'last', 'since' => (int)$l['since'], 'd' => max(1, (int)$l['end'] - (int)$l['since']), 'a' => $l['ch0'], 'b' => $l['min']];
}
