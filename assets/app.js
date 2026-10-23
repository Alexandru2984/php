/* Guestbook client behaviour — theme, counters, sharing, confirmations and
   auto-dismissing flash. No inline handlers (CSP-safe). */
(function () {
    'use strict';

    // ---- Theme toggle ----------------------------------------------------
    function currentTheme() {
        var explicit = document.documentElement.getAttribute('data-theme');
        if (explicit) return explicit;
        return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }

    var toggle = document.querySelector('[data-theme-toggle]');
    if (toggle) {
        function updateThemeControl() {
            var dark = currentTheme() === 'dark';
            toggle.setAttribute('aria-pressed', dark ? 'true' : 'false');
            toggle.setAttribute('aria-label', dark ? 'Use light theme' : 'Use dark theme');
            toggle.title = dark ? 'Use light theme' : 'Use dark theme';
        }
        toggle.addEventListener('click', function () {
            var next = currentTheme() === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', next);
            try { localStorage.setItem('gb-theme', next); } catch (e) {}
            updateThemeControl();
        });
        updateThemeControl();
    }

    // ---- Character counter ----------------------------------------------
    document.querySelectorAll('[data-counter]').forEach(function (el) {
        var out = document.getElementById(el.getAttribute('data-counter'));
        if (!out) return;
        var max = parseInt(el.getAttribute('maxlength'), 10) || 0;
        function update() {
            var len = el.value.length;
            out.textContent = len + ' / ' + max;
            out.classList.toggle('warn', max && len > max * 0.9);
        }
        el.addEventListener('input', update);
        update();
    });

    // ---- Delete confirmation --------------------------------------------
    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (!window.confirm(form.getAttribute('data-confirm'))) {
                e.preventDefault();
            }
        });
    });

    // ---- Privacy-safe sharing -------------------------------------------
    // Nothing leaves the browser until the visitor invokes a share control.
    var shareStatus = document.querySelector('[data-share-status]');
    function announceShare(message) {
        if (!shareStatus) return;
        shareStatus.textContent = '';
        window.requestAnimationFrame(function () { shareStatus.textContent = message; });
    }
    function copyShareUrl(url) {
        function legacyCopy() {
            return new Promise(function (resolve, reject) {
                var activeElement = document.activeElement;
                var helper = document.createElement('textarea');
                helper.className = 'clipboard-helper';
                helper.value = url;
                helper.setAttribute('readonly', '');
                document.body.appendChild(helper);
                helper.select();
                var copied = false;
                try { copied = document.execCommand('copy'); } catch (e) {}
                helper.remove();
                if (activeElement && typeof activeElement.focus === 'function') activeElement.focus();
                if (copied) resolve();
                else reject(new Error('Clipboard unavailable'));
            });
        }
        if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
            return navigator.clipboard.writeText(url).catch(legacyCopy);
        }
        return legacyCopy();
    }
    document.querySelectorAll('[data-share-path]').forEach(function (button) {
        button.hidden = false;
        button.addEventListener('click', async function () {
            if (button.disabled) return;
            var url = new URL(button.getAttribute('data-share-path'), window.location.origin).href;
            var title = button.getAttribute('data-share-title') || 'Micuțu Guestbook message';
            var shareData = { title: title, url: url };
            button.disabled = true;
            if (shareStatus) shareStatus.textContent = '';
            try {
                var nativeShareAvailable = typeof navigator.share === 'function'
                    && (typeof navigator.canShare !== 'function' || navigator.canShare(shareData));
                if (nativeShareAvailable) {
                    await navigator.share(shareData);
                    announceShare('Message link shared.');
                } else {
                    await copyShareUrl(url);
                    announceShare('Message link copied.');
                }
            } catch (error) {
                if (!error || error.name !== 'AbortError') {
                    announceShare('Could not share automatically. Use the permalink to copy the address.');
                }
            } finally {
                button.disabled = false;
            }
        });
    });

    // ---- Auto-dismiss flash ---------------------------------------------
    var flash = document.querySelector('.flash[data-auto-dismiss]');
    if (flash) {
        setTimeout(function () {
            flash.classList.add('hide');
            setTimeout(function () { flash.remove(); }, 400);
        }, 4000);
    }
})();
