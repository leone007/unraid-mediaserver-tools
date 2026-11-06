# writes the JELLYNAS Home grid of patch 04 into an AF2 work copy: python3 gen_grid.py <af2 work copy>, then git diff 032ca40-style against the patch-03 commit
import sys, re
AF = sys.argv[1] + '/1080i/'
A = 'plugin.video.jellynas'
P = 'Window(Home).Property(JellynasGrid.%s.%s)'
SER = '$EXP[Exp_JellynasGrid_Series]'
KINDS = (('series', SER), ('movies', '!' + SER))
FIELDS = ('letter', 'sort', 'sortlabel', 'unwatched', 'genre', 'genrelabel')

def per_kind(tag, fmt, extra=''):
    """one action line per kind, e.g. <onclick condition="...">SetProperty(JellynasGrid.series.letter,A,Home)</onclick>"""
    return ''.join('%s<%s condition="%s%s">%s</%s>\n' % (IND, tag, c, extra, fmt.replace('{k}', k), tag) for k, c in KINDS)

def var(name, fmt, default=None):
    out = '    <variable name="%s">\n' % name
    for k, c in KINDS:
        if default is not None:
            out += '        <value condition="%s + String.IsEmpty(%s)">%s</value>\n' % (c, fmt[1].replace('{k}', k), default)
            out += '        <value condition="%s">%s</value>\n' % (c, fmt[0].replace('{k}', k))
        else:
            out += '        <value condition="%s">%s</value>\n' % (c, fmt.replace('{k}', k))
    return out + '    </variable>\n'

filtered = ' | '.join('[%s + [%s]]' % (c, ' | '.join('!String.IsEmpty(%s)' % (P % (k, f)) for f in ('letter', 'sort', 'unwatched', 'genre'))) for k, c in KINDS)
EXPR = '''    <!-- JELLYNAS: a side menu category pointing at the add-on's All movies / All series folder shows a poster grid -->
    <expression name="Exp_JellynasGrid">[Window.IsVisible(Home) + String.Contains(Container(300).ListItem.Property(path),plugin.video.jellynas/?mode=all)]</expression>
    <expression name="Exp_JellynasGrid_Series">String.Contains(Container(300).ListItem.Property(path),mode=allseries)</expression>
    <expression name="Exp_JellynasGrid_Filtered">[%s]</expression>
    <expression name="Exp_JellynasGrid_BarFocus">[Control.HasFocus(4910) | ControlGroup(4920).HasFocus()]</expression>
''' % filtered

VARS = var('JellynasGrid_Query', '&amp;sort=$INFO[%s]&amp;letter=$INFO[%s]&amp;unwatched=$INFO[%s]&amp;genre=$INFO[%s]' % tuple(P % ('{k}', f) for f in ('sort', 'letter', 'unwatched', 'genre')))
VARS += var('JellynasGrid_SortLabel', ('$INFO[%s]' % (P % ('{k}', 'sortlabel')), P % ('{k}', 'sortlabel')), '$ADDON[%s 30050]' % A)
VARS += var('JellynasGrid_GenreLabel', ('$INFO[%s]' % (P % ('{k}', 'genrelabel')), P % ('{k}', 'genrelabel')), '$ADDON[%s 30058]' % A)
VARS += var('JellynasGrid_WatchedLabel', ('$ADDON[%s 30061]' % A, P % ('{k}', 'unwatched')), '$ADDON[%s 30058]' % A)
VARS += '''    <variable name="JellynasGrid_Letter">
        <value condition="String.IsEqual(%s,0) | String.IsEqual(%s,0)">#</value>
''' % (P % ('series', 'letter'), P % ('movies', 'letter'))
VARS += ''.join('        <value condition="%s">$INFO[%s]</value>\n' % (c, P % (k, 'letter')) for k, c in KINDS) + '    </variable>\n'

