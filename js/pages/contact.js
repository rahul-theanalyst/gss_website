// Keep this unconnected form from putting personal information in the URL.
document.querySelector('.lc-form').addEventListener('submit', function (event) {
  if (!this.getAttribute('action')) event.preventDefault();
});
