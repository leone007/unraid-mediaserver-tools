/* Streambox: "Ezen a héten" row on Jellyfin's home page (web client and Jellyfin Desktop).
   Loaded by the JavaScript Injector plugin; reads week.json next to this file (written by the Streambox plugin every 2 minutes).
   Two views: calendar (seven day cards) and posters (one poster row with day separators); the default comes from the
   Streambox settings, each viewer can switch with the two icons next to the title (remembered in this browser).
   Read-only: it only shows the list and links to Jellyfin's own pages. */
(function () {
  'use strict';
  if (window.__sbWeek) { return; }
  window.__sbWeek = true;

  var BASE = 'streambox/';
  try { if (document.currentScript && document.currentScript.src) { BASE = new URL('.', document.currentScript.src).href; } } catch (e) { /* keep the default */ }
  var ROOT = new URL('../../', new URL(BASE, location.href)).href;   // the Jellyfin server root (/web/streambox/ -> /)
  // IMDb ratings and the Watched menu (extras.js, written by Streambox; a stub when they are switched off)
  (function () { var x = document.createElement('script'); x.src = BASE + 'extras.js?v=' + Date.now(); document.head.appendChild(x); })();
  var DAYS = ['Hétfő', 'Kedd', 'Szerda', 'Csütörtök', 'Péntek', 'Szombat', 'Vasárnap'];
  var MON = ['jan.', 'febr.', 'márc.', 'ápr.', 'máj.', 'jún.', 'júl.', 'aug.', 'szept.', 'okt.', 'nov.', 'dec.'];
  var KEY = 'streamboxWeekView';
  var data = null, shown = [], lastFetch = 0, busy = false;   // shown: what the row lists (episodes and season cards)

  var css = '' +
    '#sbWeek .sectionTitleContainer{display:flex;align-items:center;flex-wrap:wrap}' +
    '#sbWeek .sectionTitleContainer>*+*{margin-left:.8em}' +
    '#sbWeek button.sectionTitleTextButton{background:none;border:0;font:inherit;cursor:pointer;align-items:center}' +
    '.sbw-meta{opacity:.6;font-size:.9em}' +
    '.sbw-meta b{color:#f0a83a;font-weight:600}' +
    '#sbWeek .sectionTitleContainer>.sbw-views{display:flex;margin-left:auto}' +
    '.sbw-views button+button{margin-left:.2em}' +
    '.sbw-views button{background:none;border:0;color:inherit;opacity:.45;cursor:pointer;padding:.2em;border-radius:6px;line-height:0}' +
    '.sbw-views button.on{opacity:1;background:rgba(255,255,255,.1)}' +
    '.sbw-views button:hover,.sbw-views button:focus-visible{opacity:1;outline:none}' +
    '.sbw-scroll{overflow-x:auto;scrollbar-width:thin}' +
    /* both views: the picture is as high as Jellyfin's Continue watching / Next up cards (--sbw-h, measured), 2:3 like
       its posters; under it two text lines like Jellyfin's cards, so the whole row is as high as those rows */
    '.sbw-grid{display:flex;padding:.3em 0 .6em var(--sbw-x,0px)}' +
    '.sbw-grid>*+*{margin-left:1em}' +
    '.sbw-col{flex:none;width:calc(var(--sbw-h,12em) * 2 / 3);min-width:0}' +
    /* the day card's text scales with the card: four entries (3 lines each) fill its height, the text its width */
    '.sbw-box{height:var(--sbw-h,12em);font-size:calc(var(--sbw-h,12em) / 19.2);line-height:1.3;box-sizing:border-box;background:rgba(255,255,255,.06);border-radius:10px;padding:.35em;overflow-y:auto;overflow-x:hidden;scrollbar-width:none}' +
    '.sbw-box::-webkit-scrollbar{display:none}' +
    '.sbw-col.sbw-today .sbw-box{box-shadow:inset 0 0 0 1px rgba(255,255,255,.6)}' +
    '.sbw-col.sbw-past{opacity:.72}' +
    '.sbw-box>*+*{margin-top:.2em}' +
    '.sbw-ep{display:block;border-radius:.35em;padding:.25em .3em;color:inherit;text-decoration:none}' +
    'a.sbw-ep:hover,a.sbw-ep:focus-visible{background:rgba(255,255,255,.1);outline:none}' +
    '.sbw-n{font-weight:600;display:flex;align-items:center;min-width:0}' +
    '.sbw-n span:first-child{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;min-width:0}' +
    '.sbw-st{font-size:.9em;line-height:1.45;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-variant-numeric:tabular-nums}' +
    '.sbw-s{font-size:.9em;line-height:1.45;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-variant-numeric:tabular-nums}' +
    '.sbw-c{opacity:.6}' +
    '.sbw-new{flex:none;font-size:.72em;background:#b45ff0;color:#fff;border-radius:4px;padding:0 .35em;margin-left:.4em;font-weight:700}' +
    '.sbw-none{font-size:.9em;opacity:.45;padding:.25em .3em}' +
    /* posters view */
    '.sbw-row{display:flex;padding:.3em 0 .6em var(--sbw-x,0px);align-items:stretch}' +
    '.sbw-row>*+*{margin-left:1em}' +
    '.sbw-day{flex:none;writing-mode:vertical-rl;transform:rotate(180deg);font-size:.72em;letter-spacing:.14em;text-transform:uppercase;font-weight:600;opacity:.55;border-left:1px solid rgba(255,255,255,.18);padding-left:.5em;text-align:center}' +
    '.sbw-day.sbw-today{opacity:1;border-color:rgba(255,255,255,.7)}' +
    '.sbw-card{flex:none;width:calc(var(--sbw-h,12em) * 2 / 3);color:inherit;text-decoration:none;min-width:0}' +
    '.sbw-pos{position:relative;height:var(--sbw-h,12em);border-radius:10px;overflow:hidden;background:rgba(255,255,255,.08) center/cover no-repeat}' +
    'a.sbw-card:hover .sbw-pos,a.sbw-card:focus-visible .sbw-pos{box-shadow:0 0 0 2px rgba(255,255,255,.85)}' +
    'a.sbw-card{outline:none}' +
    '.sbw-when{position:absolute;top:.4em;left:.4em;background:rgba(0,0,0,.72);border-radius:6px;padding:.15em .4em;font-size:.68em;font-weight:600;font-variant-numeric:tabular-nums}' +
    '.sbw-pos .sbw-new{position:absolute;top:.4em;right:.4em;margin:0;font-size:.64em;padding:.15em .4em;border-radius:6px}' +
    '.sbw-chip{position:absolute;left:.35em;right:.35em;bottom:.35em;background:rgba(0,0,0,.78);border-radius:7px;padding:.25em .4em;font-size:.68em;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-weight:600;font-variant-numeric:tabular-nums}' +
    '.sbw-t1{margin-top:.5em;font-weight:600;font-size:.9em;line-height:1.35;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}' +
    '.sbw-t2{font-size:.8em;line-height:1.35;opacity:.6;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}' +
    '.sbw-ok{color:#3fbf7f}.sbw-dl{color:#4a9eff}.sbw-proc{color:#38c7c0}.sbw-late{color:#f0a83a}.sbw-fail{color:#ef5350}.sbw-wait{color:inherit;opacity:.75}' +
    '.sbw-chip.sbw-wait{opacity:1;color:#e6e6e6}';

  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) { e.className = cls; }
    if (text !== undefined) { e.textContent = text; }
    return e;
  }
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function hm(ts) { var d = new Date(ts * 1000); return pad(d.getHours()) + ':' + pad(d.getMinutes()); }
  function dur(s) {
    s = Math.max(0, Math.round(s / 60));
    var d = Math.floor(s / 1440), h = Math.floor(s % 1440 / 60), m = s % 60;
    return d ? d + 'n ' + h + 'ó' : (h ? h + 'ó ' + m + 'p' : m + 'p');
  }
  function dayIdx(ts) { return (new Date(ts * 1000).getDay() + 6) % 7; }   // Monday 0 .. Sunday 6

  // A season that comes out at once (2 or more episodes of one season on one day) is one card: a whole season of
  // posters would cover the row. The card links to the series and shows one status for all its episodes.
  var GROUP_MIN = 2;
  function items(w) {
    var keys = {}, out = [];
    w.eps.forEach(function (e) {
      var k = dayIdx(e.air) + '|' + e.s + '|' + e.c.slice(0, 3);
      if (!keys[k]) { keys[k] = []; out.push(keys[k]); }
      keys[k].push(e);
    });
    var res = [];
    out.forEach(function (g) {
      if (g.length < GROUP_MIN) { Array.prototype.push.apply(res, g); return; }
      g = g.slice().sort(function (a, b) { return +a.c.slice(4) - +b.c.slice(4); });   // by episode number: E99 before E100 (item 104)
      var f = g[0], l = g[g.length - 1], nf = +f.c.slice(4), nl = +l.c.slice(4);
      var run = nl - nf + 1 === g.length;   // S02E01–E08; with gaps: S02 · 3 epizód
      res.push({ s: f.s, c: run ? f.c + '–' + l.c.slice(3) : f.c.slice(0, 3), t: run ? '' : g.length + ' epizód', air: Math.min.apply(null, g.map(function (e) { return e.air; })),
        exp: f.exp, st: 'grp', pct: null, 'new': g.some(function (e) { return e['new']; }), ep: '', sid: f.sid, img: f.img, g: g });
    });
    return res.sort(function (a, b) { return a.air - b.air || (a.s < b.s ? -1 : a.s > b.s ? 1 : 0); });
  }

  // Status text and its colour; the countdowns are recomputed in the browser
  function status(e, now) {
    if (e.g) { return groupStatus(e.g, now); }
    var target = e.exp !== null ? e.exp : e.air;
    switch (e.st) {
      case 'jf': return ['Elérhető', 'sbw-ok'];
      case 'file': case 'scan': case 'imp': return ['Hamarosan elérhető', 'sbw-proc'];
      case 'dl': return ['Letöltés ' + (e.pct !== null ? e.pct + '%' : ''), 'sbw-dl'];
      case 'grab': return ['Letöltés indul', 'sbw-dl'];
      case 'pause': return ['Szünetel', 'sbw-wait'];
      case 'fail': return ['Letöltési hiba', 'sbw-fail'];
      case 'late': return ['Késik ' + dur(now - target), 'sbw-late'];
      default:
        if (target > now) { return [(e.exp !== null ? '' : 'Adás ') + dur(target - now) + ' múlva', 'sbw-wait']; }
        return ['Bármikor jöhet', 'sbw-wait'];
    }
  }

  // One status for a season card: all available, how many are available, else the most urgent state of its episodes
  function groupStatus(g, now) {
    var n = g.filter(function (e) { return e.st === 'jf'; }).length;
    if (n === g.length) { return ['Elérhető', 'sbw-ok']; }
    if (n) { return [n + '/' + g.length + ' elérhető', 'sbw-ok']; }
    var order = ['fail', 'dl', 'grab', 'imp', 'file', 'scan', 'pause', 'late'];
    for (var i = 0; i < order.length; i++) {
      var m = g.filter(function (e) { return e.st === order[i]; });
      if (!m.length) { continue; }
      if (order[i] === 'dl') { m.sort(function (a, b) { return (a.pct || 0) - (b.pct || 0); }); }
      return status(m[0], now);
    }
    return status(g[0], now);
  }

  function link(e) {
    var id = e.g ? e.sid : (e.ep || e.sid);
    if (!id) { return ''; }
    var sv = '';
    try { sv = window.ApiClient && window.ApiClient.serverId ? window.ApiClient.serverId() : ''; } catch (x) { sv = ''; }
    return '#/details?id=' + encodeURIComponent(id) + (sv ? '&serverId=' + encodeURIComponent(sv) : '');
  }
  function thumb(e, h) {
    if (e.sid) { return ROOT + 'Items/' + encodeURIComponent(e.sid) + '/Images/Primary?fillHeight=' + h + '&quality=90'; }
    return e.img || '';
  }
  function bg(node, src) { if (src) { node.style.backgroundImage = 'url("' + src.replace(/"/g, '%22') + '")'; } }
  function tip(e) {
    if (e.g) {
      return e.s + ' · ' + e.c + ' · ' + e.g.length + ' epizód\nAdás: ' + DAYS[dayIdx(e.air)].toLowerCase() + ' ' + hm(e.air) + '\n' +
        e.g.map(function (x) { return x.c + (x.t ? ' · ' + x.t : '') + ' · ' + status(x, Date.now() / 1000)[0]; }).join('\n');
    }
    return e.s + ' · ' + e.c + (e.t ? ' · ' + e.t : '') + '\nAdás: ' + DAYS[dayIdx(e.air)].toLowerCase() + ' ' + hm(e.air) +
      (e.exp !== null ? '\nVárható: ~' + hm(e.exp) : '');
  }
  function stNode(tag, cls, e, now, i) {
    var st = status(e, now);
    var n = el(tag, cls + ' ' + st[1], st[0]);
    n.setAttribute('data-sbw', i);
    n.setAttribute('data-base', cls);
    return n;
  }

  function view() {
    var v = '';
    try { v = localStorage.getItem(KEY) || ''; } catch (x) { v = ''; }
    if (v !== 'grid' && v !== 'row') { v = data && data.view === 'row' ? 'row' : 'grid'; }
    return v;
  }
  function setView(v) {
    try { localStorage.setItem(KEY, v); } catch (x) { /* this browser only */ }
    render(true);
  }

  function metaText(w) {
    var ready = 0, late = 0;
    w.eps.forEach(function (e) { if (e.st === 'jf') { ready++; } if (e.st === 'late') { late++; } });
    var f = new Date(w.from * 1000), t = new Date(f.getFullYear(), f.getMonth(), f.getDate() + 6);
    var range = MON[f.getMonth()] + ' ' + f.getDate() + '–' + (f.getMonth() !== t.getMonth() ? MON[t.getMonth()] + ' ' : '') + t.getDate() + '.';
    return [range + ' · ' + w.eps.length + ' epizód · ' + ready + ' elérhető', late];
  }

  function buildGrid(w, now) {
    var grid = el('div', 'sbw-grid');
    var today = dayIdx(now), thisWeek = now >= w.from && now < w.to;
    for (var d = 0; d < 7; d++) {
      var col = el('div', 'sbw-col' + (thisWeek && d === today ? ' sbw-today' : '') + (thisWeek && d < today ? ' sbw-past' : ''));
      var eps = [];
      shown.forEach(function (e, i) { if (dayIdx(e.air) === d) { eps.push([e, i]); } });
      var box = el('div', 'sbw-box');
      if (!eps.length) { box.appendChild(el('div', 'sbw-none', 'nincs epizód')); }
      eps.forEach(function (x) {
        var e = x[0], href = link(e);
        var row = el(href ? 'a' : 'div', 'sbw-ep');
        if (href) { row.href = href; }
        row.title = tip(e);
        var nm = el('div', 'sbw-n');
        nm.appendChild(el('span', '', e.s));
        if (e['new']) { nm.appendChild(el('span', 'sbw-new', 'ÚJ')); }
        row.appendChild(nm);
        row.appendChild(el('div', 'sbw-s sbw-c', e.c + ' · ' + hm(e.air)));
        row.appendChild(stNode('div', 'sbw-st', e, now, x[1]));
        box.appendChild(row);
      });
      col.appendChild(box);
      // under the card like Jellyfin's card titles: the day, then its date and how many are available
      var n = 0, all = 0, dt = new Date(w.from * 1000);
      eps.forEach(function (x) { (x[0].g || [x[0]]).forEach(function (e) { all++; if (e.st === 'jf') { n++; } }); });
      dt = new Date(dt.getFullYear(), dt.getMonth(), dt.getDate() + d);
      col.appendChild(el('div', 'sbw-t1', thisWeek && d === today ? 'Ma · ' + DAYS[d] : DAYS[d]));
      col.appendChild(el('div', 'sbw-t2', MON[dt.getMonth()] + ' ' + dt.getDate() + '.' + (all ? ' · ' + n + '/' + all + ' elérhető' : '')));
      grid.appendChild(col);
    }
    return grid;
  }

  function buildRow(w, now) {
    var row = el('div', 'sbw-row');
    var today = dayIdx(now), thisWeek = now >= w.from && now < w.to, last = -1;
    shown.forEach(function (e, i) {
      var d = dayIdx(e.air);
      if (d !== last) {
        row.appendChild(el('div', 'sbw-day' + (thisWeek && d === today ? ' sbw-today' : ''), (thisWeek && d === today ? 'Ma · ' : '') + DAYS[d]));
        last = d;
      }
      var href = link(e);
      var c = el(href ? 'a' : 'div', 'sbw-card');
      if (href) { c.href = href; }
      c.title = tip(e);
      var p = el('div', 'sbw-pos');
      bg(p, thumb(e, 400));
      p.appendChild(el('span', 'sbw-when', hm(e.air)));
      if (e['new']) { p.appendChild(el('span', 'sbw-new', 'ÚJ')); }
      p.appendChild(stNode('span', 'sbw-chip', e, now, i));
      c.appendChild(p);
      c.appendChild(el('div', 'sbw-t1', e.s));
      c.appendChild(el('div', 'sbw-t2', e.c + (e.t ? ' · ' + e.t : '')));
      row.appendChild(c);
    });
    return row;
  }

  function build(w, v) {
    var now = Date.now() / 1000;
    shown = items(w);
    var box = el('div', 'verticalSection sbw');
    box.id = 'sbWeek';
    var head = el('div', 'sectionTitleContainer sectionTitleContainer-cards padded-left padded-right');
    var h2 = el('h2', 'sectionTitle sectionTitle-cards', 'Ezen a héten');
    // like Jellyfin's own rows: the title with a chevron; here it switches between the calendar and the posters view
    var more = el('button', 'more button-flat button-flat-mini sectionTitleTextButton emby-button');
    more.type = 'button';
    more.title = v === 'row' ? 'Naptár nézet' : 'Poszter nézet';
    more.appendChild(h2);
    var ic = el('span', 'material-icons chevron_right');
    ic.setAttribute('aria-hidden', 'true');
    more.appendChild(ic);
    more.addEventListener('click', function () { setView(v === 'row' ? 'grid' : 'row'); });
    head.appendChild(more);
    var mt = metaText(w);
    var meta = el('span', 'sbw-meta', mt[0]);
    if (mt[1]) { meta.appendChild(document.createTextNode(' · ')); meta.appendChild(el('b', '', mt[1] + ' késik')); }
    head.appendChild(meta);
    var vs = el('span', 'sbw-views');
    [['grid', 'calendar_view_week', 'Naptár nézet'], ['row', 'view_carousel', 'Poszter nézet']].forEach(function (b) {
      var bt = el('button', b[0] === v ? 'on' : '');
      bt.type = 'button';
      bt.title = b[2];
      bt.setAttribute('aria-label', b[2]);
      bt.appendChild(el('span', 'material-icons', b[1]));
      bt.addEventListener('click', function () { setView(b[0]); });
      vs.appendChild(bt);
    });
    head.appendChild(vs);
    box.appendChild(head);
    var sc = el('div', 'sbw-scroll padded-left padded-right');
    sc.appendChild(v === 'row' ? buildRow(w, now) : buildGrid(w, now));
    box.appendChild(sc);
    return box;
  }

  // What the row shows apart from the countdowns: rebuild only when this changes (no flicker of the posters)
  function sig(w, v) {
    return v + '|' + w.from + '|' + new Date().toDateString() + '|' + JSON.stringify(w.eps.map(function (e) {
      return [e.s, e.c, e.t, e.air, e.exp, e.st, e.pct, e['new'], e.ep, e.sid, e.img];
    }));
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

  // Picture height of Jellyfin's own wide rows (Continue watching, Next up), so the row is as high as those on every
  // screen; without such a row: the portrait posters' width (Recently added) times the usual wide/portrait card ratio
  function cardHeight(sec) {
    var c = sec.querySelectorAll('.overflowBackdropCard .cardScalable'), i;
    for (i = 0; i < c.length; i++) { if (c[i].offsetHeight > 40) { return c[i].offsetHeight; } }
    c = sec.querySelectorAll('.overflowPortraitCard .cardScalable');
    for (i = 0; i < c.length; i++) { if (c[i].offsetWidth > 40) { return Math.round(c[i].offsetWidth * 1.08); } }
    return 0;
  }

  // Left edge of Jellyfin's card pictures within their row, so the week row starts in line with them on any skin
  function align(box, sec) {
    var j = sec.querySelector('.overflowBackdropCard .cardScalable') || sec.querySelector('.overflowPortraitCard .cardScalable');
    var sc = box.querySelector('.sbw-scroll'), first = sc && sc.firstChild && sc.firstChild.firstChild;
    if (!j || !first || !j.offsetWidth) { return; }
    var jv = j.closest('.verticalSection') || sec;
    var want = j.getBoundingClientRect().left - jv.getBoundingClientRect().left;
    var cur = parseFloat(box.style.getPropertyValue('--sbw-x')) || 0;
    var have = first.getBoundingClientRect().left + sc.scrollLeft - box.getBoundingClientRect().left - cur;
    var x = Math.max(0, Math.round(want - have));
    if (x !== cur) { box.style.setProperty('--sbw-x', x + 'px'); }
  }

  function render(force) {
    var sec = homeSections();
    if (!sec) { return; }
    var old = sec.querySelector('#sbWeek');
    var ch = cardHeight(sec);
    if (old && ch && old.style.getPropertyValue('--sbw-h') !== ch + 'px') { old.style.setProperty('--sbw-h', ch + 'px'); }
    var show = data && data.ok && data.eps && data.eps.length && Date.now() / 1000 < data.to + 3600;
    if (!show) { if (old) { old.remove(); } return; }
    var v = view(), s = sig(data, v);
    if (old && !force && old.getAttribute('data-sig') === s) {
      // same list: refresh the countdowns in place
      var now = Date.now() / 1000;
      Array.prototype.forEach.call(old.querySelectorAll('[data-sbw]'), function (n) {
        var e = shown[+n.getAttribute('data-sbw')];
        if (!e) { return; }
        var st = status(e, now);
        if (n.textContent !== st[0]) { n.textContent = st[0]; }
        var cls = n.getAttribute('data-base') + ' ' + st[1];
        if (n.className !== cls) { n.className = cls; }
      });
      align(old, sec);
      return;
    }
    var box = build(data, v);
    box.setAttribute('data-sig', s);
    if (ch) { box.style.setProperty('--sbw-h', ch + 'px'); }
    var x = old ? old.querySelector('.sbw-scroll').scrollLeft : -1;
    if (old) { box.style.setProperty('--sbw-x', old.style.getPropertyValue('--sbw-x')); old.replaceWith(box); } else { sec.insertBefore(box, sec.firstChild); }
    align(box, sec);
    var sc = box.querySelector('.sbw-scroll'), td = box.querySelector('.sbw-today');
    if (x >= 0 && !force) { sc.scrollLeft = x; }
    else if (td && sc.scrollWidth > sc.clientWidth) { sc.scrollLeft = Math.max(0, td.offsetLeft - sc.offsetLeft - 8); }   // today in sight
  }

  // every 110 s; without a list yet (week.json missing or broken) every 15 s, never on each page change (item 104)
  function load(force) {
    if (busy || (!force && Date.now() - lastFetch < (data ? 110000 : 15000))) { return; }
    busy = true;
    fetch(BASE + 'week.json?_=' + Date.now(), { cache: 'no-store' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (j) { if (j) { data = j; } lastFetch = Date.now(); render(false); })
      .catch(function () { lastFetch = Date.now(); })
      .then(function () { busy = false; });
  }

  // Jellyfin rebuilds the home page on navigation: put the row back when it is missing
  var queued = false;
  function check() {
    queued = false;
    var sec = homeSections();
    if (!sec) { return; }
    if (!sec.querySelector('#sbWeek')) { if (data) { render(false); } load(false); }
    else if (data) { render(false); }   // Jellyfin's rows came in: the card height may follow them
  }
  function start() {
    var st = document.createElement('style');
    st.id = 'sbWeekCss';
    st.textContent = css;
    document.head.appendChild(st);
    new MutationObserver(function () { if (!queued) { queued = true; requestAnimationFrame(check); } })
      .observe(document.body, { childList: true, subtree: true });
    setInterval(function () { if (homeSections()) { load(false); render(false); } }, 30000);
    // Jellyfin sizes its cards to the window: measure again when the window changes
    window.addEventListener('resize', function () { if (!queued) { queued = true; requestAnimationFrame(check); } });
    load(true);
  }
  if (document.body) { start(); } else { document.addEventListener('DOMContentLoaded', start); }
})();
