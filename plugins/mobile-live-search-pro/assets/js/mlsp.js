(function () {
    'use strict';

    var cfg = window.MLSP_DATA || {};
    var i18n = cfg.i18n || {};
    var openWidget = null;

    function qs(selector, context) {
        return (context || document).querySelector(selector);
    }

    function qsa(selector, context) {
        return Array.prototype.slice.call((context || document).querySelectorAll(selector));
    }

    function matches(el, selector) {
        if (!el || el.nodeType !== 1) {
            return false;
        }
        var proto = Element.prototype;
        var fn = proto.matches || proto.webkitMatchesSelector || proto.mozMatchesSelector || proto.msMatchesSelector || proto.oMatchesSelector;
        return fn ? fn.call(el, selector) : false;
    }

    function closest(el, selector) {
        while (el && el.nodeType === 1) {
            if (matches(el, selector)) {
                return el;
            }
            el = el.parentElement;
        }
        return null;
    }

    function esc(value) {
        return String(value === null || value === undefined ? '' : value).replace(/[&<>'"]/g, function (c) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                "'": '&#039;',
                '"': '&quot;'
            }[c];
        });
    }

    function number(value, fallback, min, max) {
        value = parseInt(value, 10);
        if (isNaN(value)) {
            value = fallback;
        }
        if (typeof min === 'number') {
            value = Math.max(min, value);
        }
        if (typeof max === 'number') {
            value = Math.min(max, value);
        }
        return value;
    }

    function text(key, fallback) {
        return i18n[key] || fallback;
    }

    function formatMinChars(n) {
        var tpl = text('minChars', 'Type at least %d characters');
        return tpl.replace('%d', n);
    }

    function fallbackAjaxUrl() {
        if (cfg.ajaxUrl) {
            return cfg.ajaxUrl;
        }
        if (window.ajaxurl) {
            return window.ajaxurl;
        }
        return window.location.protocol + '//' + window.location.host + '/wp-admin/admin-ajax.php';
    }

    function buildDefaultModal(widget) {
        var overlay = document.createElement('span');
        overlay.className = 'mlsp-overlay';
        overlay.setAttribute('data-mlsp-overlay', '1');
        overlay.hidden = true;

        var modal = document.createElement('span');
        modal.className = 'mlsp-modal';
        modal.setAttribute('data-mlsp-modal', '1');
        modal.setAttribute('role', 'dialog');
        modal.setAttribute('aria-modal', 'true');
        modal.setAttribute('aria-label', 'Live search');
        modal.hidden = true;
        modal.innerHTML = '' +
            '<span class="mlsp-panel">' +
                '<span class="mlsp-header">' +
                    '<button type="button" class="mlsp-close" data-mlsp-close="1" aria-label="' + esc(text('close', 'Close search')) + '"><span aria-hidden="true">←</span></button>' +
                    '<span class="mlsp-input-wrap">' +
                        '<span class="mlsp-input-icon" aria-hidden="true"><svg class="mlsp-svg" viewBox="0 0 24 24" width="1em" height="1em" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"></circle><line x1="16.65" y1="16.65" x2="21" y2="21"></line></svg></span>' +
                        '<input class="mlsp-input" data-mlsp-input="1" type="search" autocomplete="off" inputmode="search" placeholder="' + esc(text('placeholder', 'Search news...')) + '" />' +
                    '</span>' +
                '</span>' +
                '<span class="mlsp-status" data-mlsp-status="1"></span>' +
                '<span class="mlsp-results" data-mlsp-results="1" aria-live="polite"></span>' +
                '<span class="mlsp-pagination" data-mlsp-pagination="1" hidden></span>' +
            '</span>';

        widget.appendChild(overlay);
        widget.appendChild(modal);
    }

    function getWidgetFromTrigger(trigger) {
        var widget = closest(trigger, '.mlsp-widget');
        if (widget) {
            return widget;
        }

        widget = qs('#mlsp-global-widget');
        if (!widget) {
            widget = document.createElement('span');
            widget.className = 'mlsp-widget mlsp-generated-widget';
            widget.id = 'mlsp-global-widget';
            document.body.appendChild(widget);
            buildDefaultModal(widget);
        }
        return widget;
    }

    function ensureModal(widget) {
        if (!qs('[data-mlsp-modal]', widget) || !qs('[data-mlsp-overlay]', widget)) {
            buildDefaultModal(widget);
        }
    }

    function readConfig(trigger) {
        return {
            minChars: number(trigger.getAttribute('data-mlsp-min-chars'), number(cfg.defaultMinChars, 2, 1, 10), 1, 10),
            perPage: number(trigger.getAttribute('data-mlsp-per-page'), number(cfg.defaultPerPage, 8, 1, 20), 1, 20),
            postTypes: trigger.getAttribute('data-mlsp-post-types') || 'post',
            placeholder: trigger.getAttribute('data-mlsp-placeholder') || text('placeholder', 'Search news...')
        };
    }

    function setStatus(widget, message) {
        var status = qs('[data-mlsp-status]', widget);
        if (status) {
            status.textContent = message || '';
        }
    }

    function setResults(widget, html) {
        var results = qs('[data-mlsp-results]', widget);
        if (results) {
            results.innerHTML = html || '';
        }
    }

    function clearPagination(widget) {
        var pagination = qs('[data-mlsp-pagination]', widget);
        if (pagination) {
            pagination.innerHTML = '';
            pagination.hidden = true;
        }
    }

    function loading(widget) {
        setStatus(widget, text('loading', 'Searching...'));
        setResults(widget, '<span class="mlsp-spinner" aria-hidden="true"></span>');
        clearPagination(widget);
    }

    function closeSearch(widget) {
        if (!widget) {
            widget = openWidget;
        }
        if (!widget) {
            return;
        }

        var overlay = qs('[data-mlsp-overlay]', widget);
        var modal = qs('[data-mlsp-modal]', widget);
        var trigger = widget._mlspTrigger;

        if (modal) {
            modal.classList.remove('mlsp-open');
        }
        if (overlay) {
            overlay.classList.remove('mlsp-open');
        }
        if (trigger) {
            trigger.setAttribute('aria-expanded', 'false');
        }

        if (widget._mlspXhr && widget._mlspXhr.readyState !== 4) {
            try {
                widget._mlspXhr.abort();
            } catch (ignore) {}
        }

        window.setTimeout(function () {
            if (modal && !modal.classList.contains('mlsp-open')) {
                modal.hidden = true;
            }
            if (overlay && !overlay.classList.contains('mlsp-open')) {
                overlay.hidden = true;
            }
        }, 260);

        document.body.classList.remove('mlsp-lock');
        if (openWidget === widget) {
            openWidget = null;
        }
    }

    function openSearch(trigger, event) {
        if (event) {
            event.preventDefault();
        }

        var widget = getWidgetFromTrigger(trigger);
        ensureModal(widget);

        if (openWidget && openWidget !== widget) {
            closeSearch(openWidget);
        }

        var config = readConfig(trigger);
        var overlay = qs('[data-mlsp-overlay]', widget);
        var modal = qs('[data-mlsp-modal]', widget);
        var input = qs('[data-mlsp-input]', widget);

        widget._mlspTrigger = trigger;
        widget._mlspConfig = config;
        widget._mlspActiveIndex = -1;
        widget._mlspItems = [];
        openWidget = widget;

        if (input) {
            input.setAttribute('placeholder', config.placeholder);
        }
        if (!qs('[data-mlsp-results]', widget).innerHTML) {
            setStatus(widget, formatMinChars(config.minChars));
        }
        clearPagination(widget);

        if (overlay) {
            overlay.hidden = false;
        }
        if (modal) {
            modal.hidden = false;
        }

        document.body.classList.add('mlsp-lock');
        trigger.setAttribute('aria-expanded', 'true');

        window.setTimeout(function () {
            if (overlay) {
                overlay.classList.add('mlsp-open');
            }
            if (modal) {
                modal.classList.add('mlsp-open');
            }
        }, 20);

        window.setTimeout(function () {
            if (input) {
                input.focus();
                try {
                    input.setSelectionRange(input.value.length, input.value.length);
                } catch (ignore) {}
            }
        }, 160);
    }

    function renderPagination(widget, totalPages, currentPage) {
        var pagination = qs('[data-mlsp-pagination]', widget);
        if (!pagination) {
            return;
        }

        totalPages = number(totalPages, 0, 0, 9999);
        currentPage = number(currentPage, 1, 1, totalPages || 1);
        pagination.innerHTML = '';

        if (totalPages <= 1) {
            pagination.hidden = true;
            return;
        }

        function addButton(label, page, className, disabled) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'mlsp-page-btn' + (className ? ' ' + className : '');
            button.setAttribute('data-mlsp-page', String(page));
            button.textContent = label;
            button.disabled = !!disabled;
            if (className === 'mlsp-current') {
                button.setAttribute('aria-current', 'page');
            }
            pagination.appendChild(button);
        }

        addButton('‹', currentPage - 1, '', currentPage <= 1);

        var start = Math.max(1, currentPage - 2);
        var end = Math.min(totalPages, start + 4);
        start = Math.max(1, end - 4);

        if (start > 1) {
            addButton('1', 1, '', false);
            if (start > 2) {
                var dotsStart = document.createElement('span');
                dotsStart.className = 'mlsp-page-dots';
                dotsStart.textContent = '…';
                pagination.appendChild(dotsStart);
            }
        }

        for (var i = start; i <= end; i++) {
            addButton(String(i), i, i === currentPage ? 'mlsp-current' : '', false);
        }

        if (end < totalPages) {
            if (end < totalPages - 1) {
                var dotsEnd = document.createElement('span');
                dotsEnd.className = 'mlsp-page-dots';
                dotsEnd.textContent = '…';
                pagination.appendChild(dotsEnd);
            }
            addButton(String(totalPages), totalPages, '', false);
        }

        addButton('›', currentPage + 1, '', currentPage >= totalPages);
        pagination.hidden = false;
    }

    function renderResults(widget, data) {
        var items = data && data.items ? data.items : [];
        var found = number(data && data.found_posts, items.length, 0, 999999999);
        var page = number(data && data.current_page, 1, 1, 9999);
        var totalPages = number(data && data.total_pages, 0, 0, 9999);
        var results = qs('[data-mlsp-results]', widget);

        widget._mlspItems = items;
        widget._mlspActiveIndex = -1;

        if (!items.length) {
            setStatus(widget, '');
            setResults(widget, '<span class="mlsp-empty">' + esc(text('noResults', 'No results found')) + '</span>');
            clearPagination(widget);
            return;
        }

        var html = '';
        for (var i = 0; i < items.length; i++) {
            var item = items[i] || {};
            var meta = item.category || item.date || '';
            html += '<a class="mlsp-card" data-mlsp-result-index="' + i + '" href="' + esc(item.url) + '">' +
                '<img class="mlsp-thumb" src="' + esc(item.thumb) + '" alt="" loading="lazy" decoding="async" />' +
                '<span class="mlsp-content">' +
                    '<span class="mlsp-title">' + esc(item.title) + '</span>' +
                    (meta ? '<span class="mlsp-meta">' + esc(meta) + '</span>' : '') +
                '</span>' +
            '</a>';
        }

        setResults(widget, html);
        setStatus(widget, found + ' ' + (found === 1 ? text('result', 'result found') : text('results', 'results found')));
        renderPagination(widget, totalPages, page);

        if (results) {
            results.scrollTop = 0;
        }
    }

    function sendSearch(widget, page) {
        var input = qs('[data-mlsp-input]', widget);
        var config = widget._mlspConfig || {};
        var minChars = number(config.minChars, number(cfg.defaultMinChars, 2, 1, 10), 1, 10);
        var perPage = number(config.perPage, number(cfg.defaultPerPage, 8, 1, 20), 1, 20);
        var keyword = input ? input.value.replace(/^\s+|\s+$/g, '') : '';

        page = number(page, 1, 1, 9999);

        if (keyword.length < minChars) {
            if (widget._mlspXhr && widget._mlspXhr.readyState !== 4) {
                try {
                    widget._mlspXhr.abort();
                } catch (ignore) {}
            }
            widget._mlspItems = [];
            widget._mlspActiveIndex = -1;
            setStatus(widget, formatMinChars(minChars));
            setResults(widget, '');
            clearPagination(widget);
            return;
        }

        loading(widget);

        if (widget._mlspXhr && widget._mlspXhr.readyState !== 4) {
            try {
                widget._mlspXhr.abort();
            } catch (ignore2) {}
        }

        var xhr = new XMLHttpRequest();
        widget._mlspXhr = xhr;
        widget._mlspRequestId = (widget._mlspRequestId || 0) + 1;
        var requestId = widget._mlspRequestId;

        var params = {
            action: 'mlsp_live_search',
            nonce: cfg.nonce || '',
            keyword: keyword,
            page: page,
            per_page: perPage,
            min_chars: minChars,
            post_types: config.postTypes || 'post'
        };

        var body = [];
        for (var key in params) {
            if (Object.prototype.hasOwnProperty.call(params, key)) {
                body.push(encodeURIComponent(key) + '=' + encodeURIComponent(params[key]));
            }
        }

        xhr.open('POST', fallbackAjaxUrl(), true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4 || requestId !== widget._mlspRequestId) {
                return;
            }
            if (xhr.status < 200 || xhr.status >= 300) {
                setStatus(widget, '');
                setResults(widget, '<span class="mlsp-error">' + esc(text('error', 'Search failed. Please refresh and try again.')) + '</span>');
                clearPagination(widget);
                return;
            }
            try {
                var response = JSON.parse(xhr.responseText);
                if (!response || !response.success) {
                    throw new Error('Bad response');
                }
                renderResults(widget, response.data || {});
            } catch (err) {
                setStatus(widget, '');
                setResults(widget, '<span class="mlsp-error">' + esc(text('error', 'Search failed. Please refresh and try again.')) + '</span>');
                clearPagination(widget);
            }
        };
        xhr.send(body.join('&'));
    }

    function queueSearch(widget, page) {
        if (!widget) {
            return;
        }
        window.clearTimeout(widget._mlspTimer);
        widget._mlspTimer = window.setTimeout(function () {
            sendSearch(widget, page || 1);
        }, 280);
    }

    function activeResult(widget, delta) {
        var cards = qsa('.mlsp-card', widget);
        if (!cards.length) {
            return;
        }
        var current = typeof widget._mlspActiveIndex === 'number' ? widget._mlspActiveIndex : -1;
        current += delta;
        if (current < 0) {
            current = cards.length - 1;
        }
        if (current >= cards.length) {
            current = 0;
        }
        widget._mlspActiveIndex = current;

        for (var i = 0; i < cards.length; i++) {
            cards[i].classList.remove('mlsp-active');
        }
        cards[current].classList.add('mlsp-active');
        try {
            cards[current].scrollIntoView({ block: 'nearest' });
        } catch (ignore) {
            cards[current].scrollIntoView(false);
        }
    }

    document.addEventListener('click', function (event) {
        var trigger = closest(event.target, '[data-mlsp-open], .mlsp-trigger');
        if (trigger) {
            openSearch(trigger, event);
            return;
        }

        var closeBtn = closest(event.target, '[data-mlsp-close], .mlsp-close');
        if (closeBtn) {
            closeSearch(closest(closeBtn, '.mlsp-widget'));
            return;
        }

        var overlay = closest(event.target, '[data-mlsp-overlay]');
        if (overlay) {
            closeSearch(closest(overlay, '.mlsp-widget'));
            return;
        }

        var pageButton = closest(event.target, '[data-mlsp-page]');
        if (pageButton && !pageButton.disabled) {
            event.preventDefault();
            var widget = closest(pageButton, '.mlsp-widget');
            sendSearch(widget, pageButton.getAttribute('data-mlsp-page'));
        }
    }, false);

    document.addEventListener('input', function (event) {
        var input = closest(event.target, '[data-mlsp-input]');
        if (!input) {
            return;
        }
        var widget = closest(input, '.mlsp-widget');
        queueSearch(widget, 1);
    }, false);

    document.addEventListener('keydown', function (event) {
        if (!openWidget) {
            return;
        }

        var modal = qs('[data-mlsp-modal]', openWidget);
        if (!modal || modal.hidden) {
            return;
        }

        if (event.key === 'Escape' || event.keyCode === 27) {
            event.preventDefault();
            closeSearch(openWidget);
            return;
        }

        if (event.key === 'ArrowDown' || event.keyCode === 40) {
            event.preventDefault();
            activeResult(openWidget, 1);
            return;
        }

        if (event.key === 'ArrowUp' || event.keyCode === 38) {
            event.preventDefault();
            activeResult(openWidget, -1);
            return;
        }

        if ((event.key === 'Enter' || event.keyCode === 13) && typeof openWidget._mlspActiveIndex === 'number' && openWidget._mlspActiveIndex >= 0) {
            var cards = qsa('.mlsp-card', openWidget);
            var card = cards[openWidget._mlspActiveIndex];
            if (card && card.href) {
                event.preventDefault();
                window.location.href = card.href;
            }
        }
    }, false);
})();
