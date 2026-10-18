<?php
// Progress of a qBittorrent "Moving" torrent. qBittorrent reports no percentage for a move, so the size of the files that have arrived
// at the destination (on any disk or pool under /mnt, the share path is the same on all of them) is compared with the torrent size.
// Two or more samples (at least 25 s apart) give the speed and with it the time left.
// Returns [fraction 0..0.99, seconds left or -1, waiting]. waiting is true when qBittorrent still reports the content at the place it would move
// to (a queued move has not started: the size there is the size of the source, which must not be shown as progress).
function st_move_dirsize($p) {
    // allocated bytes (blocks), not the file size: a move creates every target file at its full size at once and then fills it
    $al = function ($f) { $st = @stat($f); return $st ? (float)$st['blocks'] * 512 : 0.0; };
    if (is_file($p)) { return $al($p); }
    if (!is_dir($p)) { return 0.0; }
    $s = 0.0;
    try { foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($p, FilesystemIterator::SKIP_DOTS)) as $f) { if ($f->isFile()) { $s += $al($f->getPathname()); } } } catch (Throwable $e) {}
    return $s;
}
function st_move_info($t) {
    $dir = getenv('DBX_ST_DIR'); $dir = ($dir !== false && $dir !== '') ? $dir : '/boot/config/plugins/qbittorrent_widget';
    $c = is_file($dir . '/tools.json') ? json_decode((string)@file_get_contents($dir . '/tools.json'), true) : [];
    $mq = (string)($c['map_qb'] ?? '/data'); $mh = (string)($c['map_host'] ?? '/mnt/user/data');
    // while it moves, qBittorrent reports the SOURCE as content_path (for example /incomplete/...): the destination is save_path + name
    $cp = (string)($t['content_path'] ?? ''); $total = (float)($t['size'] ?? 0);
    if (!empty($t['save_path']) && !empty($t['name'])) { $cp = rtrim((string)$t['save_path'], '/') . '/' . $t['name']; }
    if ($cp === '' || $total <= 0) { return [0.0, -1, false]; }
    $same = rtrim((string)($t['content_path'] ?? ''), '/') === rtrim($cp, '/');
    if ($same) { return [0.0, -1, true]; }
    $host = ($mq !== '' && strpos($cp, $mq . '/') === 0) ? $mh . substr($cp, strlen($mq)) : $cp;
    $f = '/tmp/qbt_move_' . md5((string)($t['hash'] ?? $cp)) . '.json'; $now = time();
    $st = is_file($f) ? json_decode((string)@file_get_contents($f), true) : []; if (!is_array($st)) { $st = []; }
    if (!empty($st['r']) && $now - $st['r'][0] < 30) { return [$st['r'][1], $st['r'][2], false]; } // measured at most every 30 s
    $rel = strpos($host, '/mnt/user/') === 0 ? substr($host, 10) : '';
    $have = 0.0;
    if ($rel !== '') {
        foreach ((array)glob('/mnt/*', GLOB_ONLYDIR) as $root) {
            if (in_array(basename($root), ['user', 'user0', 'disks', 'remotes', 'addons'], true)) { continue; }
            $have += st_move_dirsize($root . '/' . $rel);
        }
    } else { $have = st_move_dirsize($host); }
    $frac = max(0.0, min(0.99, $have / $total));
    $h = (array)($st['s'] ?? []);
    $h = array_values(array_filter($h, function ($s) use ($now) { return $now - $s[0] < 900; }));
    if (!$h || $now - end($h)[0] >= 25) { $h[] = [$now, $have]; }
    $eta = -1; // speed over the last five minutes: steady, and it follows a change
    foreach ($h as $s) {
        if ($now - $s[0] >= 25 && $now - $s[0] <= 300 && $have > $s[1]) { $rate = ($have - $s[1]) / ($now - $s[0]); if ($rate > 0) { $eta = (int)round(max(0, $total - $have) / $rate); } break; }
    }
    @file_put_contents($f, json_encode(['s' => $h, 'r' => [$now, $frac, $eta]]));
    return [$frac, $eta, false];
}
function st_move_eta_txt($s) { if ($s < 0) { return ''; } $h = intdiv($s, 3600); $m = intdiv($s % 3600, 60); return $h > 0 ? $h . 'h ' . str_pad((string)$m, 2, '0', STR_PAD_LEFT) . 'm' : max(1, $m) . 'm'; }
