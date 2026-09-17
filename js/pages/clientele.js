/* ============================================================
   GSS — Clientele page
   Five-row infinite logo marquee.

   The logo list lives in plain HTML (#clienteleLogos). This file only
   arranges it: it deals the logos across five rails, clones each rail
   enough times to cover the screen with no gap, and drives one shared
   scroll speed so all five rows move at the same pace regardless of
   how many logos each one holds.

   Nothing here needs editing when logos are added or removed.
   ============================================================ */
(function () {
  'use strict';

  var ROWS        = 5;
  var MIN_COVER   = 2.6;   // clone the set until it is this many screens wide
  var MAX_CLONES  = 16;    // hard stop, just in case

  /* Scroll speed in pixels per second. It lives in the stylesheet as
     --clx-speed so it can be tuned next to the rest of the design; this is
     only the fallback if the property is missing. Lower is slower. All five
     rows always share it, whatever each row's length. */
  function speed() {
    var v = parseFloat(getComputedStyle(document.documentElement)
              .getPropertyValue('--clx-speed'));
    if (!v) {
      var band = document.querySelector('.clientele-page');
      if (band) v = parseFloat(getComputedStyle(band).getPropertyValue('--clx-speed'));
    }
    return v > 0 ? v : 32;
  }

  var root    = document.querySelector('[data-clx-marquee]');
  var source  = document.getElementById('clienteleLogos');
  if (!root || !source) return;

  var motion  = window.matchMedia('(prefers-reduced-motion: reduce)');

  /* ── 0. the gold rule on each dark band draws itself in on arrival ── */
  (function accents() {
    var marks = Array.prototype.slice.call(document.querySelectorAll('.clx-band'));
    if (!marks.length) return;
    if (motion.matches || !('IntersectionObserver' in window)) {
      marks.forEach(function (el) { el.classList.add('is-lit'); });
      return;
    }
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        if (!e.isIntersecting) return;
        e.target.classList.add('is-lit');
        io.unobserve(e.target);
      });
    }, { threshold: 0.18, rootMargin: '0px 0px -8% 0px' });
    marks.forEach(function (el) { io.observe(el); });
  }());

  var rails   = Array.prototype.slice.call(root.querySelectorAll('[data-clx-rail]'));
  var logos   = Array.prototype.slice.call(source.querySelectorAll('li'));
  if (!rails.length || !logos.length) return;

  /* ── 1. deal the logos across the rails ──────────────────── */
  var groups = [];
  for (var r = 0; r < ROWS; r++) groups.push([]);
  logos.forEach(function (li, i) { groups[i % ROWS].push(li); });

  /* Fewer logos than rails' worth? Fall back to fewer, fuller rows so no
     row is left nearly empty. */
  var used = groups.filter(function (g) { return g.length; });
  rails.forEach(function (rail, i) {
    if (i >= used.length) rail.remove();
  });
  rails = rails.slice(0, used.length);

  function buildTile(li) {
    var img = li.querySelector('img');
    var tile = document.createElement('div');
    tile.className = 'clx-tile';
    var clone = img.cloneNode(true);
    clone.classList.add('clx-logo');
    tile.appendChild(clone);
    return tile;
  }

  rails.forEach(function (rail, i) {
    var track = document.createElement('div');
    track.className = 'clx-track';
    track.setAttribute('data-clx-track', '');

    var set = document.createElement('div');
    set.className = 'clx-set';
    used[i].forEach(function (li) { set.appendChild(buildTile(li)); });

    track.appendChild(set);
    rail.appendChild(track);
    rail._clxSet = set;
    rail._clxTrack = track;
  });

  /* ── 2. size the loop ────────────────────────────────────── */
  function measure() {
    var viewport = root.clientWidth || window.innerWidth;
    var need = viewport * MIN_COVER;

    // Snap the tile and the gap to whole pixels before measuring anything.
    // Their CSS widths are vw-based and land on fractions, which would make the
    // loop length fractional too — and a fractional transform is resampled by
    // the compositor, softening every logo for the entire animation. Rounding
    // here keeps the artwork crisp and makes the wrap land exactly on a pixel.
    root.style.removeProperty('--clx-tile-w');
    root.style.removeProperty('--clx-gap');
    if (motion.matches) {
      rails.forEach(function (rail) {
        var track = rail._clxTrack;
        while (track.children.length > 1) track.removeChild(track.lastChild);
      });
      return;
    }
    var probe = root.querySelector('.clx-tile');
    if (probe) {
      var cs = window.getComputedStyle(probe);
      var tw = Math.round(parseFloat(cs.width));
      var gp = Math.round(parseFloat(cs.marginRight));
      if (tw > 0) root.style.setProperty('--clx-tile-w', tw + 'px');
      if (gp >= 0) root.style.setProperty('--clx-gap', gp + 'px');
    }

    rails.forEach(function (rail) {
      var track = rail._clxTrack;
      var set = rail._clxSet;

      // strip previous clones, then re-clone to the width we now need
      while (track.children.length > 1) track.removeChild(track.lastChild);

      var setWidth = set.getBoundingClientRect().width;
      if (!setWidth) return;

      var copies = Math.max(2, Math.ceil(need / setWidth) + 1);
      copies = Math.min(copies, MAX_CLONES);
      for (var c = 1; c < copies; c++) {
        var dup = set.cloneNode(true);
        dup.setAttribute('aria-hidden', 'true');
        track.appendChild(dup);
      }

      // One cycle is the distance from one copy to the next — measured, not
      // assumed, so any gap or margin between copies is included exactly.
      // Translating by it lands copy 2 precisely where copy 1 began, which is
      // what makes the loop seamless: no seam, no jump, no blank gap.
      //
      // This must be measured at sub-pixel precision. Tile widths are vw-based
      // and land on fractions, so offsetLeft — which rounds to whole pixels —
      // would leave a fraction of a pixel unaccounted for and the rows would
      // visibly tick at each wrap. getBoundingClientRect keeps the fraction.
      var cycle = setWidth;
      if (track.children.length > 1) {
        var a = track.children[0].getBoundingClientRect();
        var b = track.children[1].getBoundingClientRect();
        if (b.left - a.left > 0) cycle = b.left - a.left;
      }
      if (!cycle) cycle = setWidth;
      // Browser zoom can still produce a fractional layout pitch. Keep the
      // measured distance so the next copy lands exactly at the loop seam.

      track.style.setProperty('--clx-cycle', cycle + 'px');
      track.style.setProperty('--clx-duration', (cycle / speed()).toFixed(4) + 's');
    });
  }

  /* ── 3. only animate while on screen ─────────────────────── */
  function setRunning(on) {
    root.classList.toggle('is-running', !!on);
  }

  function start() {
    measure();
    root.classList.add('is-built');
    source.classList.add('is-consumed');   // rows are built — hide the authoring list
    // let the browser paint the built rows before the entrance runs
    window.requestAnimationFrame(function () {
      window.requestAnimationFrame(function () { root.classList.add('is-ready'); });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }

  /* The rows move by transform, not by scrolling, so a logo that drifts into
     view is never "scrolled to" — the browser's own lazy-loading heuristic can
     leave it blank. So as soon as the section comes near, every logo is
     promoted to an eager load. Clones share their original's src, so each
     logo is fetched once and every later copy comes from cache. */
  var warmed = false;
  function warmLogos() {
    if (warmed) return;
    warmed = true;
    Array.prototype.forEach.call(root.querySelectorAll('img'), function (img) {
      if (img.complete && img.naturalWidth) return;
      img.loading = 'eager';
      img.src = img.src;                 // force the fetch, not just the hint
    });
  }

  if ('IntersectionObserver' in window) {
    new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        setRunning(e.isIntersecting);
        if (e.isIntersecting) warmLogos();
      });
    }, { rootMargin: '400px 0px' }).observe(root);
  } else {
    setRunning(true);
    warmLogos();
  }

  /* ── 4. re-measure on resize / font load ─────────────────── */
  var t;
  function remeasure() {
    window.clearTimeout(t);
    t = window.setTimeout(measure, 180);
  }
  window.addEventListener('resize', remeasure);
  window.addEventListener('orientationchange', remeasure);
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(remeasure);

  /* images arrive after layout — width can shift, so settle once they're in */
  window.addEventListener('load', measure);

  /* ── 5. reduced motion ───────────────────────────────────── */
  function syncMotion() {
    root.classList.toggle('is-static', motion.matches);
    measure();
  }
  syncMotion();
  if (motion.addEventListener) motion.addEventListener('change', syncMotion);
  else if (motion.addListener) motion.addListener(syncMotion);
}());
