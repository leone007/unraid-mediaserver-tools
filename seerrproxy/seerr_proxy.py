#!/usr/bin/env python3
# JELLYNAS catalog proxy (ATADAS item 141, Katalógus): lets the Kodi add-on plugin.video.jellynas browse Seerr and
# request a title in the viewer's own name, without a Seerr key on the TVs.
#  - Every call carries the viewer's Jellyfin login (the same "MediaBrowser ... Token=" header Kofin sends); the proxy
#    checks it with Jellyfin GET /Users/Me and finds the Seerr user whose jellyfinUserId is that user.
#  - Seerr is asked with its API key, read from Seerr's own settings.json (mounted read only), never printed.
#  - Only these calls exist: GET discover lists, one title, the request list; POST one request (X-API-User = the viewer).
# Python standard library only (image python:3-alpine). Settings by environment:
#   JELLYFIN_URL (http://192.168.50.30:8096), SEERR_URL (http://192.168.50.30:5055),
#   SEERR_SETTINGS (/seerr/settings.json), PORT (5056)
import json, os, re, sys, threading, time, urllib.error, urllib.parse, urllib.request
from datetime import date
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

JELLYFIN = os.environ.get('JELLYFIN_URL', 'http://192.168.50.30:8096').rstrip('/')
SEERR = os.environ.get('SEERR_URL', 'http://192.168.50.30:5055').rstrip('/')
SETTINGS = os.environ.get('SEERR_SETTINGS', '/seerr/settings.json')
PORT = int(os.environ.get('PORT', '5056'))
IMG = 'https://image.tmdb.org/t/p/'
DISCOVER = ('trending', 'movies', 'movies/upcoming', 'tv', 'tv/upcoming')
# Seerr media status: 1 unknown, 2 pending, 3 processing, 4 partially available, 5 available (6 blocklisted, 7 deleted)
STATUS = {2: 'pending', 3: 'processing', 4: 'partial', 5: 'available'}
TIMEOUT = 15
_lock = threading.Lock()
_cache = {}   # key -> (expires, value)


def cached(key, ttl, fn):
    now = time.time()
    with _lock:
        hit = _cache.get(key)
        if hit and hit[0] > now:
            return hit[1]
    val = fn()
    with _lock:
        _cache[key] = (now + ttl, val)
        if len(_cache) > 5000:   # small server: drop the expired ones now and then
            for k in [k for k, v in _cache.items() if v[0] <= now]:
                del _cache[k]
    return val


def api_key():
    return cached('apikey', 300, lambda: json.load(open(SETTINGS, encoding='utf-8'))['main']['apiKey'])


def http(url, hdr=None, body=None):
    req = urllib.request.Request(url, data=json.dumps(body).encode('utf-8') if body is not None else None,
                                 headers=dict(hdr or {}, **({'Content-Type': 'application/json'} if body is not None else {})),
                                 method='POST' if body is not None else 'GET')
    with urllib.request.urlopen(req, timeout=TIMEOUT) as r:
        return json.loads(r.read().decode('utf-8') or 'null')


def seerr(path, user=None, body=None, **q):
    hdr = {'X-Api-Key': api_key(), 'Accept': 'application/json'}
    if user:
        hdr['X-API-User'] = str(user)
    return http('%s/api/v1%s%s' % (SEERR, path, '?' + urllib.parse.urlencode(q) if q else ''), hdr, body)


def viewer(auth):
    """The Jellyfin user of the header (checked with Jellyfin itself), cached 10 minutes per header."""
    def me():
        d = http(JELLYFIN + '/Users/Me', {'Authorization': auth, 'X-Emby-Authorization': auth, 'Accept': 'application/json'})
        return {'id': d['Id'], 'name': d.get('Name', '')}
    return cached('me:' + auth, 600, me)


def seerr_user(jf_id):
    def users():
        res = seerr('/user', take=1000, skip=0).get('results', [])
        return {(u.get('jellyfinUserId') or '').replace('-', '').lower(): u['id'] for u in res if u.get('jellyfinUserId')}
    return cached('users', 600, users).get(jf_id.replace('-', '').lower())


def img(path, size):
    return IMG + size + path if path else ''


