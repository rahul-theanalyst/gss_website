// GSS — theme toggle, navbar scroll state, first-paint flash removal.
//
// The actual theme value that prevents a "flash of wrong theme" is set by
// a tiny INLINE script in <head> (see the snippet at the top of each HTML
// file) — that inline script runs before CSS/paint, this file just wires
// up interactivity once the DOM is ready.
(function () {
  'use strict';

  var STORAGE_KEY = 'gss-theme';
  var root = document.documentElement;

  function applyTheme(theme) {
    root.setAttribute('data-theme', theme);
    try { localStorage.setItem(STORAGE_KEY, theme); } catch (e) {}
    var toggles = document.querySelectorAll('.theme-toggle');
    for (var i = 0; i < toggles.length; i++) {
      toggles[i].setAttribute('aria-pressed', theme === 'dark');
      toggles[i].setAttribute('aria-label', theme === 'dark' ? 'Switch to light theme' : 'Switch to dark theme');
    }
  }

  function currentTheme() {
    return root.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
  }

  document.addEventListener('DOMContentLoaded', function () {
    // Reveal the page now that the correct theme is already applied
    // (removes the visibility:hidden guard from styles.css).
    root.classList.remove('theme-pending');

    // Toggle button(s)
    var toggles = document.querySelectorAll('.theme-toggle');
    toggles.forEach(function (btn) {
      btn.addEventListener('click', function () {
        applyTheme(currentTheme() === 'dark' ? 'light' : 'dark');
      });
    });
    applyTheme(currentTheme()); // sync aria state on load

    // Follow OS theme changes live, but only if the visitor hasn't
    // explicitly chosen a theme on this site before.
    var hasStoredChoice = false;
    try { hasStoredChoice = !!localStorage.getItem(STORAGE_KEY + '-explicit'); } catch (e) {}
    if (!hasStoredChoice && window.matchMedia) {
      window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function (e) {
        applyTheme(e.matches ? 'dark' : 'light');
      });
    }
    // Mark explicit choice whenever the user actually clicks the toggle.
    toggles.forEach(function (btn) {
      btn.addEventListener('click', function () {
        try { localStorage.setItem(STORAGE_KEY + '-explicit', '1'); } catch (e) {}
      });
    });

    // Navbar: solid background once scrolled past the hero image so text
    // under it stays readable everywhere, not just over the photo.
    var navbar = document.querySelector('.navbar');
    if (navbar) {
      var onScroll = function () {
        navbar.classList.toggle('is-scrolled', window.scrollY > 40);
      };
      window.addEventListener('scroll', onScroll, { passive: true });
      onScroll();
    }

    // Mobile hamburger menu: toggles the slide-in panel, manages the
    // aria-expanded/label state, closes on Escape, on an outside click
    // (the scrim), on link selection, or if the viewport is resized back
    // up to desktop width — and always returns focus to the toggle
    // button so keyboard users don't lose their place.
    var navToggle = document.querySelector('.navbar__toggle');
    var navMenu = document.querySelector('.navbar__menu');
    var navScrim = document.querySelector('.navbar__scrim');
    if (navToggle && navMenu) {
      var openNav = function () {
        navMenu.classList.add('is-open');
        navToggle.setAttribute('aria-expanded', 'true');
        navToggle.setAttribute('aria-label', 'Close menu');
        document.body.classList.add('nav-open');
        if (navScrim) {
          navScrim.hidden = false;
          requestAnimationFrame(function () { navScrim.classList.add('is-open'); });
        }
        var firstLink = navMenu.querySelector('a');
        if (firstLink) firstLink.focus();
      };
      var closeNav = function (opts) {
        navMenu.classList.remove('is-open');
        navToggle.setAttribute('aria-expanded', 'false');
        navToggle.setAttribute('aria-label', 'Open menu');
        document.body.classList.remove('nav-open');
        if (navScrim) {
          navScrim.classList.remove('is-open');
          navScrim.hidden = true;
        }
        if (!opts || opts.returnFocus !== false) navToggle.focus();
      };
      navToggle.addEventListener('click', function () {
        if (navMenu.classList.contains('is-open')) { closeNav(); } else { openNav(); }
      });
      if (navScrim) {
        navScrim.addEventListener('click', function () { closeNav(); });
      }
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && navMenu.classList.contains('is-open')) closeNav();
      });
      navMenu.querySelectorAll('a').forEach(function (a) {
        a.addEventListener('click', function () { closeNav({ returnFocus: false }); });
      });
      window.addEventListener('resize', function () {
        if (window.innerWidth > 860 && navMenu.classList.contains('is-open')) {
          closeNav({ returnFocus: false });
        }
      });
    }
  });
})();
