# Runs the JELLYNAS plugin outside Kodi: stub xbmc* modules and a local stub Jellyfin server (synthetic data).
# Usage: python3 kodi/plugin.video.jellynas/tests/test_jellynas.py      exit code 1 on a failed check
import re
import http.server, json, os, sys, tempfile, threading, time, types, urllib.parse

HERE = os.path.dirname(os.path.abspath(__file__))
ADDON = os.path.dirname(HERE)
PROFILE = tempfile.mkdtemp(prefix='jellynas_')
SEEN = []          # (method, path, query, auth) of every request the plugin made
NOW = time.time()
LANG = {'lang': '0'}


# ---- stub Jellyfin ----
def ep(iid, series, sid, se, en, name, pos=0):
    return {'Id': iid, 'Type': 'Episode', 'Name': name, 'SeriesName': series, 'SeriesId': sid, 'ParentIndexNumber': se,
            'IndexNumber': en, 'RunTimeTicks': 30000000000, 'UserData': {'PlaybackPositionTicks': pos},
            'ProviderIds': {'Tmdb': '9%s' % en, 'Imdb': 'tt9%s' % en}, 'SeriesPrimaryImageTag': 'sp',
            'ParentBackdropItemId': sid, 'ParentBackdropImageTags': ['sb'], 'ParentThumbItemId': sid,
            'ParentThumbImageTag': 'st', 'ParentLogoItemId': sid, 'ParentLogoImageTag': 'sl', 'ImageTags': {'Primary': 'ep'},
            'MediaStreams': [{'Type': 'Video', 'Width': 3840, 'Height': 2160, 'Codec': 'HEVC', 'VideoRangeType': 'DOVIWithHDR10'},
                             {'Type': 'Audio', 'Channels': 6, 'Codec': 'EAC3', 'Language': 'eng'}]}


MOVIE = {'Id': 'm1', 'Type': 'Movie', 'Name': 'Hotel Transylvania', 'ProductionYear': 2012, 'RunTimeTicks': 54600000000,
         'UserData': {'PlaybackPositionTicks': 30000000000}, 'ProviderIds': {'Tmdb': '76492', 'Imdb': 'tt0837562'},
         'ImageTags': {'Primary': 'mp', 'Logo': 'ml'}, 'BackdropImageTags': ['mb'], 'CommunityRating': 6.9, 'OfficialRating': 'HU-6'}
RESUME = [MOVIE, ep('e4', 'Vízióküldetés', 's1', 1, 4, '4. epizód', 12000000000)]
NEXTUP = [ep('e5', 'Vízióküldetés', 's1', 1, 5, '5. epizód'), ep('l2', 'Lost', 's2', 4, 2, 'Halottnak nyilvánítva')]
SERIES = {'s1': {'Tmdb': '213375', 'Imdb': 'tt23112594'}, 's2': {'Tmdb': '4607', 'Imdb': 'tt0411008'}, 's3': {'Tmdb': '1'}}
WEEK_FROM = NOW - 3 * 86400


def week():
    def e(s, c, t, air, st, epid='', sid='', exp=None, pct=None):
        return {'s': s, 'c': c, 't': t, 'air': int(air), 'exp': exp, 'st': st, 'pct': pct, 'new': False, 'ep': epid, 'sid': sid, 'img': ''}
    d0 = NOW - 2 * 86400
    return {'ok': True, 'gen': int(NOW), 'view': 'row', 'from': int(WEEK_FROM), 'to': int(WEEK_FROM + 7 * 86400), 'late': 7200, 'eps': [
        e('A visszavágó', 'S01E05', '5. epizód', d0, 'jf', 'v5', 's3'),
        e('Gengszter', 'S02E01', '1. epizód', NOW - 3600, 'jf', 'g1', 's4'),
        e('Gengszter', 'S02E02', '2. epizód', NOW - 3500, 'jf', 'g2', 's4'),
        e('Gengszter', 'S02E03', '3. epizód', NOW - 3400, 'dl', '', 's4', pct=40),
        e('Nocturne', 'S01E03', 'Eyes', NOW + 30 * 3600, 'wait', '', 's5'),
        e('Az Intézet', 'S02E01', 'The Reckoning', NOW + 30 * 3600 + 60, 'late', '', 's6', exp=int(NOW - 7200))]}


