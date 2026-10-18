#!/usr/bin/env python3
# Unpacks the files embedded in a .plg into a folder, for reading: extract_plg.py dist/qbittorrent_widget.plg seedbox
import re,sys,os
plg,out=sys.argv[1],sys.argv[2]
t=open(plg,encoding='utf-8').read()
os.makedirs(out,exist_ok=True)
nm=re.search(r'<!ENTITY name\s+"([^"]+)"',t).group(1)
for name,body in re.findall(r'<FILE Name="([^"]+)">\s*<INLINE><!\[CDATA\[(.*?)\]\]></INLINE>',t,re.S):
    open(os.path.join(out,os.path.basename(name.replace('&name;',nm))),'w',encoding='utf-8').write(body)
