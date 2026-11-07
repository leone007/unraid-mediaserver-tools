#!/usr/bin/env python3
# Builds the JELLYNAS fork of Arctic Fuse 2 (item 141): the upstream skin at a pinned commit plus the patches of
# kodi/skin.arctic.fuse.2.jellynas/patches/, as skin.arctic.fuse.2.jellynas-<version>.zip for "Install from zip file".
# Only the patches live in the repo; the skin itself (~145 MB) is fetched from GitHub into a cache. The zip (~67 MB) goes
# to /work/outputs/; it is not committed (every version would stay in the git history). With --publish it is uploaded as
# an asset of the GitHub release with the tag skin.arctic.fuse.2.jellynas (the newest KEEP zips stay) and
# dist/kodi/skins/addons.xml (+ .md5) is written: repository.jellynas reads that as a second <dir> whose datadir is
# .../releases/download/, so Kodi finds <datadir>skin.arctic.fuse.2.jellynas/skin.arctic.fuse.2.jellynas-<version>.zip.
# Internal references to skin.arctic.fuse.2 stay as they are on purpose: script.skinvariables keeps the menus and
# widgets under addon_data/script.skinvariables/nodes/skin.arctic.fuse.2, so the fork uses the same menus as AF2.
# Checks before writing: every patch applies, every .xml of the skin parses, addon.xml gets the new id.
# Usage: python3 tools/kodi_skin_build.py [--out DIR] [--publish]        exit code 1 on an error
#   --publish needs the GitHub token in ~/.gh_token (never printed); commit and push dist/kodi/skins/ afterwards
import hashlib, os, re, shutil, subprocess, sys, tempfile, zipfile
import xml.etree.ElementTree as ET
R = os.path.normpath(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..'))
SRC = os.path.join(R, 'kodi', 'skin.arctic.fuse.2.jellynas')
UPSTREAM = 'https://github.com/jurialmunkey/skin.arctic.fuse.2'
BRANCH, COMMIT, UPSTREAM_VERSION = 'omega', 'e138a25fe11f24254c6456711d37ac9d1053f541', '2.12.12'
SKIN_ID, SKIN_NAME = 'skin.arctic.fuse.2.jellynas', 'Arctic Fuse 2 JELLYNAS'
VERSION = UPSTREAM_VERSION + '.27'   # our build number after the upstream version
CACHE = os.path.expanduser('~/.cache/jellynas/skin.arctic.fuse.2')
SKIP = ('.git*',)   # .git, .github, .gitattributes, .gitignore
GH_REPO, KEEP = 'leone007/unraid-mediaserver-tools', 3
SKINS = os.path.join(R, 'dist', 'kodi', 'skins')


def run(*cmd, cwd=None):
    p = subprocess.run(cmd, cwd=cwd, capture_output=True, text=True)
    if p.returncode:
        raise RuntimeError('%s: %s' % (' '.join(cmd[:3]), (p.stderr or p.stdout).strip()))
    return p.stdout


def upstream():
    if not os.path.isdir(os.path.join(CACHE, '.git')):
        os.makedirs(os.path.dirname(CACHE), exist_ok=True)
        run('git', 'clone', '-q', '--depth', '1', '-b', BRANCH, UPSTREAM, CACHE)
    if run('git', 'rev-parse', 'HEAD', cwd=CACHE).strip() != COMMIT:
        run('git', 'fetch', '-q', '--depth', '1', 'origin', COMMIT, cwd=CACHE)
        run('git', 'checkout', '-q', COMMIT, cwd=CACHE)


def addon_xml(path):
    fn = os.path.join(path, 'addon.xml')
    t = open(fn, encoding='utf-8').read()
    head = '<addon id="skin.arctic.fuse.2" name="Arctic Fuse 2" provider-name="jurialmunkey" version="%s">' % UPSTREAM_VERSION
    if head not in t:
        raise RuntimeError('addon.xml: unexpected first line (upstream changed?)')
    t = t.replace(head, '<addon id="%s" name="%s" provider-name="jurialmunkey, JELLYNAS" version="%s">' % (SKIN_ID, SKIN_NAME, VERSION))
    t = re.sub(r'<description lang="en">.*?</description>',
               '<description lang="en">Arctic Fuse 2 by jurialmunkey (CC BY-NC-SA 4.0) with the JELLYNAS changes: '
               'Jellyfin-like centred category bar, the add-on second line under the cards, IMDb or Available badge on the cards, poster grid on Home for an All movies or All series menu item with a bar for letter, sort, watched and genre, Live TV opens a Jellyfin-like TV guide, a Catalog page with Movies, Series and Requests tabs. Upstream: %s (%s, %s).</description>'
               % (UPSTREAM, BRANCH, COMMIT[:7]), t, flags=re.S)
    open(fn, 'w', encoding='utf-8').write(t)
    if ET.parse(fn).getroot().get('id') != SKIN_ID:
        raise RuntimeError('addon.xml: id not replaced')


def build(out):
    upstream()
    tmp = tempfile.mkdtemp()
    try:
        path = os.path.join(tmp, SKIN_ID)
        shutil.copytree(CACHE, path, ignore=shutil.ignore_patterns(*SKIP))
        patches = sorted(f for f in os.listdir(os.path.join(SRC, 'patches')) if f.endswith('.patch'))
        for f in patches:
            run('git', 'apply', '--whitespace=nowarn', os.path.join(SRC, 'patches', f), cwd=path)
            print('OK   patch %s' % f)
        extra = os.path.join(SRC, 'files')   # our own files (textures), copied over the skin
        if os.path.isdir(extra):
            shutil.copytree(extra, path, dirs_exist_ok=True)
            print('OK   files %s' % ', '.join(sorted(os.path.relpath(os.path.join(b, f), extra) for b, _, fs in os.walk(extra) for f in fs)))
        addon_xml(path)
        bad = []
        for base, dirs, files in os.walk(path):
            for f in files:
                if f.endswith('.xml'):
                    try:
                        ET.parse(os.path.join(base, f))
                    except ET.ParseError as x:
                        bad.append('%s: %s' % (os.path.relpath(os.path.join(base, f), path), x))
        if bad:
            print('\n'.join('FAIL ' + b for b in bad)); return 1
        print('OK   every .xml parses')
        os.makedirs(out, exist_ok=True)
        zfn = os.path.join(out, '%s-%s.zip' % (SKIN_ID, VERSION))
        with zipfile.ZipFile(zfn, 'w', zipfile.ZIP_DEFLATED) as z:
            for base, dirs, files in os.walk(path):
                dirs.sort()
                for f in sorted(files):
                    full = os.path.join(base, f)
                    zi = zipfile.ZipInfo(os.path.join(SKIN_ID, os.path.relpath(full, path)), (2026, 1, 1, 0, 0, 0))
                    zi.compress_type, zi.external_attr = zipfile.ZIP_DEFLATED, 0o644 << 16   # fixed time: the same build gives the same md5
                    z.writestr(zi, open(full, 'rb').read())
        print('OK   %s %s  %s  %.1f MB  md5 %s' % (SKIN_ID, VERSION, zfn, os.path.getsize(zfn) / 1e6,
                                                hashlib.md5(open(zfn, 'rb').read()).hexdigest()))
        if PUBLISH:
            publish(zfn, open(os.path.join(path, 'addon.xml'), encoding='utf-8').read())
        return 0
    finally:
        shutil.rmtree(tmp, ignore_errors=True)


def gh(*args):
    env = dict(os.environ, GH_TOKEN=open(os.path.expanduser('~/.gh_token')).read().strip())
    p = subprocess.run(('gh',) + args, capture_output=True, text=True, env=env)
    if p.returncode:
        raise RuntimeError('gh %s: %s' % (args[0] if args[0] != 'release' else ' '.join(args[:2]), (p.stderr or p.stdout).strip()[:300]))
    return p.stdout


def publish(zfn, xml):
    """Upload the zip to the release <SKIN_ID>, drop older zips beyond KEEP, then write the skin's repository index."""
    try:
        gh('release', 'view', SKIN_ID, '-R', GH_REPO)
    except RuntimeError:
        gh('release', 'create', SKIN_ID, '-R', GH_REPO, '--title', SKIN_NAME + ' (Kodi repository files)',
           '--notes', 'Zips of the JELLYNAS Arctic Fuse 2 fork for repository.jellynas. Build: tools/kodi_skin_build.py --publish.')
    gh('release', 'upload', SKIN_ID, zfn, '--clobber', '-R', GH_REPO)
    names = gh('release', 'view', SKIN_ID, '-R', GH_REPO, '--json', 'assets', '-q', '.assets[].name').split()
    def ver(n):
        return tuple(int(x) for x in re.findall(r'\d+', n[len(SKIN_ID):]))
    for old in sorted((n for n in names if n.startswith(SKIN_ID + '-') and n.endswith('.zip')), key=ver)[:-KEEP]:
        gh('release', 'delete-asset', SKIN_ID, old, '-y', '-R', GH_REPO)
        print('OK   release: old %s removed' % old)
    print('OK   release %s: %s uploaded' % (SKIN_ID, os.path.basename(zfn)))
    os.makedirs(SKINS, exist_ok=True)
    data = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n<addons>\n%s\n</addons>\n' % re.sub(r'^<\?xml[^>]*>\s*', '', xml).strip()
    ET.fromstring(data.encode('utf-8'))
    open(os.path.join(SKINS, 'addons.xml'), 'w', encoding='utf-8').write(data)
    open(os.path.join(SKINS, 'addons.xml.md5'), 'w').write(hashlib.md5(data.encode('utf-8')).hexdigest())
    print('OK   dist/kodi/skins/addons.xml (%s %s)' % (SKIN_ID, VERSION))


PUBLISH = '--publish' in sys.argv

if __name__ == '__main__':
    a = sys.argv[1:]
    out = a[a.index('--out') + 1] if '--out' in a else os.path.normpath(os.path.join(R, '..', 'outputs'))
    try:
        sys.exit(build(out))
    except RuntimeError as x:
        print('FAIL ' + str(x)); sys.exit(1)
