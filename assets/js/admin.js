(function () {
  'use strict';
  var btn = document.getElementById('menu-btn');
  if (btn) btn.addEventListener('click', function () { document.body.classList.toggle('nav-open'); });
  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-confirm]');
    if (t && !window.confirm(t.getAttribute('data-confirm'))) { e.preventDefault(); }
  });
  var th = localStorage.getItem('amail-admin-theme');
  if (th) document.documentElement.setAttribute('data-theme', th);
  var tog = document.getElementById('theme-toggle');
  if (tog) tog.addEventListener('click', function () {
    var cur = document.documentElement.getAttribute('data-theme');
    var dark = cur ? cur === 'dark' : window.matchMedia('(prefers-color-scheme: dark)').matches;
    var next = dark ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', next);
    localStorage.setItem('amail-admin-theme', next);
  });
})();
