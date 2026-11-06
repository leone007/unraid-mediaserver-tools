# writes the JELLYNAS TV guide look of patch 06 into an AF2 work copy (after patch 05): python3 gen_guide.py <af2 work copy>
# Jellyfin-like guide: square-ish dark programme blocks (4 px corners) instead of genre-coloured pills (short ones
# shrank to circles), the airing programme a bit lighter, the focused one white; channel column number + logo + name;
# plain background; Left at the start of the grid (now, with Past days to display = 0) goes back to Home.
import sys
AF = sys.argv[1] + '/1080i/'

def sub(f, old, new):
    s = open(AF + f).read()
    if new in s:
        return
    assert s.count(old) == 1, (f, old)
    open(AF + f, 'w').write(s.replace(old, new))

LAYOUTS = '''    <!-- JELLYNAS: TV guide programme block (id 1 controls take the block's width) -->
    <include name="Layout_JellynasGuide_Programs">
        <param name="selected">false</param>
        <param name="visible">true</param>
        <definition>
            <control type="image" id="1">
                <bordersize>3</bordersize>
                <texture colordiffuse="FF262626" border="4">jellynas/badge_r4.png</texture>
                <visible>!$PARAM[selected]</visible>
            </control>
            <control type="image" id="1">
                <bordersize>3</bordersize>
                <texture colordiffuse="FF3A3A3A" border="4">jellynas/badge_r4.png</texture>
                <visible>!$PARAM[selected] + Integer.IsGreater(ListItem.Progress,0)</visible>
            </control>
            <control type="image" id="1">
                <bordersize>3</bordersize>
                <texture colordiffuse="main_fg_100" border="4">jellynas/badge_r4.png</texture>
                <visible>$PARAM[selected] + $PARAM[visible]</visible>
            </control>
            <control type="label" id="1">
                <left>18</left>
                <right>14</right>
                <top>8</top>
                <height>36</height>
                <font>font_mini_bold</font>
                <label>$INFO[ListItem.Label]</label>
                <textcolor>main_fg_90</textcolor>
                <visible>!$PARAM[selected]</visible>
            </control>
            <control type="label" id="1">
                <left>18</left>
                <right>14</right>
                <top>8</top>
                <height>36</height>
                <font>font_mini_bold</font>
                <label>$INFO[ListItem.Label]</label>
                <textcolor>$VAR[ColorSelected]</textcolor>
                <visible>$PARAM[selected] + $PARAM[visible]</visible>
            </control>
            <control type="label" id="1">
                <left>18</left>
                <right>14</right>
                <top>42</top>
                <height>28</height>
                <font>font_tiny</font>
                <label>$INFO[ListItem.StartTime]$INFO[ListItem.EndTime, – ]</label>
                <textcolor>main_fg_50</textcolor>
                <visible>!$PARAM[selected]</visible>
            </control>
            <control type="label" id="1">
                <left>18</left>
                <right>14</right>
                <top>42</top>
                <height>28</height>
                <font>font_tiny</font>
                <label>$INFO[ListItem.StartTime]$INFO[ListItem.EndTime, – ]</label>
                <textcolor>$VAR[ColorSelected]</textcolor>
                <visible>$PARAM[selected] + $PARAM[visible]</visible>
            </control>
            <control type="image">
                <left>4</left>
                <top>8</top>
                <width>6</width>
                <height>6</height>
                <texture colordiffuse="FFE53935">jellynas/badge_r1.png</texture>
                <visible>ListItem.IsRecording | ListItem.HasTimer</visible>
            </control>
        </definition>
    </include>

    <!-- JELLYNAS: TV guide channel column: number, logo, name; the row of the focused programme a bit lighter -->
    <include name="Layout_JellynasGuide_Channels">
        <param name="selected">false</param>
        <param name="bottom">0</param>
        <param name="right">20</param>
        <definition>
            <control type="group">
                <right>$PARAM[right]</right>
                <bottom>$PARAM[bottom]</bottom>
                <control type="image">
                    <bordersize>3</bordersize>
                    <texture colordiffuse="FF262626" border="4">jellynas/badge_r4.png</texture>
                    <visible>$PARAM[selected]</visible>
                </control>
                <control type="label">
                    <left>14</left>
                    <width>60</width>
                    <align>center</align>
                    <aligny>center</aligny>
                    <font>font_mini_bold</font>
                    <label>$INFO[ListItem.ChannelNumberLabel]</label>
                    <textcolor>main_fg_50</textcolor>
                </control>
                <control type="image">
                    <left>84</left>
                    <width>96</width>
                    <top>14</top>
                    <bottom>14</bottom>
                    <aspectratio>keep</aspectratio>
                    <texture>$INFO[ListItem.Icon]</texture>
                </control>
                <control type="label">
                    <left>196</left>
                    <right>10</right>
                    <aligny>center</aligny>
                    <font>font_mini_bold</font>
                    <label>$INFO[ListItem.ChannelName]</label>
                    <textcolor>main_fg_90</textcolor>
                </control>
            </control>
        </definition>
    </include>

'''

# our layouts for the guide grid (List_EPGGrid is only used by the TV / radio guide views)
sub('Includes_Lists.xml', '''        <param name="include_channels">Layout_PVRGuide_Channels</param>
        <param name="include_programs">Layout_PVRGuide_Programs</param>''',
    '''        <param name="include_channels">Layout_JellynasGuide_Channels</param>
        <param name="include_programs">Layout_JellynasGuide_Programs</param>''')
sub('Includes_Lists.xml', '    <include name="List_EPGGrid">\n', LAYOUTS + '    <include name="List_EPGGrid">\n')

# Left at the start of the grid: back to Home (the side menu); with the Kodi setting Past days to display = 0 the start is now
sub('Includes_Views_PVR.xml', '''                <onback>9001</onback>
                <viewtype label="$PARAM[viewtype_label]">list</viewtype>
                <progresstexture border="40,0,40,0">$PARAM[progresstexture]</progresstexture>''',
    '''                <onback>9001</onback>
                <onleft condition="Window.IsActive(TVGuide)">Action(Back)</onleft>
                <viewtype label="$PARAM[viewtype_label]">list</viewtype>
                <progresstexture border="40,0,40,0">$PARAM[progresstexture]</progresstexture>''')

# plain background and the Home flag for the Live TV menu item (patch 05)
sub('MyPVRGuide.xml', '''    <include>Action_Media_Onload</include>
''', '''    <include>Action_Media_Onload</include>
    <!-- JELLYNAS: back on Home the Live TV menu item does not open the guide again -->
    <onload>SetProperty(JellynasTV.Opened,1,Home)</onload>
''')
sub('MyPVRGuide.xml', '''        <include>Background</include>
''', '''        <include>Background</include>
        <!-- JELLYNAS: plain background, no programme or channel picture behind the guide -->
        <control type="image">
            <texture colordiffuse="$VAR[ColorBackground]">common/white.png</texture>
        </control>
''')
