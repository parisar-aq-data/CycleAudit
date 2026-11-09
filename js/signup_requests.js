/* js/signup_requests.js - the "Sign-up requests" panel on the City Leader / Admin dashboards.
   Approve or Reject sends the request id. Every decision is made on the server
   (api/admin/signup_requests.php); this file only sends the click and shows the answer. */
(function () {
  'use strict';
  var panel = document.getElementById('suPanel');
  if (!panel) { return; }
  var csrf = panel.getAttribute('data-csrf') || '';
  var countEl = document.getElementById('suCount');

  function send(payload) {
    return fetch('../api/admin/signup_requests.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf, 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify(payload)
    }).then(function (r) { return r.json(); });
  }

  function setBusy(item, busy) {
    item.querySelectorAll('button').forEach(function (el) { el.disabled = busy; });
  }

  function say(item, text, kind) {
    var m = item.querySelector('.su-msg');
    m.textContent = text;
    m.className = 'su-msg ' + (kind || '');
  }

  function finished(item, text) {
    item.classList.add('done');
    item.querySelector('.su-act').hidden = true;
    say(item, text, 'ok');
    if (countEl) {
      var open = panel.querySelectorAll('.su-item:not(.done)').length;
      countEl.textContent = String(open);
      if (open === 0) { countEl.hidden = true; }
    }
  }

  function failed(item, res) {
    setBusy(item, false);
    say(item, (res && res.error) || 'Something went wrong. Please try again.', 'err');
  }

  panel.addEventListener('click', function (e) {
    var btn = e.target.closest('button');
    if (!btn) { return; }
    var item = btn.closest('.su-item');
    if (!item) { return; }
    var id = parseInt(item.getAttribute('data-id'), 10);
    var name = (item.querySelector('.su-who b') || {}).textContent || 'this person';
    var action = btn.classList.contains('su-approve') ? 'approve' : (btn.classList.contains('su-reject') ? 'reject' : '');
    if (!action) { return; }

    var question = action === 'approve'
      ? 'Approve ' + name + '? They can sign in straight away.'
      : 'Reject the sign-up from ' + name + '? They will not be able to sign in.';
    if (!window.confirm(question)) { return; }

    setBusy(item, true); say(item, '', '');
    send({ action: action, id: id })
      .then(function (res) {
        if (res && res.success) {
          finished(item, action === 'approve' ? 'Approved. ' + name + ' can now sign in.' : 'Rejected.');
        } else { failed(item, res); }
      })
      .catch(function () { failed(item, null); });
  });
})();
