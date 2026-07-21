/* Guestbook client behaviour — theme toggle, character counter,
   delete confirmation and auto-dismissing flash. No inline handlers (CSP-safe). */
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
        toggle.addEventListener('click', function () {
            var next = currentTheme() === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', next);
            try { localStorage.setItem('gb-theme', next); } catch (e) {}
        });
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

    // ---- Auto-dismiss flash ---------------------------------------------
    var flash = document.querySelector('.flash');
    if (flash) {
        setTimeout(function () {
            flash.classList.add('hide');
            setTimeout(function () { flash.remove(); }, 400);
        }, 4000);
    }
})();
