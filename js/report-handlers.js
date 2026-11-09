/* H6: report page event handlers (replace inline onclick attributes). */
(function () {
    document.querySelectorAll('[data-report-action="print"]').forEach(function (el) {
        el.addEventListener('click', function () { window.print(); });
    });
})();
