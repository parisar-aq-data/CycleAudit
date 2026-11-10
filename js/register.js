/* register.js — behaviour for auth/register.php.
   Show/hide password, the ready class and the "Please wait" button come from
   js/auth_simple.js. This file adds the live password checks and keeps the
   name, age and mobile boxes tidy. The server repeats every check. */
(function () {
  'use strict';

  var $ = function (id) { return document.getElementById(id); };
  var pass = $('inp-pass'), conf = $('inp-conf');

  // ── Password strength + rules ─────────────────────────────────
  var COLOURS = ['', '#b42318', '#d9822b', '#d4a017', '#6ea32f', '#3d8a24'];
  var WIDTHS  = ['0%', '22%', '42%', '62%', '82%', '100%'];
  var LABELS  = ['', 'Weak', 'Fair', 'Fair', 'Good', 'Strong'];

  function setRule(name, ok) {
    var li = document.querySelector('.rules [data-rule="' + name + '"]');
    if (li) li.classList.toggle('ok', ok);
  }
  function checkPassword() {
    var v = pass.value, s = 0;
    if (v.length >= 12) s++;
    if (v.length >= 16) s++;
    if (/[A-Z]/.test(v)) s++;
    if (/[0-9]/.test(v)) s++;
    if (/[^A-Za-z0-9]/.test(v)) s++;
    var fill = $('sf');
    fill.style.width = WIDTHS[s];
    fill.style.background = COLOURS[s];
    $('sl').textContent = v.length ? (LABELS[s] || 'Weak') : 'Enter a password';
    setRule('len', v.length >= 12);
    setRule('alpha', /[A-Za-z]/.test(v));
    setRule('num', /[0-9]/.test(v));
    checkMatch();
  }
  function checkMatch() {
    var m = $('match');
    if (!conf.value) { m.hidden = true; return; }
    var same = conf.value === pass.value;
    m.hidden = false;
    m.className = 'match ' + (same ? 'ok' : 'bad');
    m.textContent = same ? 'Passwords match' : 'Passwords do not match yet';
  }
  pass.addEventListener('input', checkPassword);
  conf.addEventListener('input', checkMatch);
  if (pass.value) checkPassword();

  // ── Keep the boxes tidy ───────────────────────────────────────
  $('inp-phone').addEventListener('input', function () {
    this.value = this.value.replace(/\D/g, '').slice(0, 10);
  });
  $('inp-name').addEventListener('input', function () {
    this.value = this.value.replace(/[^A-Za-z\s'\-\.]/g, '');
  });
  $('inp-age').addEventListener('input', function () {
    if (+this.value > 80) this.value = 80;
    if (+this.value < 0) this.value = '';
  });

  // ── After a failed submit, jump to the first problem ──────────
  var bad = document.querySelector('.fg.err input:not([type=radio])') || document.querySelector('.fg.err .seg input');
  if (bad) {
    var target = bad.closest('.fg');
    if (target && target.scrollIntoView) target.scrollIntoView({ block: 'center' });
    if (bad.type !== 'radio') bad.focus({ preventScroll: true });
  }
})();
