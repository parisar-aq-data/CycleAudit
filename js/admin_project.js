// Admin project page: "Edit project" (name, date, programme info, City Leader) and
// "Delete project" (type the project name to confirm). Plain fetch + the page's CSRF token.
(function () {
  'use strict';
  var app = document.getElementById('caApp');
  var editBg = document.getElementById('apEditBg');
  var delBg = document.getElementById('apDeleteBg');
  if (!app || !editBg || !delBg) return;
  var CSRF = app.dataset.csrf || '';
  var AUDIT_ID = parseInt(app.dataset.auditId, 10) || 0;
  var toastEl = document.getElementById('caToast');

  function toast(msg, type) {
    if (!toastEl) return;
    toastEl.textContent = msg;
    toastEl.className = 'ca-toast show' + (type === 'error' ? ' error' : '');
    setTimeout(function () { toastEl.className = 'ca-toast'; }, 3600);
  }

  async function post(url, payload) {
    var res = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF, 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify(payload)
    });
    var data = null;
    try { data = await res.json(); } catch (e) { /* keep null */ }
    if (!res.ok || !data || !data.success) {
      var err = new Error((data && data.error) || 'Something went wrong.');
      err.fields = (data && data.errors) || {};
      throw err;
    }
    return data;
  }

  function showFieldErrors(form, fields) {
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

  function open(bg) { bg.hidden = false; }
  function close(bg) { bg.hidden = true; }
  [editBg, delBg].forEach(function (bg) {
    bg.addEventListener('mousedown', function (ev) { if (ev.target === bg) close(bg); });
  });
  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape') { close(editBg); close(delBg); }
  });

  // ── Edit ────────────────────────────────────────────────
  var editForm = document.getElementById('apEditForm');
  var editSave = document.getElementById('apEditSave');
  var leaderSel = document.getElementById('apLeader');
  document.getElementById('apEditBtn').addEventListener('click', function () {
    open(editBg);
    editForm.elements['name'].focus();
  });
  // "Assign City Leader" opens the same dialog with the leader list ready.
  document.getElementById('apAssignBtn').addEventListener('click', function () {
    open(editBg);
    leaderSel.focus();
  });
  document.getElementById('apEditCancel').addEventListener('click', function () { close(editBg); });

  editForm.addEventListener('submit', async function (ev) {
    ev.preventDefault();
    var payload = {
      audit_id: AUDIT_ID,
      name: editForm.elements['name'].value,
      audit_date: editForm.elements['audit_date'].value,
      programme_info: editForm.elements['programme_info'].value
    };
    if (leaderSel.value) payload.leader_user_id = parseInt(leaderSel.value, 10);
    editSave.disabled = true;
    try {
      await post('../api/admin/audit_update.php', payload);
      window.location.reload();
    } catch (e) {
      showFieldErrors(editForm, e.fields);
      toast(e.message, 'error');
      editSave.disabled = false;
    }
  });

  // ── Delete ──────────────────────────────────────────────
  var confirmInput = document.getElementById('apDelConfirm');
  var goBtn = document.getElementById('apDelGo');
  var expected = confirmInput.getAttribute('data-name') || '';
  var reasonInput = document.getElementById('apDelReason');
  function delReady() { return confirmInput.value.trim() === expected && reasonInput.value.trim().length >= 5; }
  document.getElementById('apDeleteBtn').addEventListener('click', function () {
    confirmInput.value = '';
    reasonInput.value = '';
    goBtn.disabled = true;
    open(delBg);
    confirmInput.focus();
  });
  document.getElementById('apDelCancel').addEventListener('click', function () { close(delBg); });
  confirmInput.addEventListener('input', function () { goBtn.disabled = !delReady(); });
  reasonInput.addEventListener('input', function () { goBtn.disabled = !delReady(); });
  goBtn.addEventListener('click', async function () {
    goBtn.disabled = true;
    try {
      await post('../api/admin/audit_delete.php', { audit_id: AUDIT_ID, confirm_name: confirmInput.value, reason: reasonInput.value });
      window.location.href = 'platform_dashboard.php?notice=project_deleted_ok';
    } catch (e) {
      var wrap = confirmInput.closest('.ca-field');
      if (wrap) { wrap.classList.add('bad'); var er = wrap.querySelector('.ca-err'); if (er) er.textContent = e.message; }
      toast(e.message, 'error');
      goBtn.disabled = !delReady();
    }
  });
})();