IND = ' ' * 20
letters = [(chr(i), chr(i)) for i in range(65, 91)] + [('0', '#')]
items = ''
for v, label in letters:
    items += '                <item>\n                    <label>%s</label>\n                    <property name="v">%s</property>\n' % (label, v)
    for k, c in KINDS:   # the active letter again: off (conditions are checked one by one: read the snapshot JellynasGrid.was)
        items += '%s<onclick condition="%s">SetProperty(JellynasGrid.was,$INFO[%s],Home)</onclick>\n' % (IND, c, P % (k, 'letter'))
        items += '%s<onclick condition="%s + String.IsEqual(Window(Home).Property(JellynasGrid.was),%s)">ClearProperty(JellynasGrid.%s.letter,Home)</onclick>\n' % (IND, c, v, k)
        items += '%s<onclick condition="%s + !String.IsEqual(Window(Home).Property(JellynasGrid.was),%s)">SetProperty(JellynasGrid.%s.letter,%s,Home)</onclick>\n' % (IND, c, v, k, v)
    items += IND + '<onclick>SetFocus(4900,0,absolute)</onclick>\n                </item>\n'

def letter_layout(tag, w, cond, focused):
    sel = '%s + [%s]' % ('String.IsEqual(ListItem.Property(v),$INFO[Window(Home).Property(JellynasGrid.series.letter)])', SER)
    sel = '[[%s] | [String.IsEqual(ListItem.Property(v),$INFO[Window(Home).Property(JellynasGrid.movies.letter)]) + !%s]]' % (sel, SER)
    out = '            <%s width="%d" height="80">\n' % (tag, w)
    out += '''                <control type="label">
                    <width>%d</width>
                    <height>70</height>
                    <align>center</align>
                    <aligny>center</aligny>
                    <font>font_main_bold</font>
                    <label>$INFO[ListItem.Label]</label>
                    <textcolor>%s</textcolor>
                </control>
                <control type="image">
                    <left>%d</left>
                    <top>68</top>
                    <width>40</width>
                    <height>8</height>
                    <texture colordiffuse="%s" border="4">common/menu.png</texture>
                    <visible>%s</visible>
                </control>
''' % (w, 'main_fg_100' if focused else 'main_fg_50', (w - 40) // 2, 'main_fg_100' if focused else 'main_fg_30',
       'Control.HasFocus(4910)' if focused else sel)
    return out + '            </%s>\n' % tag

layouts = ''
for tag, focused in (('itemlayout', False), ('focusedlayout', True)):
    layouts += letter_layout(tag, 54, None, focused)
    if focused:  # the focused item while the list itself has no focus looks like the others
        layouts = layouts.replace('<textcolor>main_fg_100</textcolor>', '<textcolor>$VAR[JellynasGrid_LetterColor]</textcolor>')

def button(bid, label, actions, visible='true'):
    return '''                <control type="button" id="%d">
                    <width>auto</width>
                    <height>70</height>
                    <font>font_mini_bold</font>
                    <textoffsetx>36</textoffsetx>
                    <aligny>center</aligny>
                    <textcolor>main_fg_70</textcolor>
                    <focusedcolor>$VAR[ColorSelected]</focusedcolor>
                    <texturefocus colordiffuse="main_fg_100" border="34">common/menu.png</texturefocus>
                    <texturenofocus colordiffuse="CC262626" border="34">common/menu.png</texturenofocus>
                    <label>%s</label>
%s                    <visible>%s</visible>
                </control>
''' % (bid, label, actions, visible)

