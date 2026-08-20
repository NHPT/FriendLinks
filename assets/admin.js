(function () {
  'use strict';

  function initializeTemplatePreview(preview) {
    var select = document.getElementById('flm-template');
    var root = preview.querySelector('.flm-root');
    var stage = preview.querySelector('[data-flm-preview-stage]');
    var description = preview.querySelector('.flm-preview-description');
    var templates = {};

    try {
      templates = JSON.parse(preview.getAttribute('data-flm-templates') || '{}');
    } catch (error) {
      return;
    }

    if (!select || !root || !stage) {
      return;
    }

    function applyTemplate(id) {
      var template = templates[id];
      if (!template) {
        return;
      }

      Array.prototype.slice.call(root.classList).forEach(function (className) {
        if (className.indexOf('flm-layout-') === 0 || className.indexOf('flm-template-') === 0) {
          root.classList.remove(className);
        }
      });
      root.classList.add('flm-layout-' + template.layout);
      root.classList.add('flm-template-' + id);
      root.setAttribute('data-flm-template', id);
      if (description) {
        description.textContent = template.description || '';
      }
    }

    select.addEventListener('change', function () {
      applyTemplate(select.value);
    });

    Array.prototype.forEach.call(preview.querySelectorAll('[data-flm-preview-size]'), function (button) {
      button.addEventListener('click', function () {
        var size = button.getAttribute('data-flm-preview-size');
        stage.setAttribute('data-flm-preview-stage', size);
        Array.prototype.forEach.call(preview.querySelectorAll('[data-flm-preview-size]'), function (candidate) {
          var active = candidate === button;
          candidate.classList.toggle('is-active', active);
          candidate.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
      });
    });

    Array.prototype.forEach.call(preview.querySelectorAll('.flm-link'), function (link) {
      link.addEventListener('click', function (event) {
        event.preventDefault();
      });
    });
  }

  function initializeBulkSelection(selectAll) {
    var form = selectAll.closest('form');
    if (!form) {
      return;
    }
    var items = form.querySelectorAll('input[type="checkbox"][name="id[]"]');

    function updateSelectAll() {
      var checked = 0;
      Array.prototype.forEach.call(items, function (item) {
        if (item.checked) {
          checked++;
        }
      });
      selectAll.checked = items.length > 0 && checked === items.length;
      selectAll.indeterminate = checked > 0 && checked < items.length;
    }

    selectAll.addEventListener('change', function () {
      Array.prototype.forEach.call(items, function (item) {
        item.checked = selectAll.checked;
      });
      updateSelectAll();
    });
    Array.prototype.forEach.call(items, function (item) {
      item.addEventListener('change', updateSelectAll);
    });
    selectAll.disabled = 0 === items.length;
    updateSelectAll();
  }

  document.addEventListener('DOMContentLoaded', function () {
    Array.prototype.forEach.call(document.querySelectorAll('.flm-template-preview'), initializeTemplatePreview);
    Array.prototype.forEach.call(document.querySelectorAll('.typecho-table-select-all'), initializeBulkSelection);
  });
}());
