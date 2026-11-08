// Admin dashboard: "Delete" on each audit in progress. Same rules as the project page:
// type the project name and give a reason (5 to 200 characters) that the City Leader
// and surveyors will see. Plain fetch + the page's CSRF token.
(function () {
  'use strict';
  var app = document.getElementById('npApp');
  var bg = document.getElementById('dpDeleteBg');
  if (!app || !bg) return;
  var CSRF = app.dataset.csrf || '';
  var nameEl = document.getElementById('dpDelName');
  var confirmInput = document.getElementById('dpDelConfirm');
  var reasonInput = document.getElementById('dpDelReason');
  var goBtn = document.getElementById('dpDelGo');
  var toastEl = document.getElementById('caToast');
  var auditId = 0, expected = '';

  function toast(msg, type) {
    if (!toastEl) return;
    toastEl.textContent = msg;
    toastEl.className = 'ca-toast show' + (type === 'error' ? ' error' : '');
    setTimeout(function () { toastEl.className = 'ca-toast'; }, 3600);
  }
  function ready() {
    return confirmInput.value.trim() === expected && reasonInput.value.trim().length >= 5;
  }
  function clearErrors() {
    bg.querySelectorAll('.ca-field').forEach(function (f) {
      f.classList.remove('bad');
      var e = f.querySelector('.ca-err'); if (e) e.textContent = '';
    });
  }

  document.querySelectorAll('.dpDelete').forEach(function (b) {
    b.addEventListener('click', function () {
      auditId = parseInt(b.dataset.id, 10) || 0;
      expected = b.dataset.name || '';
      nameEl.textContent = expected;
      confirmInput.value = '';
      confirmInput.placeholder = expected;
      reasonInput.value = '';
      goBtn.disabled = true;
      clearErrors();
      bg.hidden = false;
      confirmInput.focus();
    });
  });
  document.getElementById('dpDelCancel').addEventListener('click', function () { bg.hidden = true; });
  confirmInput.addEventListener('input', function () { goBtn.disabled = !ready(); });
  reasonInput.addEventListener('input', function () { goBtn.disabled = !ready(); });

  goBtn.addEventListener('click', async function () {
    goBtn.disabled = true;
    clearErrors();
    try {
      var res = await fetch('../api/admin/audit_delete.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF, 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({ audit_id: auditId, confirm_name: confirmInput.value, reason: reasonInput.value })
      });
      var data = null;
      try { data = await res.json(); } catch (e) { /* keep null */ }
      if (!res.ok || !data || !data.success) {
        var err = new Error((data && data.error) || 'Something went wrong.');
        err.fields = (data && data.errors) || {};
        throw err;
      }
      window.location.href = 'platform_dashboard.php?notice=project_deleted_ok';
    } catch (e) {
      var f = e.fields || {};
      var target = f.reason ? reasonInput : confirmInput;
      var wrap = target.closest('.ca-field');
      if (wrap) { wrap.classList.add('bad'); var er = wrap.querySelector('.ca-err'); if (er) er.textContent = e.message; }
      toast(e.message, 'error');
      goBtn.disabled = !ready();
    }
  });
})();