def card(r, kind=None):
    """One discover result or title, trimmed to what the add-on shows."""
    kind = kind or r.get('mediaType')
    day = r.get('releaseDate') or r.get('firstAirDate') or ''
    mi = r.get('mediaInfo') or {}
    return {'type': kind, 'id': r.get('id'), 'title': r.get('title') or r.get('name') or '',
            'original': r.get('originalTitle') or r.get('originalName') or '', 'year': day[:4], 'date': day,
            'overview': r.get('overview') or '', 'rating': round(r.get('voteAverage') or 0, 1),
            'poster': img(r.get('posterPath'), 'w500'), 'backdrop': img(r.get('backdropPath'), 'w1280'),
            'status': STATUS.get(mi.get('status'), ''), 'jellyfin': mi.get('jellyfinMediaId') or ''}


def title(kind, tid, lang):
    def get():
        d = seerr('/%s/%d' % (kind, tid), language=lang)
        out = card(d, kind)
        out['genres'] = [g.get('name') for g in d.get('genres') or [] if g.get('name')]
        out['runtime'] = d.get('runtime') or (d.get('episodeRunTime') or [0])[0] or 0
        if kind == 'tv':
            have = {s.get('seasonNumber'): STATUS.get(s.get('status'), '') for s in (d.get('mediaInfo') or {}).get('seasons') or []}
            out['seasons'] = [{'n': s.get('seasonNumber'), 'name': s.get('name') or '', 'air': s.get('airDate') or '',
                               'episodes': s.get('episodeCount') or 0, 'status': have.get(s.get('seasonNumber'), '')}
                              for s in d.get('seasons') or [] if s.get('seasonNumber')]
        return out
    return cached('t:%s:%s:%s' % (kind, tid, lang), 3600, get)


def coming(t, seasons):
    """Requested but not out yet: a movie released later, a series whose requested season airs later (or has no date)."""
    today = date.today().isoformat()
    if t['type'] == 'movie':
        return bool(t['date']) and t['date'] > today
    air = {s['n']: s['air'] for s in t.get('seasons', [])}
    return any((air.get(n) or '9999') > today for n in seasons) if seasons else (t['date'] or '9999') > today


def requests_list(flt, lang, take):
    src = {'all': 'all', 'available': 'available', 'processing': 'processing', 'comingsoon': 'processing'}[flt]
    res = seerr('/request', take=100, skip=0, sort='added', filter=src).get('results', [])
    out = []
    for r in res:
        m = r.get('media') or {}
        kind, tid = m.get('mediaType'), m.get('tmdbId')
        if kind not in ('movie', 'tv') or not tid:
            continue
        try:
            t = dict(title(kind, int(tid), lang))
        except (urllib.error.URLError, ValueError, KeyError):
            continue
        seasons = [s.get('seasonNumber') for s in r.get('seasons') or [] if s.get('seasonNumber')]
        if flt in ('processing', 'comingsoon') and coming(t, seasons) != (flt == 'comingsoon'):
            continue
        by = r.get('requestedBy') or {}
        t.update({'status': STATUS.get(m.get('status'), t['status']), 'jellyfin': m.get('jellyfinMediaId') or t['jellyfin'],
                  'by': by.get('displayName') or by.get('jellyfinUsername') or by.get('username') or '',
                  'added': (r.get('createdAt') or '')[:10], 'requested': seasons})
        t.pop('seasons', None)
        out.append(t)
        if len(out) >= take:
            break
    return out


