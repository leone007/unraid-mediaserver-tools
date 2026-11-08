# Kodi JELLYNAS skin: beállítás Fire TV Stick 4K Max (2. gen) eszközön

Állapot: 2026-11-07, élő próba alapján (Fire OS RS8185/3898, LG C3). Ami nem igazolt, azt a szöveg külön jelöli.

Cél: Arctic Fuse 2 JELLYNAS skin, Kofin, Kofin PVR és JELLYNAS add-on, a Mac menüivel és widgetjeivel.

## Kiindulás

- A Kodi 21.3 `armeabi-v7a` (32 bit) már fent van. A régi Kodit előbb töröld.
- A Stick `Download/kodi addons` mappájában ott vannak:
  - `repository.jurialmunkey-3.4.zip`
  - `repository.kontell-1.0.2.zip`
  - `repository.jellynas.zip`
  - `script.keymapedit-master.zip`
  - `script.service.ignoreforcedsubtitles-0.1.9.zip`
  - `kodi-beall` mappa (lásd a Függeléket)

## 1. Tárhely-jogosultság

Fire TV: Settings > Applications > Manage Installed Applications > Kodi > App Permissions: engedélyezd a tárhelyet. Enélkül a Kodi nem látja a zipeket a `Download` mappában. Utána indítsd újra a Kodit.

Tartalék, ha így sem látja: Beállítások > Fájlkezelő > Forrás hozzáadása > Hálózati hely hozzáadása, WebDAV (HTTPS), `dav.leone007.net`, mappa `kodi addons`. Ezt az útvonalat Fire TV-n nem próbáltuk ki.

## 2. Kodi beállításai

**Felület**
- Beállítási szint: Szakértő (Expert).
- Terület: Central Europe. Nyelv: Hungarian.

**Lejátszó > Nyelv**
- Elsődleges hangnyelv: English. Alapértelmezett hangfolyam: ki.
- Elsődleges feliratnyelv: Hungarian.

**Lejátszó > Feliratok**
- Méret: 26. Szín: Spanish Grey.

**Lejátszó > Videók**
- Képfrissítési gyakoriság beállítása: Indításkor/leállításkor.
- Engedélyezett HDR formátumok: Dolby Vision be, HDR10+ be (lásd a Dolby Vision részt lent).
- Dolby Vision kompatibilitási mód: a jó állása még nincs tisztázva.

**PVR és élő TV**
- Megjelenítendő elmúlt napok: 0.
- Alapértelmezett kiválasztási művelet: Okos választás.

A PVR-beállítások a Kofin PVR telepítése előtt is megadhatók, így az élő TV már beállított Kodival indul.

**Rendszer > Képernyő**
- Felbontás: 1920x1080, 50 Hz. A skin 4K felülettel nem elég fürge a Sticken, a filmek ettől még 4K-ban mennek.
- Whitelist 3840x2160-ban és 1920x1080-ban is: 23.98, 24, 25, 29.97, 30, 50, 59.94, 60 Hz. Interlaced (`i`) módot ne jelölj be. A 4K sorok kellenek, mert ezek nélkül a Kodi nem vált 4K-ra.
- Dupla képfrissítés engedélyezése: be.

**Rendszer > Bővítmények**
- Ismeretlen források: be.
- Frissítés: bármely tárolóból.

## 3. Zipek

Bővítmények > Telepítés zip-fájlból > External storage > `Download/kodi addons`, ebben a sorrendben:

1. `repository.jurialmunkey-3.4.zip`
2. `repository.kontell-1.0.2.zip`
3. `repository.jellynas.zip`
4. `script.keymapedit-master.zip`
5. `script.service.ignoreforcedsubtitles-0.1.9.zip`

## 4. Kofin és élő TV

Bővítmények > Telepítés tárolóból:

1. **Kofin** (Kontell): szervercím `https://media.leone007.net`, belépés Quick Connecttel. Várd meg a filmek és sorozatok szinkronját.
2. **Kofin PVR for Jellyfin** (Kontell, PVR-kliensek): ugyanez a cím, szintén Quick Connect. A Force remuxing és a Force transcoding legyen kikapcsolva. Az EPG magától beolvas. A Tvheadend HTSP klienst ne telepítsd.

Helyi szervercímet ne adj meg: a `pvr.kofin` 21.19.0 azzal nem játszik le (`[::1]` hiba, bejelentve a fejlesztőnek).

## 5. Távirányító hosszú nyomásai

