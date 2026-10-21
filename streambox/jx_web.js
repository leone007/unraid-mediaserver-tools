/* Streambox Jellyfin extras (item 86), loaded by week.js (web client and Jellyfin Desktop):
   - an IMDb badge on every movie, series and episode card and on the detail page (rating from imdb.json); a click opens
     the title's IMDb page in a new tab (rate it there)
   - a "Watched too" switch on the home page: Jellyfin's own user setting "Hide watched content from latest media",
     reversed (so it follows the account and works in the apps too)
   - a "Watched" entry in the menu: this user's watched movies and series, last watched first
   Reads only, apart from the signed-in user's own home setting when the switch is clicked. Texts: window.__sbxT (written by Streambox). */
(function () {
  'use strict';
  if (window.__sbExtras) { return; }
  window.__sbExtras = true;

  var BASE = 'streambox/';
  try { if (document.currentScript && document.currentScript.src) { BASE = new URL('.', document.currentScript.src).href; } } catch (e) { /* keep the default */ }
  var ROOT = new URL('../../', new URL(BASE, location.href)).href;
  var R = null, rLoaded = 0, rBusy = false;          // imdb.json: Jellyfin id -> [rating x 10, votes, tt]
  var showWatched = true, prefLoaded = false, prefKnown = false;   // the switch (Jellyfin's HidePlayedInLatest, reversed)
  var IMDB = 'https://www.imdb.com/title/';
  var CFG = window.__sbxCfg || {};                      // Streambox settings: badge = title | poster

  // ---- texts ----
  function lang() {
    var l = (document.documentElement.getAttribute('lang') || navigator.language || 'en').slice(0, 2).toLowerCase();
    return window.__sbxT && window.__sbxT[l] ? l : 'en';
  }
  function T(k, v) {
    var d = window.__sbxT || {}, e = (d[lang()] || {})[k] || (d.en || {})[k], s;
    if (!e) { return k; }
    v = v || {};
    if (e.other !== undefined) { s = (Math.abs(+v.n || 0) === 1 && e.one !== undefined) ? e.one : e.other; } else { s = e.text || ''; }
    return s.replace(/\{([a-z0-9_]+)\}/g, function (a, b) { return Object.prototype.hasOwnProperty.call(v, b) ? String(v[b]) : a; });
  }
  function num(n) { try { return Number(n).toLocaleString(lang()); } catch (x) { return String(n); } }

  // ---- Jellyfin access (the signed-in user's own session) ----
  function api() { return window.ApiClient || null; }
  function uid() { var a = api(); try { return a && a.getCurrentUserId ? a.getCurrentUserId() : ''; } catch (x) { return ''; } }
  function jget(path, q) {
    var a = api();
    if (!a) { return Promise.reject(new Error('no client')); }
    var url = a.getUrl(path, q || {});
    return fetch(url, { headers: { 'Authorization': 'MediaBrowser Token="' + a.accessToken() + '"', 'Accept': 'application/json' } })
      .then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); });
  }
  function items(q) { q.userId = uid(); return jget('Items', q); }

  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) { e.className = cls; }
    if (text !== undefined) { e.textContent = text; }
    return e;
  }

  var css = '' +
    '.sbx-imdb{position:absolute;top:.45em;left:.45em;z-index:3;display:inline-flex;align-items:center;background:#f5c518;color:#121212!important;' +
    'font:800 .66em/1.2 system-ui,sans-serif;border-radius:4px;padding:.08em .28em;text-decoration:none!important;box-shadow:0 1px 4px rgba(0,0,0,.5)}' +
    '.sbx-imdb i{font-style:normal;font-weight:700;background:#121212;color:#f5c518;border-radius:2px;padding:0 .25em;margin-left:.3em}' +
    '.sbx-imdb:hover,.sbx-imdb:focus-visible{box-shadow:0 0 0 2px #fff;outline:none}' +
    '.sbx-savg{top:.6em;left:.6em;font-size:.95em}' +
    '.sbx-tx{display:flex!important;align-items:center}' +
    '.sbx-tx>:not(.sbx-imdb){min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}' +
    '.sbx-txc{justify-content:center}' +
    '.sbx-imdb.sbx-in{position:static;flex:none;margin-left:.45em;font-size:.62em;box-shadow:none;border-radius:3px}' +
    '.sbx-mi{display:inline-flex;align-items:center;color:inherit!important;text-decoration:none!important;font-weight:600;border-radius:6px;padding:.05em .35em;margin:0 .5em 0 -.35em;cursor:pointer}' +
    '.sbx-mi:hover,.sbx-mi:focus-visible{background:rgba(255,255,255,.12);outline:none}' +
    '.sbx-mi b{background:#f5c518;color:#121212;font-weight:800;font-size:.78em;border-radius:3px;padding:.05em .3em;margin-right:.35em}' +
    '.sbx-mi small{opacity:.6;font-weight:500;margin-left:.3em}' +
    '.sbx-mi span.sbx-arr{color:#f5c518;margin-left:.3em;font-size:.85em}' +
    '.sbx-hidden{display:none!important}' +
    '.sbx-tog{display:inline-flex;align-items:center;margin-left:1em;background:rgba(255,255,255,.07);border:0;color:inherit;border-radius:999px;' +
    'padding:.25em .7em .25em .3em;font:inherit;font-size:.85em;cursor:pointer}' +
    '.sbx-tog:hover,.sbx-tog:focus-visible{background:rgba(255,255,255,.14);outline:none}' +
    '.sbx-sw{position:relative;width:2.1em;height:1.2em;border-radius:999px;background:#555;margin-right:.5em;flex:none;transition:background .15s}' +
    '.sbx-sw:after{content:"";position:absolute;top:.15em;left:.15em;width:.9em;height:.9em;border-radius:50%;background:#fff;transition:left .15s}' +
    '.sbx-tog.on .sbx-sw{background:#22c55e}.sbx-tog.on .sbx-sw:after{left:1.05em}' +
    /* Watched page: below the header, over the page */
    '#sbxW{position:fixed;left:0;right:0;bottom:0;z-index:998;background:#101010;color:#eee;overflow-y:auto;padding:1em 3.3% 3em}' +
    '#sbxW .sbxw-top{display:flex;flex-wrap:wrap;align-items:center;margin-bottom:1em}' +
    '#sbxW .sbxw-top>*{margin:0 .6em .4em 0}' +
    '#sbxW h1{font-size:1.5em;margin:0 1em 0 0;font-weight:600}' +
    '#sbxW .sbxw-tab{background:rgba(255,255,255,.08);border:0;color:inherit;border-radius:999px;padding:.35em 1em;font:inherit;font-weight:600;cursor:pointer}' +
    '#sbxW .sbxw-tab.on{background:#fff;color:#111}' +
    '#sbxW .sbxw-sort{margin-left:auto;display:flex;align-items:center;opacity:.85}' +
    '#sbxW select{margin-left:.5em;background:#1f1f1f;color:inherit;border:1px solid #333;border-radius:6px;padding:.25em .4em;font:inherit}' +
    '#sbxW .sbxw-x{background:none;border:0;color:inherit;font-size:1.6em;line-height:1;cursor:pointer;opacity:.7;padding:0 .2em}' +
    '#sbxW .sbxw-x:hover{opacity:1}' +
    '#sbxW .sbxw-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(9.5em,1fr));grid-gap:1.2em 1em}' +
    '#sbxW .sbxw-c{position:relative;min-width:0}' +
    '#sbxW .sbxw-c>a{color:inherit;text-decoration:none;display:block;outline:none}' +
    '#sbxW .sbxw-t a{color:inherit;text-decoration:none}' +
    '#sbxW .sbxw-p{position:relative;padding-top:150%;border-radius:8px;background:rgba(255,255,255,.07) center/cover no-repeat}' +
    '#sbxW .sbxw-c>a:hover .sbxw-p,#sbxW .sbxw-c>a:focus-visible .sbxw-p{box-shadow:0 0 0 2px rgba(255,255,255,.85)}' +
    '#sbxW .sbxw-ok{position:absolute;top:.45em;right:.45em;width:1.5em;height:1.5em;border-radius:50%;background:rgba(255,255,255,.92);color:#111;display:grid;place-items:center;font-size:.8em;font-weight:700}' +
    '#sbxW .sbxw-t{margin-top:.45em;font-weight:600;font-size:.92em;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}' +
    '#sbxW .sbxw-d{font-size:.8em;color:#22c55e;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}' +
    '#sbxW .sbxw-msg{opacity:.6;padding:2em 0}' +
    '.sbx-nav-on{background:#fff!important;color:#111!important;border-radius:999px}';

  // ---- IMDb ratings ----
  function loadR(force) {
    if (rBusy || (!force && R && Date.now() - rLoaded < 1800000)) { return; }
    rBusy = true;
    fetch(BASE + 'imdb.json?v=' + Math.floor(Date.now() / 1800000), { cache: 'no-cache' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (j) { if (j && j.r) { R = j.r; rLoaded = Date.now(); queue(); } })
      .catch(function () { /* no ratings: no badges */ })
      .then(function () { rBusy = false; });
  }
  function score(r) { return (r[0] / 10).toFixed(1); }
  function badgeTip(r) { return T('jx.imdb_open') + (r[1] ? ' · ' + T('jx.votes', { n: r[1], v: num(r[1]) }) : ''); }

  // the badge: next to the card's title (owner's wish, 14u); on the poster's corner when the card shows no title
  function badge(box, r, inline) {
    var a = el('a', 'sbx-imdb' + (inline ? ' sbx-in' : ''), 'IMDb');
    a.href = IMDB + r[2] + '/';
    a.target = '_blank';
    a.rel = 'noopener';
    a.title = badgeTip(r);
    a.appendChild(el('i', '', score(r)));
    if (!inline && getComputedStyle(box).position === 'static') { box.style.position = 'relative'; }
    box.appendChild(a);
  }
  // the title line of a card becomes a row: the title (it keeps its ellipsis) and the badge after it
  function titleHost(c) {
    var tx = c.querySelector('.cardText-first') || c.querySelector('.cardText');
    if (!tx || !tx.textContent.trim()) { return null; }
    if (!tx.classList.contains('sbx-tx')) {
      Array.prototype.forEach.call(tx.childNodes, function (n) {
        if (n.nodeType === 3 && n.nodeValue.trim()) { var sp = el('span', '', n.nodeValue); tx.replaceChild(sp, n); }
      });
      if (getComputedStyle(tx).textAlign === 'center') { tx.classList.add('sbx-txc'); }
      tx.classList.add('sbx-tx');
    }
    return tx;
  }
  // episodes (Next up, Continue watching, a series in Recently added that shows its newest episode): the series' rating,
  // as the card's title is the series; the episode's own rating is on its detail page
  var seriesOf = {}, sBusy = false, sWant = {};
  function loadSeries() {
    var ids = Object.keys(sWant);
    if (sBusy || !ids.length || !uid()) { return; }
    sBusy = true; sWant = {};
    items({ Ids: ids.slice(0, 200).join(','), EnableImages: false, EnableUserData: false, Fields: '' }).then(function (j) {
      (j.Items || []).forEach(function (it) { seriesOf[nid(it.Id)] = it.SeriesId ? nid(it.SeriesId) : ''; });
      ids.forEach(function (id) { if (seriesOf[id] === undefined) { seriesOf[id] = ''; } });
      queue();
    }).catch(function () { ids.forEach(function (id) { seriesOf[id] = ''; }); }).then(function () { sBusy = false; });
  }
  function cards() {
    if (!R) { return; }
    var cs = document.querySelectorAll('.card[data-id]');
    for (var i = 0; i < cs.length; i++) {
      var c = cs[i], id = c.getAttribute('data-id'), type = c.getAttribute('data-type') || '';
      var done = c.getAttribute('data-sbx') === id;
      if (done && (c.getAttribute('data-sbx-none') === '1' || c.querySelector('.sbx-imdb'))) { continue; }   // a re-drawn title loses its badge: put it back
      var key = id;
      // IMDb rates series and episodes, not seasons: a season card shows the average of its episodes (never the series')
      if (type === 'Season') {
        if (seasonCache[id] === undefined) { seasonCache[id] = false; loadSeason(id); continue; }
        if (seasonCache[id] === false) { continue; }   // still loading
        c.setAttribute('data-sbx', id);
        var so = c.querySelectorAll('.sbx-imdb');
        for (var q = 0; q < so.length; q++) { so[q].remove(); }
        if (!seasonCache[id]) { c.setAttribute('data-sbx-none', '1'); continue; }
        c.removeAttribute('data-sbx-none');
        var stx = CFG.badge === 'poster' ? null : titleHost(c);
        var sb = avgBadge(seasonCache[id], id, stx ? 'sbx-in' : '');
        if (stx) { stx.appendChild(sb); continue; }
        var sbox = c.querySelector('.cardScalable') || c.querySelector('.cardImageContainer');
        if (sbox) { if (getComputedStyle(sbox).position === 'static') { sbox.style.position = 'relative'; } sbox.appendChild(sb); }
        continue;
      }
      if (type === 'Episode') {
        if (seriesOf[id] === undefined) { sWant[id] = 1; continue; }
        key = seriesOf[id] || id;
      }
      c.setAttribute('data-sbx', id);
      var old = c.querySelectorAll('.sbx-imdb');
      for (var k = 0; k < old.length; k++) { old[k].remove(); }
      var r = R[key] || R[id];
      if (!r || !r[0]) { c.setAttribute('data-sbx-none', '1'); continue; }
      c.removeAttribute('data-sbx-none');
      var tx = CFG.badge === 'poster' ? null : titleHost(c);
      if (tx) { badge(tx, r, true); continue; }
      var box = c.querySelector('.cardScalable') || c.querySelector('.cardImageContainer');
      if (box) { badge(box, r, false); }
    }
    loadSeries();
  }
  // the badge opens IMDb: keep the click away from Jellyfin's card handler (and other scripts' capture listeners)
  function stopForBadge(e) {
    var t = e.target && e.target.closest ? e.target.closest('.sbx-imdb,.sbx-mi') : null;
    if (t) { e.stopPropagation(); }
  }

  // ---- detail page: the IMDb rating instead of the star, as a link ----
  function hashId() { var m = /[?&]id=([0-9a-fA-F-]{32,36})/.exec(location.hash || ''); return m ? m[1].replace(/-/g, '').toLowerCase() : ''; }
  function detailPage() {
    var ps = document.querySelectorAll('.itemDetailPage, #itemDetailPage');
    for (var i = 0; i < ps.length; i++) { if (!ps[i].classList.contains('hide') && ps[i].offsetParent !== null) { return ps[i]; } }
    return null;
  }
  var dCache = {};   // id -> {tt, r} for items not in imdb.json (an episode without its own IMDb id: the series')
  function detailInfo(id, cb) {
    if (R && R[id] && R[id][2]) { cb({ tt: R[id][2], r: R[id] }); return; }
    if (dCache[id] !== undefined) { if (dCache[id]) { cb(dCache[id]); } return; }
    dCache[id] = null;
    var a = api();
    if (!a || !uid()) { return; }
    jget('Items/' + id, { userId: uid() }).then(function (it) {
      var tt = it && it.ProviderIds ? (it.ProviderIds.Imdb || it.ProviderIds.IMDB || '') : '';
      var sid = it && it.SeriesId ? String(it.SeriesId).replace(/-/g, '').toLowerCase() : '';
      var r = null;
      if (!tt && sid && R && R[sid]) { tt = R[sid][2]; r = R[sid]; }
      if (!/^tt\d+$/.test(tt)) { return; }
      dCache[id] = { tt: tt, r: r };
      queue();
    }).catch(function () { /* no link */ });
  }
  function detail() {
    var p = detailPage(), id = hashId();
    if (!p || !id) { return; }
    var mi = p.querySelector('.itemMiscInfo-primary') || p.querySelector('.itemMiscInfo');
    if (!mi) { return; }
    var cur = mi.querySelector('.sbx-mi');
    if (cur && cur.getAttribute('data-id') === id) { return; }
    detailInfo(id, function (d) {
      var c2 = mi.querySelector('.sbx-mi');
      if (c2) { c2.remove(); }
      var a = el('a', 'sbx-mi');
      a.setAttribute('data-id', id);
      a.href = IMDB + d.tt + '/';
      a.target = '_blank';
      a.rel = 'noopener';
      a.appendChild(el('b', '', 'IMDb'));
      if (d.r && d.r[0]) {
        a.appendChild(document.createTextNode(score(d.r)));
        if (d.r[1]) { a.appendChild(el('small', '', T('jx.votes', { n: d.r[1], v: num(d.r[1]) }))); }
        a.title = badgeTip(d.r);
      } else { a.title = T('jx.imdb_open'); }
      a.appendChild(el('span', 'sbx-arr', '↗'));
      // in place of Jellyfin's star (TMDb's rating); the star stays when IMDb has no rating for it
      var star = mi.querySelector('.starRatingContainer');
      if (star && d.r && d.r[0]) { star.classList.add('sbx-hidden'); }
      if (star) { star.parentNode.insertBefore(a, star); } else { mi.appendChild(a); }
    });
  }

  // the episode list of a season page: the episode's IMDb rating (a link) instead of Jellyfin's star, which is TMDb's;
  // an episode IMDb has not rated yet keeps the star
  function epList() {
    if (!R) { return; }
    var p = detailPage();
    if (!p) { return; }
    var li = p.querySelectorAll('.listItem[data-id]');
    for (var i = 0; i < li.length; i++) {
      var it = li[i], id = it.getAttribute('data-id');
      if (it.getAttribute('data-sbx') === id && (it.getAttribute('data-sbx-none') === '1' || it.querySelector('.sbx-mi'))) { continue; }
      it.setAttribute('data-sbx', id);
      var r = R[id], star = it.querySelector('.starRatingContainer');
      if (!r || !r[0] || !star) { it.setAttribute('data-sbx-none', '1'); continue; }
      it.removeAttribute('data-sbx-none');
      var a = el('a', 'sbx-mi');
      a.href = IMDB + r[2] + '/';
      a.target = '_blank';
      a.rel = 'noopener';
      a.title = badgeTip(r);
      a.appendChild(el('b', '', 'IMDb'));
      a.appendChild(document.createTextNode(score(r)));
      star.parentNode.insertBefore(a, star);
      star.classList.add('sbx-hidden');
    }
  }

  // a season page: the average IMDb rating of its episodes on the poster (IMDb rates episodes, not seasons), a link to the
  // season's episode list on IMDb
  var seasonCache = {};   // season id -> {avg, n, tt, sn} | null (not a season, or no rated episode) | false (loading)
  function loadSeason(id) {
    jget('Items/' + id, { userId: uid() }).then(function (it) {
      if (!it || it.Type !== 'Season') { seasonCache[id] = null; return; }
      var sid = it.SeriesId ? nid(it.SeriesId) : '';
      return items({ ParentId: id, IncludeItemTypes: 'Episode', Recursive: true, Fields: '', EnableImages: false, EnableUserData: false }).then(function (j) {
        var sum = 0, n = 0;
        (j.Items || []).forEach(function (e) { var r = R && R[nid(e.Id)]; if (r && r[0]) { sum += r[0]; n++; } });
        seasonCache[id] = n ? { avg: sum / n / 10, n: n, tt: R && R[sid] ? R[sid][2] : '', sn: it.IndexNumber } : null;
        queue();
      });
    }).catch(function () { seasonCache[id] = null; });
  }
  // the average badge (season poster, season cards): 'IMDb ⌀ 6.0', a link to the season's episodes on IMDb
  function avgBadge(d, id, cls) {
    var a = el(d.tt ? 'a' : 'span', 'sbx-imdb ' + cls, 'IMDb');
    a.setAttribute('data-id', id);
    if (d.tt) { a.href = IMDB + d.tt + '/episodes/' + (d.sn ? '?season=' + d.sn : ''); a.target = '_blank'; a.rel = 'noopener'; }
    a.title = T('jx.season_avg', { n: d.n });
    a.appendChild(el('i', '', '⌀ ' + d.avg.toFixed(1)));
    return a;
  }
  function seasonAvg() {
    if (!R || !uid()) { return; }
    var p = detailPage(), id = hashId();
    if (!p || !id) { return; }
    var host = p.querySelector('.detailImageContainer .cardScalable') || p.querySelector('.detailImageContainer');
    if (!host) { return; }
    var cur = host.querySelector('.sbx-savg');
    if (cur && cur.getAttribute('data-id') === id) { return; }
    if (cur) { cur.remove(); }
    if (seasonCache[id] === undefined) { seasonCache[id] = false; loadSeason(id); return; }
    var d = seasonCache[id];
    if (!d) { return; }
    if (getComputedStyle(host).position === 'static') { host.style.position = 'relative'; }
    host.appendChild(avgBadge(d, id, 'sbx-savg'));
  }

  // ---- the "Watched too" switch on the home page ----
  // It is Jellyfin's own user setting "Hide watched content from latest media" (HidePlayedInLatest, on by default in
  // Jellyfin): the server leaves watched titles out of the Recently added rows, so hiding them in the page could never bring
  // them back (seen 2026-10-21). The switch shows and changes that setting of the signed-in user, so it works in the apps
  // too; the page reloads to ask Jellyfin for the rows again.
  function loadPref() {
    if (prefLoaded || !uid()) { return; }
    prefLoaded = true;
    jget('Users/' + uid()).then(function (u) {
      showWatched = !(u && u.Configuration && u.Configuration.HidePlayedInLatest !== false);
      prefKnown = true;
      queue();
    }).catch(function () { prefLoaded = false; });
  }
  function savePref(show, tog) {
    var a = api();
    if (!a || !uid()) { return; }
    tog.disabled = true;
    jget('Users/' + uid()).then(function (u) {
      var c = u.Configuration || {};
      c.HidePlayedInLatest = !show;
      if (typeof a.updateUserConfiguration === 'function') { return a.updateUserConfiguration(uid(), c); }
      return fetch(a.getUrl('Users/Configuration', { userId: uid() }), {
        method: 'POST',
        headers: { 'Authorization': 'MediaBrowser Token="' + a.accessToken() + '"', 'Content-Type': 'application/json' },
        body: JSON.stringify(c)
      }).then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } });
    }).then(function () {
      // the web client keeps the signed-in user (with this setting) in localStorage ('user-<id>-<server>') and reads its
      // rows from that copy after a reload, refreshing it only minutes later (seen 2026-10-21): drop the copy first
      try {
        var id = uid();
        Object.keys(localStorage).forEach(function (k) { if (/^user-/.test(k) && k.indexOf(id) >= 0) { localStorage.removeItem(k); } });
      } catch (x) { /* the reload still asks the server, only later */ }
      location.reload();
    })
      .catch(function () { tog.disabled = false; showWatched = !show; queue(); });
  }
  function homeSections() {
    var pages = document.querySelectorAll('[id="indexPage"]');
    for (var i = 0; i < pages.length; i++) {
      var p = pages[i];
      if (p.classList.contains('hide') || p.hidden) { continue; }
      var tab = p.querySelector('#homeTab');
      var sec = tab && tab.querySelector('.sections');
      if (sec) { return sec; }
    }
    return null;
  }
  function home() {
    var sec = homeSections();
    if (!sec) { return; }
    loadPref();
    if (!prefKnown) { return; }
    var i;
    // the switch sits in the header of the first poster row (Recently added), else of the first row
    var tog = sec.querySelector('.sbx-tog');
    var host = null, vs = sec.querySelectorAll('.verticalSection');
    for (i = 0; i < vs.length && !host; i++) { if (vs[i].id !== 'sbWeek' && vs[i].querySelector('.overflowPortraitCard')) { host = vs[i]; } }
    for (i = 0; i < vs.length && !host; i++) { if (vs[i].id !== 'sbWeek' && vs[i].querySelector('.card')) { host = vs[i]; } }
    var head = host && host.querySelector('.sectionTitleContainer');
    if (!head) { return; }
    if (!tog || tog.parentNode !== head) {
      if (tog) { tog.remove(); }
      tog = el('button', 'sbx-tog');
      tog.type = 'button';
      tog.appendChild(el('span', 'sbx-sw'));
      tog.appendChild(el('span', '', T('jx.watched_too')));
      tog.title = T('jx.watched_too_tip');
      tog.addEventListener('click', function () { showWatched = !showWatched; savePref(showWatched, tog); queue(); });
      if (getComputedStyle(head).display !== 'flex') { head.style.display = 'flex'; head.style.alignItems = 'center'; head.style.flexWrap = 'wrap'; }
      // right after the title: the row's < > scroll buttons sit at the right end
      head.insertBefore(tog, head.firstElementChild ? head.firstElementChild.nextSibling : null);
    }
    if (tog.classList.contains('on') !== showWatched) { tog.classList.toggle('on', showWatched); }
    tog.setAttribute('aria-pressed', showWatched ? 'true' : 'false');
  }

  // ---- the Watched menu entry ----
  // Every menu that lists the libraries gets one: the top bar (Jellyfin's MUI header, which the Abyss skin shows across the
  // top: a.MuiButton links with an svg start icon) and the side menu (.mainDrawer, used on phones). A copy of the last
  // library link keeps the skin's look.
  var LIB = /#\/?(movies|tv|music|list|livetv|home)|topParentId=|parentId=/i;
  function navHosts() {
    var out = [], seen = [];
    var links = document.querySelectorAll('header a[href], .skinHeader a[href], .mainDrawer a.navMenuOption');
    for (var i = 0; i < links.length; i++) {
      var a = links[i];
      if (a.classList.contains('sbx-nav') || !LIB.test(a.getAttribute('href') || '')) { continue; }
      var par = a.parentNode;
      if (seen.indexOf(par) >= 0) { continue; }
      var n = 0;
      for (var j = 0; j < par.children.length; j++) { if (LIB.test(par.children[j].getAttribute('href') || '')) { n++; } }
      if (n >= 2) { seen.push(par); out.push({ par: par, model: a }); }
    }
    return out;
  }
  var CHECK = 'M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z';
  // the copy must come from a link that is NOT the current page: the skin marks the active one (a white pill in Abyss) with
  // its own classes, and a copy of it looked selected for ever (seen 2026-10-21). An idle link has no background.
  function bgOff(a) { var b = getComputedStyle(a).backgroundColor; return b === 'transparent' || /rgba\(.*,\s*0\)$/.test(b); }
  function idleModel(hs) {
    var last = null, any = null;
    for (var k = hs.par.children.length - 1; k >= 0 && !last; k--) {
      var c = hs.par.children[k];
      if (c.tagName !== 'A' || c.classList.contains('sbx-nav') || !LIB.test(c.getAttribute('href') || '')) { continue; }
      if (!any) { any = c; }
      if (bgOff(c) && !c.matches(':hover')) { last = c; }
    }
    return last || any || hs.model;
  }
  function navItem(hs) {
    var m = idleModel(hs), it = m.cloneNode(true);
    it.setAttribute('data-sbx-cls', m.getAttribute('class') || '');
    it.style.backgroundColor = getComputedStyle(m).backgroundColor;
    it.style.color = getComputedStyle(m).color;
    it.classList.add('sbx-nav');
    ['id', 'data-itemid', 'data-id', 'aria-current'].forEach(function (k) { it.removeAttribute(k); });
    it.classList.remove('navMenuOption-selected', 'selected', 'active', 'is-active', 'Mui-selected', 'Mui-focusVisible');
    it.setAttribute('href', '#');
    var tn = null, w = document.createTreeWalker(it, NodeFilter.SHOW_TEXT), t;
    while ((t = w.nextNode())) { if (t.nodeValue.trim() && (!tn || t.nodeValue.trim().length > tn.nodeValue.trim().length)) { tn = t; } }
    if (tn) { tn.nodeValue = T('jx.menu'); } else { it.appendChild(document.createTextNode(T('jx.menu'))); }
    // the icon: an svg keeps its classes (size, colour) and gets a check mark; Jellyfin's icon font takes the name as a class
    var svg = it.querySelector('svg');
    if (svg) {
      svg.setAttribute('viewBox', '0 0 24 24');
      while (svg.firstChild) { svg.removeChild(svg.firstChild); }
      var pth = document.createElementNS('http://www.w3.org/2000/svg', 'path');
      pth.setAttribute('d', CHECK);
      pth.setAttribute('fill', 'currentColor');
      svg.appendChild(pth);
    } else {
      var ic = it.querySelector('.material-icons, .navMenuOptionIcon');
      if (ic) {
        var keep = Array.prototype.filter.call(ic.classList, function (k) { return /^(material-icons|navMenuOptionIcon)$|[A-Z-]/.test(k); });
        if (keep.indexOf('material-icons') < 0) { keep.push('material-icons'); }
        var ni = el('span', keep.join(' ') + ' check_circle');
        ni.setAttribute('aria-hidden', 'true');
        ic.parentNode.replaceChild(ni, ic);
      }
    }
    // drop React's leftovers of the copied link (ripple), keep ours away from React's click handling
    Array.prototype.forEach.call(it.querySelectorAll('.MuiTouchRipple-root'), function (r) { r.textContent = ''; });
    it.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); openW(); }, true);
    hs.par.appendChild(it);
  }
  function nav() {
    navHosts().forEach(function (hs) {
      var it = hs.par.querySelector('.sbx-nav');
      // a copy made from the then active link (or after the skin changed its classes) is made again from an idle one
      if (it && !it.classList.contains('sbx-nav-on')) {
        var m = idleModel(hs);
        if (bgOff(m) && (m.getAttribute('class') || '') !== it.getAttribute('data-sbx-cls')) { it.remove(); it = null; }
      }
      if (!it) { navItem(hs); }
    });
  }

  // ---- the Watched page ----
  var W = { tab: 'movies', sort: 'date', movies: null, series: null, busy: false, err: false };
  function poster(id, h) { return ROOT + 'Items/' + id + '/Images/Primary?fillHeight=' + h + '&quality=90'; }
  function dateTxt(iso) {
    if (!iso) { return ''; }
    var d = new Date(iso);
    if (isNaN(d)) { return ''; }
    var o = { month: 'short', day: 'numeric' };
    if (d.getFullYear() !== new Date().getFullYear()) { o.year = 'numeric'; }
    try { return d.toLocaleDateString(lang(), o); } catch (x) { return d.toDateString(); }
  }
  function nid(x) { return String(x).replace(/-/g, '').toLowerCase(); }
  function loadW() {
    if (W.busy) { return; }
    W.busy = true; W.err = false;
    var mv = items({ IncludeItemTypes: 'Movie', Recursive: true, Filters: 'IsPlayed', SortBy: 'DatePlayed', SortOrder: 'Descending',
      Fields: 'ProductionYear', EnableImages: false, EnableUserData: true, Limit: 5000 }).then(function (j) {
      W.movies = (j.Items || []).map(function (it) {
        return { id: nid(it.Id), name: it.Name, sort: it.SortName || it.Name, at: it.UserData && it.UserData.LastPlayedDate || '', done: true };
      });
    });
    var sv = items({ IncludeItemTypes: 'Episode', Recursive: true, Filters: 'IsPlayed', SortBy: 'DatePlayed', SortOrder: 'Descending',
      Fields: 'SeriesId', EnableImages: false, EnableUserData: true, Limit: 10000 }).then(function (j) {
      var by = {}, list = [];
      (j.Items || []).forEach(function (e) {
        if (!e.SeriesId) { return; }
        var s = nid(e.SeriesId);
        if (!by[s]) { by[s] = { id: s, name: e.SeriesName || '', sort: e.SeriesName || '', at: e.UserData && e.UserData.LastPlayedDate || '', eps: 0, done: false }; list.push(by[s]); }
        by[s].eps++;
      });
      if (!list.length) { return list; }
      return items({ Ids: list.slice(0, 500).map(function (s) { return s.id; }).join(','), EnableImages: false, EnableUserData: true, Fields: 'SortName' })
        .then(function (k) {
          (k.Items || []).forEach(function (it) {
            var s = by[nid(it.Id)];
            if (s) { s.name = it.Name || s.name; s.sort = it.SortName || s.name; s.done = !!(it.UserData && it.UserData.Played); }
          });
          return list;
        }).catch(function () { return list; });
    }).then(function (list) { W.series = list; });
    Promise.all([mv, sv]).catch(function () { W.err = true; }).then(function () { W.busy = false; drawW(); });
  }
  function sorted(list) {
    var l = list.slice();
    if (W.sort === 'name') { l.sort(function (a, b) { return String(a.sort).localeCompare(String(b.sort), lang()); }); }
    else if (W.sort === 'imdb') { l.sort(function (a, b) { var x = R && R[a.id] ? R[a.id][0] : -1, y = R && R[b.id] ? R[b.id][0] : -1; return y - x; }); }
    return l;
  }
  function serverQ() { var a = api(); var s = ''; try { s = a && a.serverId ? a.serverId() : ''; } catch (x) { s = ''; } return s ? '&serverId=' + encodeURIComponent(s) : ''; }
  function drawW() {
    var w = document.getElementById('sbxW');
    if (!w) { return; }
    w.style.top = headerBottom() + 'px';
    w.textContent = '';
    var top = el('div', 'sbxw-top');
    top.appendChild(el('h1', '', T('jx.menu')));
    [['movies', 'jx.tab.movies'], ['series', 'jx.tab.series']].forEach(function (t) {
      var n = W[t[0]] ? W[t[0]].length : 0;
      var b = el('button', 'sbxw-tab' + (W.tab === t[0] ? ' on' : ''), T(t[1], { n: n }));
      b.type = 'button';
      b.addEventListener('click', function () { W.tab = t[0]; drawW(); });
      top.appendChild(b);
    });
    var so = el('label', 'sbxw-sort', T('jx.sort'));
    var sel = el('select');
    sel.id = 'sbxwSort';
    [['date', 'jx.sort.date'], ['name', 'jx.sort.name'], ['imdb', 'jx.sort.imdb']].forEach(function (o) {
      var op = el('option', '', T(o[1]));
      op.value = o[0];
      if (W.sort === o[0]) { op.selected = true; }
      sel.appendChild(op);
    });
    sel.addEventListener('change', function () { W.sort = sel.value; drawW(); });
    so.appendChild(sel);
    top.appendChild(so);
    var x = el('button', 'sbxw-x', '×');
    x.type = 'button';
    x.title = T('jx.close');
    x.setAttribute('aria-label', T('jx.close'));
    x.addEventListener('click', closeW);
    top.appendChild(x);
    w.appendChild(top);
    var list = W[W.tab];
    if (W.err && !list) { w.appendChild(el('div', 'sbxw-msg', T('jx.error'))); return; }
    if (!list) { w.appendChild(el('div', 'sbxw-msg', T('jx.loading'))); return; }
    if (!list.length) { w.appendChild(el('div', 'sbxw-msg', T(W.tab === 'movies' ? 'jx.empty_movies' : 'jx.empty_series'))); return; }
    var g = el('div', 'sbxw-grid');
    sorted(list).forEach(function (it) {
      var c = el('div', 'sbxw-c'), a = el('a');
      a.href = '#/details?id=' + it.id + serverQ();
      var p = el('div', 'sbxw-p');
      p.style.backgroundImage = 'url("' + poster(it.id, 360) + '")';
      if (it.done) { var ok = el('span', 'sbxw-ok', '✓'); ok.title = T('jx.done'); p.appendChild(ok); }
      a.appendChild(p);
      c.appendChild(a);
      // the title with the IMDb badge after it (a link may not hold another link: the title is a link of its own)
      var tl = el('div', 'sbxw-t sbx-tx'), ta = el('a', '', it.name);
      ta.href = a.href;
      tl.appendChild(ta);
      if (R && R[it.id] && R[it.id][0]) { badge(tl, R[it.id], true); }
      c.appendChild(tl);
      a = c;
      var d = it.at ? T('jx.when', { date: dateTxt(it.at) }) : '';
      if (W.tab === 'series') { d = T('jx.eps', { n: it.eps }) + (d ? ' · ' + d : ''); }
      a.appendChild(el('div', 'sbxw-d', d));
      g.appendChild(c);
    });
    w.appendChild(g);
  }
  // the lowest edge of the header's visible parts (the Abyss bar floats: the header box itself can be shorter than its links)
  function headerBottom() {
    var hd = document.querySelector('header.MuiAppBar-root, .skinHeader'), b = 0;
    if (hd) {
      [hd].concat(Array.prototype.slice.call(hd.querySelectorAll('a,button,div'))).forEach(function (e) {
        var r = e.getBoundingClientRect();
        if (r.height > 0 && r.bottom < 260) { b = Math.max(b, r.bottom); }
      });
    }
    return Math.max(56, Math.round(b) + 8);
  }
  var openHref = '';
  function openW() {
    var w = document.getElementById('sbxW');
    if (!w) { w = el('div'); w.id = 'sbxW'; document.body.appendChild(w); }
    document.documentElement.style.overflow = 'hidden';
    openHref = location.href;
    Array.prototype.forEach.call(document.querySelectorAll('.sbx-nav'), function (n) { n.classList.add('sbx-nav-on'); });
    W.movies = null; W.series = null;
    drawW();
    loadW();
  }
  function closeW() {
    var w = document.getElementById('sbxW');
    if (w) { w.remove(); }
    document.documentElement.style.overflow = '';
    openHref = '';
    Array.prototype.forEach.call(document.querySelectorAll('.sbx-nav'), function (n) { n.classList.remove('sbx-nav-on'); });
  }

  // ---- run ----
  var queued = false;
  function tick() {
    queued = false;
    if (openHref && location.href !== openHref && document.getElementById('sbxW')) { closeW(); }
    try { nav(); } catch (x) { /* the menu entry is optional */ }
    try { cards(); } catch (x) { /* no badges */ }
    try { detail(); } catch (x) { /* no detail link */ }
    try { epList(); } catch (x) { /* the stars stay */ }
    try { seasonAvg(); } catch (x) { /* no average */ }
    try { home(); } catch (x) { /* no switch */ }
  }
  function queue() { if (!queued) { queued = true; requestAnimationFrame(tick); } }
  function start() {
    var st = document.createElement('style');
    st.id = 'sbExtrasCss';
    st.textContent = css;
    document.head.appendChild(st);
    window.addEventListener('click', stopForBadge, true);
    window.addEventListener('mousedown', stopForBadge, true);
    window.addEventListener('pointerdown', stopForBadge, true);
    window.addEventListener('hashchange', function () { closeW(); queue(); });
    window.addEventListener('popstate', function () { closeW(); queue(); });
    // Jellyfin's router changes the address with pushState (no hashchange): any other menu link or button closes the page
    document.addEventListener('click', function (e) {
      if (!document.getElementById('sbxW')) { return; }
      var t = e.target && e.target.closest ? e.target.closest('a,button') : null;
      if (t && !t.closest('#sbxW') && !t.classList.contains('sbx-nav')) { closeW(); }
    }, true);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && document.getElementById('sbxW')) { closeW(); } });
    new MutationObserver(function (ms) {
      for (var i = 0; i < ms.length; i++) { if (!ms[i].target.closest || !ms[i].target.closest('#sbxW')) { queue(); return; } }
    }).observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['data-id', 'class'] });
    setInterval(function () { loadR(false); queue(); }, 60000);
    loadR(true);
    queue();
  }
  if (document.body) { start(); } else { document.addEventListener('DOMContentLoaded', start); }
})();
