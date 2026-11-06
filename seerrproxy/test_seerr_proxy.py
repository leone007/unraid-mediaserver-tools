#!/usr/bin/env python3
# Stub test of seerr_proxy.py: one fake server plays Jellyfin (/Users/Me) and Seerr (/api/v1/...).
# Usage: python3 seerrproxy/test_seerr_proxy.py        exit code 1 on a failed check
import json, os, sys, tempfile, threading, urllib.error, urllib.parse, urllib.request
from datetime import date, timedelta
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

SEEN, FAILS = [], []
FUTURE, PAST = (date.today() + timedelta(days=60)).isoformat(), '2020-05-01'
MOVIE = {'id': 1, 'title': 'Stick', 'releaseDate': '2025-06-03', 'overview': 'Golf.', 'voteAverage': 7.34, 'posterPath': '/p1.jpg',
         'backdropPath': '/b1.jpg', 'genres': [{'name': 'Comedy'}], 'runtime': 30, 'mediaInfo': {'status': 5, 'jellyfinMediaId': 'jf1'}}
SOON = {'id': 3, 'title': 'Later', 'releaseDate': FUTURE, 'mediaInfo': {'status': 3}}
TV = {'id': 2, 'name': 'Nocturne', 'firstAirDate': '2026-01-01', 'mediaInfo': {'status': 4, 'seasons': [{'seasonNumber': 1, 'status': 5}]},
      'seasons': [{'seasonNumber': 0, 'name': 'Specials'}, {'seasonNumber': 1, 'name': 'Season 1', 'airDate': PAST, 'episodeCount': 8},
                  {'seasonNumber': 2, 'name': 'Season 2', 'airDate': FUTURE, 'episodeCount': 8}]}


class Stub(BaseHTTPRequestHandler):
    def log_message(self, *a):
        pass

    def reply(self, code, data):
        b = json.dumps(data).encode()
        self.send_response(code)
        self.send_header('Content-Type', 'application/json')
        self.end_headers()
        self.wfile.write(b)

    def do_GET(self):
        u = urllib.parse.urlsplit(self.path)
        q = dict(urllib.parse.parse_qsl(u.query))
        SEEN.append(('GET', u.path, q, self.headers.get('X-Api-Key'), self.headers.get('X-API-User')))
        if u.path == '/Users/Me':
            if 'Token="good"' in (self.headers.get('Authorization') or ''):
                return self.reply(200, {'Id': 'abcd1234abcd1234abcd1234abcd1234', 'Name': 'leone007'})
            return self.reply(401, {})
        if self.headers.get('X-Api-Key') != 'k1':
            return self.reply(403, {})
        if u.path == '/api/v1/discover/trending':
            return self.reply(200, {'totalPages': 3, 'results': [dict(MOVIE, mediaType='movie'), dict(TV, mediaType='tv'), {'mediaType': 'person', 'id': 9},
                                                                 dict(MOVIE, id=5, title='가능한 사랑', mediaType='movie')]})
        if u.path == '/api/v1/discover/tv':
            return self.reply(200, {'results': [TV]})
        if u.path == '/api/v1/movie/1':
            return self.reply(200, MOVIE)
        if u.path == '/api/v1/movie/5':
            return self.reply(200, dict(MOVIE, id=5, title='Possible Love' if q.get('language') == 'en' else '가능한 사랑'))
        if u.path == '/api/v1/movie/3':
            return self.reply(200, SOON)
        if u.path == '/api/v1/tv/2':
            return self.reply(200, dict(TV, name='Éjszakai', overview='Magyar leírás') if q.get('language') == 'hu' else TV)
        if u.path == '/api/v1/user':
            return self.reply(200, {'results': [{'id': 7, 'jellyfinUserId': 'ABCD1234-abcd-1234-abcd-1234abcd1234'}, {'id': 8}]})
        if u.path == '/api/v1/request':
            res = [{'media': {'mediaType': 'movie', 'tmdbId': 1, 'status': 5, 'jellyfinMediaId': 'jf1'},
                    'requestedBy': {'displayName': 'Zsuzsi'}, 'createdAt': '2026-10-31T10:00:00.000Z', 'seasons': []},
                   {'media': {'mediaType': 'movie', 'tmdbId': 3, 'status': 3}, 'requestedBy': {'displayName': 'leone007'}, 'seasons': []},
                   {'media': {'mediaType': 'tv', 'tmdbId': 2, 'status': 4}, 'requestedBy': {'displayName': 'Zsófi'},
                    'seasons': [{'seasonNumber': 1}]}]
            if q.get('filter') == 'processing':
                res = res[1:]
            return self.reply(200, {'results': res})
        self.reply(404, {})

    def do_POST(self):
        body = json.loads(self.rfile.read(int(self.headers.get('Content-Length'))))
        SEEN.append(('POST', self.path, body, self.headers.get('X-Api-Key'), self.headers.get('X-API-User')))
        if body.get('mediaId') == 1:
            return self.reply(409, {'message': 'Request already exists'})
        self.reply(201, {'id': 55, 'media': {'status': 2}})


def ok(cond, what):
    print(('ok   ' if cond else 'FAIL ') + what)
    if not cond:
        FAILS.append(what)


