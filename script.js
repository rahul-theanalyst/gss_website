/* ============================================================
   GSS — Global Soft Systems
   script.js  —  vanilla, no dependencies
   ------------------------------------------------------------
   01. Helpers
   02. Theme toggle
   03. Sticky header
   04. Mobile overlay menu
   05. Hero carousel
   06. Scroll reveal
   07. Section-aware nav highlighting
   08. Footer year
   ============================================================ */
(function () {
  'use strict';

  /* ── 01. HELPERS ───────────────────────────────────────── */
  var $  = function (sel, ctx) { return (ctx || document).querySelector(sel); };
  var $$ = function (sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); };

  var motionQuery = window.matchMedia('(prefers-reduced-motion: reduce)');
  var reduceMotion = motionQuery.matches;

  function store(key, value) {
    try {
      if (value === undefined) return window.localStorage.getItem(key);
      window.localStorage.setItem(key, value);
    } catch (e) { /* storage blocked — degrade silently */ }
    return null;
  }


  /* ── 02. THEME TOGGLE ──────────────────────────────────── */
  (function theme() {
    var root = document.documentElement;
    var toggles = [$('#themeToggle'), $('#themeToggleMobile')].filter(Boolean);

    var saved = store('gss-theme');
    if (saved === 'dark' || saved === 'light') root.setAttribute('data-theme', saved);

    function sync() {
      var isDark = root.getAttribute('data-theme') === 'dark';
      toggles.forEach(function (btn) {
        btn.setAttribute('aria-pressed', String(isDark));
        btn.setAttribute('aria-label', isDark ? 'Switch to light theme' : 'Switch to dark theme');
      });
      var meta = document.querySelector('meta[name="theme-color"]');
      if (meta) meta.setAttribute('content', isDark ? '#0B1424' : '#0F2247');
    }

    toggles.forEach(function (btn) {
      btn.addEventListener('click', function () {
        var next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
        root.setAttribute('data-theme', next);
        store('gss-theme', next);
        sync();
      });
    });

    sync();
  }());


  /* ── 03. STICKY HEADER ─────────────────────────────────── */
  (function stickyHeader() {
    var header = $('#siteHeader');
    if (!header) return;
    var ticking = false;

    function update() {
      header.classList.toggle('is-stuck', window.scrollY > 24);
      ticking = false;
    }
    window.addEventListener('scroll', function () {
      if (!ticking) { ticking = true; window.requestAnimationFrame(update); }
    }, { passive: true });
    update();
  }());


  /* ── 04. MOBILE OVERLAY MENU ───────────────────────────── */
  (function mobileMenu() {
    var toggle = $('#menuToggle');
    var panel  = $('#mobileNav');
    if (!toggle || !panel) return;

    function open() {
      panel.hidden = false;
      // force a frame so the opacity transition runs
      window.requestAnimationFrame(function () { panel.classList.add('is-open'); });
      toggle.setAttribute('aria-expanded', 'true');
      toggle.setAttribute('aria-label', 'Close menu');
      document.body.classList.add('nav-open');
    }

    function close() {
      panel.classList.remove('is-open');
      toggle.setAttribute('aria-expanded', 'false');
      toggle.setAttribute('aria-label', 'Open menu');
      document.body.classList.remove('nav-open');
      window.setTimeout(function () {
        if (!panel.classList.contains('is-open')) panel.hidden = true;
      }, reduceMotion ? 0 : 300);
    }

    toggle.addEventListener('click', function () {
      if (toggle.getAttribute('aria-expanded') === 'true') { close(); } else { open(); }
    });

    $$('a', panel).forEach(function (link) { link.addEventListener('click', close); });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && toggle.getAttribute('aria-expanded') === 'true') {
        close();
        toggle.focus();
      }
    });

    // if the viewport grows past the breakpoint, make sure we're not stuck open
    window.matchMedia('(min-width: 1025px)').addEventListener('change', function (e) {
      if (e.matches && toggle.getAttribute('aria-expanded') === 'true') close();
    });
  }());


  /* ── 04b. SOCIAL / CONNECT DROPDOWN (desktop + tablet) ── */
  (function socialMenu() {
    var menu = $('#socialMenu');
    if (!menu) return;
    var btn   = $('#socialToggle', menu);
    var drop  = $('#socialDropdown', menu);
    var items = $$('.social-item', drop);
    if (!btn || !drop) return;

    var closeTimer = null;

    function isOpen() { return menu.classList.contains('is-open'); }

    function open() {
      window.clearTimeout(closeTimer);
      drop.hidden = false;
      window.requestAnimationFrame(function () { menu.classList.add('is-open'); });
      btn.setAttribute('aria-expanded', 'true');
      window.setTimeout(function () {
        document.addEventListener('click', onOutside);
      }, 0);
      document.addEventListener('keydown', onKey);
    }

    function close(returnFocus) {
      menu.classList.remove('is-open');
      btn.setAttribute('aria-expanded', 'false');
      document.removeEventListener('click', onOutside);
      document.removeEventListener('keydown', onKey);
      closeTimer = window.setTimeout(function () {
        if (!isOpen()) drop.hidden = true;
      }, reduceMotion ? 0 : 260);
      if (returnFocus) btn.focus();
    }

    function onOutside(e) {
      if (!menu.contains(e.target)) close(false);
    }

    function focusItem(i) {
      if (!items.length) return;
      var n = (i + items.length) % items.length;
      items[n].focus();
    }

    function onKey(e) {
      switch (e.key) {
        case 'Escape':
          e.preventDefault(); close(true); break;
        case 'ArrowDown':
          e.preventDefault(); focusItem(items.indexOf(document.activeElement) + 1); break;
        case 'ArrowUp':
          e.preventDefault(); focusItem(items.indexOf(document.activeElement) - 1); break;
        case 'Home':
          e.preventDefault(); focusItem(0); break;
        case 'End':
          e.preventDefault(); focusItem(items.length - 1); break;
        case 'Tab':
          // let focus move, then close if it left the menu
          window.setTimeout(function () {
            if (!menu.contains(document.activeElement)) close(false);
          }, 0);
          break;
      }
    }

    btn.addEventListener('click', function () {
      isOpen() ? close(false) : open();
    });
    btn.addEventListener('keydown', function (e) {
      if ((e.key === 'ArrowDown' || e.key === 'Enter' || e.key === ' ') && !isOpen()) {
        if (e.key === 'ArrowDown') e.preventDefault();
        open();
        if (e.key === 'ArrowDown') window.setTimeout(function () { focusItem(0); }, 30);
      }
    });
    items.forEach(function (it) {
      it.addEventListener('click', function () { close(false); });
    });

    // collapse if the viewport crosses into the mobile range
    window.matchMedia('(max-width: 767.98px)').addEventListener('change', function (e) {
      if (e.matches && isOpen()) close(false);
    });
  }());


  /* ── 04c. "CONNECT WITH US" DISCLOSURE (mobile overlay) ── */
  (function mobileConnect() {
    var box = $('#mobileConnect');
    if (!box) return;
    var toggle = $('#mobileConnectToggle', box);
    var panel  = $('#mobileConnectPanel', box);
    if (!toggle || !panel) return;

    toggle.addEventListener('click', function () {
      var collapsed = box.classList.toggle('is-collapsed');
      toggle.setAttribute('aria-expanded', String(!collapsed));
    });
  }());


  /* ── 05. HERO CAROUSEL ─────────────────────────────────── */
  (function heroCarousel() {
    var region = $('#hero');
    var slides = $$('.hero-slide', region);
    var dots   = $$('.dot', $('#heroDots'));
    var live   = $('#heroLive');
    if (!region || slides.length < 2) return;

    var INTERVAL = 6500;
    var index = 0;
    var timer = null;
    var paused = false;

    function setSlide(next, announce) {
      if (next === index) return;
      index = next;

      slides.forEach(function (slide, i) {
        var active = i === index;
        slide.classList.toggle('is-active', active);
        slide.setAttribute('aria-hidden', String(!active));
        // hidden slides must never hold keyboard focus
        $$('a, button', slide).forEach(function (el) {
          if (active) { el.removeAttribute('tabindex'); }
          else { el.setAttribute('tabindex', '-1'); }
        });
      });

      dots.forEach(function (dot, i) {
        dot.classList.toggle('is-active', i === index);
        dot.setAttribute('aria-selected', String(i === index));
      });

      if (announce && live) {
        var heading = slides[index].querySelector('.hero-title');
        live.textContent = 'Slide ' + (index + 1) + ' of ' + slides.length +
          (heading ? ': ' + heading.textContent.replace(/\s+/g, ' ').trim() : '');
      }
    }

    function advance() { setSlide((index + 1) % slides.length); }

    function start() {
      if (reduceMotion || paused || timer) return;
      timer = window.setInterval(advance, INTERVAL);
    }
    function stop() {
      if (timer) { window.clearInterval(timer); timer = null; }
    }
    function restart() { stop(); start(); }

    // manual control — never steals focus
    dots.forEach(function (dot) {
      dot.addEventListener('click', function () {
        setSlide(parseInt(dot.getAttribute('data-goto'), 10), true);
        restart();
      });
    });

    // arrow-key support while the dots have focus
    $('#heroDots').addEventListener('keydown', function (e) {
      var delta = e.key === 'ArrowRight' ? 1 : e.key === 'ArrowLeft' ? -1 : 0;
      if (!delta) return;
      e.preventDefault();
      var next = (index + delta + slides.length) % slides.length;
      setSlide(next, true);
      dots[next].focus();
      restart();
    });

    // Pause while the visitor is actually interacting with the carousel.
    //
    // NB: this deliberately does NOT listen on the whole hero. The hero is a
    // full-viewport section, so hovering it is the resting state for most
    // pointers — binding the pause there stops the carousel permanently for
    // anyone whose cursor happens to sit over the page. Only the controls and
    // the active call-to-action count as "interacting".
    var hotspots = [$('#heroDots')].concat($$('.hero-slide a.btn', region));

    hotspots.forEach(function (el) {
      if (!el) return;
      el.addEventListener('mouseenter', function () { paused = true; stop(); });
      el.addEventListener('mouseleave', function () {
        if (region.contains(document.activeElement)) return;
        paused = false; start();
      });
    });

    // keyboard focus anywhere in the hero also holds the carousel still
    region.addEventListener('focusin', function () { paused = true; stop(); });
    region.addEventListener('focusout', function () {
      window.setTimeout(function () {
        if (region.contains(document.activeElement)) return;
        paused = false; start();
      }, 0);
    });

    // pause when the tab isn't visible
    document.addEventListener('visibilitychange', function () {
      if (document.visibilityState === 'hidden') { stop(); }
      else if (!paused) { start(); }
    });

    // react live to a reduced-motion preference change
    motionQuery.addEventListener('change', function (e) {
      reduceMotion = e.matches;
      if (reduceMotion) { stop(); } else { start(); }
    });

    // seed initial tabindex state, then run
    slides.forEach(function (slide, i) {
      if (i === index) return;
      $$('a, button', slide).forEach(function (el) { el.setAttribute('tabindex', '-1'); });
    });

    start();
  }());


  /* ── 06. SCROLL REVEAL ─────────────────────────────────── */
  (function reveal() {
    var items = $$('.reveal');
    if (!items.length) return;

    if (reduceMotion || !('IntersectionObserver' in window)) {
      items.forEach(function (el) { el.classList.add('is-in'); });
      return;
    }

    var pending = items.slice();

    function show(el) {
      if (el.classList.contains('is-in')) return;
      var siblings = el.parentElement ? $$('.reveal', el.parentElement) : [];
      var order = Math.max(0, siblings.indexOf(el));
      el.style.transitionDelay = Math.min(order, 6) * 90 + 'ms';
      el.classList.add('is-in');
      observer.unobserve(el);
    }

    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) show(entry.target);
      });
    }, { rootMargin: '0px 0px -12% 0px', threshold: 0.12 });

    items.forEach(function (el) { observer.observe(el); });

    // Safety net: very fast or programmatic scrolling can outrun the observer's
    // delivery, so sweep on scroll for anything that is already past the fold.
    var sweeping = false;
    function sweep() {
      sweeping = false;
      var limit = window.innerHeight * 0.92;
      pending = pending.filter(function (el) {
        if (el.classList.contains('is-in')) return false;
        if (el.getBoundingClientRect().top < limit) { show(el); return false; }
        return true;
      });
      if (!pending.length) window.removeEventListener('scroll', onScroll);
    }
    function onScroll() {
      if (!sweeping) { sweeping = true; window.requestAnimationFrame(sweep); }
    }
    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('resize', onScroll, { passive: true });
    onScroll();
  }());


  /* ── 07. SECTION-AWARE NAV HIGHLIGHTING ────────────────── */
  (function navSpy() {
    var links = $$('.nav-desktop a[href^="#"]');
    if (!links.length || !('IntersectionObserver' in window)) return;

    var map = {};
    var targets = [];
    links.forEach(function (link) {
      var id = link.getAttribute('href').slice(1);
      var section = document.getElementById(id);
      if (!section) return;
      map[id] = link;
      targets.push(section);
    });

    var spy = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;
        links.forEach(function (l) { l.classList.remove('is-current'); l.removeAttribute('aria-current'); });
        var link = map[entry.target.id];
        if (link) { link.classList.add('is-current'); link.setAttribute('aria-current', 'true'); }
      });
    }, { rootMargin: '-45% 0px -50% 0px' });

    targets.forEach(function (t) { spy.observe(t); });
  }());


  /* ── 08. FOOTER YEAR ───────────────────────────────────── */
  (function year() {
    var el = $('#year');
    if (el) el.textContent = String(new Date().getFullYear());
  }());


  /* ── 09. PAGE TRANSITION ───────────────────────────────── */
  /* Leaves a page by fading the content out (the same motion the hero slides
     use), then navigates. The entrance — crossfade + staggered rise — is pure
     CSS in section 18b, so it works even without this script. */
  (function pageTransition() {
    var main = $('#main');
    if (!main) return;

    var header = $('.header-inner');
    var sections = $$('#main > section');
    var leaving = false;

    var ease = getComputedStyle(document.documentElement)
                 .getPropertyValue('--ease').trim() || 'ease';

    function clearAnim(el) { if (el) el.style.animation = 'none'; }

    function settle() {                    // guarantee the page is visible once it has arrived
      if (leaving) return;
      clearAnim(main);
      clearAnim(header);
      sections.forEach(clearAnim);
    }

    function leave(url) {
      if (leaving) return;
      leaving = true;

      if (reduceMotion) { window.location.href = url; return; }

      // hand opacity control back from the entrance animations, then fade out
      clearAnim(main);
      sections.forEach(function (s) { s.style.animation = 'none'; });
      void main.offsetWidth;
      main.style.transition = 'opacity .42s ' + ease + ', transform .42s ' + ease;
      main.style.opacity = '0';
      main.style.transform = 'translateY(-10px)';

      var done = false;
      function go() { if (done) return; done = true; window.location.href = url; }
      main.addEventListener('transitionend', function (e) {
        if (e.target === main && e.propertyName === 'opacity') go();
      });
      window.setTimeout(go, 560);          // failsafe if transitionend never fires
    }

    document.addEventListener('click', function (e) {
      if (e.defaultPrevented || e.button !== 0 ||
          e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;

      var a = e.target.closest ? e.target.closest('a[href]') : null;
      if (!a) return;
      if (a.target && a.target !== '_self') return;
      if (a.hasAttribute('download')) return;

      var href = a.getAttribute('href');
      if (!href || /^(#|mailto:|tel:|javascript:)/i.test(href)) return;

      var dest;
      try { dest = new URL(href, window.location.href); } catch (err) { return; }
      if (dest.origin !== window.location.origin) return;
      // same page (a bare hash or query change) — let the browser handle it
      if (dest.pathname === window.location.pathname && dest.search === window.location.search) return;

      e.preventDefault();
      leave(dest.href);
    });

    // coming back through the bfcache — clear the fade-out and replay the entrance
    window.addEventListener('pageshow', function (e) {
      if (!e.persisted) return;
      leaving = false;
      [main, header].concat(sections).forEach(function (el) {
        if (!el) return;
        el.style.animation = '';
        el.style.transition = '';
        el.style.opacity = '';
        el.style.transform = '';
      });
      void main.offsetWidth;
    });

    // safety net: never leave the page stuck mid-fade-in
    window.addEventListener('load', function () {
      window.setTimeout(settle, 1500);
    });
  }());

}());
