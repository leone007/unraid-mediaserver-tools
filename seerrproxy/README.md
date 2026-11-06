# JELLYNAS catalog proxy

Small HTTP service for the Kodi add-on `plugin.video.jellynas` (Catalog): it lists Seerr's discover rows, one title and
the requests, and sends a request in the viewer's own Seerr name. The add-on sends its Kofin (Jellyfin) login; the proxy
checks it with Jellyfin `GET /Users/Me`, finds the Seerr user with that `jellyfinUserId` and calls Seerr with Seerr's API
key (`X-API-User` = that user). The key is read from Seerr's `settings.json` (mounted read only) and is never printed or
sent to the TVs.

| Call | Seerr |
|---|---|
| `GET /health` (no login) | - |
| `GET /discover/trending\|movies\|movies/upcoming\|tv\|tv/upcoming?page=&language=` | `/api/v1/discover/...` |
| `GET /item/movie\|tv/<tmdb id>?language=` | `/api/v1/movie\|tv/<id>` (status, Jellyfin id, seasons) |
| `GET /requests?filter=all\|processing\|comingsoon\|available` | `/api/v1/request` + titles (coming soon = not released yet) |
| `POST /request` `{"type":"movie","id":1}` or `{"type":"tv","id":2,"seasons":[2,3]}` | `POST /api/v1/request` as the viewer |

Run: Unraid template `my-jellynas-catalog.xml` (python:3.12-alpine, port 5056, the script folder and Seerr's config
folder read only). Test: `python3 seerrproxy/test_seerr_proxy.py` (stub Jellyfin + Seerr).
