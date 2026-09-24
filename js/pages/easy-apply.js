/* ============================================================
   GSS — Careers page
   easy-apply.js
   ------------------------------------------------------------
   The "Easy Apply" dialog. Clicking a role's Easy Apply button
   (rendered by careers.js) opens a dialog whose fields, required
   flags and dropdown options come straight from that job's
   application form in Ceipal, via server/careers-apply.php — so
   when HR changes the form in Ceipal, this form follows.

   The application is submitted to careers-apply.php, which
   validates it again and passes it (with the résumé) to Ceipal.
   ============================================================ */

(function () {
  'use strict';

  var API = '../server/careers-apply.php';
  var MAX_FILE = 10 * 1024 * 1024;           // matches the server
  var DOC_TYPES = /\.(pdf|docx?)$/i;

  if (!window.fetch || !window.FormData) return;

  var dialog, titleEl, codeEl, bodyEl, formEl, statusEl, submitBtn, lastTrigger;
  var currentJob = null;
  var loadToken = 0;

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  /* ── 1. Dialog shell (built once) ──────────────────────── */
  function build() {
    if (dialog) return;
    dialog = document.createElement('dialog');
    dialog.className = 'ea-dialog';
    dialog.setAttribute('aria-labelledby', 'ea-title');
    dialog.innerHTML = '' +
      '<div class="ea-dialog__header">' +
        '<p class="ea-dialog__eyebrow">Easy Apply</p>' +
        '<h2 class="ea-dialog__title" id="ea-title" tabindex="-1"></h2>' +
        '<p class="ea-dialog__code" data-ea-code hidden></p>' +
        '<button class="ea-dialog__close" type="button" aria-label="Close">&times;</button>' +
      '</div>' +
      '<div class="ea-dialog__body" data-ea-body></div>';
    document.body.appendChild(dialog);

    titleEl = dialog.querySelector('#ea-title');
    codeEl  = dialog.querySelector('[data-ea-code]');
    bodyEl  = dialog.querySelector('[data-ea-body]');

    dialog.querySelector('.ea-dialog__close').addEventListener('click', close);
    dialog.addEventListener('cancel', function (e) { e.preventDefault(); close(); });
    // click on the backdrop (outside the dialog box) closes it
    dialog.addEventListener('click', function (e) {
      if (e.target !== dialog) return;
      var r = dialog.getBoundingClientRect();
      var inside = e.clientX >= r.left && e.clientX <= r.right && e.clientY >= r.top && e.clientY <= r.bottom;
      if (!inside) close();
    });
  }

  function open(jobId, jobTitle, trigger) {
    build();
    lastTrigger = trigger || null;
    currentJob = jobId;
    titleEl.textContent = jobTitle || 'Apply for this role';
    codeEl.hidden = true;
    bodyEl.innerHTML = '<p class="ea-loading" role="status">Loading the application form&hellip;</p>';
    document.documentElement.classList.add('legal-is-open');   // shared scroll lock
    if (typeof dialog.showModal === 'function') dialog.showModal(); else dialog.setAttribute('open', '');
    titleEl.focus();
    loadForm(jobId);
  }

  function close() {
    if (!dialog) return;
    loadToken++;                                   // drop any in-flight load
    if (typeof dialog.close === 'function') dialog.close(); else dialog.removeAttribute('open');
    document.documentElement.classList.remove('legal-is-open');
    if (lastTrigger && document.body.contains(lastTrigger)) lastTrigger.focus();
  }

  /* ── 2. Load the job's form from Ceipal (via PHP) ──────── */
  function loadForm(jobId) {
    var token = ++loadToken;
    fetch(API + '?action=form&job=' + encodeURIComponent(jobId), { cache: 'no-store' })
      .then(function (r) { return r.json().catch(function () { return {}; }); })
      .then(function (data) {
        if (token !== loadToken) return;
        if (!data || !data.ok || !data.form) throw new Error((data && data.message) || '');
        render(data.form);
      })
      .catch(function (err) {
        if (token !== loadToken) return;
        bodyEl.innerHTML = '<p class="ea-note is-error" role="alert">' +
          esc(err && err.message ? err.message : 'The application form couldn’t be loaded. Please try again shortly.') +
          '</p>';
      });
  }

  /* ── 3. Render ─────────────────────────────────────────── */
  var SECTION_TITLES = {
    standard_fields:   'Your details',
    custom_fields:     'Additional details',
    submission_fields: 'Preferences',
    document_fields:   'Résumé'
  };

  // Ceipal's field order, paired up into two-column rows where it reads well
  var FULL_WIDTH = { file: true, textarea: true, multi: true };

  function render(form) {
    if (form.title) titleEl.textContent = form.title;
    if (form.jobCode) { codeEl.textContent = 'Job code ' + form.jobCode; codeEl.hidden = false; }

    var html = '' +
      '<form class="ea-form" novalidate>' +
        // honeypot + render stamp, checked by server/lib/spam-guard.php
        '<div class="ea-hp" aria-hidden="true"><label>Leave this field blank' +
          '<input type="text" name="hp_website" tabindex="-1" autocomplete="off"></label></div>' +
        '<input type="hidden" name="form_rendered_at" value="' + Date.now() + '">' +
        '<input type="hidden" name="job" value="' + esc(form.jobId) + '">' +
        '<input type="hidden" name="time_zone" value="' + esc(timeZone()) + '">';

    var index = 0;
    ['standard_fields', 'custom_fields', 'submission_fields', 'document_fields'].forEach(function (group) {
      var fields = (form.sections && form.sections[group]) || [];
      if (!fields.length) return;
      html += '<fieldset class="ea-section"><legend class="ea-section__title">' + esc(SECTION_TITLES[group] || '') + '</legend>' +
              '<div class="ea-grid">';
      fields.forEach(function (f) {
        html += fieldHtml(f, 'f_' + index++);
      });
      html += '</div></fieldset>';
    });

    if (form.terms) {
      html += '' +
        '<div class="ea-terms">' +
          '<details class="ea-terms__text"><summary>Terms and conditions</summary><p>' + esc(form.terms) + '</p></details>' +
          '<label class="ea-check"><input type="checkbox" name="terms" value="1"> I accept the terms and conditions</label>' +
          '<p class="ea-error" data-error-for="terms"></p>' +
        '</div>';
    }

    html += '' +
        '<p class="form-legal-note">By applying, you agree to our <button type="button" data-legal-open="privacy" ' +
          'aria-haspopup="dialog">Privacy Policy</button>. Your application is sent to our recruiting system (Ceipal).</p>' +
        '<div class="ea-actions">' +
          '<button class="btn btn-primary btn-sm ea-submit" type="submit">Submit Application ' +
            '<svg class="ico" aria-hidden="true"><use href="#i-arrow"/></svg></button>' +
          '<button class="ea-cancel" type="button">Cancel</button>' +
        '</div>' +
        '<p class="ea-note" data-ea-status role="status" hidden></p>' +
      '</form>';

    bodyEl.innerHTML = html;
    formEl    = bodyEl.querySelector('.ea-form');
    statusEl  = bodyEl.querySelector('[data-ea-status]');
    submitBtn = bodyEl.querySelector('.ea-submit');
    bind(form);
  }

  function fieldHtml(f, alias) {
    var id = 'ea-' + alias;
    var req = f.required ? ' required aria-required="true"' : '';
    var star = f.required ? ' <span class="ea-req" aria-hidden="true">*</span>' : '';
    var wide = FULL_WIDTH[f.kind] ? ' ea-field--wide' : '';
    var label = '<label class="ea-label" for="' + id + '">' + esc(f.label) + star + '</label>';
    var error = '<p class="ea-error" id="' + id + '-err" data-error-for="' + alias + '"></p>';
    var described = ' aria-describedby="' + id + '-err"';
    var control;

    switch (f.kind) {
      case 'country':
      case 'select':
        control = '<select id="' + id + '" name="' + alias + '"' + req + described +
                  (f.kind === 'country' ? ' data-country' : '') + '>' +
                  '<option value="">Select</option>' +
                  (f.options || []).map(function (o) {
                    return '<option value="' + esc(o.value) + '"' + (o['default'] ? ' selected' : '') + '>' + esc(o.label) + '</option>';
                  }).join('') + '</select>';
        break;
      case 'state':
        control = '<select id="' + id + '" name="' + alias + '"' + req + described + ' data-state disabled>' +
                  '<option value="">Select a country first</option></select>';
        break;
      case 'multi':
        control = '<div class="ea-multi" role="group" aria-labelledby="' + id + '-lbl">' +
                  (f.options || []).map(function (o, i) {
                    return '<label class="ea-check"><input type="checkbox" name="' + alias + '[]" value="' + esc(o.value) + '"> ' + esc(o.label) + '</label>';
                  }).join('') + '</div>';
        label = '<p class="ea-label" id="' + id + '-lbl">' + esc(f.label) + star + '</p>';
        break;
      case 'textarea':
        control = '<textarea id="' + id + '" name="' + alias + '" rows="4" maxlength="4000"' + req + described + '></textarea>';
        break;
      case 'date':
        control = '<input type="date" id="' + id + '" name="' + alias + '"' + req + described + '>';
        break;
      case 'file':
        control = '' +
          '<div class="car-file ea-file">' +
            '<span class="car-file__name" data-file-name>No file selected</span>' +
            '<label class="car-file__btn" for="' + id + '">Select File</label>' +
            '<input type="file" id="' + id + '" name="' + alias + '"' + req + described +
              ' accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document">' +
          '</div><p class="ea-hint">PDF or Word document, up to 10 MB.</p>';
        break;
      default:
        var type = 'text', extra = ' maxlength="250"';
        if (f.format === 'text_email') { type = 'email'; extra += ' autocomplete="email"'; }
        else if (f.format === 'text_mobile' || f.format === 'text_phone') { type = 'tel'; extra += ' autocomplete="tel"'; }
        else if (f.format === 'text_url') { type = 'url'; }
        else if (f.format === 'text_number') { extra += ' inputmode="decimal"'; }
        if (f.key === 'firstname') extra += ' autocomplete="given-name"';
        if (f.key === 'lastname')  extra += ' autocomplete="family-name"';
        if (f.key === 'city')      extra += ' autocomplete="address-level2"';
        control = '<input type="' + type + '" id="' + id + '" name="' + alias + '"' + req + described + extra + '>';
    }

    return '<div class="ea-field' + wide + '" data-field="' + alias + '" data-kind="' + esc(f.kind) + '"' +
           (f.format ? ' data-format="' + esc(f.format) + '"' : '') +
           (f.required ? ' data-required' : '') + ' data-label="' + esc(f.label) + '">' +
           label + control + error + '</div>';
  }

  function timeZone() {
    try { return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC'; } catch (e) { return 'UTC'; }
  }

  /* ── 4. Behaviour ──────────────────────────────────────── */
  function bind(form) {
    formEl.querySelector('.ea-cancel').addEventListener('click', close);

    // file name display
    formEl.querySelectorAll('input[type="file"]').forEach(function (input) {
      input.addEventListener('change', function () {
        var name = input.files && input.files[0] ? input.files[0].name : 'No file selected';
        input.closest('.ea-file').querySelector('[data-file-name]').textContent = name;
        clearError(input.closest('.ea-field'));
      });
    });

    // country → state list
    var country = formEl.querySelector('select[data-country]');
    var state = formEl.querySelector('select[data-state]');
    if (country && state) {
      country.addEventListener('change', function () { loadStates(country.value, state); });
      if (country.value) loadStates(country.value, state);
    }

    // clear a field's error as soon as it's edited
    formEl.addEventListener('input', function (e) {
      var field = e.target.closest && e.target.closest('.ea-field');
      if (field) clearError(field);
    });
    formEl.addEventListener('change', function (e) {
      var field = e.target.closest && e.target.closest('.ea-field');
      if (field) clearError(field);
    });

    formEl.addEventListener('submit', function (e) {
      e.preventDefault();
      submit();
    });
  }

  // Ceipal relabels State for a few countries (UK counties, Canadian provinces)
  var STATE_LABELS = { '826': 'County', '124': 'Province' };

  function loadStates(countryId, select) {
    var field = select.closest('.ea-field');
    var label = field.querySelector('.ea-label');
    if (!label.hasAttribute('data-base')) label.setAttribute('data-base', label.innerHTML);
    var base = label.getAttribute('data-base');
    label.innerHTML = STATE_LABELS[countryId]
      ? esc(STATE_LABELS[countryId]) + (field.hasAttribute('data-required') ? ' <span class="ea-req" aria-hidden="true">*</span>' : '')
      : base;

    select.innerHTML = '<option value="">' + (countryId ? 'Loading&hellip;' : 'Select a country first') + '</option>';
    select.disabled = true;
    if (!countryId) return;

    fetch(API + '?action=states&country=' + encodeURIComponent(countryId), { cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data || !data.ok) throw new Error();
        var states = data.states || [];
        select.innerHTML = '<option value="">Select</option>' + states.map(function (s) {
          return '<option value="' + esc(s.value) + '">' + esc(s.label) + '</option>';
        }).join('');
        select.disabled = false;
        // a country with no subdivisions can't make State required
        if (!states.length) {
          select.innerHTML = '<option value="">Not applicable</option>';
          field.removeAttribute('data-required');
        } else if (select.hasAttribute('required')) {
          field.setAttribute('data-required', '');
        }
      })
      .catch(function () {
        select.innerHTML = '<option value="">Couldn’t load the list — change country to retry</option>';
      });
  }

  /* ── 5. Validation (the server checks everything again) ── */
  var EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
  var PHONE = /^[+\d\s().\/-]+$/;                 // server normalises to +digits for Ceipal

  function showError(field, message) {
    if (!field) return;
    field.classList.add('has-error');
    var slot = field.querySelector('.ea-error');
    if (slot) slot.textContent = message;
    var control = field.querySelector('input:not([type="checkbox"]), select, textarea');
    if (control) control.setAttribute('aria-invalid', 'true');
  }

  function clearError(field) {
    if (!field || !field.classList.contains('has-error')) return;
    field.classList.remove('has-error');
    var slot = field.querySelector('.ea-error');
    if (slot) slot.textContent = '';
    var control = field.querySelector('[aria-invalid]');
    if (control) control.removeAttribute('aria-invalid');
  }

  function validate() {
    var firstBad = null;
    formEl.querySelectorAll('.ea-field').forEach(function (field) {
      clearError(field);
      var kind = field.getAttribute('data-kind');
      var label = field.getAttribute('data-label');
      var required = field.hasAttribute('data-required');
      var message = '';

      if (kind === 'file') {
        var input = field.querySelector('input[type="file"]');
        var file = input.files && input.files[0];
        if (!file) { if (required) message = 'Please attach your ' + label.toLowerCase() + '.'; }
        else if (!DOC_TYPES.test(file.name)) message = 'Please upload a PDF or Word document.';
        else if (file.size > MAX_FILE) message = 'The file is larger than 10 MB.';
      } else if (kind === 'multi') {
        if (required && !field.querySelector('input:checked')) message = 'Please choose at least one option.';
      } else {
        var control = field.querySelector('input, select, textarea');
        var value = String(control.value || '').trim();
        var format = field.getAttribute('data-format');
        if (!value) {
          if (required) message = (control.tagName === 'SELECT' ? 'Please select ' : 'Please enter ') + label.toLowerCase() + '.';
        } else if (format === 'text_email' && !EMAIL.test(value)) {
          message = 'Please enter a valid email address.';
        } else if ((format === 'text_mobile' || format === 'text_phone') &&
                   (!PHONE.test(value) || value.replace(/\D/g, '').length < 7 || value.replace(/\D/g, '').length > 15)) {
          message = 'Please enter a valid phone number.';
        }
      }

      if (message) {
        showError(field, message);
        if (!firstBad) firstBad = field;
      }
    });

    var terms = formEl.querySelector('input[name="terms"]');
    var termsSlot = formEl.querySelector('[data-error-for="terms"]');
    if (terms && termsSlot) {
      termsSlot.textContent = terms.checked ? '' : 'Please accept the terms and conditions.';
      if (!terms.checked && !firstBad) firstBad = terms.closest('.ea-terms');
    }

    if (firstBad) {
      var focusable = firstBad.querySelector('input:not([type="hidden"]), select, textarea');
      if (focusable) focusable.focus();
      firstBad.scrollIntoView({ block: 'center', behavior: 'smooth' });
      return false;
    }
    return true;
  }

  /* ── 6. Submit ─────────────────────────────────────────── */
  function setStatus(message, kind) {
    statusEl.textContent = message;
    statusEl.className = 'ea-note' + (kind ? ' is-' + kind : '');
    statusEl.hidden = !message;
  }

  function submit() {
    setStatus('', '');
    if (!validate()) return;

    var data = new FormData(formEl);
    // multi-choice fields go as one comma-joined value, like Ceipal's own form
    formEl.querySelectorAll('.ea-field[data-kind="multi"]').forEach(function (field) {
      var alias = field.getAttribute('data-field');
      var values = Array.prototype.map.call(field.querySelectorAll('input:checked'), function (i) { return i.value; });
      data.delete(alias + '[]');
      data.set(alias, values.join(','));
    });
    data.set('action', 'submit');

    submitBtn.disabled = true;
    submitBtn.setAttribute('aria-busy', 'true');
    setStatus('Submitting your application…', '');

    fetch(API, { method: 'POST', body: data })
      .then(function (r) {
        return r.json().catch(function () { return { ok: false }; });
      })
      .then(function (res) {
        if (res && res.ok) { done(res.message); return; }
        if (res && res.errors) {
          Object.keys(res.errors).forEach(function (alias) {
            var field = formEl.querySelector('.ea-field[data-field="' + alias + '"]');
            if (field) showError(field, res.errors[alias]);
            else if (alias === 'terms') {
              var slot = formEl.querySelector('[data-error-for="terms"]');
              if (slot) slot.textContent = res.errors[alias];
            }
          });
        }
        throw new Error((res && res.message) || '');
      })
      .catch(function (err) {
        setStatus(err && err.message ? err.message : 'We couldn’t submit your application just now. Please try again.', 'error');
        submitBtn.disabled = false;
        submitBtn.removeAttribute('aria-busy');
      });
  }

  function done(message) {
    bodyEl.innerHTML = '' +
      '<div class="ea-done" role="status">' +
        '<span class="ea-done__mark" aria-hidden="true">&#10003;</span>' +
        '<p class="ea-done__title">Application submitted</p>' +
        '<p class="ea-done__text">' + esc(message || 'Thank you — your application has been submitted.') + '</p>' +
        '<button class="btn btn-outline btn-sm" type="button" data-ea-close>Close</button>' +
      '</div>';
    bodyEl.querySelector('[data-ea-close]').addEventListener('click', close);
    bodyEl.querySelector('[data-ea-close]').focus();
  }

  /* ── 7. Wire up the buttons careers.js renders ─────────── */
  document.addEventListener('click', function (e) {
    var trigger = e.target.closest && e.target.closest('[data-easy-apply]');
    if (!trigger) return;
    e.preventDefault();
    open(trigger.getAttribute('data-easy-apply'), trigger.getAttribute('data-job-title'), trigger);
  });
})();