stub = ThreadingHTTPServer(('127.0.0.1', 0), Stub)
threading.Thread(target=stub.serve_forever, daemon=True).start()
base = 'http://127.0.0.1:%d' % stub.server_address[1]
cfg = tempfile.NamedTemporaryFile('w', suffix='.json', delete=False)
json.dump({'main': {'apiKey': 'k1'}}, cfg)
cfg.close()
os.environ.update(JELLYFIN_URL=base, SEERR_URL=base, SEERR_SETTINGS=cfg.name)
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import seerr_proxy as P  # noqa: E402

srv = ThreadingHTTPServer(('127.0.0.1', 0), P.Handler)
threading.Thread(target=srv.serve_forever, daemon=True).start()
PX = 'http://127.0.0.1:%d' % srv.server_address[1]
GOOD = 'MediaBrowser Client="Kofin", Device="Mac", DeviceId="d", Version="0.29.3", Token="good"'


def call(path, auth=GOOD, body=None):
    req = urllib.request.Request(PX + path, data=json.dumps(body).encode() if body is not None else None,
                                 headers={'Authorization': auth} if auth else {}, method='POST' if body is not None else 'GET')
    try:
        with urllib.request.urlopen(req, timeout=10) as r:
            return r.status, json.loads(r.read())
    except urllib.error.HTTPError as x:
        return x.code, json.loads(x.read() or b'{}')


ok(call('/health', auth=None) == (200, {'ok': True}), 'health without login')
ok(call('/discover/trending', auth=None)[0] == 401, 'no login: 401')
ok(call('/discover/trending', auth=GOOD.replace('good', 'bad'))[0] == 401, 'wrong Jellyfin token: 401')
code, d = call('/discover/trending?language=hu')
ok(code == 200 and [r['title'] for r in d['results']] == ['Stick', 'Nocturne', 'Possible Love'] and d['pages'] == 3,
   'trending: movies and series, no persons; a Korean title (boxes in the skin font) becomes the English one')
s = d['results'][0]
ok(s['type'] == 'movie' and s['year'] == '2025' and s['rating'] == 7.3 and s['poster'] == 'https://image.tmdb.org/t/p/w500/p1.jpg'
   and s['status'] == 'available' and s['jellyfin'] == 'jf1', 'card: year, rating, TMDb poster, status, Jellyfin id')
ok(d['results'][1]['status'] == 'partial', 'card: partially available')
ok([x for x in SEEN if x[1] == '/api/v1/discover/trending'][-1][2].get('language') == 'hu', 'language goes to Seerr')
ok([r['title'] for r in call('/discover/trending?language=hu&type=tv')[1]['results']] == ['Nocturne'], 'trending: only series with type=tv')
ok(call('/discover/tv?language=hu')[1]['results'][0]['type'] == 'tv', 'discover tv: type from the list kind')
ok('language' not in [x for x in SEEN if x[1] == '/api/v1/discover/tv'][-1][2], 'discover tv: no language (Seerr filters the original language with it)')
ok(call('/discover/tv?language=hu&page=2')[1]['results'][0]['title'] == 'Éjszakai', 'discover tv: the Hungarian title from the title page')
ok(call('/discover/people')[0] == 404, 'unknown list: 404')
code, t = call('/item/tv/2')
ok(code == 200 and [x['n'] for x in t['seasons']] == [1, 2] and t['seasons'][0]['status'] == 'available' and t['seasons'][1]['status'] == '',
   'series: seasons without specials, per-season status')
code, r = call('/requests?filter=all')
ok(code == 200 and [x['title'] for x in r['results']] == ['Stick', 'Later', 'Nocturne'] and r['results'][0]['by'] == 'Zsuzsi'
   and r['results'][0]['added'] == '2026-10-31' and r['results'][2]['requested'] == [1], 'requests: titles, who, when, seasons')
ok([x['title'] for x in call('/requests?filter=comingsoon')[1]['results']] == ['Later'], 'coming soon: released later')
ok([x['title'] for x in call('/requests?filter=processing')[1]['results']] == ['Nocturne'], 'processing: out already (season 1 aired)')
ok(call('/requests?filter=pending')[0] == 404, 'pending approval is not offered')
code, p = call('/request', body={'type': 'tv', 'id': 2, 'seasons': [2]})
post = [x for x in SEEN if x[0] == 'POST'][-1]
ok(code == 200 and p == {'ok': True, 'status': 'pending'} and post[2] == {'mediaType': 'tv', 'mediaId': 2, 'seasons': [2]}
   and post[3] == 'k1' and post[4] == '7', 'request: in the viewer\'s Seerr name (X-API-User), with the key')
ok(call('/request', body={'type': 'movie', 'id': 1})[0] == 409, 'request already there: 409')
ok(call('/request', body={'type': 'tv', 'id': 2})[0] == 400 and call('/request', body={'type': 'person', 'id': 2})[0] == 400,
   'bad body: 400 (a series needs seasons)')
ok(call('/request', auth=None, body={'type': 'movie', 'id': 5})[0] == 401, 'request without login: 401')
ok(call('/anything', body={})[0] == 404, 'no other POST')
ok(all(x[3] == 'k1' for x in SEEN if x[1].startswith('/api/v1/')), 'every Seerr call with the key')
ok(not [x for x in SEEN if x[0] == 'POST' and x[1] != '/api/v1/request'], 'the only POST is /request')
srv.shutdown()
stub.shutdown()
os.unlink(cfg.name)
print('%d failed' % len(FAILS) if FAILS else 'all checks passed')
sys.exit(1 if FAILS else 0)
