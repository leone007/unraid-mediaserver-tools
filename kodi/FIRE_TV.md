# Kodi JELLYNAS skin: telepítés Fire TV Stick 4K Max (2. gen) eszközre

Állapot: 2026-11-06, a Macen élőben kipróbált beállítások alapján. **Fire TV-n még nem próbáltuk ki.** Ha valamelyik lépés másképp megy, ezt a fájlt kell javítani.

Cél: Kodi 21, Arctic Fuse 2 JELLYNAS skin, Kofin, Kofin PVR, JELLYNAS add-on, a Mac menüivel és widgetjeivel.

## 0. Előkészítés a Macen

A Kodi legyen bezárva. Terminálban:

```
cd ~/Library/Application\ Support/Kodi/userdata/addon_data
```

```
mkdir -p ~/Desktop/kodi-beall/skin.arctic.fuse.2.jellynas
```

```
grep -v -- '-hash"' skin.arctic.fuse.2.jellynas/settings.xml > ~/Desktop/kodi-beall/skin.arctic.fuse.2.jellynas/settings.xml
```

```
cp -R script.skinvariables ~/Desktop/kodi-beall/
```

A `-hash` sorok szándékosan maradnak ki, így a skin az első indításkor újraépíti a menüket.

A `kodi-beall` mappát másold a szerver `PROGRAM/APK/kodi addons` mappájába (a WebDAV csak olvasható). SMB-n root nem léphet be: külön Unraid-felhasználó kell Read/Write joggal, vagy egyszeri `scp` bekapcsolt SSH-val, utána az SSH-t kapcsold ki. Ugyanebben a mappában vannak a repó-zipek:
- `repository.jurialmunkey-3.4.zip`
- `repository.kontell-1.0.2.zip`
- `repository.jellynas.zip`

A Kofin mappáit ne másold, belépési adatok vannak bennük.

## 1. Kodi felrakása

1. Fire TV: Settings > My Fire TV > About: nyomd meg hétszer az eszköz nevét, így megjelenik a Developer Options.
2. Developer Options: engedélyezd az ismeretlen forrásokat a Downloader appnak.
3. Telepítsd a **Downloader** appot az Amazon boltból.
4. Downloaderben: `kodi.tv/download` > Android > **Kodi 21.x (Omega), ARMV7A (32 bit)**. Ha nem települ, vagy gond van vele, próbáld az ARMV8A (64 bit) változatot; a források ellentmondanak, melyik a jobb erre a modellre.
5. Indítsd el a Kodit, és engedélyezz mindent, amit kér.

## 2. Tárolók

1. Beállítások > Rendszer > Bővítmények: **Ismeretlen források: Be**.
2. Beállítások > Fájlkezelő > Forrás hozzáadása > Tallózás > Hálózati hely hozzáadása:
   - Protokoll: WebDAV (HTTPS)
   - Szerver: `dav.leone007.net`
   - A saját belépési adataid
   - Mappa: `kodi addons`
3. Bővítmények > Telepítés zip-fájlból, ebben a sorrendben: **jurialmunkey**, **kontell**, **jellynas**.

## 3. Bővítmények

Bővítmények > Telepítés tárolóból:

1. **Kofin** (Kontell): lépj be, szervercím `https://media.leone007.net`. Várd meg az első szinkront, ez a Sticken lassú lehet.
2. **Kofin PVR for Jellyfin** (Kontell, PVR-kliensek): ugyanez a cím és belépés. **Force remuxing és Force transcoding kikapcsolva.** A Tvheadend HTSP klienst ne telepítsd.
3. **JELLYNAS** (Video add-ons).
4. **Arctic Fuse 2 JELLYNAS** (Skins, Look and feel). Telepítés után még **ne válts rá**.

A szervercímhez ne írj helyi címet: a `pvr.kofin` 21.19.0 helyi címmel nem játszik le (`[::1]` hiba, bejelentve a fejlesztőnek).

## 4. A beállítások átmásolása

1. Beállítások > Fájlkezelő. Bal oldalt a WebDAV-forrás > `kodi-beall`, jobb oldalt a **Profilkönyvtár > addon_data**.
2. Másold át mindkét mappát: `skin.arctic.fuse.2.jellynas` és `script.skinvariables`.
3. Beállítások > Felület > Megjelenés > Skin: **Arctic Fuse 2 JELLYNAS**. Ha kérdezi, tartsd meg.
4. Indítsd újra a Kodit, a menük ilyenkor épülnek újra.

## 5. Kézi beállítások

- Beállítások > PVR és élő TV > Műsorújság > Past days to display: **0**.
- Beállítások > Lejátszó > Videók > Képfrissítés igazítása: **Be, indításkor/leállításkor**. Az 50 fps-es tévécsatornákhoz ez fontos.
- Nyelv, ha kell: Beállítások > Felület > Terület.

## 6. Ellenőrzés

- [ ] Kezdőlap: menü, sorok, IMDb- és Elérhető-jelvény.
- [ ] Összes film és Összes sorozat: rács, betűsor, szűrők.
- [ ] Live TV: 1 mp után megnyílik a guide, Balra visszavisz a menüre.
- [ ] Sport 1 és Sport 2: elindul, sima, a Streamboxon látszik.
- [ ] Csatorna leállítása után a Tvheadend Előfizetések fülén eltűnik.
- [ ] Katalógus: Filmek, Sorozatok, Kérések, Fel/Le. Csak otthoni hálón megy.
- [ ] Egy film lejátszása Direct Play-jel.

## Ha valami nem megy

- **`...:9981/stream/channelid/...` timeout a naplóban:** nézd meg a Tvheadend Állapot > Előfizetések fülét. Beragadt Jellyfin-előfizetéseknél a Jellyfin újraindítása segített; végső esetben Force remuxing.
- **Nem látszik a Streamboxon:** a Cloudflare `Kodi PVR jelentes` szabálya él-e (`/Sessions/Playing`, `/LiveStreams/Close`).
- **Duplán jönnek a csatornák:** maradt egy másik PVR-kliens, tiltsd le.
- **Lassú a felület:** a skin nagy (67 MB), gyengébb eszközön a Skin beállításaiban a háttérképek és az animációk csökkentése segíthet. Ezt ezen a modellen még nem láttuk.
- **Visszaút:** Beállítások > Felület > Megjelenés > Skin: Estuary.
