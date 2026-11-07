# Arctic Fuse 2 JELLYNAS (skin.arctic.fuse.2.jellynas)

A fork of [Arctic Fuse 2](https://github.com/jurialmunkey/skin.arctic.fuse.2) by jurialmunkey for Kodi 21 Omega,
licensed like the original under CC BY-NC-SA 4.0 (non-commercial, attribution, same licence). Only the changes are
kept here, as patches against the pinned upstream commit (`tools/kodi_skin_build.py`: branch `omega`, 2.12.12).

Build: `python3 tools/kodi_skin_build.py` -> `../outputs/skin.arctic.fuse.2.jellynas-<version>.zip` (about 67 MB; not
in git, every version would stay in the history). `--publish` uploads it to the GitHub release `skin.arctic.fuse.2.jellynas`
and writes `dist/kodi/skins/addons.xml`: repository.jellynas 1.0.1+ reads it as a second `<dir>` with the release
download path as datadir, so Kodi updates the skin like the add-on. First install without the repository: Install from zip file.

Patches:
- `01-centred-text-menu.patch`: with Skin settings > Layout > Customise style > Home > Categories = Text, the
  categories are a Jellyfin-like centred bar (dark rounded background, white pill on the focused item, dim pill on
  the current one), every category at a fixed place (upstream shows the items relative to the focused one, so the
  first ones slide out to the left).
- `02-card-second-line.patch`: with Skin settings > Layout > Customise style > Viewtypes > Text labels = Detailed,
  the second line under a card shows the item property `sublabel` first (plugin.video.jellynas: `KEDD 02:00 ·
  S01E05`, `S1:E4 · title`); upstream picks the series name or the premiere date before Label2. Patched in the
  template `shortcuts/skinvariables-labels.xml` and in the shipped generated includes.
- `03-imdb-badge.patch`: a badge in the top left corner of landscape and poster cards: green with the add-on's
  `jellynas.badge` text (This week: Available), else (not on `jellynas.kind=week` items) yellow IMDb: the add-on's
  property `jellynas.imdb`, else Kodi's IMDb rating of the item (`ListItem.Rating(imdb)`); rounded corners from
  `files/media/jellynas/badge_r4.png` (4 px) and `badge_r1.png` (1 px, the black score box) (our own files under `files/` are copied over the skin; Kodi reads loose
  textures from `media/` next to Textures.xbt).
- `04-home-poster-grid.patch`: Home with the vertical side menu: when the focused menu item's path is the add-on's
  All movies or All series folder (`plugin://plugin.video.jellynas/?mode=allmovies|allseries`, expression
  `Exp_JellynasGrid`), a vertical poster panel (id 4900, AF2 `List_Poster_Row` cards with the badge) fills the area
  right of the menu instead of the widget rows and their info panel; Right from the menu goes into it, Left or Back goes back to the menu.
  Up from the first row opens the grid bar: a letter row (A-Z, #; id 4910; the active letter again = off) and buttons (4920: Sort, Watched,
  Genre, Clear filters). The choices are Home window properties `JellynasGrid.<movies|series>.*` added to the grid's
  path (`$VAR[JellynasGrid_Query]`); the add-on (0.1.8) does the filtering, Sort and Genre are its dialogs
  (`?mode=pick`). Active filters show in a small line above the grid. Labels come from the add-on (`$ADDON[...]`). A plain background covers the spotlight fanart behind the grid. The XML is written by `gen_grid.py`.
- `05-livetv-guide.patch` (written by `gen_tv.py`): the side menu item Live TV (path with `TVGuide`, `TVChannels` or
  `pvr://`, expression `Exp_JellynasTV`) opens Kodi's TV guide window a second after it gets focus (alarm
  `jellynastv` from `Home_Menu_List_HiddenButton`, cancelled when the focus moves on) or at once with Right; back from
  the guide the item does not reopen it (`JellynasTV.Opened`, set by the guide window) until another item had focus; the
  alarm is armed again on every refocus. Plain background, no widgets.
- `06-tv-guide-look.patch` (written by `gen_guide.py`): Jellyfin-like TV guide: dark blocks with 4 px corners and 3 px gaps
  (`Layout_JellynasGuide_Programs`, no genre colours, no pills that shrink to circles), the airing programme lighter, the
  focused one white, title plus start-end time, a red dot for recording or timer; channel column number, logo, name
  (`Layout_JellynasGuide_Channels`); plain background; Left at the start of the grid = Back to Home (with the Kodi
  setting PVR > Guide > Past days to display = 0 the grid starts now).
- `07-home-catalog.patch` (written by `gen_catalog.py`): the side menu item whose path is the add-on's Catalog folder
  (`?mode=catalog`, `Exp_JellynasCatalog`) shows tabs Movies | Series | Requests (list 4950, Left/Right switches at
  once, Down into the rows) and per tab a grouplist (4960/4970/4980) of three poster rows from the add-on: trending
  (split by type), popular, upcoming; requests: processing, coming soon, available. Plain background.
- `08-poster-row-labels.patch` (one line, on top of 06): `List_Poster_Row` shows the title and second line on poster
  cards that carry our `sublabel` (AF2 did this only for cast cards, `Property(role)`): on the poster's bottom over a
  fade, in the widget rows and the poster grid.

The menus and widgets are shared with Arctic Fuse 2 (script.skinvariables keeps them under the upstream skin id);
the skin settings (`addon_data/<skin id>/settings.xml`) are per skin id and are copied once by hand.
