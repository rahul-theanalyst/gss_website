// Keep root and former page URLs working after moving pages into html/.
(function () {
  'use strict';
  var siteRoot = new URL('../../', document.currentScript.src);
  var page = window.location.pathname.slice(siteRoot.pathname.length);
  var pages = [
    'index.html', 'about.html', 'blog.html', 'business-consulting.html',
    'career.html', 'clientele.html', 'contact.html', 'data-business-insight.html',
    'global-talent.html', 'our-focus-area.html', 'alliances.html',
    'technology-delivery.html'
  ];
  // renamed pages: the old address still lands on the new one
  var renamed = { 'partner.html': 'alliances.html' };
  if (renamed[page]) page = renamed[page];
  if (pages.indexOf(page) === -1) page = 'index.html';

  var destination = new URL('html/' + page, siteRoot);
  destination.search = window.location.search;
  destination.hash = window.location.hash;
  window.location.replace(destination.href);
}());
