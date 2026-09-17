(function () {
  var fi = document.getElementById('f-resume');
  var fn = document.getElementById('f-resume-name');
  if (fi && fn) fi.addEventListener('change', function () {
    fn.textContent = fi.files && fi.files.length ? fi.files[0].name : 'Upload résumé';
  });
  document.querySelectorAll('.car-search').forEach(function (f) {
    f.addEventListener('submit', function (e) { e.preventDefault(); });
  });
  document.querySelector('.car-form').addEventListener('submit', function (event) {
    if (!this.getAttribute('action')) event.preventDefault();
  });
}());
