# JELLYNAS rows for Kodi skins (ATADAS item 141), shown as widget folders (Arctic Fuse 2: Edit widgets / Choose spotlight):
#  - Continue watching: the Jellyfin server's own resume list (movies and episodes mixed, its order), episodes under the series name
#  - Spotlight: continue watching + next up, one item per series, the series art and logo for episodes
#  - This week: Streambox week.json, a day separator item before each day, a season pack of one day as one card
# Jellyfin is asked with the Kofin login of this Kodi (GET only); week.json and imdb.json come from the Jellyfin web path
# (/web/streambox/, written by Streambox). Playback goes through Kofin (mode=play&id=<Jellyfin id>).
import json
import os
import re
import time
import urllib.parse
import urllib.request

import xbmc
import xbmcaddon
import xbmcgui
import xbmcplugin
import xbmcvfs

ADDON = xbmcaddon.Addon()
BASE = 'plugin://%s/' % ADDON.getAddonInfo('id')
KOFIN = 'plugin.video.kofin'
PLAY = 'plugin://plugin.video.kofin/?mode=play&id=%s'
ROOT = xbmcvfs.translatePath(ADDON.getAddonInfo('path'))
PROFILE = xbmcvfs.translatePath(ADDON.getAddonInfo('profile'))
MEDIA = os.path.join(ROOT, 'resources', 'media')
TIMEOUT = 8
LIMIT = 24
SPOT_MAX = 10
FIELDS = 'Overview,ProviderIds,Genres,MediaStreams,PremiereDate,OfficialRating,CommunityRating'
IMAGES = 'Primary,Backdrop,Thumb,Logo'
GROUP_MIN = 2   # this many episodes of one season on one day make one card (as on the Jellyfin page)
HDR = {'HDR10': 'hdr10', 'HDR10Plus': 'hdr10', 'HLG': 'hlg'}


# ---- texts: Kodi's language, or the one chosen in the settings (0 = Kodi, 1 = English, 2 = Hungarian) ----
_po = None


def _read_po(fn):
    out, ctx, mid = {}, None, ''
    try:
        lines = open(fn, encoding='utf-8').read().splitlines()
    except OSError:
        return out
    for ln in lines:
        m = re.match(r'(msgctxt|msgid|msgstr)\s+"(.*)"\s*$', ln)
        if not m:
            continue
        key, val = m.group(1), m.group(2).replace('\\"', '"')
        if key == 'msgctxt':
            ctx = int(val[1:]) if val.startswith('#') and val[1:].isdigit() else None
        elif key == 'msgid':
            mid = val
        elif ctx is not None:
            out[ctx] = val or mid
            ctx = None
    return out


def L(sid, **kw):
    global _po
    if _po is None:
        code = {'1': 'resource.language.en_gb', '2': 'resource.language.hu_hu'}.get(ADDON.getSetting('lang'))
        _po = _read_po(os.path.join(ROOT, 'resources', 'language', code, 'strings.po')) if code else {}
    s = _po.get(sid) or ADDON.getLocalizedString(sid)
    for k, v in kw.items():
        s = s.replace('{%s}' % k, str(v))
    return s


def log(msg):
    xbmc.log('[JELLYNAS] %s' % msg, xbmc.LOGINFO)


# ---- Kofin login and Jellyfin requests ----
def kofin():
    try:
        k = xbmcaddon.Addon(KOFIN)
    except RuntimeError:
        return None
    srv, uid, tok = k.getSetting('serverAddress').rstrip('/'), k.getSetting('userId'), k.getSetting('accessToken')
    if not (srv and uid and tok):
        return None
    # the same header Kofin sends (core/auth.py), so the server sees the one Kofin session of this Kodi
    hdr = 'MediaBrowser Client="Kofin", Device="%s", DeviceId="%s", Version="%s", Token="%s"' % (
        (xbmc.getInfoLabel('System.FriendlyName') or 'Kodi').replace('"', "'"), k.getSetting('deviceId'),
        k.getAddonInfo('version'), tok)
    return {'srv': srv, 'uid': uid, 'hdr': hdr}


