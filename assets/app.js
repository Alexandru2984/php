/* Guestbook client behaviour — theme, counters, private drafts, safe previews,
   sharing, confirmations and auto-dismissing flash. No inline handlers. */
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

    // ---- Opt-in local drafts and inert text preview ---------------------
    var composeForm = document.querySelector('[data-compose-form]');
    if (composeForm) {
        var authorInput = composeForm.querySelector('#author');
        var messageInput = composeForm.querySelector('#message');
        var draftTools = composeForm.querySelector('[data-draft-tools]');
        var draftOptIn = composeForm.querySelector('[data-draft-opt-in]');
        var draftDelete = composeForm.querySelector('[data-draft-delete]');
        var draftStatus = composeForm.querySelector('[data-draft-status]');
        var previewToggle = composeForm.querySelector('[data-preview-toggle]');
        var preview = composeForm.querySelector('[data-message-preview]');
        var previewAuthor = composeForm.querySelector('[data-preview-author]');
        var previewBody = composeForm.querySelector('[data-preview-body]');
        var draftKey = 'gb-compose-draft-v1';
        var draftLifetime = 24 * 60 * 60 * 1000;
        var saveTimer = null;

        function announceDraft(message) {
            if (!draftStatus) return;
            draftStatus.textContent = '';
            window.requestAnimationFrame(function () { draftStatus.textContent = message; });
        }

        function removeDraft() {
            try { localStorage.removeItem(draftKey); } catch (e) {}
            if (draftDelete) draftDelete.hidden = true;
        }

        function storageAvailable() {
            try {
                var probe = draftKey + '-probe';
                localStorage.setItem(probe, '1');
                localStorage.removeItem(probe);
                return true;
            } catch (e) {
                return false;
            }
        }

        function updatePreview() {
            if (!previewAuthor || !previewBody || !authorInput || !messageInput) return;
            previewAuthor.textContent = authorInput.value.trim() || 'Anonymous';
            previewBody.textContent = messageInput.value || 'Start typing to preview your message.';
        }

        function validDraft(value, now) {
            return value
                && typeof value === 'object'
                && typeof value.author === 'string'
                && typeof value.message === 'string'
                && Number.isInteger(value.savedAt)
                && Number.isInteger(value.expiresAt)
                && value.savedAt <= now + (5 * 60 * 1000)
                && value.expiresAt > now
                && value.expiresAt <= value.savedAt + draftLifetime
                && authorInput
                && messageInput
                && value.author.length <= (parseInt(authorInput.maxLength, 10) || 60)
                && value.message.length <= (parseInt(messageInput.maxLength, 10) || 2000);
        }

        function saveDraft() {
            if (!draftOptIn || !draftOptIn.checked || !authorInput || !messageInput) return;
            var now = Date.now();
            var value = {
                author: authorInput.value,
                message: messageInput.value,
                savedAt: now,
                expiresAt: now + draftLifetime,
            };
            try {
                localStorage.setItem(draftKey, JSON.stringify(value));
                if (draftDelete) draftDelete.hidden = false;
                announceDraft('Draft saved on this device.');
            } catch (e) {
                draftOptIn.checked = false;
                announceDraft('Draft storage is unavailable in this browser.');
            }
        }

        function scheduleDraftSave() {
            updatePreview();
            if (!draftOptIn || !draftOptIn.checked) return;
            if (saveTimer !== null) window.clearTimeout(saveTimer);
            saveTimer = window.setTimeout(saveDraft, 250);
        }

        if (previewToggle && preview) {
            previewToggle.hidden = false;
            previewToggle.addEventListener('click', function () {
                var opening = preview.hidden;
                preview.hidden = !opening;
                previewToggle.setAttribute('aria-expanded', opening ? 'true' : 'false');
                previewToggle.textContent = opening ? 'Hide preview' : 'Preview';
                if (opening) updatePreview();
            });
        }

        if (storageAvailable() && draftTools && draftOptIn && draftDelete) {
            draftTools.hidden = false;
            if (composeForm.hasAttribute('data-clear-local-draft')) removeDraft();

            var storedDraft = null;
            try {
                var encodedDraft = localStorage.getItem(draftKey);
                if (encodedDraft !== null) storedDraft = JSON.parse(encodedDraft);
            } catch (e) {
                removeDraft();
            }

            var now = Date.now();
            if (storedDraft !== null && validDraft(storedDraft, now)) {
                draftOptIn.checked = true;
                draftDelete.hidden = false;
                if (authorInput.value === '' && messageInput.value === '') {
                    authorInput.value = storedDraft.author;
                    messageInput.value = storedDraft.message;
                    announceDraft('Draft restored from this device.');
                }
            } else if (storedDraft !== null) {
                removeDraft();
            }

            draftOptIn.addEventListener('change', function () {
                if (draftOptIn.checked) saveDraft();
                else {
                    removeDraft();
                    announceDraft('Draft saving turned off.');
                }
            });
            draftDelete.addEventListener('click', function () {
                if (saveTimer !== null) window.clearTimeout(saveTimer);
                draftOptIn.checked = false;
                removeDraft();
                announceDraft('Saved draft deleted. Your current text is unchanged.');
            });
            authorInput.addEventListener('input', scheduleDraftSave);
            messageInput.addEventListener('input', scheduleDraftSave);
            composeForm.addEventListener('submit', function () {
                if (saveTimer !== null) window.clearTimeout(saveTimer);
                saveDraft();
            });
        } else if (draftTools) {
            draftTools.hidden = true;
        }
        updatePreview();
    }

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
