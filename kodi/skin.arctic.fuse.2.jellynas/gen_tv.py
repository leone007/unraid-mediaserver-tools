# writes the JELLYNAS Home TV list of patch 05 into an AF2 work copy (after patch 04): python3 gen_tv.py <af2 work copy>
# The side menu item Live TV (path with TVGuide, TVChannels or pvr://) opens Kodi's TV guide window a second after it
# gets focus (owner 2026-11-06; the epggrid only lives in that window); Back lands on the item without reopening it.
import re, sys
AF = sys.argv[1] + '/1080i/'
A = 'plugin.video.jellynas'

def sub(f, old, new, n=1):
    s = open(AF + f).read()
    assert s.count(old) == n, (f, old)
    open(AF + f, 'w').write(s.replace(old, new))

EXPR = '''    <!-- JELLYNAS: the Live TV side menu item opens the TV guide (Home_Menu_List_HiddenButton) -->
    <expression name="Exp_JellynasTV">[Window.IsVisible(Home) + [String.Contains(Container(300).ListItem.Property(path),TVGuide) | String.Contains(Container(300).ListItem.Property(path),TVChannels) | String.Contains(Container(300).ListItem.Property(path),pvr://)]]</expression>
    <expression name="Exp_JellynasPanel">[$EXP[Exp_JellynasGrid] | $EXP[Exp_JellynasTV]]</expression>
'''

TV = '''<!-- JELLYNAS: Live TV side menu item (Exp_JellynasTV): plain background here; the TV guide window opens by
itself a second after the item gets focus (Home_Menu_List_HiddenButton), or with Right -->
<include name="Hub_JellynasTV">
<control type="image">
<description>JELLYNAS TV: plain background</description>
<left>-1000</left>
<width>4000</width>
<top>-200</top>
<height>1500</height>
<texture colordiffuse="$VAR[ColorBackground]">common/white.png</texture>
<visible>$EXP[Exp_JellynasTV]</visible>
<animation effect="fade" start="0" end="100" time="200">Visible</animation>
<animation effect="fade" start="100" end="0" time="200">Hidden</animation>
</control>
</include>

'''

def indent(block, d0):
    out, d = [], d0
    for ln in block.split('\n'):
        t = ln.strip()
        if not t:
            out.append('')
            continue
        if t.startswith('</'):
            d -= 1
        out.append('    ' * d + t)
        if t.startswith('<') and not t.startswith(('</', '<!--')) and not t.endswith('/>') and '</' not in t and not t.endswith('-->'):
            d += 1
    return '\n'.join(out)

e = open(AF + 'Includes_Expressions.xml').read()
e = re.sub(r'    <!-- JELLYNAS: the Live TV side menu item.*?<expression name="Exp_JellynasPanel">[^\n]*\n', '', e, flags=re.S)
e = e.replace('    <expression name="Exp_JellynasGrid_Series">', EXPR + '    <expression name="Exp_JellynasGrid_Series">', 1)
open(AF + 'Includes_Expressions.xml', 'w').write(e)

h = open(AF + 'Includes_Hubs.xml').read()
h = re.sub(r'    <!-- JELLYNAS: Live TV next to the side menu.*?</include>\n\n', '', h, flags=re.S)
h = h.replace('    <!-- JELLYNAS: poster grid of the focused', indent(TV, 1).replace('\n    itself a second', '\n         itself a second') + '    <!-- JELLYNAS: poster grid of the focused', 1)
h = h.replace('<include condition="$PARAM[is_homemenu]">Hub_JellynasGrid</include>\n',
              '<include condition="$PARAM[is_homemenu]">Hub_JellynasGrid</include>\n                        <include condition="$PARAM[is_homemenu]">Hub_JellynasTV</include>\n', 1) \
    if 'Hub_JellynasTV</include>' not in h else h
h = h.replace('''        <control type="group" id="340">
            <visible>!$EXP[Exp_JellynasGrid]</visible>''', '''        <control type="group" id="340">
            <visible>!$EXP[Exp_JellynasPanel]</visible>''')
h = h.replace('<param name="visible">!$EXP[Exp_JellynasGrid]</param>', '<param name="visible">!$EXP[Exp_JellynasPanel]</param>')
open(AF + 'Includes_Hubs.xml', 'w').write(h)

a = open(AF + 'Includes_Actions.xml').read()
if 'Exp_JellynasTV' not in a:
    a = a.replace('<onright condition="$EXP[Exp_JellynasGrid]">SetFocus(4900)</onright>',
                  '<onright condition="$EXP[Exp_JellynasGrid]">SetFocus(4900)</onright>\n            <onright condition="$EXP[Exp_JellynasTV]">SetProperty(JellynasTV.Opened,1,Home)</onright>\n            <onright condition="$EXP[Exp_JellynasTV]">ActivateWindow(TVGuide)</onright>', 1)
    a = a.replace('"!$EXP[Exp_JellynasGrid] + ', '"!$EXP[Exp_JellynasPanel] + ')
open(AF + 'Includes_Actions.xml', 'w').write(a)

m = open(AF + 'Includes_Home.xml').read()
HB = '''    <include name="Home_Menu_List_HiddenButton">
        <control type="button">
            <visible allowhiddenfocus="true">false</visible>
            <animation effect="fade" start="0" end="0" time="50" reversible="false">Focus</animation>
'''
OPEN = '''            <!-- JELLYNAS: Live TV opens the TV guide a second after its menu item gets focus (moving on cancels it);
                 JellynasTV.Opened: back from the guide on the same item does not reopen it -->
            <onfocus>CancelAlarm(jellynastv,silent)</onfocus>
            <onfocus condition="!$EXP[Exp_JellynasTV]">ClearProperty(JellynasTV.Opened,Home)</onfocus>
            <onfocus condition="$EXP[Exp_JellynasTV] + String.IsEmpty(Window(Home).Property(JellynasTV.Opened))">AlarmClock(jellynastv,ActivateWindow(TVGuide),00:01,silent)</onfocus>
            <onfocus condition="$EXP[Exp_JellynasTV]">SetProperty(JellynasTV.Opened,1,Home)</onfocus>
'''
if 'jellynastv' not in m:
    assert m.count(HB) == 1
    m = m.replace(HB, HB + OPEN)
open(AF + 'Includes_Home.xml', 'w').write(m)
