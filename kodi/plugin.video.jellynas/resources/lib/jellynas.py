# JELLYNAS rows for Kodi skins (ATADAS item 141), shown as widget folders (Arctic Fuse 2: Edit widgets / Choose spotlight):
#  - Continue watching: the Jellyfin server's own resume list (movies and episodes mixed, its order), episodes under the series name
#  - Spotlight: continue watching + next up, one item per series, the series art and logo for episodes
#  - Next up, Recently added movies, Recently added series: the same lists as the Jellyfin home page (the Kodi library
#    rows from Kofin's sync have no IMDb rating, so the skin's IMDb badge stays empty on them)
#  - All movies, All series: the whole library for a category's poster wall (newest first, Kodi's sort menu for the rest);
#    the skin's grid bar adds sort, letter, unwatched and genre (mode=pick sets them as Home window properties)
#  - This week: Streambox week.json in air order, the day and time in the card's second line (no day cards: they were
#    focusable and showed an empty info panel), a season pack of one day as one card
#  - Catalog: Seerr through the JELLYNAS catalog proxy (seerrproxy/ in the repo; setting 'catalog'): trending, popular and
#    upcoming movies and series, the requests (all, processing, coming soon, available); a card opens a choice: play
#    (in Jellyfin), request (with a yes/no, as the viewer), details. The proxy checks the Kofin login, so no Seerr key here.
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
BROWSE = 'plugin://plugin.video.kofin/?mode=browse&type=%s&folder=%s'   # Kofin's drill-down: series -> seasons, season -> episodes
ROOT = xbmcvfs.translatePath(ADDON.getAddonInfo('path'))
PROFILE = xbmcvfs.translatePath(ADDON.getAddonInfo('profile'))
TIMEOUT = 8
CATALOG = 'http://192.168.50.30:5056'   # default of the setting 'catalog' (LAN); outside the LAN the NPM address
CAT_LISTS = (('trending', 30071), ('movies', 30072), ('movies/upcoming', 30073), ('tv', 30074), ('tv/upcoming', 30075))
CAT_REQS = (('all', 30077), ('processing', 30078), ('comingsoon', 30079), ('available', 30080))
CAT_STATUS = {'available': 30080, 'partial': 30081, 'processing': 30082, 'pending': 30082}
LIMIT = 24
SPOT_MAX = 10
FIELDS = 'Overview,ProviderIds,Genres,MediaStreams,PremiereDate,OfficialRating,CommunityRating,ChildCount'   # ChildCount: seasons of a series card
IMAGES = 'Primary,Backdrop,Thumb,Logo'
GRID_SORTS = (('new', 30050), ('name', 30051), ('year', 30052), ('imdb', 30053))   # grid bar: value, label
SKIN_STRINGS = (30057, 30058, 30059, 30060, 30061, 30097, 30098, 30110, 30111)   # only the JELLYNAS skin's grid bar uses them ($ADDON[plugin.video.jellynas N])
GRID_FIELDS = 'Overview,ProviderIds,Genres,PremiereDate,OfficialRating,CommunityRating,DateCreated'   # no MediaStreams: the whole library in one answer
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
        d = jf(c, '/Items', Ids=','.join(ids), Fields='ProviderIds,Overview,Genres,OfficialRating', EnableImageTypes=IMAGES, ImageTypeLimit=1,
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


def length(secs):
    h, m = secs // 3600, secs % 3600 // 60
    return L(30105).format(h=h, m=m) if h else L(30106).format(m=m)


def second_line(it, row):
    """The card's second line under the poster (owner, 2026-11-07): episode code; new movies year and length, new
    series year and seasons; else the year."""
    kind, year = it.get('Type'), str(it.get('ProductionYear') or '')
    if kind == 'Episode':
        return 'S%d:E%d' % (it.get('ParentIndexNumber') or 0, it.get('IndexNumber') or 0)
    if kind == 'Season':
        return it.get('Name') or ''
    extra = ''
    if row == 'movies' and it.get('RunTimeTicks'):
        extra = length(int(it['RunTimeTicks'] / 10000000))
    elif row == 'series' and kind == 'Series' and it.get('ChildCount'):
        n = it['ChildCount']
        extra = L(30108) if n == 1 else L(30107).format(n=n)
    return ' · '.join(x for x in (year, extra) if x)


def media_item(c, it, imdb, sids, row=''):
    kind = it.get('Type')
    ep, show = kind == 'Episode', kind in ('Series', 'Season')
    name, series = it.get('Name') or '', it.get('SeriesName') or ''
    se, en = it.get('ParentIndexNumber') or 0, it.get('IndexNumber') or 0
    sub = second_line(it, row)
    li = xbmcgui.ListItem(series if ep or kind == 'Season' else name, sub, offscreen=True)
    tag = li.getVideoInfoTag()
    tag.setMediaType('episode' if ep else ('tvshow' if kind == 'Series' else ('season' if show else 'movie')))
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
    ud = it.get('UserData') or {}
    pos = (ud.get('PlaybackPositionTicks') or 0) / 10000000
    if pos and total:
        tag.setResumePoint(pos, total)
    if ud.get('Played') and kind != 'Series':
        tag.setPlaycount(max(1, ud.get('PlayCount') or 0))
    if it.get('DateCreated'):
        tag.setDateAdded(it['DateCreated'][:19].replace('T', ' '))
    if kind == 'Series':
        # the skin's watched tick and unwatched count read these (as on Kodi library series)
        n, left = it.get('RecursiveItemCount') or 0, ud.get('UnplayedItemCount') or 0
        if n:
            li.setProperty('TotalEpisodes', str(n))
            li.setProperty('WatchedEpisodes', str(max(0, n - left)))
            li.setProperty('UnWatchedEpisodes', str(left))
        if ud.get('Played'):
            tag.setPlaycount(1)
    r = imdb.get(it.get('SeriesId') if ep or kind == 'Season' else it.get('Id'))
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
    li.setProperty('sublabel', sub)
    if kind == 'Series':
        return BROWSE % ('series', it.get('Id')), li, True
    if kind == 'Season':
        return BROWSE % ('season', it.get('Id')) + '&' + urllib.parse.urlencode({'series': it.get('SeriesId', '')}), li, True
    li.setProperty('IsPlayable', 'true')
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


def latest_items(c, types):
    """Recently added, as on the Jellyfin home page: new episodes grouped into their series (or season)."""
    d = jf(c, '/Items/Latest', Limit=LIMIT, IncludeItemTypes=types, Fields=FIELDS, EnableImageTypes=IMAGES,
           ImageTypeLimit=1, EnableUserData='true', GroupItems='true')
    return d if isinstance(d, list) else d.get('Items', [])


def all_items(c, kind, q=None):
    """The whole library of one kind, newest first (as Jellyfin's Movies / Shows tab sorted by date added), or as the
    grid bar asks: sort=name|year (imdb is sorted by list_all), letter=A..Z or 0/# (before A), unwatched=1, genre=<name>."""
    q = q or {}
    p = {'SortBy': 'DateCreated,SortName', 'SortOrder': 'Descending'}
    if q.get('sort') == 'name':
        p = {'SortBy': 'SortName', 'SortOrder': 'Ascending'}
    elif q.get('sort') == 'year':
        p = {'SortBy': 'ProductionYear,SortName', 'SortOrder': 'Descending'}
    letter = q.get('letter', '')
    if letter in ('#', '0'):   # the skin sends 0: a # would end the URL
        p['NameLessThan'] = 'A'
    elif len(letter) == 1 and letter.isalpha():
        p['NameStartsWith'] = letter
    if q.get('unwatched') == '1':
        p['IsPlayed'] = 'false'
    if q.get('genre'):
        p['Genres'] = q['genre']
    return jf(c, '/Items', Recursive='true', IncludeItemTypes=kind,
              Fields=GRID_FIELDS + (',RecursiveItemCount' if kind == 'Series' else ''), EnableImageTypes=IMAGES, ImageTypeLimit=1,
              EnableUserData='true', EnableTotalRecordCount='false', **p).get('Items', [])


def list_media(h, c, items, content='videos', row=''):
    imdb, sids = imdb_table(c), series_ids(c, items)
    xbmcplugin.setContent(h, content)
    xbmcplugin.addDirectoryItems(h, [media_item(c, it, imdb, sids, row) for it in items if it.get('Id')])


def do_resume(h, c):
    list_media(h, c, resume_items(c))


def do_nextup(h, c):
    list_media(h, c, nextup_items(c))


def do_movies(h, c):
    list_media(h, c, latest_items(c, 'Movie'), row='movies')


def do_series(h, c):
    list_media(h, c, latest_items(c, 'Episode'), row='series')


def list_all(h, c, kind, content, q):
    items = all_items(c, kind, q)
    if q.get('sort') == 'imdb':   # the same ratings as the badges (Streambox imdb.json), unrated last
        imdb = imdb_table(c)
        items.sort(key=lambda it: -((imdb.get(it.get('Id')) or [0])[0] or 0))
    list_media(h, c, items, content)
    # the first one keeps the server's order (date added); the rest are Kodi's sort menu
    for m in (xbmcplugin.SORT_METHOD_UNSORTED, xbmcplugin.SORT_METHOD_DATEADDED, xbmcplugin.SORT_METHOD_LABEL_IGNORE_THE,
              xbmcplugin.SORT_METHOD_VIDEO_YEAR, xbmcplugin.SORT_METHOD_VIDEO_RATING, xbmcplugin.SORT_METHOD_PLAYCOUNT):
        xbmcplugin.addSortMethod(h, m)


def do_allmovies(h, c, q):
    list_all(h, c, 'Movie', 'movies', q)


def do_allseries(h, c, q):
    list_all(h, c, 'Series', 'tvshows', q)


def do_pick(c, q):
    """Grid bar button (RunPlugin): choose the sort or the genre of the Home poster grid. The choice goes to the Home
    window properties JellynasGrid.<movies|series>.<sort|genre> (the value, URL-quoted: the skin puts it in the grid's
    path) and ...<sort|genre>label (shown by the skin); the default choice clears both."""
    kind = 'series' if q.get('kind') == 'series' else 'movies'
    what = q.get('what')
    if what == 'sort':
        vals, labels = [s for s, _ in GRID_SORTS], [L(n) for _, n in GRID_SORTS]
    elif what == 'genre':
        g = jf(c, '/Genres', IncludeItemTypes='Series' if kind == 'series' else 'Movie', Recursive='true',
               SortBy='SortName').get('Items', [])
        names = [x['Name'] for x in g if x.get('Name')]
        vals, labels = [''] + [urllib.parse.quote(n) for n in names], [L(30054)] + names
    else:
        return
    i = xbmcgui.Dialog().select(L(30055 if what == 'sort' else 30056), labels)
    if i < 0:
        return
    win, pre = xbmcgui.Window(10000), 'JellynasGrid.%s.%s' % (kind, what)
    first = i == 0
    win.setProperty(pre, '' if first else vals[i])
    win.setProperty(pre + 'label', '' if first else labels[i])


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


def when(e, now, this_week):
    """KEDD 02:00, or MA 02:00 on today's episodes of this week"""
    d = day_idx(e['air'])
    day = L(30031) if this_week and d == day_idx(now) else day_name(d)
    return '%s %s' % (day.upper(), time.strftime('%H:%M', time.localtime(e['air'])))


def week_episodes(c, cards):
    """The Jellyfin items of the available episodes (overview, streams, length) for the info panel, by id."""
    ids = sorted({x.get('ep') for e in cards for x in (e.get('g') or [e]) if x.get('st') == 'jf' and x.get('ep')})
    if not ids:
        return {}
    try:
        d = jf(c, '/Items', Ids=','.join(ids), Fields=FIELDS, EnableImageTypes='', EnableUserData='false')
        return {it.get('Id'): it for it in d.get('Items', [])}
    except Exception as x:  # noqa: BLE001
        log('week episodes: %s' % x)
        return {}


def week_card(c, e, imdb, now, info, day=None, eps=None):
    """day: the day and time for the second line (the week row); the episodes of a group folder show their state.
    The info panel shows what Jellyfin knows, as on the other rows: an available episode's own overview, streams and
    length, else the series overview (owner, 2026-11-05); the made-up text only when Jellyfin has no overview."""
    grp = e.get('g')
    st = group_status(grp, now) if grp else status(e, now)
    sub = '%s · %s' % (day, e.get('c', '')) if day else '%s · %s' % (e.get('c', ''), st)
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
    it = None if grp else (eps or {}).get(e.get('ep'))
    show = info.get(e.get('sid')) or {}
    src = it or show
    plot = (it or {}).get('Overview') or show.get('Overview')
    tag.setPlot(plot or '%s · %s%s\n%s %s · %s' % (e.get('s', ''), e.get('c', ''), (' · ' + e['t']) if e.get('t') else '',
                                                   day_name(day_idx(e['air'])), hm, st))
    if src.get('Genres'):
        tag.setGenres(src['Genres'])
    if src.get('OfficialRating'):
        tag.setMpaa(src['OfficialRating'])
    if it:
        total = int((it.get('RunTimeTicks') or 0) / 10000000)
        if total:
            tag.setDuration(total)
        add_streams(tag, it)
    r = imdb.get(e.get('sid'))
    if r:   # the IMDb badge on top and Available at the bottom of the card (owner, 2026-11-07)
        tag.setRating(r[0] / 10.0, int(r[1] or 0), 'imdb', True)
        li.setProperty('jellynas.imdb', '%.1f' % (r[0] / 10.0))
    li.setArt(series_art(c, e, info))
    li.setProperty('jellynas.kind', 'week')
    li.setProperty('sublabel', sub)
    li.setProperty('jellynas.time', hm)
    li.setProperty('jellynas.state', st)
    ready = e.get('st') == 'jf' or bool(grp and all(x.get('st') == 'jf' for x in grp))
    li.setProperty('jellynas.ready', '1' if ready else '0')
    if ready:
        li.setProperty('jellynas.badge', L(30020))
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
    now, imdb = time.time(), imdb_table(c)
    cards = week_cards(w)
    info, eps = series_info(c, [e.get('sid') for e in cards]), week_episodes(c, cards)
    this_week = w.get('from', 0) <= now < w.get('to', 0)
    out = [week_card(c, e, imdb, now, info, when(e, now, this_week), eps) for e in cards]
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
    items = week_episodes(c, eps)
    xbmcplugin.addDirectoryItems(h, [week_card(c, e, imdb, now, info, None, items) for e in sorted(eps, key=lambda e: ep_num(e.get('c', '')))])


# ---- catalog (Seerr through the JELLYNAS catalog proxy) ----
def cat_lang():
    s = ADDON.getSetting('lang')
    return 'hu' if s == '2' or (s not in ('1', '2') and xbmc.getLanguage(xbmc.ISO_639_1) == 'hu') else 'en'


def cat_get(c, path, **q):
    q['language'] = cat_lang()
    return get_json('%s%s?%s' % ((ADDON.getSetting('catalog') or CATALOG).rstrip('/'), path, urllib.parse.urlencode(q)), c['hdr'])


def cat_post(c, body):
    """The one write of the add-on: a Seerr request, sent by the proxy in the viewer's name. Returns the HTTP code."""
    req = urllib.request.Request((ADDON.getSetting('catalog') or CATALOG).rstrip('/') + '/request', data=json.dumps(body).encode('utf-8'),
                                 headers={'Content-Type': 'application/json', 'Authorization': c['hdr'],
                                          'User-Agent': 'JELLYNAS/%s (Kodi)' % ADDON.getAddonInfo('version')}, method='POST')
    try:
        with urllib.request.urlopen(req, timeout=TIMEOUT) as r:
            return r.status
    except urllib.error.HTTPError as x:
        return x.code


def cat_item(t, sub=None):
    li = xbmcgui.ListItem(t['title'], sub if sub is not None else ' · '.join(x for x in (
        ('★ %.1f' % t['rating']) if t.get('rating') else '', t.get('year', '')) if x), offscreen=True)
    tag = li.getVideoInfoTag()
    tag.setMediaType('movie' if t['type'] == 'movie' else 'tvshow')
    tag.setTitle(t['title'])
    if t.get('original') and t['original'] != t['title']:
        tag.setOriginalTitle(t['original'])
    tag.setPlot(t.get('overview', ''))
    if t.get('year', '').isdigit():
        tag.setYear(int(t['year']))
    if t.get('date'):
        tag.setPremiered(t['date'])
    if t.get('rating'):
        tag.setRating(float(t['rating']), 0, 'themoviedb', True)
    if t.get('genres'):
        tag.setGenres(t['genres'])
    if t.get('runtime'):
        tag.setDuration(int(t['runtime']) * 60)
    tag.setUniqueIDs({'tmdb': str(t['id'])}, 'tmdb')
    li.setProperty('tmdb_id' if t['type'] == 'movie' else 'tvshow.tmdb_id', str(t['id']))
    li.setProperty('sublabel', li.getLabel2())
    li.setProperty('jellynas.kind', 'catalog')
    if t.get('status') in ('available', 'partial'):   # the skin's badge is narrow: the short word for partially available
        li.setProperty('jellynas.badge', L(30080 if t['status'] == 'available' else 30096))
    li.setArt({'poster': t.get('poster', ''), 'thumb': t.get('backdrop') or t.get('poster', ''), 'fanart': t.get('backdrop', ''),
               'icon': t.get('poster', '')})
    li.setProperty('IsPlayable', 'false')
    return BASE + '?' + urllib.parse.urlencode({'mode': 'catitem', 'type': t['type'], 'id': t['id']}), li, False


def do_catalog(h, c):
    xbmcplugin.addDirectoryItems(h, [folder(L(n), 'catlist&list=' + k) for k, n in CAT_LISTS]
                                 + [folder(L(30076), 'catreqs')])


def do_catlist(h, c, q):
    kind, typ = q.get('list', 'trending'), q.get('type', '')   # type=movie|tv: trending split (the skin's Catalog tabs)
    xbmcplugin.setContent(h, 'movies' if kind.startswith('movies') or typ == 'movie' else ('tvshows' if kind.startswith('tv') or typ == 'tv' else 'videos'))
    out, seen = [], set()
    for page in ((1, 2, 3) if typ else (1, 2)):   # 40 cards, as a Seerr row scrolled twice (a split trending row: 3 pages)
        for t in cat_get(c, '/discover/' + kind, page=page, **({'type': typ} if typ else {})).get('results', []):
            if (t['type'], t['id']) not in seen:
                seen.add((t['type'], t['id']))
                out.append(cat_item(t))
    xbmcplugin.addDirectoryItems(h, out)


def do_catreqs(h, c, q):
    flt = q.get('filter')
    if not flt:
        xbmcplugin.addDirectoryItems(h, [folder(L(n), 'catreqs&filter=' + k) for k, n in CAT_REQS])
        return
    xbmcplugin.setContent(h, 'videos')
    xbmcplugin.addDirectoryItems(h, [cat_item(t, L(30094, who=t.get('by', ''), day=t.get('added', '')))
                                     for t in cat_get(c, '/requests', filter=flt).get('results', [])])


def do_catitem(c, q):
    """A catalog card was clicked (RunPlugin): play it in Jellyfin, request it (yes/no first), or show the details."""
    try:
        t = cat_get(c, '/item/%s/%d' % (q.get('type'), int(q.get('id'))))
    except Exception as x:  # noqa: BLE001 - proxy down or unknown title: tell the viewer
        log('catitem: %s' % x)
        xbmcgui.Dialog().notification('JELLYNAS', L(30092), xbmcgui.NOTIFICATION_WARNING, 5000)
        return
    acts, open_seasons = [], [s['n'] for s in t.get('seasons', []) if not s.get('status')]
    if t.get('jellyfin') and t.get('status') in ('available', 'partial'):
        acts.append(('play', L(30083)))
    if t['type'] == 'movie' and not t.get('status'):
        acts.append(('movie', L(30084)))
    if t['type'] == 'tv' and open_seasons:
        acts.append(('all', L(30085, n=len(open_seasons))))
        if len(open_seasons) > 1:
            acts.append(('pick', L(30086)))
    acts.append(('info', L(30087)))
    head = '%s (%s)%s' % (t['title'], t.get('year', ''), (' · ' + L(CAT_STATUS[t['status']])) if t.get('status') in CAT_STATUS else '')
    i = xbmcgui.Dialog().select(head, [a[1] for a in acts])
    if i < 0:
        return
    act = acts[i][0]
    if act == 'info':
        xbmcgui.Dialog().info(cat_item(t)[1])
        return
    if act == 'play':
        if t['type'] == 'movie':
            xbmc.executebuiltin('PlayMedia(%s)' % (PLAY % t['jellyfin']))
        else:
            xbmc.executebuiltin('ActivateWindow(Videos,%s,return)' % (BROWSE % ('series', t['jellyfin'])))
        return
    seasons = open_seasons
    if act == 'pick':
        names = [L(30093, n=n) for n in open_seasons]
        sel = xbmcgui.Dialog().multiselect(L(30086), names)
        if not sel:
            return
        seasons = [open_seasons[k] for k in sel]
    what = t['title'] if act == 'movie' else '%s · %s' % (t['title'], ', '.join(L(30093, n=n) for n in seasons))
    if not xbmcgui.Dialog().yesno(L(30084), L(30088, title=what)):
        return
    code = cat_post(c, {'type': t['type'], 'id': t['id']} if act == 'movie' else {'type': 'tv', 'id': t['id'], 'seasons': seasons})
    msg = {200: 30089, 201: 30089, 409: 30090, 403: 30091}.get(code, 30092)
    xbmcgui.Dialog().notification('JELLYNAS', L(msg), os.path.join(ROOT, 'resources', 'icon.png') if msg in (30089, 30090)
                                  else xbmcgui.NOTIFICATION_WARNING, 4000)
    if msg == 30089:
        xbmc.executebuiltin('Container.Refresh')


# ---- router ----
ROUTES = {'resume': do_resume, 'nextup': do_nextup, 'movies': do_movies, 'series': do_series, 'spotlight': do_spotlight,
          'week': do_week, 'allmovies': do_allmovies, 'allseries': do_allseries, 'catalog': do_catalog}
Q_ROUTES = {'catlist': do_catlist, 'catreqs': do_catreqs}   # these take the query too


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
        xbmcplugin.addDirectoryItems(h, [folder(L(30001), 'resume'), folder(L(30004), 'nextup'), folder(L(30005), 'movies'),
                                         folder(L(30006), 'series'), folder(L(30002), 'spotlight'), folder(L(30003), 'week'),
                                         folder(L(30007), 'allmovies'), folder(L(30008), 'allseries'), folder(L(30070), 'catalog')])
        xbmcplugin.endOfDirectory(h)
        return
    c = kofin()
    ok = True
    if not c:
        xbmcgui.Dialog().notification('JELLYNAS', L(30010), xbmcgui.NOTIFICATION_WARNING, 5000)
        ok = False
    else:
        try:
            if mode == 'pick':   # RunPlugin from the skin's grid bar: a dialog, no directory
                do_pick(c, q)
                return
            if mode == 'catitem':   # a catalog card: a dialog, no directory
                do_catitem(c, q)
                return
            if mode == 'wgroup':
                do_wgroup(h, c, q)
            elif mode in Q_ROUTES:
                Q_ROUTES[mode](h, c, q)
            elif mode in ('allmovies', 'allseries'):
                ROUTES[mode](h, c, q)
            elif mode in ROUTES:
                ROUTES[mode](h, c)
        except Exception as x:  # noqa: BLE001 - a widget must not break the home screen: log and show an empty row
            log('%s: %s' % (mode, x))
            ok = False
    if mode not in ('pick', 'catitem'):
        xbmcplugin.endOfDirectory(h, succeeded=ok, cacheToDisc=False)
