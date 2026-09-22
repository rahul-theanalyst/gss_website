(function () {
  'use strict';

  var form = document.querySelector('.lc-form');
  if (!form) return;

  var statusNote = form.querySelector('[data-submission-status]');
  var submitBtn = form.querySelector('button[type="submit"]');
  var originalBtnHtml = submitBtn ? submitBtn.innerHTML : 'Send Message';

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

    var formData = new FormData(form);

    fetch(form.getAttribute('action'), {
      method: 'POST',
      body: formData,
      headers: {
        'Accept': 'application/json'
      }
    })
    .then(function (response) {
      return response.json().then(function (data) {
        return { ok: response.ok, status: response.status, data: data };
      }).catch(function () {
        return { ok: response.ok, status: response.status, data: null };
      });
    })
    .then(function (res) {
      if (res.ok && res.data && res.data.success) {
        // Success: display message and reset form fields
        if (statusNote) {
          statusNote.className = 'form-submission-note is-success';
          statusNote.textContent = res.data.message || 'Thank you! Your message has been sent — we’ll be in touch soon.';
          statusNote.hidden = false;
        }

        form.reset();
        resyncCustomSelects();
      } else {
        // Failure: display error message while PRESERVING all user entered input values
        var errorMessage = (res.data && res.data.error)
          ? res.data.error
          : 'Unable to send your message right now (Error ' + res.status + '). Please check your details and try again.';

        if (statusNote) {
          statusNote.className = 'form-submission-note is-error';
          statusNote.textContent = errorMessage;
          statusNote.hidden = false;
        }
      }
    })
    .catch(function () {
      // Network failure: preserve all inputs and allow retry
      if (statusNote) {
        statusNote.className = 'form-submission-note is-error';
        statusNote.textContent = 'Network or connection error. Please check your connection and click Send Message to retry.';
        statusNote.hidden = false;
      }
    })
    .finally(function () {
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.classList.remove('is-submitting');
        submitBtn.innerHTML = originalBtnHtml;
      }
    });
  });
}());
