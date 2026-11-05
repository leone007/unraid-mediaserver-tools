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

The menus and widgets are shared with Arctic Fuse 2 (script.skinvariables keeps them under the upstream skin id);
the skin settings (`addon_data/<skin id>/settings.xml`) are per skin id and are copied once by hand.
