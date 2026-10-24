/*
 * Share button click tracking.
 *
 * Every row of share buttons (".share-buttons") knows which page it shares
 * (data-share-url) and where to report clicks (data-track-url).
 * When a button is clicked we send the page URL and the platform to the back-end
 * (track_share.php). The browser still follows the link normally, so the share
 * window / mail app opens as usual.
 */
(function () {
  'use strict';

  function track(trackUrl, pageUrl, platform) {
    var payload = JSON.stringify({ page_url: pageUrl, platform: platform });

    // keepalive lets the request finish even if the browser navigates away (e.g. mailto:)
    if (window.fetch) {
      fetch(trackUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: payload,
        keepalive: true
      }).catch(function () { /* tracking must never break sharing */ });
    } else if (navigator.sendBeacon) {
      navigator.sendBeacon(trackUrl, new Blob([payload], { type: 'application/json' }));
    }
  }

  // One listener for all buttons on the page (works for every post card).
  document.addEventListener('click', function (event) {
    var btn = event.target.closest ? event.target.closest('.share-btn') : null;
    if (!btn) { return; }

    var row = btn.closest('.share-buttons');
    if (!row) { return; }

    var pageUrl  = (row.getAttribute('data-share-url') || window.location.href).split('#')[0];
    var trackUrl = row.getAttribute('data-track-url') || 'track_share.php';

    track(trackUrl, pageUrl, btn.getAttribute('data-platform'));
  });
})();
