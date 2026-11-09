/* H6: segment page event handlers (replaces inline onclick/oninput/... attributes) */
(function () {
    var actions = {
        'road-search': [
            ['input',   function (e) { roadSearchFilter(e.currentTarget.value); }],
            ['focus',   function ()  { roadDropdownOpen(); }],
            ['keydown', function (e) { roadSearchKeydown(e); }]
        ],
        'validate-custom-road': [['input', function (e) { validateCustomRoad(e.currentTarget); }]],
        'sanitize-numeric':     [['input', function (e) { sanitizeNumericInput(e.currentTarget); }]],
        'generate-segments':    [['click', function () { generateAutoSegments(); }]],
        'edit-road':            [['click', function () { editRoadInfo(); }]],
        'go-dashboard':         [['click', function () { goToDashboard(); }]],
        'download-score':       [['click', function () { downloadRoadScore(); }]],
        'finalize-road':        [['click', function () { finalizeRoad(); }]],
        'modal-backdrop':       [['click', function (e) { if (e.target === e.currentTarget) { closeEditModal(); } }]],
        'close-edit-modal':     [['click', function () { closeEditModal(); }]],
        'confirm-edit-road':    [['click', function () { confirmEditRoad(); }]]
    };
    document.querySelectorAll('[data-segment-action]').forEach(function (el) {
        var list = actions[el.getAttribute('data-segment-action')] || [];
        list.forEach(function (pair) { el.addEventListener(pair[0], pair[1]); });
    });
})();

/* H6: segment page, buttons built by JavaScript */
document.addEventListener('click', function (e) {
    var el = e.target.closest ? e.target.closest('[data-segment-dyn]') : null;
    if (!el) { return; }
    switch (el.getAttribute('data-segment-dyn')) {
        case 'road-select-custom': roadSelectCustom(); break;
        case 'edit-audited':       editAuditedSegment(Number(el.getAttribute('data-seg-id'))); break;
        case 'audit-segment':      auditSegment(Number(el.getAttribute('data-seg-id'))); break;
    }
});