def get_json(url, hdr=None):
    # an own User-Agent: the reverse proxy in front of the public address answers 403 to Python's default one
    h = {'Accept': 'application/json', 'User-Agent': 'JELLYNAS/%s (Kodi)' % ADDON.getAddonInfo('version')}
    if hdr:
        h['Authorization'] = hdr
    with urllib.request.urlopen(urllib.request.Request(url, headers=h), timeout=TIMEOUT) as r:
        return json.loads(r.read().decode('utf-8'))


def jf(c, path, **params):
    params['userId'] = c['uid']
    return get_json('%s%s?%s' % (c['srv'], path, urllib.parse.urlencode(params)), c['hdr'])


def cached(name, url, ttl):
    """A public Streambox file, kept in the addon profile for ttl seconds (the old copy when the server does not answer)."""
    fn = os.path.join(PROFILE, name)
    try:
        if time.time() - os.path.getmtime(fn) < ttl:
            return json.load(open(fn, encoding='utf-8'))
    except (OSError, ValueError):
        pass
    try:
        data = get_json(url)
        os.makedirs(PROFILE, exist_ok=True)
        with open(fn + '.tmp', 'w', encoding='utf-8') as f:
            json.dump(data, f)
        os.replace(fn + '.tmp', fn)
        return data
    except Exception as x:  # noqa: BLE001 - any network or JSON error: use the old copy
        log('%s: %s' % (name, x))
        try:
            return json.load(open(fn, encoding='utf-8'))
        except (OSError, ValueError):
            return None


def imdb_table(c):
    d = cached('imdb.json', c['srv'] + '/web/streambox/imdb.json', 6 * 3600)
    return d.get('r', {}) if isinstance(d, dict) else {}


# ---- items ----
def img(c, iid, kind, tag, width):
    if not iid or not tag:
        return ''
    return '%s/Items/%s/Images/%s?tag=%s&maxWidth=%d&quality=90' % (c['srv'], iid, kind, tag, width)


def art_of(c, it):
    tags, back = it.get('ImageTags') or {}, it.get('BackdropImageTags') or []
    if it.get('Type') == 'Episode':
        poster = img(c, it.get('SeriesId'), 'Primary', it.get('SeriesPrimaryImageTag'), 500)
        fan = img(c, it.get('ParentBackdropItemId'), 'Backdrop', (it.get('ParentBackdropImageTags') or [''])[0], 1920)
        thumb = img(c, it.get('ParentThumbItemId'), 'Thumb', it.get('ParentThumbImageTag'), 960) or fan
        logo = img(c, it.get('ParentLogoItemId'), 'Logo', it.get('ParentLogoImageTag'), 800)
        a = {'poster': poster, 'tvshow.poster': poster, 'fanart': fan, 'tvshow.fanart': fan, 'landscape': thumb,
             'tvshow.landscape': thumb, 'thumb': thumb or img(c, it.get('Id'), 'Primary', tags.get('Primary'), 960),
             'clearlogo': logo, 'tvshow.clearlogo': logo, 'icon': poster}
    else:
        iid = it.get('Id')
        poster = img(c, iid, 'Primary', tags.get('Primary'), 500)
        fan = img(c, iid, 'Backdrop', back[0] if back else '', 1920)
        thumb = img(c, iid, 'Thumb', tags.get('Thumb'), 960) or fan
        a = {'poster': poster, 'fanart': fan, 'landscape': thumb, 'thumb': thumb or poster,
             'clearlogo': img(c, iid, 'Logo', tags.get('Logo'), 800), 'icon': poster}
    return {k: v for k, v in a.items() if v}


