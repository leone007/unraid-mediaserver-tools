/* Abyss spotlight and SeerrFin fixes for the old player (JS Injector, a script of its own; not part of Streambox).
   1) Jellyfin Media Player: Abyss stretches its layers with `inset: 0`, which the player's old Chromium (before 87)
      does not know: the spotlight backdrop stayed at the image's own size and the blurred page background was missing.
      The long form is added in the page and inside the (same-origin) spotlight iframe; newer browsers are not affected.
   2) In the player only (its user agent names QtWebEngine / JellyfinMediaPlayer), the spotlight is as tall as in the
      browser: about 57% of the window height, measured on the owner's screenshots (42vw was low, 50vw too tall in a
      normal window, 2026-10-15); at least 30% of the width, so a wide, low window does not squash it.
      Browsers, phones and tablets keep Abyss's own rules.
   3) SeerrFin (Katalógus) in the player: its details/request popup, quality picker, card backdrops and logos use
      `inset` too, so the popup was added to the page with no size and never showed (item 70, 2026-10-15).
   4) Jellyfin Desktop: the card rows (Recently added, the Katalógus rows) showed a horizontal scrollbar under them, the
      browser shows none (item 75, 2026-10-17). Not tied to the user agent (the first try was, and did nothing in Jellyfin
      Desktop): Jellyfin's scrollers by class, and any row that scrolls sideways only and shows a scrollbar that takes room
      (found by a scan; browsers with hidden or overlay scrollbars are never touched). Both the standard rule and the
      old webkit one, for any Chromium version. Scrolling itself stays (dragging, the arrow buttons, Shift + wheel).
      The scan measures the whole page, so it runs only after a page change (the address changes), 0.5, 1.5, 3 and 6 s
      later while the rows load, not all the time (owner, 2026-10-17: Jellyfin Desktop felt slow).
   5) Jellyfin Desktop, the < > buttons of the rows (item 75): Jellyfin puts them (and its own drag scrolling, with the
      native scrollbar hidden) only in desktop mode AND when matchMedia('(pointer: fine)') matches. Jellyfin Desktop 2.0
      (user agent JellyfinDesktop/2.0, Chrome 130) reports no fine pointer although a mouse is used (owner's Alt+Shift+J
      box: pointer fine false, hover true, 0 touch points), so the rows fell back to native scrolling. In an app (window.
      NativeShell) that can hover and has no touch points, '(pointer: fine)' answers true; everything else asks the
      browser as before (phones, tablets and the Android app have touch points or no hover). If rows were drawn before
      the script ran, the page reloads once. Alt+Shift+J shows the state (click the box to close).
   6) SeerrFin popup (item 76): in the old player and Jellyfin Desktop (Qt WebEngine) the page breaks into black squares
      and the dimming/popup can vanish while scrolling with a Katalógus popup open; Chrome is fine, and it happens with
      this script switched off too. The popup card and quality panel blur the whole page behind them (backdrop-filter
      16px) on an opaque black background, the Letterboxd action bar sticks with an 8px blur: the blur is not visible
      but has to be redrawn on every scroll step. It is switched off in every client (nothing changes on screen).
   7) Katalógus rows, dragging a card (item 76): SeerrFin opens its popup from a click listener on the document in the
      capture phase, before Jellyfin's scroller can swallow the click that ends a drag, so letting go after a drag
      opened the card. Jellyfin's own cards were fine. A listener on the window (it runs before the document's) notes
      how far the mouse moved while pressed; after a move of more than 6 px the click on a SeerrFin card is dropped. */
