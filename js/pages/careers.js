/* ============================================================
   GSS — Careers page
   careers.js
   ------------------------------------------------------------
   Renders the "Current Opportunities" board from careers-jobs.php.

   NOTHING IN THIS FILE NEEDS TO CHANGE WHEN OPENINGS CHANGE.
   Job openings are managed in Ceipal — see CEIPAL-INTEGRATION.md.

   Scope: this file is loaded only by career.html and touches
   only elements inside #opportunities. It does not modify any
   shared behaviour in script.js.
   ============================================================ */

(function () {
  'use strict';

  /* ── Settings a developer may tune (editors never need these) ── */
  var DATA_URL  = '../server/careers-jobs.php';  // PHP lives in server/; pages live in html/
  var PAGE_SIZE = 8;            // rows shown before "View All Openings"

  var root = document.getElementById('opportunities');
  if (!root) return;

  var listEl   = root.querySelector('[data-jobs-list]');
  var stateEl  = root.querySelector('[data-jobs-state]');
  var countEl  = root.querySelector('[data-jobs-count]');
  var moreEl   = root.querySelector('[data-jobs-more]');
  var formEl   = root.querySelector('[data-jobs-filters]');
  var qEl      = document.getElementById('role-q');
  var locEl    = document.getElementById('role-loc');
  var modelEl  = document.getElementById('role-model');
  var locList  = document.getElementById('role-loc-options');
  if (!listEl) return;

  /* modelEl (#role-model) is the trigger button of a custom .gss-select
     widget (button + listbox), not a native <select> — script.js's
     generic .gss-select init only wires click/keyboard handling to
     whatever <li> options exist at page load. Since the Work Model list
     has to be rebuilt from live job data after that init already ran,
     the rebuilt options need their own lightweight click handling here. */
  var modelRoot  = modelEl && modelEl.closest('.gss-select');
  var modelMenu  = modelRoot && modelRoot.querySelector('.gss-select__menu');
  var modelValue = modelRoot && modelRoot.querySelector('.gss-select__value');
  var modelInput = modelRoot && modelRoot.querySelector('.gss-select__input');

  var reduceMotion = window.matchMedia &&
    window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  var ALL      = [];    // every opening, normalised
  var VISIBLE  = [];    // openings passing the current filters
  var expanded = false; // "View All Openings" has been pressed
  var openId   = null;  // id of the row whose detail panel is open

  /* ── Small helpers ─────────────────────────────────────── */
  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }
  function slug(s) {
    return String(s || '').toLowerCase()
      .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 60);
  }
  function norm(s) { return String(s == null ? '' : s).toLowerCase().trim(); }

  /* Validates careers-jobs.php's optional structured description (headings,
     label/value lines, paragraphs, bullet lists — see htmlToBlocks() in
     careers-jobs.php) so a malformed or missing entry just falls back to
     the plain-text description instead of breaking the row. */
  function sanitizeBlocks(raw) {
    if (!Array.isArray(raw)) return [];
    var out = [];
    raw.forEach(function (b) {
      if (!b || typeof b !== 'object') return;
      if (b.type === 'heading' || b.type === 'para') {
        var text = String(b.text || '').trim();
        if (text) out.push({ type: b.type, text: text });
      } else if (b.type === 'label') {
        var label = String(b.label || '').trim();
        var value = String(b.value || '').trim();
        if (label && value) out.push({ type: 'label', label: label, value: value });
      } else if (b.type === 'list' && Array.isArray(b.items)) {
        var items = b.items.map(function (it) {
          return { text: String((it && it.text) || '').trim(), sub: !!(it && it.sub) };
        }).filter(function (it) { return it.text; });
        if (items.length) out.push({ type: 'list', items: items });
      }
    });
    return out;
  }

  function safeApplyLink(value) {
    var link = String(value || '').trim();
    if (!link) return 'contact.html';
    try {
      var url = new URL(link, window.location.href);
      return /^(https?:|mailto:)$/.test(url.protocol) ? url.href : 'contact.html';
    } catch (e) { return 'contact.html'; }
  }

  function debounce(fn, wait) {
    var t;
    return function () {
      var args = arguments, self = this;
      clearTimeout(t);
      t = setTimeout(function () { fn.apply(self, args); }, wait);
    };
  }

  /* ── 1. Load ───────────────────────────────────────────── */
  function load() {
    showSkeleton();

    if (!window.fetch) { showError(); return; }

    fetch(DATA_URL, { cache: 'no-store' })
      .then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
      })
      .then(function (data) {
        ALL = normalise(data);
        buildFilterOptions();
        bindFilters();
        apply();
        openFromHash();
        window.addEventListener('hashchange', openFromHash);
      })
      .catch(function () { showError(); });
  }

  /* Accepts either [ {...} ] or { "jobs": [ {...} ] }. */
  function normalise(data) {
    var raw = Array.isArray(data) ? data
            : (data && Array.isArray(data.jobs) ? data.jobs : []);
    var seen = {};

    return raw.filter(function (j) {
      return j && typeof j === 'object' && String(j.title || '').trim();
    }).map(function (j, i) {
      // anchor id: the title alone, widened only if two jobs share a title
      var base = slug(j.id || j.title) || ('role-' + i);
      var id = base;
      if (seen[id]) id = slug(base + '-' + (j.location || i)) || (base + '-' + i);
      while (seen[id]) { id = base + '-' + i; i++; }
      seen[id] = true;

      var skills = Array.isArray(j.skills) ? j.skills
                 : (j.skills ? String(j.skills).split(/\s*,\s*/) : []);

      return {
        id:          id,
        title:       String(j.title).trim(),
        location:    String(j.location || '').trim(),
        type:        String(j.type || '').trim(),
        experience:  String(j.experience || '').trim(),
        department:  String(j.department || '').trim(),
        description: String(j.description || '').trim(),
        descriptionBlocks: sanitizeBlocks(j.descriptionBlocks),
        skills:      skills.map(function (s) { return String(s).trim(); })
                           .filter(Boolean),
        applyLink:   safeApplyLink(j.applyLink),
        featured:    j.featured === true || j.featured === 'true',
        haystack:    norm([j.title, j.location, j.type, j.department,
                           j.experience, j.description,
                           skills.join(' ')].join(' '))
      };
    }).sort(function (a, b) {
      // featured openings first, otherwise the order of the file is kept
      return (b.featured ? 1 : 0) - (a.featured ? 1 : 0);
    });
  }

  /* ── 2. Filter controls, built from the data itself ────── */
  function buildFilterOptions() {
    if (modelRoot && modelMenu) rebuildModelOptions();
    if (locList) {
      locList.innerHTML = unique(ALL.map(function (j) { return j.location; }))
        .map(function (l) { return '<option value="' + esc(l) + '"></option>'; })
        .join('');
    }
  }

  function rebuildModelOptions() {
    var current = modelInput ? modelInput.value : '';
    var models = unique(ALL.map(function (j) { return j.type; }));

    modelMenu.innerHTML =
      '<li class="gss-select__opt' + (current ? '' : ' is-selected') + '" role="option" ' +
        'aria-selected="' + (current ? 'false' : 'true') + '" data-value="">Work model</li>' +
      models.map(function (m) {
        var sel = m === current;
        return '<li class="gss-select__opt' + (sel ? ' is-selected' : '') + '" role="option" ' +
          'aria-selected="' + (sel ? 'true' : 'false') + '" data-value="' + esc(m) + '">' + esc(m) + '</li>';
      }).join('');

    modelMenu.querySelectorAll('.gss-select__opt').forEach(function (opt, i) {
      opt.id = 'role-model-opt-' + i;
      opt.addEventListener('click', function (e) {
        e.stopPropagation();
        selectModelOption(opt);
        closeModelMenu();
      });
    });

    var selectedOpt = modelMenu.querySelector('.gss-select__opt.is-selected');
    if (modelValue && selectedOpt) modelValue.textContent = selectedOpt.textContent;
    modelRoot.classList.toggle('has-value', !!current);
  }

  function selectModelOption(opt) {
    modelMenu.querySelectorAll('.gss-select__opt').forEach(function (o) {
      o.classList.remove('is-selected'); o.setAttribute('aria-selected', 'false');
    });
    opt.classList.add('is-selected');
    opt.setAttribute('aria-selected', 'true');
    if (modelValue) modelValue.textContent = opt.textContent;
    if (modelInput) modelInput.value = opt.getAttribute('data-value') || '';
    modelRoot.classList.toggle('has-value', !!opt.getAttribute('data-value'));
    expanded = false;
    apply();
  }

  function closeModelMenu() {
    modelRoot.classList.remove('is-open');
    if (modelEl) modelEl.setAttribute('aria-expanded', 'false');
    window.setTimeout(function () {
      if (!modelRoot.classList.contains('is-open')) modelMenu.hidden = true;
    }, reduceMotion ? 0 : 220);
  }

  function resetModelSelect() {
    if (!modelInput) return;
    modelInput.value = '';
    var placeholder = modelMenu && modelMenu.querySelector('.gss-select__opt[data-value=""]');
    if (placeholder) selectModelOptionSilently(placeholder);
  }

  // same visual reset as selectModelOption, without re-triggering apply()
  // (clearFilters() calls apply() itself right after)
  function selectModelOptionSilently(opt) {
    modelMenu.querySelectorAll('.gss-select__opt').forEach(function (o) {
      o.classList.remove('is-selected'); o.setAttribute('aria-selected', 'false');
    });
    opt.classList.add('is-selected');
    opt.setAttribute('aria-selected', 'true');
    if (modelValue) modelValue.textContent = opt.textContent;
    modelRoot.classList.remove('has-value');
  }

  function unique(arr) {
    var out = [], seen = {};
    arr.forEach(function (v) {
      v = String(v || '').trim();
      if (v && !seen[norm(v)]) { seen[norm(v)] = 1; out.push(v); }
    });
    return out.sort(function (a, b) { return a.localeCompare(b); });
  }

  function bindFilters() {
    var run = debounce(function () { expanded = false; apply(); }, 160);
    [qEl, locEl].forEach(function (el) { if (el) el.addEventListener('input', run); });
    // the model dropdown applies its own filter immediately on selection
    // (see selectModelOption above) — no separate change listener needed
    if (formEl) formEl.addEventListener('submit', function (e) {
      e.preventDefault(); expanded = false; apply();
    });
  }

  function filters() {
    return {
      q:     norm(qEl && qEl.value),
      loc:   norm(locEl && locEl.value),
      model: norm(modelInput && modelInput.value)
    };
  }
  function isFiltered() {
    var f = filters();
    return !!(f.q || f.loc || f.model);
  }
  function clearFilters() {
    if (qEl) qEl.value = '';
    if (locEl) locEl.value = '';
    resetModelSelect();
    expanded = false;
    apply();
  }

  /* ── 3. Apply filters and paint ────────────────────────── */
  function apply() {
    var f = filters();
    VISIBLE = ALL.filter(function (j) {
      if (f.q && j.haystack.indexOf(f.q) === -1) return false;
      if (f.loc && norm(j.location).indexOf(f.loc) === -1) return false;
      if (f.model && norm(j.type) !== f.model) return false;
      return true;
    });
    render();
  }

  function render() {
    // with nothing posted at all, filters have nothing to act on
    if (formEl) formEl.hidden = !ALL.length;
    if (!ALL.length)     { showEmpty('none'); return; }
    if (!VISIBLE.length) { showEmpty('filtered'); return; }

    hideState();

    var shown = expanded ? VISIBLE : VISIBLE.slice(0, PAGE_SIZE);
    listEl.innerHTML = shown.map(row).join('');
    listEl.hidden = false;
    bindRows();
    animateIn();

    // count line
    if (countEl) {
      var word = VISIBLE.length === 1 ? 'opening' : 'openings';
      countEl.innerHTML = isFiltered()
        ? 'Showing ' + shown.length + ' of ' + VISIBLE.length + ' matching ' + word +
          ' <button type="button" class="car-clear" data-jobs-clear>Clear filters</button>'
        : 'Showing ' + shown.length + ' of ' + ALL.length + ' current ' + word + '.';
      bindClear();
    }

    // "View All Openings"
    if (moreEl) {
      var remaining = VISIBLE.length - shown.length;
      moreEl.hidden = remaining <= 0;
      var label = moreEl.querySelector('[data-jobs-more-label]');
      if (label) label.textContent = remaining > 0
        ? 'View ' + remaining + ' more ' + (remaining === 1 ? 'opening' : 'openings')
        : 'All openings shown';
    }
  }

  /* Rich description when careers-jobs.php could structure it, otherwise
     the plain-text fallback (unstyled, but still a description). */
  function renderDescription(j) {
    if (j.descriptionBlocks.length) {
      return '<div class="car-job__desc">' + j.descriptionBlocks.map(renderBlock).join('') + '</div>';
    }
    return j.description ? '<p class="car-job__desc">' + esc(j.description) + '</p>' : '';
  }

  function renderBlock(b) {
    if (b.type === 'heading') return '<p class="car-jd-h">' + esc(b.text) + '</p>';
    if (b.type === 'label') {
      return '<p class="car-jd-label"><span class="car-jd-label__k">' + esc(b.label) + '</span>' + esc(b.value) + '</p>';
    }
    if (b.type === 'list') {
      return '<ul class="car-jd-list">' + b.items.map(function (it) {
        return '<li' + (it.sub ? ' class="is-sub"' : '') + '>' + esc(it.text) + '</li>';
      }).join('') + '</ul>';
    }
    return '<p class="car-jd-p">' + esc(b.text) + '</p>';
  }

  function row(j) {
    var meta = [
      j.department ? ['Department', j.department] : null,
      j.experience ? ['Experience', j.experience] : null,
      j.type       ? ['Work model', j.type]       : null,
      j.location   ? ['Location',   j.location]   : null
    ].filter(Boolean);

    return '' +
    '<li class="car-job-item' + (j.featured ? ' is-featured' : '') + '" id="job-' + esc(j.id) + '">' +
      '<div class="car-job">' +
        '<span class="car-job__title">' + esc(j.title) +
          (j.featured ? ' <span class="car-job__flag">Featured</span>' : '') +
        '</span>' +
        '<span class="car-job__loc">' + esc(j.location || '—') + '</span>' +
        '<span class="car-job__model">' + esc(j.type || '—') + '</span>' +
        '<button class="car-job__cta" type="button" aria-expanded="false" ' +
                'aria-controls="panel-' + esc(j.id) + '" data-job-toggle="' + esc(j.id) + '">' +
          'View Role' +
          '<span class="sr-only">: ' + esc(j.title) + '</span>' +
          '<svg class="ico" aria-hidden="true"><use href="#i-arrow"/></svg>' +
        '</button>' +
      '</div>' +

      '<div class="car-job__panel" id="panel-' + esc(j.id) + '" data-open="false" ' +
           'role="region" aria-label="' + esc(j.title) + ' — role details">' +
        '<div class="car-job__panel-in">' +
          (meta.length ?
            '<dl class="car-job__meta">' + meta.map(function (m) {
              return '<div><dt>' + esc(m[0]) + '</dt><dd>' + esc(m[1]) + '</dd></div>';
            }).join('') + '</dl>' : '') +
          renderDescription(j) +
          (j.skills.length ?
            '<ul class="car-skills">' + j.skills.map(function (s) {
              return '<li>' + esc(s) + '</li>';
            }).join('') + '</ul>' : '') +
          '<div class="car-job__actions">' +
            '<a class="btn btn-primary btn-sm" href="' + esc(j.applyLink) + '">' +
              'Apply for this role<span class="sr-only">: ' + esc(j.title) + '</span> ' +
              '<svg class="ico" aria-hidden="true"><use href="#i-arrow"/></svg>' +
            '</a>' +
          '</div>' +
        '</div>' +
      '</div>' +
    '</li>';
  }

  /* ── 4. Row interaction ────────────────────────────────── */
  function bindRows() {
    listEl.querySelectorAll('[data-job-toggle]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        toggle(btn.getAttribute('data-job-toggle'));
      });
    });
    if (openId) setOpen(openId, true, false);
  }

  function toggle(id) {
    if (openId === id) { setOpen(id, false, true); openId = null; return; }
    if (openId) setOpen(openId, false, false);
    openId = id;
    setOpen(id, true, true);
  }

  function setOpen(id, open, focusable) {
    var item  = listEl.querySelector('#job-' + cssEsc(id));
    if (!item) return;
    var btn   = item.querySelector('[data-job-toggle]');
    var panel = item.querySelector('.car-job__panel');
    if (!btn || !panel) return;

    item.classList.toggle('is-open', open);
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    panel.setAttribute('data-open', open ? 'true' : 'false');
    if (open && focusable) {
      // keep the row in view when a tall panel opens near the fold
      requestAnimationFrame(function () {
        var r = item.getBoundingClientRect();
        if (r.bottom > window.innerHeight) {
          item.scrollIntoView({
            block: 'nearest',
            behavior: reduceMotion ? 'auto' : 'smooth'
          });
        }
      });
    }
  }

  function cssEsc(id) {
    return (window.CSS && CSS.escape) ? CSS.escape('job-' + id).replace(/^job-/, '')
                                      : String(id).replace(/[^\w-]/g, '');
  }

  /* Opens career.html#job-senior-power-bi-developer directly. */
  function openFromHash() {
    var h = (location.hash || '').replace(/^#job-/, '');
    if (!h || h === location.hash) return;
    var match = ALL.filter(function (j) { return j.id === h; })[0];
    if (!match) return;
    if (VISIBLE.indexOf(match) >= PAGE_SIZE) { expanded = true; render(); }
    openId = match.id;
    setOpen(match.id, true, false);
    var el = document.getElementById('job-' + match.id);
    if (el) el.scrollIntoView({ block: 'center', behavior: reduceMotion ? 'auto' : 'smooth' });
  }

  /* ── 5. Entry animation for freshly rendered rows ──────── */
  function animateIn() {
    if (reduceMotion) return;
    var rows = listEl.children;
    for (var i = 0; i < rows.length; i++) {
      (function (el, n) {
        el.classList.add('car-in');
        el.style.transitionDelay = Math.min(n, 7) * 55 + 'ms';
        requestAnimationFrame(function () {
          requestAnimationFrame(function () { el.classList.add('is-in'); });
        });
      }(rows[i], i));
    }
  }

  /* ── 6. States: loading, empty, error ──────────────────── */
  function hideState() { if (stateEl) { stateEl.hidden = true; stateEl.innerHTML = ''; } }

  function showSkeleton() {
    listEl.hidden = true;
    if (moreEl) moreEl.hidden = true;
    if (countEl) countEl.textContent = 'Loading current openings…';
    if (!stateEl) return;
    var rows = '';
    for (var i = 0; i < 4; i++) rows += '<div class="car-skel"><span></span><span></span><span></span></div>';
    stateEl.className = 'car-state car-state--loading';
    stateEl.innerHTML = rows;
    stateEl.hidden = false;
  }

  function showEmpty(kind) {
    listEl.hidden = true;
    listEl.innerHTML = '';
    if (moreEl) moreEl.hidden = true;
    if (countEl) countEl.textContent = kind === 'filtered'
      ? 'Showing 0 matching openings of ' + ALL.length + ' current ' + (ALL.length === 1 ? 'opening.' : 'openings.')
      : '0 current openings.';
    if (!stateEl) return;

    stateEl.className = 'car-state';
    stateEl.innerHTML = kind === 'filtered'
      ? icon() +
        '<h3 class="car-state__title">No roles match your search.</h3>' +
        '<p class="car-state__text">Try a broader search term, a different location, ' +
          'or clear the filters to see every current opening.</p>' +
        '<button type="button" class="btn btn-outline btn-sm" data-jobs-clear>Clear filters</button>'
      : icon() +
        '<h3 class="car-state__title">No current openings.</h3>' +
        '<p class="car-state__text">We are between postings right now. Share your profile below ' +
          'and we will keep your experience in mind when relevant work opens up.</p>' +
        '<a class="btn btn-outline btn-sm" href="#stay-connected">Share your profile ' +
          '<svg class="ico" aria-hidden="true"><use href="#i-arrow"/></svg></a>';
    stateEl.hidden = false;
    bindClear();
  }

  function showError() {
    listEl.hidden = true;
    if (moreEl) moreEl.hidden = true;
    if (countEl) countEl.textContent = '';
    if (!stateEl) return;
    stateEl.className = 'car-state';
    stateEl.innerHTML = icon() +
      '<h3 class="car-state__title">Openings are taking a moment to load.</h3>' +
      '<p class="car-state__text">Please refresh the page. If this keeps happening, ' +
        'you can still reach us directly and we will send you the current list.</p>' +
      '<a class="btn btn-outline btn-sm" href="contact.html">Talk to Us ' +
        '<svg class="ico" aria-hidden="true"><use href="#i-arrow"/></svg></a>';
    stateEl.hidden = false;
  }

  function icon() {
    return '<svg class="car-state__ico" viewBox="0 0 48 48" aria-hidden="true" fill="none" ' +
      'stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round">' +
      '<rect x="7" y="14" width="34" height="26" rx="3"/>' +
      '<path d="M18 14v-3a3 3 0 0 1 3-3h6a3 3 0 0 1 3 3v3"/>' +
      '<path d="M7 24h34"/><path d="M21 24v3h6v-3"/></svg>';
  }

  function bindClear() {
    root.querySelectorAll('[data-jobs-clear]').forEach(function (b) {
      b.addEventListener('click', function (e) { e.preventDefault(); clearFilters(); });
    });
  }

  /* ── 7. "View All Openings" ────────────────────────────── */
  if (moreEl) moreEl.addEventListener('click', function (e) {
    e.preventDefault();
    expanded = true;
    render();
  });

  /* ── go ────────────────────────────────────────────────── */
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', load);
  } else {
    load();
  }
}());
