(function () {
  'use strict';

  if (window.FriendLinksFrontend && window.FriendLinksFrontend.mountAll) {
    window.FriendLinksFrontend.mountAll();
    return;
  }

  function initialize(root) {
    var component = root.matches && root.matches('.flm-root')
      ? root
      : root.querySelector('.flm-root');
    if (!component || component.getAttribute('data-flm-initialized') === '1') {
      return;
    }
    component.setAttribute('data-flm-initialized', '1');

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

  function isDarkTheme(host) {
    return !!host.closest(
      'html.theme-dark, body.theme-dark, .theme-dark, [data-theme="dark"], .dark'
    );
  }

  function synchronizeTheme(host) {
    host.toggleAttribute('data-flm-dark', isDarkTheme(host));
  }

  function mount(host) {
    var root = host.shadowRoot;
    var template = host.querySelector('template[data-flm-shadow]');

    if (!root && template && host.attachShadow) {
      root = host.attachShadow({mode: 'open'});
      root.appendChild(template.content.cloneNode(true));
      template.remove();
    }

    if (!root && template) {
      var fallback = template.content.cloneNode(true);
      var component = fallback.querySelector('.flm-root');
      host.parentNode.insertBefore(fallback, host);
      host.parentNode.removeChild(host);
      if (component) {
        initialize(component);
      }
      return;
    }

    if (root) {
      synchronizeTheme(host);
      initialize(root);
    }
  }

  function mountAll() {
    Array.prototype.forEach.call(document.querySelectorAll('friend-links-widget[data-flm-host]'), mount);
    Array.prototype.forEach.call(document.querySelectorAll('.flm-root'), initialize);
  }

  function observeTheme() {
    if (!window.MutationObserver) {
      return;
    }
    var observer = new MutationObserver(mountAll);
    observer.observe(document.documentElement, {
      attributes: true,
      attributeFilter: ['class', 'data-theme']
    });
    if (document.body) {
      observer.observe(document.body, {
        attributes: true,
        attributeFilter: ['class', 'data-theme']
      });
    }
  }

  window.FriendLinksFrontend = {
    mountAll: mountAll
  };

  function ready() {
    mountAll();
    observeTheme();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', ready);
  } else {
    ready();
  }
}());
