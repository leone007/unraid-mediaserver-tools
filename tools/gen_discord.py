#!/usr/bin/env python3
# Generates diskbox/discord.php and sysbox/discord.php from common/discord_tpl.php
import os
R=os.path.normpath(os.path.join(os.path.dirname(os.path.abspath(__file__)),'..'))
t=open(R+'/common/discord_tpl.php',encoding='utf-8').read()
for name,P,U in (('Diskbox','dbn','DBN'),('Sysbox','sxn','SXN')):
    s=t.replace('@N@',name).replace('@P@',P).replace('@U@',U).replace('@S@',name.lower())
    open('%s/%s/discord.php'%(R,name.lower()),'w',encoding='utf-8').write(s)
