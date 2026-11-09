/* H6: audit form event handlers (replaces inline onclick/oninput/... attributes).
   Loaded BEFORE js/form.js so these listeners run first, as the inline attributes did. */
(function () {
    function arg(e) { return e.currentTarget.getAttribute('data-fa-arg'); }

    /* controls that exist in the page itself: one listener per element */
    var actions = {
        'submit-audit':          [['submit', function (e) { e.preventDefault(); submitFullAudit(); }]],
        'do-reset':              [['click',  function () { doReset(); }]],
        'close-confirm':         [['click',  function () { closeConfirm(); }]],
        'scroll-top':            [['click',  function () { window.scrollTo({ top: 0, behavior: 'smooth' }); }]],
        'clear-error':           [['input',  function (e) { clearError(arg(e)); }]],
        'clear-error-self':      [['input',  function (e) { clearError(e.currentTarget.id); }]],
        'fill-gps':              [['click',  function (e) { fillGPS(arg(e)); }]],
        'clear-req-error':       [['change', function (e) { clearReqError(arg(e)); }]],
        'dropdown-search':       [['input',  function (e) { filterList(arg(e), e.currentTarget); }],
                                  ['focus',  function (e) { openDropdown(arg(e)); }]],
        'toggle-missing-length': [['change', function (e) { toggleMissingLength(e.currentTarget); }]],
        'missing-length':        [['input',  function (e) { missingLengthInput(e.currentTarget); }],
                                  ['blur',   function (e) { missingLengthBlur(e.currentTarget); }]],
        'add-intersection':      [['click',  function () { addIntersection(); }]],
        'footpath-score':        [['change', function () { updateFootpathScore(); }]],
        'adjust-counter':        [['click',  function (e) {
                                      adjustCounter(arg(e), Number(e.currentTarget.getAttribute('data-fa-delta')));
                                  }]],
        'counter':               [['input',  function (e) { counterInput(arg(e)); }],
                                  ['blur',   function (e) { counterBlur(arg(e)); }],
                                  ['wheel',  function (e) { e.preventDefault(); }, { passive: false }]],
        'sanitize-numeric':      [['input',  function (e) { sanitizeNumericInput(e.currentTarget); }]],
        'reset-form':            [['click',  function () { resetForm(); }]]
    };
    document.querySelectorAll('[data-form-action]').forEach(function (el) {
        var list = actions[el.getAttribute('data-form-action')] || [];
        list.forEach(function (item) { el.addEventListener(item[0], item[1], item[2]); });
    });

    /* controls that js/form.js builds later: one listener on the document */
    function num(el, name) { return Number(el.getAttribute(name)); }
    var dynamic = {
        'toggle-obstruction':  { change: function (el) {
                                    toggleObstruction(el.getAttribute('data-fa-type'), el.getAttribute('data-fa-item'));
                                } },
        'adjust-counter':      { click: function (el) {
                                    adjustCounter(el.getAttribute('data-fa-id'), num(el, 'data-fa-delta'));
                                } },
        'counter':             { input:    function (el) { counterInput(el.getAttribute('data-fa-id')); },
                                 focusout: function (el) { counterBlur(el.getAttribute('data-fa-id')); } },
        'fill-gps':            { click: function (el) { fillGPS(el.getAttribute('data-fa-target')); } },
        'remove-intersection': { click: function (el) { removeIntersection(num(el, 'data-fa-uid')); } },
        'toggle-intersection': { click: function (el) { toggleIntersection(num(el, 'data-fa-uid')); } },
        'fsm-reload':          { click: function () {
                                    FormStateManager.clear();
                                    var banner = document.getElementById('fsm-banner');
                                    if (banner) { banner.remove(); }
                                    location.reload();
                                } }
    };
    function dispatch(e) {
        var el = e.target;
        while (el && el !== document) {
            if (el.nodeType === 1 && el.hasAttribute('data-form-dyn')) {
                var h = dynamic[el.getAttribute('data-form-dyn')];
                /* blur-type events do not bubble in the original, so only the element itself counts */
                if (h && h[e.type] && (e.type !== 'focusout' || el === e.target)) { h[e.type](el, e); }
                if (e.cancelBubble) { break; }
            }
            el = el.parentNode;
        }
    }
    document.addEventListener('click', dispatch);               /* bubbling, like onclick */
    ['input', 'change', 'focusout'].forEach(function (type) {   /* capture: run before form-level listeners, like inline handlers did */
        document.addEventListener(type, dispatch, true);
    });
})();