Gyors út: a `kodi-beall` mappában lévő `keymaps/gen.xml` (a Shieldről mentve 2026-11-08, Android-távirányítókra) a 7. lépésben a többivel együtt átmásolható: Fájlkezelő, bal oldalt `kodi-beall/keymaps`, jobb oldalt Profilkönyvtár, a teljes `keymaps` mappa. Utána indítsd újra a Kodit. Ekkor a `script.keymapedit` nem kell. A Mac `gen.xml`-je nem jó Androidra (más gombkódok).

Kézi út, `script.keymapedit`:

| Ablak | Művelet | Gomb (hosszú nyomás) |
|---|---|---|
| Global | Navigation actions: Context menu | Jobb |
| Global | Activate Window: Shutdownmenu | Középső |
| Fullscreenvideo és Fullscreenlivetv | Activate Window: Playerprocessinfo | Középső |
| Fullscreenvideo és Fullscreenlivetv | Subtitles Actions: Delay | Fel |
| Fullscreenvideo és Fullscreenlivetv | Subtitles Actions: Next Subtitle | Jobb |
| Fullscreenvideo és Fullscreenlivetv | Audio Actions: Delay | Le |

## 6. JELLYNAS és a skin

Bővítmények > Telepítés tárolóból:

1. **JELLYNAS** (Video add-ons).
2. **Arctic Fuse 2 JELLYNAS** (Skins, Look and feel). Az eredeti AF2 nem kell, a függőségeket a Kodi behúzza. Még **ne válts rá**.

Ha a telepítés függőségi hibával leáll, keresd meg, melyik függőség hiányzik, és tedd fel kézzel a jurialmunkey tárolóból. A próbán a `Roboto CJKSC` telepítettnek látszott, a skin mégis hiányzónak jelezte: a jurialmunkey tároló alatt törölve és újratelepítve rendbe jött. Az oka nem tisztázott. A Texture Maker is kézzel ment fel.

## 7. Beállítások átmásolása és skinváltás

1. Beállítások > Fájlkezelő. Bal oldalt `Download/kodi addons/kodi-beall`, jobb oldalt Profilkönyvtár > `addon_data`.
2. Másold át mindkét mappát: `skin.arctic.fuse.2.jellynas` és `script.skinvariables`. Felülírást engedélyezz.
   A hosszú nyomásokhoz a `kodi-beall/keymaps` mappát a Profilkönyvtár gyökerébe másold (nem az `addon_data` alá), lásd az 5. lépést.
3. Beállítások > Felület > Megjelenés > Skin: **Arctic Fuse 2 JELLYNAS**, tartsd meg.

A menük felépültek, újraindítás sem kellett. Ha mégsem jelennek meg, indítsd újra a Kodit.

A `kodi-beall` mappa (2026-11-08, a Mac teljes beállítása):
- Menü: JELLYNAS, Live TV, Összes film, Összes sorozat, Katalógus.
- A JELLYNAS oldalon a Megtekintés folytatása és a Következik landscape, az Ezen a héten, a Nemrég hozzáadott filmek és a Nemrég hozzáadott sorozatok poszter.

Eszközönként kapcsold ki a menüszerkesztőben, ami túl nehéz:
- Shield, Mac: minden marad.
- Fire TV Stick 4K Max: Összes film és Összes sorozat ki.
- Régi, sima Fire TV Stick: Összes film, Összes sorozat és Katalógus ki.

**Rotten Tomatoes értékelés (OMDb-kulcs)**

Az IMDb és a TMDb értékelés magától megjön, a Rotten Tomatoes-hoz OMDb API-kulcs kell (ingyenes, omdbapi.com).

1. Bővítmények > Videó bővítmények > **TMDb Helper** (a skin függőségként telepítette) > hosszú Középső > Beállítások.
2. Az API-kulcsok között az **OMDb API key** mezőbe írd be a kulcsot.
3. Ugyanitt a TMDb Helper beállításaiban töröld a cache-t. Enélkül a már letöltött, RT nélküli értékelések maradnak.

A kulcs és a cache törlése után az RT értékelés élőben megjelent (2026-11-07, a tulajdonos).

## 8. Ellenőrzés

Kipróbálva:
- [x] Kezdőlap, menük (a tulajdonos szerint minden jó).
- [x] Sport 2 megy, bezárás után eltűnik a Tvheadend Előfizetések fülén.
- [x] 1080p-s és 4K-s film megy.
- [x] 4K kimenet: The Punisher: One Last Kill alatt 3840x2160 @ 24 Hz, 1080p-s felületről is átvált.

Még nincs kipróbálva:
- [ ] Streamboxon látszik-e a Stick élő adása.
- [ ] Összes film, Összes sorozat: rács, betűsor, szűrők.
- [ ] Live TV: a guide megnyílik, Balra visszavisz a menüre.
- [ ] Katalógus: Filmek, Sorozatok, Kérések, Fel/Le. Csak otthoni hálón megy.
- [ ] Rotten Tomatoes értékelés az infósávban (OMDb-kulcs után).
- [ ] Direct Play a Streamboxon egy filmnél.

