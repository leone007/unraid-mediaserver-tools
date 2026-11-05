# Entry point: Kodi runs this file for every plugin:// request of the addon
import os
import sys

import xbmcaddon
import xbmcvfs

sys.path.insert(0, os.path.join(xbmcvfs.translatePath(xbmcaddon.Addon().getAddonInfo('path')), 'resources', 'lib'))

from jellynas import main  # noqa: E402

main(sys.argv)
