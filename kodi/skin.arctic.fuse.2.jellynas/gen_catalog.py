# writes the JELLYNAS Home Catalog page of patch 07 into an AF2 work copy (after patch 06): python3 gen_catalog.py <af2 work copy>
# The side menu item whose path is the add-on's Catalog folder (plugin.video.jellynas/?mode=catalog) shows, right of the
# menu, tabs Movies | Series | Requests (Left/Right switches at once) and below them rows of poster cards from the
# add-on (Seerr through the catalog proxy): trending, popular, upcoming; requests by state. OK on a card = the add-on's choice.
import re, sys
AF = sys.argv[1] + '/1080i/'
A = 'plugin.video.jellynas'
P = 'plugin://plugin.video.jellynas/?mode='

def sub(f, old, new):
    s = open(AF + f).read()
    if new in s:
        return
    assert s.count(old) == 1, (f, old)
    open(AF + f, 'w').write(s.replace(old, new))

TABS = (('30097', 4960, (('30110', 'catlist&amp;list=trending&amp;type=movie'), ('30072', 'catlist&amp;list=movies'), ('30073', 'catlist&amp;list=movies/upcoming'))),
        ('30098', 4970, (('30111', 'catlist&amp;list=trending&amp;type=tv'), ('30074', 'catlist&amp;list=tv'), ('30075', 'catlist&amp;list=tv/upcoming'))),
        ('30076', 4980, (('30078', 'catreqs&amp;filter=processing'), ('30079', 'catreqs&amp;filter=comingsoon'), ('30080', 'catreqs&amp;filter=available'))))

def card(lid, focused):
    def poster(selected, vis):
        return '''<control type="group">
<left>20</left>
<top>20</top>
<visible>%s</visible>
<include content="Layout_Poster">
<param name="item_w">view_poster_item_w</param>
<param name="item_h">view_poster_item_h</param>
<param name="selected">%s</param>
<param name="id">%d</param>
</include>
</control>
''' % (vis, selected, lid)
    out = poster('false', '!Control.HasFocus(%d)' % lid if focused else 'true')
    if focused:
        out += poster('true', 'Control.HasFocus(%d)' % lid)
    for top, h, font, color, lab in ((342, 36, 'font_mini_bold', 'main_fg_90' if not focused else '$VAR[JellynasCatalog_TitleColor]', '$INFO[ListItem.Label]'),
                                      (378, 30, 'font_tiny', 'main_fg_50', '$INFO[ListItem.Property(sublabel)]')):
        out += '''<control type="label">
<left>20</left>
<top>%d</top>
<width>view_poster_item_w</width>
<height>%d</height>
<font>%s</font>
<textcolor>%s</textcolor>
<label>%s</label>
</control>
''' % (top, h, font, color, lab)
    return out

def has(i):
    return 'Integer.IsGreater(Container(%d).NumItems,0)' % i


def nav(tag, targets, last):
    """<onup>/<ondown> to the first non-empty list of targets, else last (0 = nowhere)."""
    out, before = '', []
    for t in targets:
        out += '<%s condition="%s">%d</%s>\n' % (tag, ' + '.join(['!' + has(b) for b in before] + [has(t)]), t, tag)
        before.append(t)
    if last:
        out += '<%s condition="%s">%d</%s>\n' % (tag, ' + '.join('!' + has(b) for b in before), last, tag) if before else '<%s>%d</%s>\n' % (tag, last, tag)
    return out


def row(lid, label, path, ups, downs):   # lists in a grouplist need their own up/down (Kodi does not pass them on)
    return '''<control type="group">
<height>470</height>
<control type="label">
<left>view_offx</left>
<height>50</height>
<font>font_main_bold</font>
<textcolor>main_fg_90</textcolor>
<label>$ADDON[%s %s]</label>
</control>
<control type="list" id="%d">
<left>60</left>
<top>50</top>
<height>420</height>
<orientation>horizontal</orientation>
<scrolltime tween="quadratic">300</scrolltime>
<preloaditems>2</preloaditems>
%s%s<onleft>300</onleft>
<onback>4950</onback>
<itemlayout width="257" height="420">
%s</itemlayout>
<focusedlayout width="257" height="420">
%s</focusedlayout>
<content>%s%s</content>
</control>
</control>
''' % (A, label, lid, nav('onup', ups, 4950), nav('ondown', downs, 0), card(lid, False), card(lid, True), P, path)

def tab_layout(focused):
    out = '''<control type="image">
<left>6</left>
<right>6</right>
<texture colordiffuse="%s" border="34">common/menu.png</texture>
%s</control>
''' % (('main_fg_100', '<visible>Control.HasFocus(4950)</visible>\n') if focused else ('00FFFFFF', ''))
    if focused:
        out += '''<control type="image">
<left>6</left>
<right>6</right>
<texture colordiffuse="main_fg_12" border="34">common/menu.png</texture>
<visible>!Control.HasFocus(4950)</visible>
</control>
'''
    out += '''<control type="label">
<align>center</align>
<aligny>center</aligny>
<font>font_main_bold</font>
<textcolor>%s</textcolor>
<label>$INFO[ListItem.Label]</label>
</control>
''' % ('$VAR[JellynasCatalog_TabColor]' if focused else 'main_fg_50')
    return out

