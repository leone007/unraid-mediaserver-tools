#!/usr/bin/env python3
# Builds dist/qbittorrent_widget.plg: seedbox/template.plg with every @@SRC:path@@ replaced by the file's content.
import os
D=os.path.normpath(os.path.join(os.path.dirname(os.path.abspath(__file__)),'..'))+'/'
t=open(D+'seedbox/template.plg',encoding='utf-8').read()
import re
def sub(m):
    body=open(D+m.group(1),encoding='utf-8').read()
    assert ']]>' not in body,m.group(1)
    return body
out=re.sub(r'@@SRC:([^@]+)@@',sub,t)
# the shared tile look (common/tile.css) goes where a page has the marker
out=out.replace('/*@@TILE_CSS@@*/',open(D+'common/tile.css',encoding='utf-8').read().strip())
out=out.replace('/*@@SETTINGS_CSS@@*/',open(D+'common/settings.css',encoding='utf-8').read().strip())
open(D+'dist/qbittorrent_widget.plg','w',encoding='utf-8').write(out)
