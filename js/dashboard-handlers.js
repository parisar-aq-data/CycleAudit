/* H6: dashboard event handlers (replace inline onclick attributes). */
(function () {
    var actions = {
        'toggle-user-menu':   function (e) { toggleUserMenu(e); },
        'close-delete-modal': function ()  { closeDeleteModal(); }
    };
    document.querySelectorAll('[data-dash-action]').forEach(function (el) {
        var fn = actions[el.getAttribute('data-dash-action')];
        if (fn) { el.addEventListener('click', fn); }
    });
})();