class H(http.server.BaseHTTPRequestHandler):
    def log_message(self, *a):
        pass

    def do_GET(self):
        u = urllib.parse.urlparse(self.path)
        q = dict(urllib.parse.parse_qsl(u.query))
        SEEN.append(('GET', u.path, q, self.headers.get('Authorization', ''), self.headers.get('User-Agent', '')))
        if 'Python-urllib' in self.headers.get('User-Agent', ''):
            self.send_response(403)
            self.end_headers()
            return
        body = None
        if u.path == '/UserItems/Resume':
            body = {'Items': RESUME}
        elif u.path == '/Shows/NextUp':
            body = {'Items': NEXTUP}
        elif u.path == '/Items/Latest':
            body = [MOVIE] if q.get('IncludeItemTypes') == 'Movie' else [
                {'Id': 's2', 'Type': 'Series', 'Name': 'Lost', 'ProductionYear': 2004, 'ImageTags': {'Primary': 'p2'}, 'BackdropImageTags': ['b2']},
                {'Id': 'se3', 'Type': 'Season', 'Name': '1. évad', 'SeriesName': 'A visszavágó', 'SeriesId': 's3', 'ImageTags': {}},
                ep('v6', 'A visszavágó', 's3', 1, 6, '6. epizód')]
        elif u.path == '/Items':
            ids = q.get('Ids', '').split(',')
            body = {'Items': [{'Id': i, 'Type': 'Series', 'ProviderIds': SERIES[i], 'ImageTags': {'Primary': 'p' + i},
                               'BackdropImageTags': ['b' + i], 'Overview': 'Series overview ' + i, 'Genres': ['Reality'],
                               'OfficialRating': 'HU-12'} for i in ids if i in SERIES]
                              + [dict(ep(i, 'x', 's3', 1, 5, 'n'), Overview='Episode overview ' + i) for i in ids if i in ('v5', 'g1', 'g2')]}
        elif u.path == '/web/streambox/week.json':
            body = week()
        elif u.path == '/web/streambox/imdb.json':
            body = {'gen': 1, 'src': 1, 'r': {'m1': [71, 260000, 'tt0837562'], 's1': [78, 9000, 'tt23112594'], 's3': [63, 500, 'tt1']}}
        data = json.dumps(body).encode() if body is not None else b'{}'
        self.send_response(200 if body is not None else 404)
        self.send_header('Content-Type', 'application/json')
        self.end_headers()
        self.wfile.write(data)

    def do_POST(self):
        SEEN.append(('POST', self.path, {}, '', ''))
        self.send_response(405)
        self.end_headers()


srv = http.server.ThreadingHTTPServer(('127.0.0.1', 0), H)
threading.Thread(target=srv.serve_forever, daemon=True).start()
URL = 'http://127.0.0.1:%d' % srv.server_address[1]


# ---- stub Kodi modules ----
def po(lang):
    import re
    t = open(os.path.join(ADDON, 'resources', 'language', 'resource.language.%s' % lang, 'strings.po'), encoding='utf-8').read()
    out = {}
    for m in re.finditer(r'msgctxt "#(\d+)"\nmsgid "(.*)"\nmsgstr "(.*)"', t):
        out[int(m.group(1))] = m.group(3) or m.group(2)
    return out


EN = po('en_gb')


class Addon:
    def __init__(self, aid=None):
        self.aid = aid or 'plugin.video.jellynas'
        if aid and aid != 'plugin.video.kofin':
            raise RuntimeError('unknown addon')

    def getAddonInfo(self, k):
        return {'id': self.aid, 'path': ADDON, 'profile': PROFILE, 'version': '0.29.3'}[k]

    def getSetting(self, k):
        if self.aid == 'plugin.video.kofin':
            return {'serverAddress': URL + '/', 'userId': 'u1', 'accessToken': 'TOKEN', 'deviceId': 'dev1'}.get(k, '')
        return LANG.get(k, '')

    def getLocalizedString(self, i):
        return EN.get(i, '')


class Tag:
    def __init__(self):
        self.d = {'ratings': [], 'video': [], 'audio': []}

    def __getattr__(self, name):
        def f(*a, **k):
            if name == 'setRating':
                self.d['ratings'].append(a)
            elif name == 'addVideoStream':
                self.d['video'].append(a[0].__dict__)
            elif name == 'addAudioStream':
                self.d['audio'].append(a[0].__dict__)
            else:
                self.d[name[3:].lower()] = a if len(a) > 1 else a[0]
        return f


class ListItem:
    def __init__(self, label='', label2='', offscreen=False):
        self.label, self.label2, self.art, self.props, self.tag = label, label2, {}, {}, Tag()

    def getVideoInfoTag(self):
        return self.tag

    def setArt(self, a):
        self.art.update(a)

    def setProperty(self, k, v):
        self.props[k] = v


class Detail:
    def __init__(self, **k):
        self.__dict__.update(k)


DIR = {'items': [], 'end': None, 'notes': []}
xbmc = types.SimpleNamespace(log=lambda *a: None, LOGINFO=1, ISO_639_1=0, getLanguage=lambda f: 'en', getInfoLabel=lambda k: 'Mac "Studio"',
                             VideoStreamDetail=Detail, AudioStreamDetail=Detail)
