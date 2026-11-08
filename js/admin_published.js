// Admin "Published audits" page. Add a past audit (with an optional PDF), take a live audit off
// the home page (Unpublish) or delete one. Plain fetch + the page's CSRF token (data-csrf on #npApp).
(function () {
  'use strict';
  var app = document.getElementById('npApp');
  var addBg = document.getElementById('paAddBg');
  var confBg = document.getElementById('pubConfirmBg');
  if (!app || !addBg || !confBg) return;
  var CSRF = app.dataset.csrf || '';
  var toastEl = document.getElementById('caToast');

  function toast(msg, type) {
    if (!toastEl) return;
    toastEl.textContent = msg;
    toastEl.className = 'ca-toast show' + (type === 'error' ? ' error' : '');
    setTimeout(function () { toastEl.className = 'ca-toast'; }, 3600);
  }
  function open(bg) { bg.hidden = false; }
  function close(bg) { bg.hidden = true; }
  [addBg, confBg].forEach(function (bg) {
    bg.addEventListener('mousedown', function (ev) { if (ev.target === bg) close(bg); });
  });
  document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') { close(addBg); close(confBg); } });

  async function send(url, body, isForm) {
    var headers = { 'X-CSRF-Token': CSRF, 'X-Requested-With': 'XMLHttpRequest' };
    if (!isForm) headers['Content-Type'] = 'application/json';
    var res = await fetch(url, { method: 'POST', headers: headers, body: isForm ? body : JSON.stringify(body) });
    var data = null;
    try { data = await res.json(); } catch (e) { /* keep null */ }
    if (!res.ok || !data || !data.success) {
      var err = new Error((data && data.error) || 'Something went wrong.');
      err.fields = (data && data.errors) || {};
      throw err;
    }
    return data;
  }
  function reload(notice) { window.location.href = 'published_audits.php?notice=' + notice; }

  // ── Add a past audit ───────────────────────────────────────
  var form = document.getElementById('paAddForm');
  var saveBtn = document.getElementById('paAddSave');
  var cityInput = form.city_name, stateInput = form.state;
  var byName = {}, aliases = {};
  var list = document.getElementById('npCityList');   // the same list "+ New Project" uses
  if (list) {
    list.querySelectorAll('option').forEach(function (o) {
      byName[o.value.trim().toLowerCase()] = { label: o.value, state: o.getAttribute('data-state') || '' };
    });
    try { aliases = JSON.parse(list.getAttribute('data-aliases') || '{}'); } catch (e) { aliases = {}; }
  }
  function syncCity() {
    var k = cityInput.value.replace(/\s+/g, ' ').trim().toLowerCase();
    var hit = byName[k] || (aliases[k] && byName[String(aliases[k]).toLowerCase()]);
    stateInput.value = hit ? hit.state : '';
  }
  if (window.CitySearch && list) {
    var items = Object.keys(byName).map(function (k) { return { label: byName[k].label, state: byName[k].state, boost: 0 }; });
    window.CitySearch.attach(cityInput, items, syncCity, { aliases: aliases });
  }
  cityInput.addEventListener('input', syncCity);
  cityInput.addEventListener('change', function () {
    var k = cityInput.value.replace(/\s+/g, ' ').trim().toLowerCase();
    if (!byName[k] && aliases[k] && byName[String(aliases[k]).toLowerCase()]) cityInput.value = byName[String(aliases[k]).toLowerCase()].label;
    syncCity();
  });

  function showErrors(fields) {
    form.querySelectorAll('.ca-field').forEach(function (f) { f.classList.remove('bad'); });
    Object.keys(fields || {}).forEach(function (name) {
      var input = form.querySelector('[name="' + name + '"]');
      var wrap = input && input.closest('.ca-field');
      if (!wrap) return;
      wrap.classList.add('bad');
      var e = wrap.querySelector('.ca-err');
      if (e) e.textContent = fields[name];
    });
  }

  document.getElementById('paAddBtn').addEventListener('click', function () {
    form.reset(); showErrors({}); open(addBg); cityInput.focus();
  });
  document.getElementById('paAddCancel').addEventListener('click', function () { close(addBg); });

  var reportInput = form.report;
  reportInput.addEventListener('change', function () {
    var f = reportInput.files[0];
    if (f && f.size > 8 * 1024 * 1024) {
      showErrors({ report: 'The PDF is too large. The limit is 8 MB.' });
      reportInput.value = '';
    }
  });

  form.addEventListener('submit', async function (ev) {
    ev.preventDefault();
    saveBtn.disabled = true;
    var label = saveBtn.textContent;
    saveBtn.textContent = 'Publishing…';
    try {
      await send('../api/admin/past_audit_create.php', new FormData(form), true);
      reload('past_audit_added');
    } catch (err) {
      showErrors(err.fields);
      if (!Object.keys(err.fields || {}).length) toast(err.message, 'error');
      saveBtn.disabled = false;
      saveBtn.textContent = label;
    }
  });

  // ── Unpublish / Delete ─────────────────────────────────────
  var title = document.getElementById('pubConfTitle'), text = document.getElementById('pubConfText');
  var nameWrap = document.getElementById('pubConfNameWrap'), nameInput = document.getElementById('pubConfName');
  var goBtn = document.getElementById('pubConfGo');
  var reasonWrap = document.getElementById('pubConfReasonWrap'), reasonInput = document.getElementById('pubConfReason');
  var action = null;
  function ready() {
    if (!action || !action.typeName) return true;
    return nameInput.value.trim() === action.name && reasonInput.value.trim().length >= 5;
  }

  function ask(cfg) {
    action = cfg;
    title.textContent = cfg.title;
    text.textContent = cfg.text;
    goBtn.textContent = cfg.button;
    goBtn.className = 'ca-btn' + (cfg.danger ? ' ca-btn-danger' : '');
    nameWrap.hidden = !cfg.typeName;
    nameInput.value = '';
    nameInput.placeholder = cfg.name;
    reasonWrap.hidden = !cfg.typeName;
    reasonInput.value = '';
    goBtn.disabled = !!cfg.typeName;
    open(confBg);
    if (cfg.typeName) nameInput.focus(); else goBtn.focus();
  }
  nameInput.addEventListener('input', function () { goBtn.disabled = !ready(); });
  reasonInput.addEventListener('input', function () { goBtn.disabled = !ready(); });
  document.getElementById('pubConfCancel').addEventListener('click', function () { close(confBg); });

  document.querySelectorAll('.pubUnpublish').forEach(function (b) {
    b.addEventListener('click', function () {
      var id = parseInt(b.dataset.id, 10), name = b.dataset.name;
      ask({
        title: 'Unpublish this audit?', name: name, danger: false, typeName: false, button: 'Unpublish',
        text: '“' + name + '” is hidden from the home page. Nothing is deleted and it stays approved. You can publish it again any time from this page.',
        run: function () { return send('../api/admin/audit_unpublish.php', { audit_id: id }); }, done: 'audit_unpublished'
      });
    });
  });
  document.querySelectorAll('.pubRepublish').forEach(function (b) {
    b.addEventListener('click', function () {
      var id = parseInt(b.dataset.id, 10), name = b.dataset.name;
      ask({
        title: 'Publish this audit again?', name: name, danger: false, typeName: false, button: 'Publish again',
        text: '“' + name + '” appears on the home page again.',
        run: function () { return send('../api/admin/audit_republish.php', { audit_id: id }); }, done: 'audit_republished'
      });
    });
  });
  document.querySelectorAll('.pubDelete').forEach(function (b) {
    b.addEventListener('click', function () {
      var id = parseInt(b.dataset.id, 10), name = b.dataset.name;
      if (b.dataset.kind === 'past') {
        ask({
          title: 'Delete this past audit?', name: name, danger: true, typeName: false, button: 'Delete past audit',
          text: '“' + name + '” and its PDF are removed from the home page straight away. The record stays in the database.',
          run: function () { return send('../api/admin/past_audit_delete.php', { past_audit_id: id }); }, done: 'past_audit_deleted'
        });
      } else {
        ask({
          title: 'Delete this audit?', name: name, danger: true, typeName: true, button: 'Delete audit',
          text: '“' + name + '” is removed from the home page and from every dashboard, and all work on it stops. The City Leader and surveyors are told. The data stays in the database but cannot be opened from the app. To keep it and only hide it, use Unpublish instead.',
          run: function () { return send('../api/admin/audit_delete.php', { audit_id: id, confirm_name: nameInput.value, reason: reasonInput.value }); }, done: 'project_deleted_ok'
        });
      }
    });
  });
  goBtn.addEventListener('click', async function () {
    if (!action) return;
    goBtn.disabled = true;
    try {
      await action.run();
      reload(action.done);
    } catch (err) {
      toast(err.message, 'error');
      goBtn.disabled = !ready();
    }
  });
})();