def series_info(c, ids):
    """The series items (ProviderIds and image tags) by id: TMDb Helper finds a series by its TMDb id rather than by a
    Hungarian title, and image URLs need the real tags (a series without a Thumb image falls back to its backdrop)."""
    ids = sorted({i for i in ids if i})
    if not ids:
        return {}
    try:
        d = jf(c, '/Items', Ids=','.join(ids), Fields='ProviderIds', EnableImageTypes=IMAGES, ImageTypeLimit=1,
               EnableUserData='false')
        return {s.get('Id'): s for s in d.get('Items', [])}
    except Exception as x:  # noqa: BLE001
        log('series: %s' % x)
        return {}


def series_ids(c, items):
    info = series_info(c, [it.get('SeriesId') for it in items if it.get('Type') == 'Episode'])
    return {k: {p.lower(): v for p, v in (s.get('ProviderIds') or {}).items() if v} for k, s in info.items()}


def add_streams(tag, it):
    for s in it.get('MediaStreams') or []:
        if s.get('Type') == 'Video' and not s.get('IsExternal'):
            rng = s.get('VideoRangeType') or ''
            tag.addVideoStream(xbmc.VideoStreamDetail(width=s.get('Width') or 0, height=s.get('Height') or 0,
                                                      codec=(s.get('Codec') or '').lower(),
                                                      hdrtype='dolbyvision' if rng.startswith('DOVI') else HDR.get(rng, '')))
            break
    for s in it.get('MediaStreams') or []:
        if s.get('Type') == 'Audio':
            tag.addAudioStream(xbmc.AudioStreamDetail(channels=s.get('Channels') or 2, codec=(s.get('Codec') or '').lower(),
                                                      language=s.get('Language') or ''))


def media_item(c, it, imdb, sids):
    ep = it.get('Type') == 'Episode'
    name, series = it.get('Name') or '', it.get('SeriesName') or ''
    se, en = it.get('ParentIndexNumber') or 0, it.get('IndexNumber') or 0
    sub = ('S%d:E%d · %s' % (se, en, name)) if ep else str(it.get('ProductionYear') or '')
    li = xbmcgui.ListItem(series if ep else name, sub, offscreen=True)
    tag = li.getVideoInfoTag()
    tag.setMediaType('episode' if ep else 'movie')
    tag.setTitle(name)
    if ep:
        tag.setTvShowTitle(series)
        tag.setSeason(se)
        tag.setEpisode(en)
    tag.setPlot(it.get('Overview') or '')
    if it.get('ProductionYear'):
        tag.setYear(int(it['ProductionYear']))
    if it.get('PremiereDate'):
        tag.setPremiered(it['PremiereDate'][:10])
    if it.get('OfficialRating'):
        tag.setMpaa(it['OfficialRating'])
    if it.get('Genres'):
        tag.setGenres(it['Genres'])
    total = int((it.get('RunTimeTicks') or 0) / 10000000)
    if total:
        tag.setDuration(total)
    pos = ((it.get('UserData') or {}).get('PlaybackPositionTicks') or 0) / 10000000
    if pos and total:
        tag.setResumePoint(pos, total)
    r = imdb.get(it.get('SeriesId') if ep else it.get('Id'))
    if r:
        tag.setRating(r[0] / 10.0, int(r[1] or 0), 'imdb', True)
        li.setProperty('jellynas.imdb', '%.1f' % (r[0] / 10.0))
    if it.get('CommunityRating'):
        tag.setRating(float(it['CommunityRating']), 0, 'themoviedb', not r)
    ids = {k.lower(): v for k, v in (it.get('ProviderIds') or {}).items() if v}
    show = sids.get(it.get('SeriesId'), {}) if ep else {}
    if show.get('tmdb'):
        ids['tvshow.tmdb'] = show['tmdb']
        li.setProperty('tvshow.tmdb_id', show['tmdb'])
    if show.get('imdb'):
        ids['tvshow.imdb'] = show['imdb']
    if ids:
        tag.setUniqueIDs(ids, 'tmdb' if 'tmdb' in ids else next(iter(ids)))
    if not ep and ids.get('tmdb'):
        li.setProperty('tmdb_id', ids['tmdb'])
    add_streams(tag, it)
    li.setArt(art_of(c, it))
    li.setProperty('IsPlayable', 'true')
    li.setProperty('sublabel', sub)
    return PLAY % it.get('Id'), li, False


