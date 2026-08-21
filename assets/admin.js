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

  function initializeCategoryEditor(button) {
    var editor = document.querySelector('[data-flm-category-editor]');
    if (!editor) {
      return;
    }

    button.addEventListener('click', function () {
      var willOpen = editor.hidden;
      editor.hidden = !willOpen;
      button.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
      button.textContent = willOpen ? '收起' : '新增分类';
      if (willOpen) {
        var firstInput = editor.querySelector('input[type="text"]');
        if (firstInput) {
          firstInput.focus();
        }
      }
    });
  }

  function initializeNotificationTabs(form) {
    var buttons = form.querySelectorAll('[data-flm-notification-tab]');
    var panels = form.querySelectorAll('[data-flm-notification-panel]');
    if (!buttons.length || !panels.length) {
      return;
    }

    function activate(id, updateHash) {
      Array.prototype.forEach.call(buttons, function (button) {
        var active = button.getAttribute('data-flm-notification-tab') === id;
        button.setAttribute('aria-selected', active ? 'true' : 'false');
        button.parentNode.classList.toggle('current', active);
      });
      Array.prototype.forEach.call(panels, function (panel) {
        panel.hidden = panel.getAttribute('data-flm-notification-panel') !== id;
      });
      if (updateHash && window.history && window.history.replaceState) {
        window.history.replaceState(null, '', '#notification-' + id);
      }
    }

    Array.prototype.forEach.call(buttons, function (button) {
      button.addEventListener('click', function () {
        activate(button.getAttribute('data-flm-notification-tab'), true);
      });
    });

    var requested = window.location.hash.indexOf('#notification-') === 0
      ? window.location.hash.substring(14)
      : 'policy';
    if (!form.querySelector('[data-flm-notification-panel="' + requested + '"]')) {
      requested = 'policy';
    }
    activate(requested, false);
  }

  function initializeHistoryDialog(dialog) {
    var title = dialog.querySelector('#flm-history-dialog-title');
    var content = dialog.querySelector('[data-flm-history-content]');
    var closeButton = dialog.querySelector('[data-flm-history-close]');
    var previousFocus = null;

    function close() {
      if ('function' === typeof dialog.close) {
        dialog.close();
      } else {
        dialog.removeAttribute('open');
      }
      if (previousFocus) {
        previousFocus.focus();
      }
    }

    Array.prototype.forEach.call(document.querySelectorAll('[data-flm-history-open]'), function (button) {
      button.addEventListener('click', function () {
        var source = document.getElementById(button.getAttribute('data-flm-history-open'));
        if (!source || !content) {
          return;
        }
        previousFocus = button;
        title.textContent = button.getAttribute('data-flm-history-title') || '检测诊断';
        content.textContent = '';
        content.appendChild(source.content.cloneNode(true));
        if ('function' === typeof dialog.showModal) {
          dialog.showModal();
        } else {
          dialog.setAttribute('open', '');
        }
        closeButton.focus();
      });
    });

    closeButton.addEventListener('click', close);
    dialog.addEventListener('click', function (event) {
      if (event.target === dialog) {
        close();
      }
    });
    dialog.addEventListener('cancel', function (event) {
      event.preventDefault();
      close();
    });
    document.addEventListener('keydown', function (event) {
      if ('Escape' === event.key && dialog.hasAttribute('open')) {
        event.preventDefault();
        close();
      }
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    Array.prototype.forEach.call(document.querySelectorAll('.flm-template-preview'), initializeTemplatePreview);
    Array.prototype.forEach.call(document.querySelectorAll('.typecho-table-select-all'), initializeBulkSelection);
    Array.prototype.forEach.call(document.querySelectorAll('[data-flm-category-toggle]'), initializeCategoryEditor);
    Array.prototype.forEach.call(
      document.querySelectorAll('[data-flm-notification-settings]'),
      initializeNotificationTabs
    );
    Array.prototype.forEach.call(document.querySelectorAll('[data-flm-history-dialog]'), initializeHistoryDialog);
  });
}());