class Handler(BaseHTTPRequestHandler):
    server_version = 'jellynas-catalog/1'

    def log_message(self, fmt, *a):   # path only: no headers, no tokens
        sys.stderr.write('%s %s\n' % (self.command, self.path.split('?')[0]))

    def send(self, code, data):
        b = json.dumps(data, ensure_ascii=False).encode('utf-8')
        self.send_response(code)
        self.send_header('Content-Type', 'application/json; charset=utf-8')
        self.send_header('Content-Length', str(len(b)))
        self.end_headers()
        self.wfile.write(b)

    def who(self):
        auth = self.headers.get('Authorization') or self.headers.get('X-Emby-Authorization') or ''
        if 'Token=' not in auth:
            self.send(401, {'error': 'login'})
            return None
        try:
            return viewer(auth)
        except urllib.error.HTTPError as x:
            self.send(401 if x.code in (401, 403) else 502, {'error': 'login' if x.code in (401, 403) else 'jellyfin'})
        except (urllib.error.URLError, OSError, ValueError, KeyError):
            self.send(502, {'error': 'jellyfin'})
        return None

    def do_GET(self):
        u = urllib.parse.urlsplit(self.path)
        q = dict(urllib.parse.parse_qsl(u.query))
        lang = re.sub(r'[^a-zA-Z-]', '', q.get('language', 'en'))[:5] or 'en'
        if u.path == '/health':
            return self.send(200, {'ok': True})
        if not self.who():
            return
        try:
            m = re.fullmatch(r'/discover/([a-z/]+)', u.path)
            if m and m.group(1) in DISCOVER:
                page = max(1, min(int(q.get('page', '1')), 20))
                # Seerr's movie and tv discover lists read 'language' as the ORIGINAL language filter (language=hu gave
                # Hungarian films only): only trending gets it; the titles' language comes from Seerr's own locale
                extra = {'language': lang} if m.group(1) == 'trending' else {}
                d = cached('d:%s:%d:%s' % (m.group(1), page, lang), 900,
                           lambda: seerr('/discover/' + m.group(1), page=page, **extra))
                kind = {'movies': 'movie', 'movies/upcoming': 'movie', 'tv': 'tv', 'tv/upcoming': 'tv'}.get(m.group(1))
                return self.send(200, {'results': [card(r, kind) for r in d.get('results', [])
                                                   if (kind or r.get('mediaType')) in ('movie', 'tv')],
                                       'pages': d.get('totalPages', 1)})
            m = re.fullmatch(r'/item/(movie|tv)/(\d+)', u.path)
            if m:
                return self.send(200, title(m.group(1), int(m.group(2)), lang))
            if u.path == '/requests' and q.get('filter', 'all') in ('all', 'available', 'processing', 'comingsoon'):
                return self.send(200, {'results': requests_list(q.get('filter', 'all'), lang, max(1, min(int(q.get('take', '40')), 100)))})
            self.send(404, {'error': 'path'})
        except urllib.error.HTTPError as x:
            self.send(502, {'error': 'seerr %d' % x.code})
        except (urllib.error.URLError, OSError, ValueError, KeyError) as x:
            self.send(502, {'error': 'seerr: %s' % type(x).__name__})

    def do_POST(self):
        if urllib.parse.urlsplit(self.path).path != '/request':
            return self.send(404, {'error': 'path'})
        me = self.who()
        if not me:
            return
        try:
            body = json.loads(self.rfile.read(min(int(self.headers.get('Content-Length') or 0), 4096)).decode('utf-8'))
            kind, tid, seasons = body.get('type'), int(body.get('id')), body.get('seasons')
            if kind not in ('movie', 'tv') or tid <= 0:
                raise ValueError
            if kind == 'tv' and not (seasons == 'all' or (isinstance(seasons, list) and seasons and all(isinstance(n, int) and n > 0 for n in seasons))):
                raise ValueError
        except (ValueError, TypeError, AttributeError):
            return self.send(400, {'error': 'body'})
        user = seerr_user(me['id'])
        if not user:
            return self.send(403, {'error': 'no seerr user'})
        req = {'mediaType': kind, 'mediaId': tid}
        if kind == 'tv':
            req['seasons'] = seasons
        try:
            r = seerr('/request', user=user, body=req)
        except urllib.error.HTTPError as x:
            try:
                msg = json.loads(x.read().decode('utf-8')).get('message', '')
            except (ValueError, OSError):
                msg = ''
            return self.send(409 if x.code == 409 else 502, {'error': 'seerr %d' % x.code, 'message': msg[:200]})
        except (urllib.error.URLError, OSError) as x:
            return self.send(502, {'error': 'seerr: %s' % type(x).__name__})
        with _lock:   # the title's status changed
            for k in [k for k in _cache if k.startswith('t:%s:%d:' % (kind, tid)) or k.startswith('d:')]:
                del _cache[k]
        sys.stderr.write('request %s %d by %s\n' % (kind, tid, me['name']))
        self.send(200, {'ok': True, 'status': STATUS.get((r.get('media') or {}).get('status'), 'pending')})


if __name__ == '__main__':
    print('jellynas catalog proxy on :%d (Jellyfin %s, Seerr %s)' % (PORT, JELLYFIN, SEERR), flush=True)
    ThreadingHTTPServer(('', PORT), Handler).serve_forever()