xbmcaddon = types.SimpleNamespace(Addon=Addon)
xbmcgui = types.SimpleNamespace(ListItem=ListItem, NOTIFICATION_WARNING='w',
                                Dialog=lambda: types.SimpleNamespace(notification=lambda *a: DIR['notes'].append(a)))
xbmcplugin = types.SimpleNamespace(setContent=lambda h, c: None, addDirectoryItems=lambda h, items: DIR['items'].extend(items),
                                   endOfDirectory=lambda h, succeeded=True, cacheToDisc=True: DIR.__setitem__('end', succeeded))
xbmcvfs = types.SimpleNamespace(translatePath=lambda p: p)
for n, m in (('xbmc', xbmc), ('xbmcaddon', xbmcaddon), ('xbmcgui', xbmcgui), ('xbmcplugin', xbmcplugin), ('xbmcvfs', xbmcvfs)):
    sys.modules[n] = m
sys.path.insert(0, os.path.join(ADDON, 'resources', 'lib'))
import jellynas  # noqa: E402

FAILS = []


def ok(cond, what):
    print(('ok   ' if cond else 'FAIL ') + what)
    if not cond:
        FAILS.append(what)


def run(query):
    DIR['items'], DIR['end'], DIR['notes'] = [], None, []
    jellynas._po = None
    jellynas.main(['plugin://plugin.video.jellynas/', '1', query])
    return DIR['items']


# ---- checks ----
root = run('')
ok([li.label for _, li, _ in root] == ['Continue watching', 'Next up', 'Recently added movies', 'Recently added series', 'Spotlight', 'This week'], 'root: six folders')

items = run('?mode=resume')
ok(len(items) == 2 and DIR['end'] is True, 'resume: 2 items, directory ok')
url, li, isdir = items[1]
ok(li.label == 'Vízióküldetés' and li.label2 == 'S1:E4 · 4. epizód', 'resume: episode under the series name, S1:E4 · title below')
ok(url == 'plugin://plugin.video.kofin/?mode=play&id=e4' and not isdir and li.props.get('IsPlayable') == 'true', 'resume: plays through Kofin')
ok(li.art.get('clearlogo', '').startswith(URL + '/Items/s1/Images/Logo?tag=sl'), 'resume: series clear logo')
ok(li.art.get('poster', '').startswith(URL + '/Items/s1/Images/Primary?tag=sp'), 'resume: series poster')
ok(('imdb' in [r[2] for r in li.tag.d['ratings']]) and li.props.get('jellynas.imdb') == '7.8', 'resume: IMDb from imdb.json (series id)')
ok(li.tag.d.get('uniqueids', [{}])[0].get('tvshow.tmdb') == '213375' and li.props.get('tvshow.tmdb_id') == '213375', 'resume: series TMDb id for TMDb Helper')
ok(li.tag.d.get('resumepoint') == (1200.0, 3000), 'resume: resume point')
ok(li.tag.d['video'][0].get('hdrtype') == 'dolbyvision' and li.tag.d['video'][0].get('width') == 3840, 'resume: video stream 4K DV')
mv = items[0][1]
ok(mv.label == 'Hotel Transylvania' and mv.label2 == '2012' and mv.props.get('tmdb_id') == '76492', 'resume: movie label, year, tmdb_id')
auth = [s[3] for s in SEEN if s[1] == '/UserItems/Resume'][0]
ok(auth == 'MediaBrowser Client="Kofin", Device="Mac \'Studio\'", DeviceId="dev1", Version="0.29.3", Token="TOKEN"', 'auth header like Kofin')
ok(all(s[2].get('userId') == 'u1' for s in SEEN if not s[1].startswith('/web/')), 'every Jellyfin request names the user')

items = run('?mode=spotlight')
ok([li.label for _, li, _ in items] == ['Hotel Transylvania', 'Vízióküldetés', 'Lost'], 'spotlight: resume + next up, one per series')

items = run('?mode=week')
ok(not [li for _, li, _ in items if li.props.get('jellynas.separator')], 'week: no day cards')
ok([e for e in items if e[1].label2.startswith('TODAY ')], 'week: today marked in the second line: %s' % [li.label2 for _, li, _ in items])
grp = [(u, li, d) for u, li, d in items if li.label == 'Gengszter']
ok(len(grp) == 1 and grp[0][2] and 'mode=wgroup' in grp[0][0] and grp[0][1].label2.endswith(' · S02E01–E03') and '2/3 available' in grp[0][1].tag.d['plot'], 'week: season pack of one day = one folder card')
v5 = [x for x in items if x[1].label == 'A visszavágó'][0]
ok(v5[0].endswith('mode=play&id=v5') and [r for r in v5[1].tag.d['ratings'] if r[2] == 'imdb' and abs(r[0] - 6.3) < 0.01], 'week: available episode plays, IMDb by series (info panel)')
ok(v5[1].art.get('landscape') == URL + '/Items/s3/Images/Backdrop?tag=bs3&maxWidth=1920&quality=90' and 'tag=ps3' in v5[1].art.get('poster', ''),
   'week: tagged series images, backdrop when the series has no Thumb')
