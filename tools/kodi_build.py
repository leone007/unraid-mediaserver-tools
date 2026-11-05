#!/usr/bin/env python3
# Builds the Kodi add-ons of kodi/ into the Kodi repository dist/kodi/ (served from GitHub raw by repository.jellynas):
#   dist/kodi/<id>/<id>-<version>.zip, dist/kodi/addons.xml and addons.xml.md5, and dist/kodi/repository.jellynas.zip
#   (a fixed name for the first install from zip).
# Checks before writing: addon.xml parses, every .py compiles, en_gb and hu_hu strings.po have the same ids and
# every string id the code or settings.xml uses is in en_gb (the two-language rule of CLAUDE.md).
# Usage: python3 tools/kodi_build.py        exit code 1 on an error
import hashlib, os, re, shutil, sys, zipfile
import xml.etree.ElementTree as ET
R = os.path.normpath(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..'))
SRC, OUT = os.path.join(R, 'kodi'), os.path.join(R, 'dist', 'kodi')
SKIP = ('__pycache__', 'tests')


def po_ids(fn):
    return set(int(x) for x in re.findall(r'^msgctxt "#(\d+)"', open(fn, encoding='utf-8').read(), re.M)) if os.path.exists(fn) else set()


def check(path, aid):
    err = []
    for base, dirs, files in os.walk(path):
        dirs[:] = [d for d in dirs if d not in SKIP]
        for f in files:
            if f.endswith('.py'):
                try:
                    compile(open(os.path.join(base, f), encoding='utf-8').read(), os.path.join(base, f), 'exec')
                except SyntaxError as x:
                    err.append('%s: %s' % (aid, x))
    lang = os.path.join(path, 'resources', 'language')
    if os.path.isdir(lang):
        en = po_ids(os.path.join(lang, 'resource.language.en_gb', 'strings.po'))
        hu = po_ids(os.path.join(lang, 'resource.language.hu_hu', 'strings.po'))
        if en != hu:
            err.append('%s: en_gb and hu_hu differ: %s' % (aid, sorted(en ^ hu)))
        used = set()
        for base, dirs, files in os.walk(path):
            dirs[:] = [d for d in dirs if d not in SKIP]
            for f in files:
                if f.endswith(('.py', '.xml')) and f != 'addon.xml':
                    t = open(os.path.join(base, f), encoding='utf-8').read()
                    used |= set(int(x) for x in re.findall(r'\bL\((3\d{4})', t))
                    used |= set(int(x) for x in re.findall(r'(?:label|lvalues)="([\d|]+)"', t) for x in x.split('|') if x)
                    used |= set(int(x) for x in re.findall(r'(?<![\d#])(30[0-9]{3})\b', t) if f.endswith('.py'))
        for i in sorted(used - en):
            err.append('%s: string %d used but not in en_gb strings.po' % (aid, i))
        for i in sorted(en - used):
            err.append('%s: string %d in strings.po but not used' % (aid, i))
    return err


def build():
    errors, addons = [], []
    for aid in sorted(os.listdir(SRC)):
        path = os.path.join(SRC, aid)
        if not os.path.isfile(os.path.join(path, 'addon.xml')):
            continue
        try:
            root = ET.parse(os.path.join(path, 'addon.xml')).getroot()
        except ET.ParseError as x:
            errors.append('%s: addon.xml: %s' % (aid, x)); continue
        if root.get('id') != aid:
            errors.append('%s: folder name and addon id (%s) differ' % (aid, root.get('id'))); continue
        errors += check(path, aid)
        addons.append((aid, root.get('version'), path, root))
    if errors:
        print('\n'.join('FAIL ' + e for e in errors)); return 1
    os.makedirs(OUT, exist_ok=True)
    xml = ['<?xml version="1.0" encoding="UTF-8" standalone="yes"?>', '<addons>']
    for aid, ver, path, root in addons:
        d = os.path.join(OUT, aid)
        os.makedirs(d, exist_ok=True)
        zfn = os.path.join(d, '%s-%s.zip' % (aid, ver))
        with zipfile.ZipFile(zfn, 'w', zipfile.ZIP_DEFLATED) as z:
            for base, dirs, files in os.walk(path):
                dirs[:] = sorted(x for x in dirs if x not in SKIP)
                for f in sorted(files):
                    full = os.path.join(base, f)
                    z.write(full, os.path.join(aid, os.path.relpath(full, path)))
        for a in root.iter('assets'):   # Kodi reads the assets from <datadir>/<id>/<the path in addon.xml>
            for el in a:
                if el.text and os.path.exists(os.path.join(path, el.text)):
                    os.makedirs(os.path.dirname(os.path.join(d, el.text)), exist_ok=True)
                    shutil.copy(os.path.join(path, el.text), os.path.join(d, el.text))
        xml.append(open(os.path.join(path, 'addon.xml'), encoding='utf-8').read().split('?>', 1)[1].strip())
        print('OK   %s %s  %s  md5 %s' % (aid, ver, os.path.relpath(zfn, R), hashlib.md5(open(zfn, 'rb').read()).hexdigest()))
        if aid.startswith('repository.'):
            shutil.copy(zfn, os.path.join(OUT, aid + '.zip'))
    xml.append('</addons>')
    data = '\n'.join(xml) + '\n'
    ET.fromstring(data.encode('utf-8'))
    open(os.path.join(OUT, 'addons.xml'), 'w', encoding='utf-8').write(data)
    open(os.path.join(OUT, 'addons.xml.md5'), 'w').write(hashlib.md5(data.encode('utf-8')).hexdigest())
    print('OK   dist/kodi/addons.xml (%d add-ons)' % len(addons))
    return 0


if __name__ == '__main__':
    sys.exit(build())
