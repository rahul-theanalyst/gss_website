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
        if (statusNote) {
          statusNote.className = 'form-submission-note is-error';
          statusNote.textContent = 'Invalid file type. Please select a valid PDF document (.pdf).';
          statusNote.hidden = false;
        }
        return;
      }

      // Enforce 10 MB size limit
      if (file.size > MAX_FILE_BYTES) {
        fileInput.value = '';
        fileNameDisplay.textContent = 'Upload resume (PDF only)';
        if (statusNote) {
          statusNote.className = 'form-submission-note is-error';
          statusNote.textContent = 'The selected file is too large (' + (file.size / (1024 * 1024)).toFixed(1) + ' MB). Maximum allowed size is 10 MB.';
          statusNote.hidden = false;
        }
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
      if (statusNote) {
        statusNote.className = 'form-submission-note is-error';
        statusNote.textContent = 'Please select and attach your resume in PDF format before submitting.';
        statusNote.hidden = false;
      }
      if (fileInput) fileInput.focus();
      return;
    }

    var selectedFile = fileInput.files[0];
    if (!selectedFile.name.toLowerCase().endsWith('.pdf')) {
      if (statusNote) {
        statusNote.className = 'form-submission-note is-error';
        statusNote.textContent = 'Only PDF documents (.pdf) are accepted for the resume attachment.';
        statusNote.hidden = false;
      }
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

    fetch(action, {
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
          statusNote.textContent = res.data.message || 'Thank you! Your profile and résumé have been submitted successfully to our talent team.';
          statusNote.hidden = false;
        }

        form.reset();
        stampRenderTime();
        if (fileNameDisplay) {
          fileNameDisplay.textContent = 'Upload resume (PDF only)';
        }
      } else {
        // Failure: display error message while PRESERVING all user entered input values
        var errorMessage = (res.data && res.data.error)
          ? res.data.error
          : 'Something went wrong. Please try again or email contact@globalsoftsystems.com directly.';

        if (statusNote) {
          statusNote.className = 'form-submission-note is-error';
          statusNote.textContent = errorMessage;
          statusNote.hidden = false;
        }
      }
    })
    .catch(function () {
      // Network failure (fetch threw, no response at all): preserve all inputs and allow retry
      if (statusNote) {
        statusNote.className = 'form-submission-note is-error';
        statusNote.textContent = 'Something went wrong. Please try again or email contact@globalsoftsystems.com directly.';
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