# ---- listings ----
def resume_items(c):
    return jf(c, '/UserItems/Resume', Limit=LIMIT, MediaTypes='Video', Recursive='true', Fields=FIELDS,
              EnableImageTypes=IMAGES, ImageTypeLimit=1, EnableUserData='true', EnableTotalRecordCount='false').get('Items', [])


def nextup_items(c):
    cutoff = time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime(time.time() - 365 * 86400))
    return jf(c, '/Shows/NextUp', Limit=LIMIT, Fields=FIELDS, EnableImageTypes=IMAGES, ImageTypeLimit=1,
              EnableUserData='true', EnableTotalRecordCount='false', DisableFirstEpisode='false',
              NextUpDateCutoff=cutoff, EnableResumable='false', EnableRewatching='false').get('Items', [])


def list_media(h, c, items):
    imdb, sids = imdb_table(c), series_ids(c, items)
    xbmcplugin.setContent(h, 'videos')
    xbmcplugin.addDirectoryItems(h, [media_item(c, it, imdb, sids) for it in items if it.get('Id')])


def do_resume(h, c):
    list_media(h, c, resume_items(c))


def do_spotlight(h, c):
    seen, out = set(), []
    for it in resume_items(c) + nextup_items(c):
        key = it.get('SeriesId') or it.get('Id')
        if key in seen:
            continue
        seen.add(key)
        out.append(it)
        if len(out) >= SPOT_MAX:
            break
    list_media(h, c, out)


# ---- this week ----
def dur(s):
    s = max(0, int(round(s / 60.0)))
    d, hh, m = s // 1440, s % 1440 // 60, s % 60
    return L(30032, d=d, h=hh) if d else (L(30033, h=hh, m=m) if hh else L(30034, m=m))


def status(e, now):
    target = e['exp'] if e.get('exp') is not None else e['air']
    st = e.get('st')
    if st == 'jf':
        return L(30020)
    if st in ('file', 'scan', 'imp'):
        return L(30021)
    if st == 'dl':
        return L(30023, p=e['pct']) if e.get('pct') is not None else L(30022)
    simple = {'grab': 30024, 'pause': 30025, 'fail': 30026}
    if st in simple:
        return L(simple[st])
    if st == 'late':
        return L(30027, d=dur(now - target))
    if target > now:
        return L(30028 if e.get('exp') is not None else 30029, d=dur(target - now))
    return L(30030)


def group_status(g, now):
    n = len([e for e in g if e.get('st') == 'jf'])
    if n == len(g):
        return L(30020)
    if n:
        return L(30035, n=n, m=len(g))
    for st in ('fail', 'dl', 'grab', 'imp', 'file', 'scan', 'pause', 'late'):
        m = [e for e in g if e.get('st') == st]
        if m:
            if st == 'dl':
                m.sort(key=lambda e: e.get('pct') or 0)
            return status(m[0], now)
    return status(g[0], now)


def day_idx(ts):
    return time.localtime(ts).tm_wday   # Monday 0 .. Sunday 6


DAYS = (30040, 30041, 30042, 30043, 30044, 30045, 30046)   # Monday .. Sunday


def day_name(d):
    return L(DAYS[d])


def season_of(code):
    return code[:3]


def ep_num(code):
    try:
        return int(code[4:])
    except ValueError:
        return 0