IND = ' ' * 20
pick = 'RunPlugin(plugin://%s/?mode=pick&amp;what=%%s&amp;kind={k})' % A
watched = ''.join('%s<onclick condition="%s">SetProperty(JellynasGrid.was,$INFO[%s],Home)</onclick>\n' % (IND, c, P % (k, 'unwatched'))
                  + '%s<onclick condition="%s + String.IsEmpty(Window(Home).Property(JellynasGrid.was))">SetProperty(JellynasGrid.%s.unwatched,1,Home)</onclick>\n' % (IND, c, k)
                  + '%s<onclick condition="%s + !String.IsEmpty(Window(Home).Property(JellynasGrid.was))">ClearProperty(JellynasGrid.%s.unwatched,Home)</onclick>\n' % (IND, c, k)
                  for k, c in KINDS)
clear = ''.join(per_kind('onclick', 'ClearProperty(JellynasGrid.{k}.%s,Home)' % f) for f in FIELDS) + IND + '<onclick>SetFocus(4900,0,absolute)</onclick>\n'
buttons = (button(4921, '$ADDON[%s 30055]: $VAR[JellynasGrid_SortLabel]' % A, per_kind('onclick', pick % 'sort'))
           + button(4922, '$ADDON[%s 30060]: $VAR[JellynasGrid_WatchedLabel]' % A, watched)
           + button(4923, '$ADDON[%s 30056]: $VAR[JellynasGrid_GenreLabel]' % A, per_kind('onclick', pick % 'genre'))
           + button(4924, '$ADDON[%s 30059]' % A, clear, '$EXP[Exp_JellynasGrid_Filtered]'))

def status(label, vis):
    return '''                <control type="label">
                    <width>auto</width>
                    <height>40</height>
                    <font>font_mini_bold</font>
                    <textcolor>main_fg_70</textcolor>
                    <label>%s</label>
                    <visible>%s</visible>
                </control>
''' % (label, vis)

def anyk(f, neg=''):
    return '[%s]' % ' | '.join('[%s + %s!String.IsEmpty(%s)]' % (c, neg, P % (k, f)) for k, c in KINDS)

stat = (status('$VAR[JellynasGrid_Letter]', anyk('letter'))
        + status('$VAR[JellynasGrid_SortLabel]', anyk('sortlabel'))
        + status('$ADDON[%s 30057]' % A, anyk('unwatched'))
        + status('$VAR[JellynasGrid_GenreLabel]', anyk('genrelabel')))

