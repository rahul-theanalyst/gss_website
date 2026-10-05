/* Verification code for the site's plain forms (Let's Connect, Careers
   "Stay connected"). Fills every [data-form-captcha] block with a challenge
   from server/form-captcha.php; the form's handler checks the answer before
   anything is sent. The answer input is `required`, so the form can't be
   submitted until it's filled in.

   Each code works for one submit only, so page scripts call
   GSSFormCaptcha.reload(form) after every attempt. (Easy Apply has its own
   CAPTCHA in easy-apply.js and doesn't use this.) */
(function () {
  'use strict';

  var API = '../server/form-captcha.php';

  function load(block) {
    var box = block.querySelector('[data-form-captcha-box]');
    var token = block.querySelector('[data-form-captcha-token]');
    var answer = block.querySelector('input[name="captcha_answer"]');
    if (!box || !token) return;
    token.value = '';
    if (answer) answer.value = '';
    box.textContent = 'Loading…';
    fetch(API, { cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res || !res.ok || !res.token) throw new Error('captcha');
        token.value = res.token;
        box.textContent = '';
        if (res.image) {
          var img = document.createElement('img');
          img.className = 'form-captcha__img';
          img.src = res.image;
          img.width = 190;
          img.height = 62;
          img.alt = 'Verification code: type the characters shown in this image';
          box.appendChild(img);
          if (answer) answer.placeholder = 'Type the characters shown';
        } else {
          box.textContent = res.question || '';
          if (answer) answer.placeholder = 'Your answer';
        }
      })
      .catch(function () {
        box.textContent = 'Couldn’t load the verification code. Select “New code” to try again.';
      });
  }

  function blocksIn(form) {
    return form ? form.querySelectorAll('[data-form-captcha]') : [];
  }

  document.querySelectorAll('[data-form-captcha]').forEach(function (block) {
    load(block);
    var again = block.querySelector('[data-form-captcha-new]');
    if (again) again.addEventListener('click', function () { load(block); });
  });

  window.GSSFormCaptcha = {
    reload: function (form) { Array.prototype.forEach.call(blocksIn(form), load); }
  };
}());
