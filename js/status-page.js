/**
 * Every timestamp on the public status page is server-rendered as its
 * stored UTC value, inside a `<time datetime="..." data-status-time>`
 * element (see `partials/status-time.html.twig`) whose own text content is
 * a UTC fallback. This reformats each one to the visitor's own
 * browser-local time on load -- there's no authenticated visitor here whose
 * timezone the server could otherwise know, unlike the admin-side
 * `plugins.status-page.timezone` setting used for entering/displaying
 * announcement times in Admin2.
 *
 * No dependencies: `Intl.DateTimeFormat` with no explicit `timeZone` option
 * already defaults to the browser's own local zone.
 */
(function () {
  var formatter;

  function getFormatter() {
    if (!formatter) {
      formatter = new Intl.DateTimeFormat(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
      });
    }

    return formatter;
  }

  function localize(el) {
    var iso = el.getAttribute('datetime');
    if (!iso) {
      return;
    }

    var moment = new Date(iso);
    if (isNaN(moment.getTime())) {
      return;
    }

    el.textContent = getFormatter().format(moment);
  }

  document.querySelectorAll('[data-status-time]').forEach(localize);
})();
