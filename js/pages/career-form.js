(function () {
  'use strict';

  var form = document.querySelector('.car-form');
  if (!form) return;

  var fileInput = document.getElementById('f-resume');
  var fileNameDisplay = document.getElementById('f-resume-name');
  var statusNote = form.querySelector('[data-submission-status]');
  var submitBtn = form.querySelector('.car-form__submit');
  var originalBtnHtml = submitBtn ? submitBtn.innerHTML : 'Submit Profile';

  var MAX_FILE_BYTES = 10 * 1024 * 1024; // 10 MB

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

  var SUCCESS_MESSAGE = 'Thank you! Your profile and résumé have been received successfully.';
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

  // 1. File Input Validation & Label update
  if (fileInput && fileNameDisplay) {
    fileInput.addEventListener('change', function () {
      if (!fileInput.files || !fileInput.files.length) {
        fileNameDisplay.textContent = 'Upload resume (PDF only)';
        return;
      }

      var file = fileInput.files[0];
      var nameLower = file.name.toLowerCase();

      // Enforce PDF extension
      if (!nameLower.endsWith('.pdf')) {
        fileInput.value = '';
        fileNameDisplay.textContent = 'Upload resume (PDF only)';
        showStatus('error', 'Invalid file type. Please select a valid PDF document (.pdf).');
        return;
      }

      // Enforce 10 MB size limit
      if (file.size > MAX_FILE_BYTES) {
        fileInput.value = '';
        fileNameDisplay.textContent = 'Upload resume (PDF only)';
        showStatus('error', 'The selected file is too large (' + (file.size / (1024 * 1024)).toFixed(1) + ' MB). Maximum allowed size is 10 MB.');
        return;
      }

      // Valid PDF selected
      var sizeFormatted = file.size > 1024 * 1024
        ? (file.size / (1024 * 1024)).toFixed(1) + ' MB'
        : (file.size / 1024).toFixed(0) + ' KB';

      fileNameDisplay.textContent = file.name + ' (' + sizeFormatted + ')';
      if (statusNote && statusNote.classList.contains('is-error')) {
        statusNote.hidden = true;
      }
    });
  }

  // 2. Prevent search form submission reload
  document.querySelectorAll('.car-search').forEach(function (f) {
    f.addEventListener('submit', function (e) { e.preventDefault(); });
  });

  // 3. Handle Form Submission via AJAX (preserving inputs on failure)
  form.addEventListener('submit', function (event) {
    event.preventDefault();

    var action = form.getAttribute('action') || '../server/careers-submit.php';

    // Client-side required fields check
    if (!form.checkValidity()) {
      form.reportValidity();
      return;
    }

    // Additional PDF verification
    if (!fileInput || !fileInput.files || !fileInput.files.length) {
      showStatus('error', 'Please select and attach your resume in PDF format before submitting.');
      if (fileInput) fileInput.focus();
      return;
    }

    var selectedFile = fileInput.files[0];
    if (!selectedFile.name.toLowerCase().endsWith('.pdf')) {
      showStatus('error', 'Only PDF documents (.pdf) are accepted for the resume attachment.');
      return;
    }

    // Prepare UI for in-flight submission
    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.classList.add('is-submitting');
      submitBtn.innerHTML = 'Submitting Profile&hellip;';
    }

    if (statusNote) {
      statusNote.hidden = true;
      statusNote.className = 'form-submission-note';
    }

    var formData = new FormData(form);

    // Abort a request that never answers, so the button can't stay stuck
    // in its loading state.
    var controller = window.AbortController ? new AbortController() : null;
    var timer = controller ? setTimeout(function () { controller.abort(); }, 90000) : null;

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
        stampRenderTime();
        if (fileNameDisplay) {
          fileNameDisplay.textContent = 'Upload resume (PDF only)';
        }
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