GRID = '''    <!-- JELLYNAS: poster grid of the focused side menu category (Exp_JellynasGrid), right of the menu, no extra OK.
         Up from the first row: the grid bar (letters, sort, watched, genre); the choices are Home window properties
         JellynasGrid.<movies|series>.* that go into the grid's path ($VAR[JellynasGrid_Query]), the add-on filters. -->
    <include name="Hub_JellynasGrid">
        <control type="group">
            <control type="image">
                <description>JELLYNAS grid: plain background over the spotlight / last widget fanart</description>
                <left>-1000</left>
                <width>4000</width>
                <top>-200</top>
                <height>1500</height>
                <texture colordiffuse="$VAR[ColorBackground]">common/white.png</texture>
                <visible>$EXP[Exp_JellynasGrid]</visible>
                <animation effect="fade" start="0" end="100" time="200">Visible</animation>
                <animation effect="fade" start="100" end="0" time="200">Hidden</animation>
            </control>
            <control type="grouplist">
                <description>JELLYNAS grid: active filters</description>
                <left>view_offx</left>
                <right>view_offx</right>
                <top>30</top>
                <height>40</height>
                <orientation>horizontal</orientation>
                <itemgap>40</itemgap>
                <usecontrolwidth>true</usecontrolwidth>
                <visible>$EXP[Exp_JellynasGrid] + $EXP[Exp_JellynasGrid_Filtered] + !$EXP[Exp_JellynasGrid_BarFocus]</visible>
%s            </control>

            <control type="group">
                <description>JELLYNAS grid bar</description>
                <visible allowhiddenfocus="true">$EXP[Exp_JellynasGrid] + $EXP[Exp_JellynasGrid_BarFocus]</visible>
                <include content="Animation_ConditionalFade">
                    <param name="condition">!$EXP[Exp_JellynasGrid_BarFocus]</param>
                </include>
                <control type="list" id="4910">
                    <left>view_offx</left>
                    <right>view_offx</right>
                    <top>20</top>
                    <height>80</height>
                    <orientation>horizontal</orientation>
                    <scrolltime tween="quadratic">200</scrolltime>
                    <ondown>4920</ondown>
                    <onleft>300</onleft>
                    <onback>4900</onback>
%s                    <content>
%s                    </content>
                </control>
                <control type="grouplist" id="4920">
                    <left>view_offx</left>
                    <right>view_offx</right>
                    <top>115</top>
                    <height>70</height>
                    <orientation>horizontal</orientation>
                    <itemgap>20</itemgap>
                    <onup>4910</onup>
                    <ondown>4900</ondown>
                    <onleft>300</onleft>
                    <onback>4900</onback>
%s                </control>
            </control>

            <include content="List_Poster_Row">
                <param name="id">4900</param>
                <param name="control">panel</param>
                <param name="orientation">vertical</param>
                <param name="offset_y">40</param>
                <top>20</top>
                <bottom>0</bottom>
                <onup>4910</onup>
                <onleft>300</onleft>
                <onback>300</onback>
                <visible>$EXP[Exp_JellynasGrid]</visible>
                <animation effect="slide" end="0,200" time="300" tween="cubic" easing="out" condition="$EXP[Exp_JellynasGrid_BarFocus]">Conditional</animation>
                <animation effect="slide" end="0,50" time="300" tween="cubic" easing="out" condition="!$EXP[Exp_JellynasGrid_BarFocus] + $EXP[Exp_JellynasGrid_Filtered]">Conditional</animation>
                <onfocus>ClearProperty(TMDbHelper.WidgetContainer)</onfocus>
                <content target="videos">$INFO[Container(300).ListItem.Property(path)]$VAR[JellynasGrid_Query]</content>
            </include>
        </control>
    </include>

''' % (stat, layouts.replace('\n            <', '\n                    <').replace('            <itemlayout', '                    <itemlayout').replace('            <focusedlayout', '                    <focusedlayout'), items.replace('\n                ', '\n                        ').replace('                <item>', '                        <item>'), buttons.replace('\n                ', '\n                    ').replace('                <control type="button"', '                    <control type="button"'))

VARS += '''    <variable name="JellynasGrid_LetterColor">
        <value condition="Control.HasFocus(4910)">main_fg_100</value>
        <value>main_fg_50</value>
    </variable>
'''

h = open(AF + 'Includes_Hubs.xml').read()
h = re.sub(r'    <variable name="JellynasGrid_[A-Za-z]*">.*?</variable>\n', '', h, flags=re.S)
h = re.sub(r'    <!-- JELLYNAS: poster grid of the focused.*?</include>\n\n', lambda m: GRID + VARS + '\n', h, flags=re.S)
a, b = h.index('    <include name="Hub_JellynasGrid">'), h.index('    <variable name="JellynasGrid_Query">')
out, d = [], 1
for ln in h[a:b].split('\n'):   # re-indent the generated block by tag depth
    t = ln.strip()
    if not t:
        out.append('')
        continue
    if t.startswith('</'):
        d -= 1
    out.append('    ' * d + t)
    if t.startswith('<') and not t.startswith(('</', '<!--')) and not t.endswith('/>') and '</' not in t:
        d += 1
h = h[:a] + '\n'.join(out) + h[b:]
open(AF + 'Includes_Hubs.xml', 'w').write(h)
e = open(AF + 'Includes_Expressions.xml').read()
e = re.sub(r"    <!-- JELLYNAS: a side menu category.*?\n    <expression name=\"Exp_JellynasGrid\">[^\n]*\n", lambda m: EXPR, e, flags=re.S)
open(AF + 'Includes_Expressions.xml', 'w').write(e)
