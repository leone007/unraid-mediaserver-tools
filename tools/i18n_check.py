#!/usr/bin/env python3
# Checks the dictionaries of the pages and tiles (<plugin>/lang/*.json, see common/i18n.php):
#  - en.json is valid JSON, every entry has text (or one + other) and a note
#  - every key the code uses (T('group.key') in PHP and JS) is in en.json, and every en.json key is used
#  - every translation is valid JSON, has only known keys, and keeps the placeholders ({n}, {name}) of English
# Usage: python3 tools/i18n_check.py [plugin ...]    exit code 1 on an error; missing translations are only counted
import json, os, re, sys
R = os.path.normpath(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..'))
USE = re.compile(r"T\(\s*'([a-z][a-z0-9_]*\.[a-z0-9_.]+)'")
PH = re.compile(r'\{([a-z0-9_]+)\}')
FORMS = ('text', 'one', 'other')

def forms(e):
    return {f: e[f] for f in FORMS if isinstance(e, dict) and f in e}

def check(plugin):
    err, d = [], os.path.join(R, plugin, 'lang')
    try:
        en = json.load(open(os.path.join(d, 'en.json'), encoding='utf-8'))
    except Exception as x:
        return ['%s: en.json: %s' % (plugin, x)]
    keys = {k: v for k, v in en.items() if not k.startswith('_')}
    for k, v in keys.items():
        f = forms(v)
        if not f or ('text' not in f and not ('one' in f and 'other' in f)):
            err.append('%s: %s: needs text, or one and other' % (plugin, k))
        if not str(v.get('note', '')).strip():
            err.append('%s: %s: no note for the translator' % (plugin, k))
    used = set()
    for base, _, files in os.walk(os.path.join(R, plugin)):
        if '/lang' in base:
            continue
        for fn in files:
            if fn.endswith(('.page', '.php', '.js')):
                used |= set(USE.findall(open(os.path.join(base, fn), encoding='utf-8').read()))
    for k in sorted(used - set(keys)):
        err.append('%s: %s: used in the code but not in en.json' % (plugin, k))
    for k in sorted(set(keys) - used):
        err.append('%s: %s: in en.json but not used' % (plugin, k))
    for fn in sorted(os.listdir(d)):
        if not fn.endswith('.json') or fn == 'en.json':
            continue
        try:
            tr = json.load(open(os.path.join(d, fn), encoding='utf-8'))
        except Exception as x:
            err.append('%s: %s: %s' % (plugin, fn, x)); continue
        miss = 0
        for k, v in tr.items():
            if k.startswith('_'):
                continue
            if k not in keys:
                err.append('%s: %s: %s is not in en.json' % (plugin, fn, k)); continue
            want = set(PH.findall(' '.join(forms(keys[k]).values())))
            for f, t in forms(v).items():
                got = set(PH.findall(t))
                if got - want:
                    err.append('%s: %s: %s.%s has placeholders English does not: %s' % (plugin, fn, k, f, ', '.join(sorted(got - want))))
                if f != 'one' and want - got:
                    err.append('%s: %s: %s.%s lost placeholders: %s' % (plugin, fn, k, f, ', '.join(sorted(want - got))))
        miss = len([k for k in keys if k not in tr or not forms(tr[k])])
        print('%s: %s: %d of %d texts translated' % (plugin, fn, len(keys) - miss, len(keys)))
    print('%s: en.json: %d texts, %d used in the code' % (plugin, len(keys), len(used)))
    return err

plugins = sys.argv[1:] or sorted(p for p in os.listdir(R) if os.path.isfile(os.path.join(R, p, 'lang', 'en.json')))
errors = [e for p in plugins for e in check(p)]
for e in errors:
    print('ERROR ' + e)
sys.exit(1 if errors else 0)
