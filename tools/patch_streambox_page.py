#!/usr/bin/env python3
# Streambox has no template: puts every file of streambox/ that the plg embeds (streambox.page with common/tile.css at its
# marker) back into dist/streambox.plg, adds the files listed in NEW that are not in it yet, and bumps the version.
import re,sys,os
D=os.path.normpath(os.path.join(os.path.dirname(os.path.abspath(__file__)),'..'))+'/'
NEW=['week_lib.php','week.php','week_late.php','week_web.php','week_web.js','lang.php','lang_block.php','lang_save.php','i18n.php','lang/en.json','lang/hu.json']
COMMON=['lang.php','lang_block.php','lang_save.php','i18n.php']   # shared with the other plugins: taken from common/   # inserted before notify_lib.php when missing
ver,change=sys.argv[1],sys.argv[2]
P=D+'dist/streambox.plg'
s=open(P,encoding='utf-8').read()

def src(f):
    t=open(D+('common/' if f in COMMON else 'streambox/')+f,encoding='utf-8').read()
    if f=='streambox.page': t=t.replace('/*@@TILE_CSS@@*/',open(D+'common/tile.css',encoding='utf-8').read().strip())
    assert ']]>' not in t, f
    return t

for f in NEW:
    if '<FILE Name="&plugdir;/%s">'%f not in s:
        a='<FILE Name="&plugdir;/notify_lib.php">'
        s=s.replace(a,'<FILE Name="&plugdir;/%s">\n<INLINE><![CDATA[]]></INLINE>\n</FILE>\n\n'%f+a,1)

n=0
pos=0
while True:
    m=re.compile(r'<FILE Name="&plugdir;/([^"]+)">\n<INLINE><!\[CDATA\[').search(s,pos)
    if not m: break
    f=m.group(1).replace('&name;','streambox')
    i=m.end(); j=s.index(']]></INLINE>',i)
    if f in COMMON or os.path.isfile(D+'streambox/'+f):
        t=src(f); s=s[:i]+t+s[j:]; j=i+len(t); n+=1
    pos=j

old=re.search(r'<!ENTITY version   "([^"]+)">',s).group(1)
s=s.replace('<!ENTITY version   "%s">'%old,'<!ENTITY version   "%s">'%ver,1)
assert '###&version;\n' in s and '<' not in change and '>' not in change
s=s.replace('###&version;\n','###&version;\n- '+change+'\n###'+old+'\n',1)
open(P,'w',encoding='utf-8').write(s)
print(old,'->',ver,'files synced:',n)
