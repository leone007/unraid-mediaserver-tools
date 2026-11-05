# Arctic Fuse 2 JELLYNAS (skin.arctic.fuse.2.jellynas)

A fork of [Arctic Fuse 2](https://github.com/jurialmunkey/skin.arctic.fuse.2) by jurialmunkey for Kodi 21 Omega,
licensed like the original under CC BY-NC-SA 4.0 (non-commercial, attribution, same licence). Only the changes are
kept here, as patches against the pinned upstream commit (`tools/kodi_skin_build.py`: branch `omega`, 2.12.12).

Build: `python3 tools/kodi_skin_build.py` -> `../outputs/skin.arctic.fuse.2.jellynas-<version>.zip` (about 67 MB,
too big for the Kodi repository on GitHub), installed in Kodi with Add-ons > Install from zip file.

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

The menus and widgets are shared with Arctic Fuse 2 (script.skinvariables keeps them under the upstream skin id);
the skin settings (`addon_data/<skin id>/settings.xml`) are per skin id and are copied once by hand.
