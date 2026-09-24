(function () {
  'use strict';

  var form = document.querySelector('.lc-form');
  if (!form) return;

  var statusNote = form.querySelector('[data-submission-status]');
  var submitBtn = form.querySelector('button[type="submit"]');
  var originalBtnHtml = submitBtn ? submitBtn.innerHTML : 'Send Message';

  var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // Shows the message line under the form. Errors use role="alert" so
  // screen readers announce them immediately; successes stay a polite
  // status update. Scrolled into view only if it's off-screen (e.g. on a
  // phone, where the button can be near the bottom edge).
  function showStatus(kind, text) {
    if (!statusNote) return;
    statusNote.className = 'form-submission-note is-' + kind;
    statusNote.setAttribute('role', kind === 'error' ? 'alert' : 'status');
    statusNote.textContent = text;
    statusNote.hidden = false;
    var r = statusNote.getBoundingClientRect();
    if (r.top < 0 || r.bottom > window.innerHeight) {
      statusNote.scrollIntoView({ block: 'nearest', behavior: reduceMotion ? 'auto' : 'smooth' });
    }
  }

  var SUCCESS_MESSAGE = 'Thank you! Your message has been received successfully — we’ll be in touch soon.';
  var ERROR_MESSAGE = 'Something went wrong while submitting the form. Please try again.';

  // A plain (non-background) form post lands back here with ?status=...
  // in the URL: show the matching message, then drop the parameter so a
  // reload doesn't show it again.
  (function showStatusFromUrl() {
    var params = new URLSearchParams(window.location.search);
    var status = params.get('status');
    if (status !== 'success' && status !== 'error') return;
    showStatus(status, status === 'success' ? SUCCESS_MESSAGE : ERROR_MESSAGE);
    params.delete('status');
    var query = params.toString();
    try {
      history.replaceState(null, '', window.location.pathname + (query ? '?' + query : '') + window.location.hash);
    } catch (e) {}
  }());

  // Basic spam-timing signal: stamp when the form became available so the
  // server can tell a person filling it in apart from a script submitting
  // it instantly. Added via JS (not present in the HTML at all) so a
  // visitor with scripting disabled simply omits it — the server treats a
  // missing timestamp as "can't check" rather than "reject".
  var renderStamp = form.querySelector('input[name="form_rendered_at"]');
  if (!renderStamp) {
    renderStamp = document.createElement('input');
    renderStamp.type = 'hidden';
    renderStamp.name = 'form_rendered_at';
    form.appendChild(renderStamp);
  }
  function stampRenderTime() { renderStamp.value = String(Date.now()); }
  stampRenderTime();

  // form.reset() restores each .gss-select__input hidden field to its
  // default value, but the styled button label and option highlight are
  // plain DOM state that native reset knows nothing about — resync them
  // by hand so "Country code" / "How can we help?" don't keep showing
  // the value the visitor picked before submitting.
  function resyncCustomSelects() {
    form.querySelectorAll('.gss-select').forEach(function (root) {
      var input = root.querySelector('.gss-select__input');
      var valueEl = root.querySelector('.gss-select__value');
      var opts = root.querySelectorAll('.gss-select__opt');
      if (!input || !valueEl || !opts.length) return;

      var matched = null;
      opts.forEach(function (opt) {
        var isMatch = (opt.dataset.value || '') === input.value;
        opt.classList.toggle('is-selected', isMatch && !matched);
        opt.setAttribute('aria-selected', (isMatch && !matched) ? 'true' : 'false');
        if (isMatch && !matched) matched = opt;
      });
      if (matched) {
        valueEl.textContent = matched.dataset.short || matched.textContent;
        root.classList.toggle('has-value', !!matched.dataset.value);
      }
    });
  }

  // Keep this form from putting personal information in the URL if it is
  // ever submitted without a delivery endpoint configured.
  form.addEventListener('submit', function (event) {
    if (!form.getAttribute('action')) { event.preventDefault(); return; }

    event.preventDefault();

    // Prepare UI for in-flight submission
    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.classList.add('is-submitting');
      submitBtn.innerHTML = 'Sending&hellip;';
    }

    if (statusNote) {
      statusNote.hidden = true;
      statusNote.className = 'form-submission-note';
    }

    var action = form.getAttribute('action');
    var formData = new FormData(form);

    // Abort a request that never answers, so the button can't stay stuck
    // in its loading state.
    var controller = window.AbortController ? new AbortController() : null;
    var timer = controller ? setTimeout(function () { controller.abort(); }, 30000) : null;

    // The backend answers every submission with a redirect back to this
    // page carrying ?status=success or ?status=error; fetch follows it, so
    // the final URL tells us the outcome.
    fetch(action, {
      method: 'POST',
      body: formData,
      signal: controller ? controller.signal : undefined
    })
    .then(function (response) {
      var status = null;
      try { status = new URL(response.url).searchParams.get('status'); } catch (e) {}
      if (status === 'success') {
        // Reset first, then show the message. Order matters: legal.js
        // hides the status note on the form's reset event.
        form.reset();
        resyncCustomSelects();
        stampRenderTime();
        showStatus('success', SUCCESS_MESSAGE);
      } else {
        // Failure: keep everything the visitor entered so they can retry.
        showStatus('error', ERROR_MESSAGE);
      }
    })
    .catch(function (err) {
      // No usable response: offline, blocked, or timed out.
      showStatus('error', (err && err.name === 'AbortError')
        ? 'The request timed out. Please check your connection and try again.'
        : ERROR_MESSAGE);
    })
    .finally(function () {
      if (timer) clearTimeout(timer);
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.classList.remove('is-submitting');
        submitBtn.innerHTML = originalBtnHtml;
      }
    });
  });
}());
