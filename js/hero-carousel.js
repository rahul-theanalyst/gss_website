// GSS — homepage hero carousel: autoplay with dot/arrow controls, looping
// infinitely in both directions. Always keeps rotating (pausing only while
// the browser tab itself is hidden) — under prefers-reduced-motion the
// slides still rotate, they just hard-cut instead of crossfading (see the
// `.hero-slide { transition: none; }` reduced-motion rule in styles.css).
(function () {
  'use strict';

  var AUTOPLAY_MS = 4000;

  document.addEventListener('DOMContentLoaded', function () {
    var carousel = document.querySelector('.hero-carousel');
    if (!carousel) return;

    var slides = carousel.querySelectorAll('.hero-slide');
    var dots = carousel.querySelectorAll('.hero-carousel__dot');
    var prevBtn = carousel.querySelector('.hero-carousel__arrow--prev');
    var nextBtn = carousel.querySelector('.hero-carousel__arrow--next');
    if (slides.length < 2) return;

    var current = 0;
    for (var i = 0; i < slides.length; i++) {
      if (slides[i].classList.contains('is-active')) { current = i; break; }
    }

    var timer = null;

    function show(index) {
      var target = ((index % slides.length) + slides.length) % slides.length;
      if (target === current) return;

      slides[current].classList.remove('is-active');
      slides[current].setAttribute('aria-hidden', 'true');
      if (dots[current]) {
        dots[current].classList.remove('is-active');
        dots[current].setAttribute('aria-selected', 'false');
      }

      current = target;
      slides[current].classList.add('is-active');
      slides[current].setAttribute('aria-hidden', 'false');
      if (dots[current]) {
        dots[current].classList.add('is-active');
        dots[current].setAttribute('aria-selected', 'true');
      }
    }

    function goNext() { show(current + 1); }
    function goPrev() { show(current - 1); }

    function startAutoplay() {
      stopAutoplay();
      timer = window.setInterval(goNext, AUTOPLAY_MS);
    }
    function stopAutoplay() {
      if (timer) { window.clearInterval(timer); timer = null; }
    }

    dots.forEach(function (dot, i) {
      dot.addEventListener('click', function () { show(i); startAutoplay(); });
    });
    if (nextBtn) nextBtn.addEventListener('click', function () { goNext(); startAutoplay(); });
    if (prevBtn) prevBtn.addEventListener('click', function () { goPrev(); startAutoplay(); });

    // Only the controls themselves pause on hover/focus — the hero spans
    // almost the whole viewport, so pausing on hover anywhere in it made
    // the carousel look stalled any time the cursor happened to rest there.
    var controls = carousel.querySelectorAll('.hero-carousel__arrow, .hero-carousel__dot');
    controls.forEach(function (el) {
      el.addEventListener('mouseenter', stopAutoplay);
      el.addEventListener('mouseleave', startAutoplay);
      el.addEventListener('focus', stopAutoplay);
      el.addEventListener('blur', startAutoplay);
    });

    document.addEventListener('visibilitychange', function () {
      if (document.hidden) stopAutoplay(); else startAutoplay();
    });

    startAutoplay();
  });
})();
