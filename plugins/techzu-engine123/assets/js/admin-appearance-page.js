(function ($) {
  'use strict';

  var tzSubmenuBoardUserEdited = false;
  var tzSubmenuBoardBaselineFingerprint = '';
  var tzEngineClearSubmenuLayoutSubmit = false;

  function stableSubmenuLayoutFingerprint(obj) {
    if (!obj || typeof obj !== 'object') {
      return '';
    }
    var keys = Object.keys(obj).sort();
    var parts = [];
    var i;
    for (i = 0; i < keys.length; i++) {
      var k = keys[i];
      var arr = Array.isArray(obj[k]) ? obj[k] : [];
      parts.push(k + '\u001d' + arr.map(String).join('\u001e'));
    }
    return parts.join('\u001f');
  }

  function sortedMultisetKey(arr) {
    if (!Array.isArray(arr)) {
      return '';
    }
    return arr
      .slice()
      .map(function (x) {
        return String(x);
      })
      .sort()
      .join('\u0000');
  }

  function submenuLayoutsSameMultisetsPerParent(a, b) {
    if (!a || typeof a !== 'object' || !b || typeof b !== 'object') {
      return false;
    }
    var keySet = {};
    Object.keys(a).forEach(function (k) {
      keySet[k] = true;
    });
    Object.keys(b).forEach(function (k) {
      keySet[k] = true;
    });
    var keys = Object.keys(keySet);
    var i;
    for (i = 0; i < keys.length; i++) {
      var k = keys[i];
      if (sortedMultisetKey(a[k]) !== sortedMultisetKey(b[k])) {
        return false;
      }
    }
    return true;
  }

  function reindexMenuRows() {
    $('#tz-engine-menu-sort > li').each(function (index) {
      var $li = $(this);
      $li.find('input[data-tz-field="hook"]').attr('name', 'tz_menu_rows[' + index + '][hook]');
      var $title = $li.find('input[data-tz-field="title"]');
      if ($title.length) {
        $title.attr('name', 'tz_menu_rows[' + index + '][title]');
      }
      var $defaultTitle = $li.find('input[data-tz-field="default_title"]');
      if ($defaultTitle.length) {
        $defaultTitle.attr('name', 'tz_menu_rows[' + index + '][default_title]');
      }
    });
  }

  function makeSeparatorId() {
    var t = Date.now().toString(36);
    var r = '';
    try {
      if (window.crypto && window.crypto.getRandomValues) {
        var a = new Uint8Array(6);
        window.crypto.getRandomValues(a);
        for (var i = 0; i < a.length; i++) {
          r += (a[i] % 36).toString(36);
        }
      }
    } catch (e) {}
    if (!r) {
      r = Math.random().toString(36).slice(2, 10);
    }
    return 'tz-sep-' + (t + r).replace(/[^a-z0-9]/gi, '').slice(0, 24);
  }

  function appendSeparatorRow() {
    var cfg = window.tzEngineAdminAppearance || {};
    var dragTitle = cfg.separatorDragTitle || 'Drag to reorder';
    var lbl = cfg.separatorDefaultLbl || 'Custom separator';
    var rm =
      (typeof cfg.removeLabel !== 'undefined' && cfg.removeLabel) || 'Remove';
    var id = makeSeparatorId();
    var idx = $('#tz-engine-menu-sort > li').length;
    var $li = $('<li class="tz-engine-menu-sort__item tz-engine-menu-sort__item--separator"></li>');
    $li.append(
      $('<span class="tz-engine-menu-sort__handle" aria-hidden="true">⋮⋮</span>').attr('title', dragTitle)
    );
    var $main = $('<div class="tz-engine-menu-sort__main tz-engine-menu-sort__main--separator"></div>');
    $main.append($('<code class="tz-engine-menu-sort__hook"></code>').text(id));
    $main.append($('<span class="tz-engine-menu-sort__sep-label"></span>').text(lbl));
    $main.append(
      $('<input type="hidden" data-tz-field="hook" />')
        .attr('name', 'tz_menu_rows[' + idx + '][hook]')
        .val(id)
    );
    $li.append($main);
    $li.append(
      $('<button type="button" class="button tz-engine-menu-sort__remove-sep"></button>').text(rm)
    );
    $('#tz-engine-menu-sort').append($li);
  }

  function reindexCustomMenuRows() {
    $('#tz-engine-custom-menus-body tr').each(function (index) {
      $(this)
        .find('input[name^="tz_custom_menus"]')
        .each(function () {
          var nm = $(this).attr('name');
          if (!nm) {
            return;
          }
          $(this).attr('name', nm.replace(/tz_custom_menus\[\d+]/, 'tz_custom_menus[' + index + ']'));
        });
    });
  }

  function appendCustomMenuRow() {
    var idx = $('#tz-engine-custom-menus-body tr').length;
    var rm =
      (typeof window.tzEngineAdminAppearance !== 'undefined' && window.tzEngineAdminAppearance.removeLabel) ||
      'Remove';
    var $tr = $('<tr class="tz-engine-custom-menu-row"></tr>');
    $tr.append(
      $('<td></td>').append(
        $('<input type="text" class="regular-text" />').attr('name', 'tz_custom_menus[' + idx + '][title]')
      )
    );
    $tr.append(
      $('<td></td>').append(
        $('<input type="text" class="regular-text code" />')
          .attr('name', 'tz_custom_menus[' + idx + '][slug]')
          .attr('placeholder', 'tz-custom-…')
      )
    );
    $tr.append(
      $('<td></td>').append(
        $('<input type="text" class="regular-text code" />')
          .attr('name', 'tz_custom_menus[' + idx + '][icon]')
          .attr('placeholder', 'dashicons-admin-generic')
      )
    );
    $tr.append(
      $('<td></td>').append(
        $('<button type="button" class="button tz-engine-custom-menu-remove"></button>').text(rm)
      )
    );
    $('#tz-engine-custom-menus-body').append($tr);
  }

  function initAppearanceTabs() {
    var $shell = $('[data-tz-ap-tabs="1"]');
    if (!$shell.length) {
      return;
    }

    function setTab(tabId) {
      if (tabId !== 'export' && tabId !== 'menu' && tabId !== 'plugins') {
        return;
      }
      $shell.find('.tz-ap-tabs__btn').each(function () {
        var $btn = $(this);
        var id = $btn.attr('data-tz-ap-tab');
        var on = id === tabId;
        $btn.toggleClass('is-active', on);
        $btn.attr('aria-selected', on ? 'true' : 'false');
        $btn.attr('tabindex', on ? '0' : '-1');
      });
      $shell.find('.tz-ap-tabs__panel').each(function () {
        var $p = $(this);
        var id = $p.attr('data-tz-ap-panel');
        if (id === tabId) {
          $p.removeAttr('hidden');
        } else {
          $p.attr('hidden', true);
        }
      });
      var $field = $('#tz-ap-active-tab');
      if ($field.length && (tabId === 'menu' || tabId === 'plugins')) {
        $field.val(tabId);
      }
    }

    $shell.on('click', '.tz-ap-tabs__btn', function () {
      setTab($(this).attr('data-tz-ap-tab'));
    });
  }

  function collectSubmenuLayoutObject() {
    var out = {};
    $('#tz-submenu-board .tz-submenu-column').each(function () {
      var parent = $(this).data('parent');
      if (!parent) {
        return;
      }
      var kids = [];
      $(this)
        .find('ul.tz-submenu-sortable > li')
        .each(function () {
          var c = $(this).data('child');
          if (c) {
            kids.push(String(c));
          }
        });
      out[String(parent)] = kids;
    });
    return out;
  }

  function submenuLayoutsEquivalent(a, b) {
    if (!a || typeof a !== 'object' || !b || typeof b !== 'object') {
      return false;
    }
    var keysA = Object.keys(a).sort();
    var keysB = Object.keys(b).sort();
    if (keysA.length !== keysB.length) {
      return false;
    }
    var i;
    for (i = 0; i < keysA.length; i++) {
      if (keysA[i] !== keysB[i]) {
        return false;
      }
    }
    for (i = 0; i < keysA.length; i++) {
      var k = keysA[i];
      var ca = a[k];
      var cb = b[k];
      if (!Array.isArray(ca) || !Array.isArray(cb) || ca.length !== cb.length) {
        return false;
      }
      var j;
      for (j = 0; j < ca.length; j++) {
        if (String(ca[j]) !== String(cb[j])) {
          return false;
        }
      }
    }
    return true;
  }

  function applySubmenuLayoutFieldForSubmit() {
    var $field = $('#tz-submenu-layout-json');
    if (!$field.length) {
      return;
    }
    var built = collectSubmenuLayoutObject();
    var cfg = window.tzEngineAdminAppearance || {};
    var canonical = cfg.submenuLayoutCanonical;
    if (canonical && typeof canonical === 'object') {
      if (submenuLayoutsEquivalent(built, canonical)) {
        $field.val('{}');
        return;
      }
      if (!tzSubmenuBoardUserEdited && submenuLayoutsSameMultisetsPerParent(built, canonical)) {
        $field.val('{}');
        return;
      }
    }
    $field.val(JSON.stringify(built));
  }

  $(function () {
    initAppearanceTabs();

    var $list = $('#tz-engine-menu-sort');
    if ($list.length && $.fn.sortable) {
      $list.sortable({
        handle: '.tz-engine-menu-sort__handle',
        axis: 'y',
        cursor: 'move',
        tolerance: 'pointer',
        placeholder: 'tz-engine-menu-sort__placeholder',
      });
    }

    if ($.fn.sortable && $('#tz-submenu-board .tz-submenu-sortable').length) {
      tzSubmenuBoardBaselineFingerprint = stableSubmenuLayoutFingerprint(collectSubmenuLayoutObject());
      $('#tz-submenu-board .tz-submenu-sortable').sortable({
        connectWith: '#tz-submenu-board .tz-submenu-sortable',
        handle: '.tz-submenu-sort__handle',
        cursor: 'move',
        tolerance: 'pointer',
        distance: 6,
        placeholder: 'tz-submenu-sort__placeholder',
        appendTo: '#tz-submenu-board',
        scroll: true,
        stop: function () {
          var now = stableSubmenuLayoutFingerprint(collectSubmenuLayoutObject());
          if (now !== tzSubmenuBoardBaselineFingerprint) {
            tzSubmenuBoardUserEdited = true;
          }
        },
      });
    }

    $(document).on('click', '#tz-engine-custom-menu-add', function () {
      appendCustomMenuRow();
    });

    $(document).on('click', '#tz-engine-menu-separator-add', function () {
      appendSeparatorRow();
    });

    $(document).on('click', '.tz-engine-menu-sort__remove-sep', function () {
      $(this).closest('li').remove();
      reindexMenuRows();
    });

    $(document).on('click', '.tz-engine-custom-menu-remove', function () {
      $(this).closest('tr').remove();
      reindexCustomMenuRows();
    });

    $(document).on('click', '#tz-engine-clear-submenu-layout-btn', function (e) {
      var cfg = window.tzEngineAdminAppearance || {};
      var msg =
        cfg.clearSubmenuConfirm ||
        'Clear saved submenu layout and restore WordPress default order? Other settings on this page will still be saved.';
      if (!window.confirm(msg)) {
        e.preventDefault();
        return false;
      }
      tzEngineClearSubmenuLayoutSubmit = true;
    });

    $('.tz-engine-admin-appearance__form').on('submit', function () {
      var $active = $('[data-tz-ap-tabs="1"] .tz-ap-tabs__btn.is-active');
      if ($active.length) {
        var tid = $active.attr('data-tz-ap-tab');
        if (tid === 'menu' || tid === 'plugins') {
          $('#tz-ap-active-tab').val(tid);
        }
      }
      reindexCustomMenuRows();
      reindexMenuRows();
      if (tzEngineClearSubmenuLayoutSubmit) {
        tzEngineClearSubmenuLayoutSubmit = false;
        $('#tz-submenu-layout-json').val('{}');
        return;
      }
      applySubmenuLayoutFieldForSubmit();
    });
  });
})(jQuery);