A kimenet felbontása: a C3 nem írja ki, a Playerprocessinfo csak a forrást mutatja. Film közben Vissza gomb, majd Beállítások > Rendszerinformáció > Összegzés > Képernyő felbontása.

## Dolby Vision

Tesztfilmek típusonként:

| Típus | Film | Eredmény |
|---|---|---|
| Profil 8 + HDR10+ | The Punisher: One Last Kill (2026) | Hibátlan; a HDR10+ állása a próba alatt nincs rögzítve |
| Profil 7, BL+EL+RPU | Land of Bad (2024) | HDR10+ engedélyezve jó a kép, a C3 Dolby Visiont ír. Egy korábbi lejátszásnál vibrált, amikor a HDR10+ tiltva volt |
| Profil 8 + HDR10 | Nonnas (2025) | Nincs kipróbálva |
| Profil 8, Blu-ray-alap | Annihilation (2018) | Nincs kipróbálva |
| Profil 5 (nincs HDR10 alap) | The Electric State (2025) | Nincs kipróbálva |

Nyitott kérdések:
- Nem tudjuk, miért segít a HDR10+ engedélyezése a Land of Badnél: a fájlban a `mediainfo` szerint nincs HDR10+, és a C3 sem kezeli. Még nincs megismételve, hogy kikapcsolva visszajön-e a vibrálás.
- A The Punishert célzottan HDR10+ engedélyezve is meg kell nézni. A fórumok ilyen fájloknál fekete képet jeleznek a 4K Max-on.
- A Dolby Vision kompatibilitási mód hatása nincs tesztelve.

A Dolby Vision teljes tiltása nem jó tartós megoldás: a profil 5-ös filmeknek nincs HDR10 alapja, azok lila-zöldek lennének.

## Ha valami nem megy

- **`...:9981/stream/channelid/...` timeout a naplóban:** nézd meg a Tvheadend Állapot > Előfizetések fülét. Beragadt Jellyfin-előfizetéseknél indítsd újra a Jellyfint. Egyszer ezután megszűnt a hiba, de az ok nincs bizonyítva. Ha nincs beragadt előfizetés, és a hiba marad, a Force remuxing megkerülheti, ez viszont nálunk szaggatott, ezért csak ideiglenes megoldás.
- **Nem látszik a Streamboxon:** a Cloudflare `Kodi PVR jelentes` szabálya él-e (`/Sessions/Playing`, `/LiveStreams/Close`).
- **Gyenge eszköz, nincs meg az 50 fps élő TV-nél (pl. a pergola projektorának saját Kodija):** a Kofin PVR-ben kapcsold be a Force transcodingot. Ekkor a Jellyfin a szerveren alakítja át a váltott soros adást 50 fps-re, az eszköznek csak le kell játszania. Nincs még kipróbálva. Csak a gyenge eszközön kell, a 4K Max-on és a Shielden maradjon kikapcsolva.
- **Duplán jönnek a csatornák:** maradt egy másik PVR-kliens, tiltsd le.
- **Lassú a felület:** ellenőrizd, hogy a felület 1080p-n fut-e (2. lépés).
- **Lassú a Wi-Fi:** a próbán Wi-Fi 6, 80 MHz, -76 dBm (5 méter, fal és tévé), kb. 300 Mbps. Ez 60 Mbps-os remuxhoz is elég volt. A Sticken nincs külön Wi-Fi 6 kapcsoló.
- **A napló helye:** `/storage/emulated/0/Android/data/org.xbmc.kodi/files/.kodi/temp/kodi.log`.
- **Visszaút:** Beállítások > Felület > Megjelenés > Skin: Estuary.

## Függelék: a `kodi-beall` mappa készítése a Macen

A Kodi legyen bezárva. Terminálban, soronként:

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

A `-hash` sorok szándékosan maradnak ki, így a skin újraépíti a menüket. A Kofin mappáit ne másold, belépési adatok vannak bennük.

Ha az Asztalon már van egy régi `kodi-beall`, előbb töröld (`rm -rf ~/Desktop/kodi-beall`), és a `mkdir` sort ne hagyd ki. A szerveren a régi mappát ne írd felül, hanem nevezd át (pl. `kodi-beall-1106`).

A mappa a szerver `PROGRAM/APK/kodi addons` mappájába kerül, onnan a Stickre. SMB-n root nem léphet be: külön Unraid-felhasználó kell Read/Write joggal, vagy egyszeri `scp` bekapcsolt SSH-val, utána az SSH-t kapcsold ki.