def week_cards(w):
    """The episodes of the week, a season's episodes of one day folded into one group (like week_web.js items())."""
    keys, groups = {}, []
    for e in w.get('eps', []):
        k = '%d|%s|%s' % (day_idx(e['air']), e.get('s', ''), season_of(e.get('c', '')))
        if k not in keys:
            keys[k] = []
            groups.append(keys[k])
        keys[k].append(e)
    cards = []
    for g in groups:
        if len(g) < GROUP_MIN:
            cards.extend(g)
            continue
        g = sorted(g, key=lambda e: ep_num(e.get('c', '')))
        f, last = g[0], g[-1]
        run = ep_num(last['c']) - ep_num(f['c']) + 1 == len(g)
        cards.append({'s': f['s'], 'c': (f['c'] + '–' + last['c'][3:]) if run else season_of(f['c']),
                      't': '' if run else L(30036, n=len(g)), 'air': min(e['air'] for e in g), 'exp': f.get('exp'),
                      'st': 'grp', 'ep': '', 'sid': f.get('sid', ''), 'img': f.get('img', ''), 'g': g})
    return sorted(cards, key=lambda e: (e['air'], e.get('s', '')))


def series_art(c, e, info):
    s = info.get(e.get('sid'))
    if s:
        a = art_of(c, s)
        a.update({'tvshow.' + k: a[k] for k in ('poster', 'fanart', 'landscape', 'clearlogo') if k in a})
        return a
    return {'poster': e['img'], 'thumb': e['img'], 'icon': e['img']} if e.get('img') else {}


def lang_code():
    sel = ADDON.getSetting('lang')
    if sel in ('1', '2'):
        return 'hu' if sel == '2' else 'en'
    return 'hu' if xbmc.getLanguage(xbmc.ISO_639_1) == 'hu' else 'en'


def separator(label, d, today):
    """A day card before the day's episodes: the day name drawn into the image (kodi_build.py makes them from strings.po),
    until a skin can draw a narrow separator from the jellynas.separator property."""
    name = 'day_%s_%d%s' % (lang_code(), d, '_today' if today else '')
    land, poster = os.path.join(MEDIA, name + '_land.png'), os.path.join(MEDIA, name + '_poster.png')
    li = xbmcgui.ListItem(label, offscreen=True)
    li.setArt({'poster': poster, 'thumb': land, 'landscape': land, 'icon': poster, 'fanart': land})
    li.setProperty('jellynas.separator', '1')
    li.setProperty('IsPlayable', 'false')
    return BASE + '?mode=noop', li, False


def week_card(c, e, imdb, now, info):
    grp = e.get('g')
    st = group_status(grp, now) if grp else status(e, now)
    sub = '%s · %s' % (e.get('c', ''), st)
    li = xbmcgui.ListItem(e.get('s', ''), sub, offscreen=True)
    tag = li.getVideoInfoTag()
    tag.setMediaType('tvshow' if grp else 'episode')
    tag.setTvShowTitle(e.get('s', ''))
    tag.setTitle(e.get('t') or e.get('c', ''))
    m = re.match(r'S(\d+)E(\d+)', e.get('c', ''))
    if m:
        tag.setSeason(int(m.group(1)))
        if not grp:
            tag.setEpisode(int(m.group(2)))
    tag.setPremiered(time.strftime('%Y-%m-%d', time.localtime(e['air'])))
    hm = time.strftime('%H:%M', time.localtime(e['air']))
    tag.setPlot('%s · %s%s\n%s %s · %s' % (e.get('s', ''), e.get('c', ''), (' · ' + e['t']) if e.get('t') else '',
                                           day_name(day_idx(e['air'])), hm, st))
    r = imdb.get(e.get('sid'))
    if r:
        tag.setRating(r[0] / 10.0, int(r[1] or 0), 'imdb', True)
        li.setProperty('jellynas.imdb', '%.1f' % (r[0] / 10.0))
    li.setArt(series_art(c, e, info))
    li.setProperty('sublabel', sub)
    li.setProperty('jellynas.time', hm)
    li.setProperty('jellynas.state', st)
    li.setProperty('jellynas.ready', '1' if (e.get('st') == 'jf' or (grp and all(x.get('st') == 'jf' for x in grp))) else '0')
    if grp:
        q = urllib.parse.urlencode({'mode': 'wgroup', 'day': day_idx(e['air']), 's': e.get('s', ''), 'season': e['c'][:3],
                                    'sid': e.get('sid', '')})
        return BASE + '?' + q, li, True
    if e.get('st') == 'jf' and e.get('ep'):
        li.setProperty('IsPlayable', 'true')
        return PLAY % e['ep'], li, False
    li.setProperty('IsPlayable', 'false')
    return BASE + '?' + urllib.parse.urlencode({'mode': 'na', 'st': st}), li, False


