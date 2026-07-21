/* Runs before paint to apply the saved theme with no flash of the wrong colors.
   Kept tiny and separate so it can load render-blocking in <head>. */
(function () {
    try {
        var t = localStorage.getItem('gb-theme');
        if (t === 'dark' || t === 'light') {
            document.documentElement.setAttribute('data-theme', t);
        }
    } catch (e) { /* storage blocked — fall back to prefers-color-scheme */ }
})();
