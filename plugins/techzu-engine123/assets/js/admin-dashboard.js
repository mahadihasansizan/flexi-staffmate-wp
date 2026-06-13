(function () {
  'use strict';

  function ready(fn) {
    if (document.readyState !== 'loading') {
      fn();
    } else {
      document.addEventListener('DOMContentLoaded', fn);
    }
  }

  ready(function () {
    var wrap = document.querySelector('#wpbody-content .wrap');
    var bin = document.getElementById('tz-engine-admin-notice-bin');
    var drawer = document.getElementById('tz-engine-notice-drawer');
    var hub = document.getElementById('wp-admin-bar-tz-engine-notice-hub');
    var badge = hub ? hub.querySelector('.tz-engine-notice-badge') : null;
    var i18n = (window.tzEngineDashboard && window.tzEngineDashboard.i18n) || {};

    if (!wrap || !bin || !drawer) {
      return;
    }

    function isSkippable(el) {
      if (!el || el.nodeType !== 1) {
        return true;
      }
      if (el.classList.contains('tz-engine-persist-notice')) {
        return true;
      }
      if (el.classList.contains('techzu-engine-settings-notice')) {
        return true;
      }
      if (el.closest && el.closest('#tz_engine_support_hub')) {
        return true;
      }
      return false;
    }

    function noticeLike(el) {
      if (!el || el.nodeType !== 1) {
        return false;
      }
      if (el.id === 'screen-meta' || el.id === 'screen-meta-links') {
        return false;
      }
      return (
        el.classList.contains('notice') ||
        el.classList.contains('updated') ||
        el.classList.contains('update-nag') ||
        el.classList.contains('error')
      );
    }

    function relocateNotices() {
      var moved = 0;
      var children = wrap.children;
      var i;
      for (i = 0; i < children.length; i++) {
        var el = children[i];
        if (!noticeLike(el) || isSkippable(el)) {
          continue;
        }
        bin.appendChild(el);
        moved++;
      }
      if (badge) {
        badge.textContent = String(moved);
        badge.style.display = moved > 0 ? 'inline-block' : 'none';
      }
      if (moved === 0 && i18n.empty) {
        bin.innerHTML = '<p class="tz-engine-notice-drawer__empty">' + i18n.empty + '</p>';
      }
    }

    function openDrawer() {
      drawer.hidden = false;
      drawer.setAttribute('aria-hidden', 'false');
      drawer.classList.add('is-open');
    }

    function closeDrawer() {
      drawer.classList.remove('is-open');
      drawer.setAttribute('aria-hidden', 'true');
      drawer.hidden = true;
    }

    relocateNotices();

    if (hub) {
      var hubLink = hub.querySelector('a.ab-item');
      if (hubLink) {
        hubLink.addEventListener('click', function (e) {
          e.preventDefault();
          if (drawer.classList.contains('is-open')) {
            closeDrawer();
          } else {
            openDrawer();
          }
        });
      }
    }

    var closeBtn = drawer.querySelector('.tz-engine-notice-drawer__close');
    if (closeBtn) {
      closeBtn.addEventListener('click', closeDrawer);
    }

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && drawer.classList.contains('is-open')) {
        closeDrawer();
      }
    });
  });
})();