items = ''.join('''<item>
<label>$ADDON[%s %s]</label>
<onclick>SetFocus(%d)</onclick>
</item>
''' % (A, lab, gid) for lab, gid, _ in TABS)
groups = ''
for n, (lab, gid, rows) in enumerate(TABS, 1):
    groups += '''<control type="grouplist" id="%d">
<left>0</left>
<right>0</right>
<top>140</top>
<bottom>0</bottom>
<orientation>vertical</orientation>
<itemgap>10</itemgap>
<onup>4950</onup>
<onleft>300</onleft>
<visible>$EXP[Exp_JellynasCatalog] + Integer.IsEqual(Container(4950).CurrentItem,%d)</visible>
%s</control>
''' % (gid, n, ''.join(row(gid + k + 1, l, p, [gid + j for j in range(k, 0, -1)], [gid + j for j in range(k + 2, len(rows) + 1)]) for k, (l, p) in enumerate(rows)))

down = ''.join(nav('ondown', [gid + 1, gid + 2, gid + 3], 0).replace('<ondown condition="', '<ondown condition="Integer.IsEqual(Container(4950).CurrentItem,%d) + ' % n)
               for n, (_, gid, _) in enumerate(TABS, 1))
INC = '''<!-- JELLYNAS: Catalog page next to the side menu (Exp_JellynasCatalog): tabs Movies | Series | Requests (list 4950,
Left/Right switches at once), rows of poster cards in grouplists 4960 / 4970 / 4980 from the add-on (Seerr) -->
<include name="Hub_JellynasCatalog">
<control type="group">
<control type="image">
<description>JELLYNAS catalog: plain background</description>
<left>-1000</left>
<width>4000</width>
<top>-200</top>
<height>1500</height>
<texture colordiffuse="$VAR[ColorBackground]">common/white.png</texture>
<visible>$EXP[Exp_JellynasCatalog]</visible>
<animation effect="fade" start="0" end="100" time="200">Visible</animation>
<animation effect="fade" start="100" end="0" time="200">Hidden</animation>
</control>
<control type="list" id="4950">
<description>JELLYNAS catalog tabs</description>
<left>view_offx</left>
<width>900</width>
<top>40</top>
<height>80</height>
<orientation>horizontal</orientation>
<onleft>300</onleft>
<onback>300</onback>
%s<visible>$EXP[Exp_JellynasCatalog]</visible>
<itemlayout width="270" height="80">
%s</itemlayout>
<focusedlayout width="270" height="80">
%s</focusedlayout>
<content>
%s</content>
</control>
%s</control>
</include>

''' % (down, tab_layout(False), tab_layout(True), items, groups)

VARS = '''    <variable name="JellynasCatalog_TabColor">
        <value condition="Control.HasFocus(4950)">$VAR[ColorSelected]</value>
        <value>main_fg_100</value>
    </variable>
    <variable name="JellynasCatalog_TitleColor">
        <value condition="Control.HasFocus(4961) | Control.HasFocus(4962) | Control.HasFocus(4963) | Control.HasFocus(4971) | Control.HasFocus(4972) | Control.HasFocus(4973) | Control.HasFocus(4981) | Control.HasFocus(4982) | Control.HasFocus(4983)">main_fg_100</value>
        <value>main_fg_90</value>
    </variable>
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

h = open(AF + 'Includes_Hubs.xml').read()
h = re.sub(r'    <!-- JELLYNAS: Catalog page next to the side menu.*?</include>\n\n', '', h, flags=re.S)
h = re.sub(r'    <variable name="JellynasCatalog_[A-Za-z]*">.*?</variable>\n', '', h, flags=re.S)
inc = indent(INC, 1).replace('\n    Left/Right switches', '\n         Left/Right switches')
h = h.replace('    <!-- JELLYNAS: poster grid of the focused', inc + '    <!-- JELLYNAS: poster grid of the focused', 1)
h = h.replace('    <variable name="JellynasGrid_Query">', VARS + '    <variable name="JellynasGrid_Query">', 1)
if 'Hub_JellynasCatalog</include>' not in h:
    h = h.replace('<include condition="$PARAM[is_homemenu]">Hub_JellynasTV</include>\n',
                  '<include condition="$PARAM[is_homemenu]">Hub_JellynasTV</include>\n                        <include condition="$PARAM[is_homemenu]">Hub_JellynasCatalog</include>\n', 1)
open(AF + 'Includes_Hubs.xml', 'w').write(h)

sub('Includes_Expressions.xml', '    <expression name="Exp_JellynasPanel">[$EXP[Exp_JellynasGrid] | $EXP[Exp_JellynasTV]]</expression>\n',
    '''    <!-- JELLYNAS: the side menu item pointing at the add-on's Catalog folder shows the catalog page -->
    <expression name="Exp_JellynasCatalog">[Window.IsVisible(Home) + String.Contains(Container(300).ListItem.Property(path),plugin.video.jellynas/?mode=catalog)]</expression>
    <expression name="Exp_JellynasPanel">[$EXP[Exp_JellynasGrid] | $EXP[Exp_JellynasTV] | $EXP[Exp_JellynasCatalog]]</expression>
''')
sub('Includes_Actions.xml', '<onright condition="$EXP[Exp_JellynasTV]">ActivateWindow(TVGuide)</onright>',
    '<onright condition="$EXP[Exp_JellynasTV]">ActivateWindow(TVGuide)</onright>\n            <onright condition="$EXP[Exp_JellynasCatalog]">SetFocus(4950)</onright>')