noc = [x for x in items if x[1].label == 'Nocturne'][0]
ok(re.match(r'^[A-Z]+ \d\d:\d\d · S01E03$', noc[1].label2) and 'Airs in 1d' in noc[1].tag.d['plot'] and 'mode=na' in noc[0],
   'week: day and time in the second line, the countdown in the plot, not playable: %s' % noc[1].label2)
late = [x for x in items if x[1].label == 'Az Intézet'][0]
ok('Late 2h 0m' in late[1].tag.d['plot'], 'week: late state with duration (plot)')

g = run('?' + grp[0][0].split('?', 1)[1])
ok([li.label2 for _, li, _ in g] == ['S02E01 · Available', 'S02E02 · Available', 'S02E03 · Downloading 40%'], 'week group folder: its episodes')

LANG['lang'] = '2'
items = run('?mode=week')
ok(any(li.label2.startswith('MA ') for _, li, _ in items) and any(li.props.get('jellynas.badge') == 'Elérhető' for _, li, _ in items), 'Hungarian chosen in the settings')
LANG['lang'] = '0'

items = run('?mode=nextup')
ok([li.label for _, li, _ in items] == ['Vízióküldetés', 'Lost'] and items[0][1].props.get('sublabel') == 'S1:E5 · 5. epizód', 'next up row')
items = run('?mode=movies')
ok(items[0][1].label == 'Hotel Transylvania' and items[0][1].props.get('jellynas.imdb') == '7.1' and items[0][0].endswith('mode=play&id=m1'),
   'recently added movies: IMDb badge value, plays')
lat = [q for q in SEEN if q[1] == '/Items/Latest']
ok(lat and lat[-1][2].get('IncludeItemTypes') == 'Movie' and lat[-1][2].get('userId') == 'u1', 'latest movies asked with the user')
items = run('?mode=series')
ser = {li.label: (u, li, d) for u, li, d in items}
ok(ser['Lost'][2] and ser['Lost'][0].endswith('mode=browse&type=series&folder=s2') and ser['Lost'][1].props.get('sublabel') == '2004',
   'recently added series: a series opens its seasons in Kofin')
ssn = [x for x in items if 'type=season' in x[0]]
ok(ssn and ssn[0][2] and 'series=s3' in ssn[0][0] and ssn[0][1].props.get('jellynas.imdb') == '6.3', 'season card: Kofin season browse, IMDb of its series')
eps6 = [x for x in items if x[0].endswith('id=v6')]
ok(eps6 and eps6[0][1].props.get('sublabel') == 'S1:E6 · 6. epizód', 'recently added series: a single new episode plays')

items = run('?mode=week')
ok(not [li for _, li, _ in items if li.props.get('jellynas.imdb')] and all(li.props.get('jellynas.kind') == 'week' for _, li, _ in items),
   'week: no IMDb badge value on the cards (kind=week)')
v5 = [x for x in items if x[1].label == 'A visszavágó'][0][1]
noc = [x for x in items if x[1].label == 'Nocturne'][0][1]
ok(v5.props.get('jellynas.badge') == 'Available' and not noc.props.get('jellynas.badge') and 'imdb' in [r[2] for r in v5.tag.d['ratings']],
   'week: Available badge only when available, IMDb rating kept for the info panel')

ok(v5.tag.d.get('plot') == 'Episode overview v5' and v5.tag.d['video'] and v5.tag.d.get('duration') == 3000, 'week: available episode: its own overview, streams, length')
ok(noc.tag.d.get('plot', '').startswith('Nocturne · S01E03'), 'week: no Jellyfin overview: the made-up text stays')
gp = [x for x in items if x[1].label == 'Gengszter'][0][1]
ok('Episode overview' not in gp.tag.d.get('plot', ''), 'week: a season pack card has no single episode overview')

ok(not [s for s in SEEN if s[0] != 'GET'], 'only GET requests')
ok(all(s[4].startswith('JELLYNAS/') for s in SEEN), 'own User-Agent (the proxy refuses Python-urllib)')
srv.shutdown()
print('%d failed' % len(FAILS) if FAILS else 'all checks passed')
sys.exit(1 if FAILS else 0)
