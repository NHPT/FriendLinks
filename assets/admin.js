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
      Array.prototype.forEach.call(form.querySelectorAll('[data-flm-notification-test]'), function (button) {
        var channel = button.getAttribute('data-flm-notification-test');
        button.hidden = ['webhook', 'dingtalk', 'email'].indexOf(id) === -1 || channel !== id;
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

    var allowed = ['policy', 'webhook', 'dingtalk', 'email', 'template'];
    var requested = window.location.hash.indexOf('#notification-') === 0
      ? window.location.hash.substring(14)
      : 'policy';
    if (allowed.indexOf(requested) === -1) {
      requested = 'policy';
    }
    activate(requested, false);
  }

  function initializeNotificationTestButtons(form) {
    var buttons = form.querySelectorAll('[data-flm-notification-test]');
    if (!buttons.length) {
      return;
    }

    var field = function (name) {
      return form.querySelector('[name="' + name + '"]');
    };
    var value = function (name) {
      var input = field(name);
      return input ? input.value.replace(/^\s+|\s+$/g, '') : '';
    };
    var checked = function (name) {
      var input = field(name);
      return !!(input && input.checked);
    };
    var configuredValue = function (name, clearName) {
      var input = field(name);
      if (!input) {
        return false;
      }
      return value(name) !== '' || (input.getAttribute('data-flm-configured') === '1' && !checked(clearName));
    };
    var enabled = function (channel) {
      if (channel === 'webhook') {
        return configuredValue('webhook_url', 'clear_webhook_url');
      }
      if (channel === 'dingtalk') {
        return configuredValue('dingtalk_webhook_url', 'clear_dingtalk_webhook_url');
      }
      if (channel === 'email') {
        if (!value('smtp_host') || !value('smtp_port') || !value('smtp_from_address') || !value('email_recipients')) {
          return false;
        }
        return !value('smtp_username') || configuredValue('smtp_password', 'clear_smtp_password');
      }
      return false;
    };
    var refresh = function () {
      Array.prototype.forEach.call(buttons, function (button) {
        var active = enabled(button.getAttribute('data-flm-notification-test'));
        button.disabled = !active;
        button.setAttribute('aria-disabled', active ? 'false' : 'true');
      });
    };

    form.addEventListener('input', refresh);
    form.addEventListener('change', refresh);
    refresh();
  }

  function initializeSettingsTabs(form) {
    var buttons = form.querySelectorAll('[data-flm-settings-tab]');
    var panels = form.querySelectorAll('[data-flm-settings-panel]');
    var saveButton = form.querySelector('[data-flm-settings-save]');
    var cronUnavailable = form.getAttribute('data-flm-cron-unavailable') === '1';
    if (!buttons.length || !panels.length) {
      return;
    }

    function activate(id, updateHash) {
      Array.prototype.forEach.call(buttons, function (button) {
        var active = button.getAttribute('data-flm-settings-tab') === id;
        button.setAttribute('aria-selected', active ? 'true' : 'false');
        button.parentNode.classList.toggle('current', active);
      });
      Array.prototype.forEach.call(panels, function (panel) {
        panel.hidden = panel.getAttribute('data-flm-settings-panel') !== id;
      });
      if (saveButton) {
        var saveDisabled = cronUnavailable && id === 'cli-worker';
        saveButton.disabled = saveDisabled;
        saveButton.setAttribute('aria-disabled', saveDisabled ? 'true' : 'false');
      }
      if (updateHash && window.history && window.history.replaceState) {
        window.history.replaceState(null, '', '#settings-' + id);
      }
    }

    Array.prototype.forEach.call(buttons, function (button) {
      button.addEventListener('click', function () {
        activate(button.getAttribute('data-flm-settings-tab'), true);
      });
    });

    var allowed = ['display', 'detection', 'cli-worker', 'worker'];
    var requested = window.location.hash.indexOf('#settings-') === 0
      ? window.location.hash.substring(10)
      : 'display';
    if (allowed.indexOf(requested) === -1) {
      requested = 'display';
    }
    activate(requested, false);

    var interval = form.querySelector('#flm-cron-interval-value');
    var unit = form.querySelector('#flm-cron-interval-unit');
    if (interval && unit) {
      var updateIntervalLimit = function () {
        var selected = unit.options[unit.selectedIndex];
        interval.min = selected ? (selected.getAttribute('data-min') || '1') : '1';
        interval.max = selected ? (selected.getAttribute('data-max') || '1440') : '1440';
      };
      unit.addEventListener('change', updateIntervalLimit);
      updateIntervalLimit();
    }
  }

  function initializeConfirmDialog(dialog) {
    var title = dialog.querySelector('[data-flm-confirm-title]');
    var message = dialog.querySelector('[data-flm-confirm-message]');
    var acceptButton = dialog.querySelector('[data-flm-confirm-accept]');
    var cancelButtons = dialog.querySelectorAll('[data-flm-confirm-cancel]');
    var trigger = null;

    function close(restoreFocus) {
      if ('function' === typeof dialog.close) {
        dialog.close();
      } else {
        dialog.removeAttribute('open');
      }
      if (restoreFocus && trigger) {
        trigger.focus();
      }
      if (restoreFocus) {
        trigger = null;
      }
    }

    document.addEventListener('click', function (event) {
      var candidate = event.target.closest('[data-flm-confirm]');
      if (!candidate) {
        return;
      }
      event.preventDefault();
      trigger = candidate;
      title.textContent = candidate.getAttribute('data-flm-confirm-title') || '确认操作';
      message.textContent = candidate.getAttribute('data-flm-confirm-message') || '确认继续此操作？';
      acceptButton.textContent = candidate.getAttribute('data-flm-confirm-label') || '确认';
      if ('function' === typeof dialog.showModal) {
        dialog.showModal();
      } else {
        dialog.setAttribute('open', '');
      }
      acceptButton.focus();
    });

    acceptButton.addEventListener('click', function () {
      var activeTrigger = trigger;
      if (!activeTrigger) {
        return;
      }
      trigger = null;
      close(false);
      if (activeTrigger.form) {
        if ('function' === typeof activeTrigger.form.requestSubmit) {
          activeTrigger.form.requestSubmit(activeTrigger);
        } else {
          if (activeTrigger.formAction) {
            activeTrigger.form.action = activeTrigger.formAction;
          }
          activeTrigger.form.submit();
        }
      } else if (activeTrigger.href) {
        window.location.assign(activeTrigger.href);
      }
    });

    Array.prototype.forEach.call(cancelButtons, function (button) {
      button.addEventListener('click', function () {
        close(true);
      });
    });
    dialog.addEventListener('click', function (event) {
      if (event.target === dialog) {
        close(true);
      }
    });
    dialog.addEventListener('cancel', function (event) {
      event.preventDefault();
      close(true);
    });
  }

  function initializeAutomaticCheck(config) {
    var linkId = config.getAttribute('data-flm-auto-check-id');
    var url = config.getAttribute('data-flm-auto-check-url');
    var state = document.getElementById('flm-link-state-' + linkId);
    if (!linkId || !url) {
      return;
    }

    var cleanUrl = new URL(window.location.href);
    cleanUrl.searchParams.delete('auto_check');
    if (state) {
      Array.prototype.slice.call(state.classList).forEach(function (className) {
        if (className.indexOf('flm-state-') === 0) {
          state.classList.remove(className);
        }
      });
      state.classList.add('flm-state-checking');
      state.textContent = '检测中…';
    }

    fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      cache: 'no-store',
      keepalive: true,
      headers: {'X-Requested-With': 'XMLHttpRequest'}
    }).then(function (response) {
      if (!response.ok) {
        throw new Error('HTTP ' + response.status);
      }
      return response.json();
    }).then(function (result) {
      if (!result || !result.ok) {
        throw new Error('check_failed');
      }
      if (window.history && window.history.replaceState) {
        window.history.replaceState(null, '', cleanUrl.toString());
      }
      window.location.reload();
    }).catch(function () {
      if (window.history && window.history.replaceState) {
        window.history.replaceState(null, '', cleanUrl.toString());
      }
      if (state) {
        state.classList.remove('flm-state-checking');
        state.classList.add('flm-state-pending');
        state.textContent = '待检测';
        state.title = '后台检测未完成，将由下一次 Worker 继续处理。';
      }
    });
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

  function initializeSubmitLock(form) {
    form.addEventListener('submit', function (event) {
      if ('true' === form.getAttribute('data-flm-submitting')) {
        event.preventDefault();
        return;
      }
      form.setAttribute('data-flm-submitting', 'true');
      form.setAttribute('aria-busy', 'true');
      var submitter = event.submitter || document.activeElement;
      if (submitter && submitter.form === form) {
        submitter.setAttribute('aria-disabled', 'true');
        submitter.classList.add('flm-is-submitting');
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
    Array.prototype.forEach.call(
      document.querySelectorAll('[data-flm-notification-settings]'),
      initializeNotificationTestButtons
    );
    Array.prototype.forEach.call(document.querySelectorAll('[data-flm-settings]'), initializeSettingsTabs);
    Array.prototype.forEach.call(document.querySelectorAll('[data-flm-confirm-dialog]'), initializeConfirmDialog);
    Array.prototype.forEach.call(document.querySelectorAll('[data-flm-auto-check]'), initializeAutomaticCheck);
    Array.prototype.forEach.call(document.querySelectorAll('[data-flm-history-dialog]'), initializeHistoryDialog);
    Array.prototype.forEach.call(
      document.querySelectorAll('[data-flm-notification-settings]'),
      initializeSubmitLock
    );
  });
}());