(function () {
  var jmp = /QtWebEngine|JellyfinMediaPlayer/i.test(navigator.userAgent);
  var page = '#abyss-spotlight-backdrop,.abyss-spotlight-backdrop-layer,' +
    '.bst-popout-wrapper,.bst-popout-backdrop,.bst-popout-center,.bst-quality-wrapper,.bst-quality-backdrop,' +
    '.seerrfin-skeleton-title::after,.seerrfin-skeleton-card::after,.seerrfin-native-skeleton-image::after,' +
    '.seerrfin-native-skeleton-text::after,.seerrfin-card-thumb-skeleton::after,' +
    '.seerrfin-discover-card--backdrop .seerrfin-discover-backdrop-image,.seerrfin-discover-backdrop-media' +
    '{top:0;right:0;bottom:0;left:0}' +
    '[style*="inset:20% 12%"]{top:20%;bottom:20%;left:12%;right:12%}[style*="inset:29% 15%"]{top:29%;bottom:29%;left:15%;right:15%}' +
    (jmp ? '@media (min-width:1025px){iframe.featurediframe{height:57vh;min-height:30vw;max-height:85vh}}' : '') +
    '.emby-scroller,.scrollX,.jfxNoBar{scrollbar-width:none!important}' +
    '.bst-aether-card,.bst-quality-panel,.seerrfin-letterboxd-actionbar{backdrop-filter:none!important;-webkit-backdrop-filter:none!important}' +
    '.emby-scroller::-webkit-scrollbar,.scrollX::-webkit-scrollbar,.jfxNoBar::-webkit-scrollbar{display:none!important;height:0!important}';
  var frame = '#backdrop,#backdrop::after,#content,#empty{top:0;right:0;bottom:0;left:0}';
  function add(d, id, css) {
    if (d && d.head && !d.getElementById(id)) {
      var s = d.createElement('style'); s.id = id; s.textContent = css; d.head.appendChild(s);
    }
  }
  function fix() {
    add(document, 'abyssFixPage', page);
    document.querySelectorAll('iframe[src*="spotlight"]').forEach(function (f) {
      try { add(f.contentDocument, 'abyssFixFrame', frame); } catch (e) { /* not reachable yet */ }
    });
  }
  // a sideways-only row with a scrollbar that takes room (SeerrFin rows): hide its scrollbar too
  function rows() {
    document.querySelectorAll('div,ul,section').forEach(function (e) {
      if (e.classList.contains('jfxNoBar') || e.scrollWidth <= e.clientWidth + 1 || e.offsetHeight - e.clientHeight < 2) { return; }
      var cs = getComputedStyle(e);
      if ((cs.overflowX === 'auto' || cs.overflowX === 'scroll') && cs.overflowY !== 'auto' && cs.overflowY !== 'scroll') {
        e.classList.add('jfxNoBar');
      }
    });
  }
  // 5) a fine pointer for Jellyfin Desktop
  var mmOrig = window.matchMedia ? window.matchMedia.bind(window) : null, faked = 0, reload = 'no';
  function mouseApp() {
    return !!window.NativeShell && !navigator.maxTouchPoints && mmOrig && mmOrig('(hover: hover)').matches && !mmOrig('(pointer: fine)').matches;
  }
  if (mmOrig) {
    window.matchMedia = function (q) {
      var r = mmOrig(q);
      if (/^\s*\(\s*pointer\s*:\s*fine\s*\)\s*$/i.test(String(q)) && !r.matches && mouseApp()) {
        faked++;
        return {matches: true, media: r.media, onchange: null, addListener: function () {}, removeListener: function () {},
          addEventListener: function () {}, removeEventListener: function () {}, dispatchEvent: function () { return false; }};
      }
      return r;
    };
  }
  var waitRows = 0, rt = setInterval(function () {
    if (++waitRows > 120) { clearInterval(rt); return; }
    if (!document.querySelector('.emby-scroller')) { return; }
    clearInterval(rt);
    if (mouseApp() && !document.querySelector('.emby-scrollbuttons')) {
      try {
        if (!sessionStorage.getItem('jfxPointerReload')) { sessionStorage.setItem('jfxPointerReload', '1'); reload = 'yes'; location.reload(); }
        else { reload = 'done once, still no buttons'; }
      } catch (e) { reload = 'no session storage'; }
    }
  }, 500);
  function diag() {
    var ns = window.NativeShell, mm = function (q) { return mmOrig ? mmOrig(q).matches : '?'; };
    var t = ['jfx diagnosis (v14, click to close)', 'html: ' + document.documentElement.className,
      'app: ' + !!ns + ' · mouse app: ' + mouseApp() + ' · fine pointer answered: ' + faked + ' times · reload: ' + reload,
      'real: pointer fine ' + mm('(pointer: fine)') + ' · hover ' + mm('(hover: hover)') + ' · touch points ' + navigator.maxTouchPoints,
      'rows: ' + document.querySelectorAll('.emby-scroller').length + ' · with buttons: ' + document.querySelectorAll('.emby-scrollbuttons').length,
      'ua: ' + navigator.userAgent];
    var d = document.getElementById('jfxDiag') || document.createElement('pre');
    d.id = 'jfxDiag'; d.textContent = t.join('\n');
    d.style.cssText = 'position:fixed;left:10px;top:10px;z-index:2147483647;max-width:90vw;white-space:pre-wrap;background:#111;color:#0f0;' +
      'font:12px monospace;padding:10px;border:1px solid #0f0;cursor:pointer';
    d.onclick = function () { d.remove(); };
    document.body.appendChild(d);
  }
  document.addEventListener('keydown', function (e) {
    if (e.altKey && e.shiftKey && (e.key === 'J' || e.key === 'j')) { diag(); }
  }, true);
  // 7) a drag is not a click on a SeerrFin card
  var down = null, dragged = false;
  window.addEventListener('mousedown', function (e) { down = e.button === 0 ? [e.clientX, e.clientY] : null; dragged = false; }, true);
  window.addEventListener('mousemove', function (e) {
    if (down && !dragged && (e.buttons & 1) && Math.abs(e.clientX - down[0]) + Math.abs(e.clientY - down[1]) > 6) { dragged = true; }
  }, true);
  window.addEventListener('click', function (e) {
    if (!dragged) { return; }
    dragged = false;
    if (e.target.closest && e.target.closest('.seerrfin-discover-card, [data-seerrfin-native-card="true"]')) {
      e.preventDefault(); e.stopPropagation(); e.stopImmediatePropagation();
    }
  }, true);
  // the row scan only after a page change: a cheap address check, then a few scans while the rows load
  var href = '', scans = [];
  function pageChanged() {
    if (location.href === href) { return; }
    href = location.href;
    scans.forEach(clearTimeout);
    scans = [500, 1500, 3000, 6000].map(function (ms) { return setTimeout(rows, ms); });
  }
  setInterval(function () { fix(); pageChanged(); }, 1000);
  window.addEventListener('hashchange', pageChanged);
  window.addEventListener('popstate', pageChanged);
  fix();
  pageChanged();
})();
