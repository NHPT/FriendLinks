(function () {
  'use strict';

  function initialize(root) {
    var filters = root.querySelectorAll('[data-flm-filter]');
    var groups = root.querySelectorAll('[data-flm-group]');
    if (!filters.length || !groups.length) {
      return;
    }

    Array.prototype.forEach.call(filters, function (button) {
      button.addEventListener('click', function () {
        var selected = button.getAttribute('data-flm-filter');
        Array.prototype.forEach.call(filters, function (candidate) {
          var active = candidate === button;
          candidate.classList.toggle('is-active', active);
          candidate.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
        Array.prototype.forEach.call(groups, function (group) {
          group.hidden = selected !== 'all' && group.getAttribute('data-flm-group') !== selected;
        });
      });
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    Array.prototype.forEach.call(document.querySelectorAll('.flm-root'), initialize);
  });
}());