def week_data(c):
    w = cached('week.json', c['srv'] + '/web/streambox/week.json', 120)
    return w if isinstance(w, dict) and w.get('ok') else None


def do_week(h, c):
    w = week_data(c)
    xbmcplugin.setContent(h, 'episodes')
    if not w:
        return
    now, imdb, out, last_day = time.time(), imdb_table(c), [], None
    cards = week_cards(w)
    info = series_info(c, [e.get('sid') for e in cards])
    this_week = w.get('from', 0) <= now < w.get('to', 0)
    today = day_idx(now)
    for e in cards:
        d = day_idx(e['air'])
        if d != last_day:
            name = day_name(d)
            is_today = this_week and d == today
            out.append(separator((L(30031, d=name) if is_today else name).upper(), d, is_today))
            last_day = d
        out.append(week_card(c, e, imdb, now, info))
    xbmcplugin.addDirectoryItems(h, out)


def do_wgroup(h, c, q):
    w = week_data(c)
    xbmcplugin.setContent(h, 'episodes')
    if not w:
        return
    now, imdb = time.time(), imdb_table(c)
    info = series_info(c, [q.get('sid')])
    eps = [e for e in w.get('eps', []) if str(day_idx(e['air'])) == q.get('day') and e.get('s') == q.get('s')
           and season_of(e.get('c', '')) == q.get('season')]
    xbmcplugin.addDirectoryItems(h, [week_card(c, e, imdb, now, info) for e in sorted(eps, key=lambda e: ep_num(e.get('c', '')))])


# ---- router ----
ROUTES = {'resume': do_resume, 'spotlight': do_spotlight, 'week': do_week}


def folder(label, mode):
    li = xbmcgui.ListItem(label, offscreen=True)
    li.setArt({'icon': os.path.join(ROOT, 'resources', 'icon.png'), 'thumb': os.path.join(ROOT, 'resources', 'icon.png')})
    return BASE + '?mode=' + mode, li, True


def main(argv):
    h = int(argv[1])
    q = dict(urllib.parse.parse_qsl(argv[2][1:] if len(argv) > 2 else ''))
    mode = q.get('mode', '')
    if mode == 'noop':
        return
    if mode == 'na':
        xbmcgui.Dialog().notification('JELLYNAS', L(30012, st=q.get('st', '')), os.path.join(ROOT, 'resources', 'icon.png'), 4000)
        return
    if not mode:
        xbmcplugin.addDirectoryItems(h, [folder(L(30001), 'resume'), folder(L(30002), 'spotlight'), folder(L(30003), 'week')])
        xbmcplugin.endOfDirectory(h)
        return
    c = kofin()
    ok = True
    if not c:
        xbmcgui.Dialog().notification('JELLYNAS', L(30010), xbmcgui.NOTIFICATION_WARNING, 5000)
        ok = False
    else:
        try:
            if mode == 'wgroup':
                do_wgroup(h, c, q)
            elif mode in ROUTES:
                ROUTES[mode](h, c)
        except Exception as x:  # noqa: BLE001 - a widget must not break the home screen: log and show an empty row
            log('%s: %s' % (mode, x))
            ok = False
    xbmcplugin.endOfDirectory(h, succeeded=ok, cacheToDisc=False)
