/* js/profile.js — extracted from pages/profile.php */

const API  = '../api/user/profile.php';

// Strips non-digits and caps length at 10 (Indian mobile number format).
function sanitizePhoneInput(el) {
  const v = el.value.replace(/\D/g, '').slice(0, 10);
  if (v !== el.value) el.value = v;
}

// ── Tab switching ──────────────────────────────────────────────
document.querySelectorAll('.tab-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('tab-' + btn.dataset.tab).classList.add('active');
  });
});

// ── Show alert ─────────────────────────────────────────────────
function showAlert(zone, type, msg) {
  const el  = document.getElementById('alert-' + zone);
  const txt = document.getElementById('alert-' + zone + '-msg');
  el.className = `alert alert-${type} show`;
  txt.textContent = msg;
  el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  clearTimeout(el._t);
  el._t = setTimeout(() => el.classList.remove('show'), 5000);
}

// ── Load profile from API ──────────────────────────────────────
async function loadProfile() {
  const res  = await fetch(API, { headers: { 'Accept': 'application/json' } });
  const data = await res.json();
  if (!data.success) return;

  const u = data.user;
  document.getElementById('f-name').value    = u.name    || '';
  document.getElementById('f-phone').value   = u.phone   || '';
  document.getElementById('f-org').value     = u.organisation || '';
  document.getElementById('f-city').value    = u.home_city || '';
  document.getElementById('f-state').value   = u.home_state || '';
  if (window.initLocationFields) initLocationFields('f-city', 'f-state');
  document.getElementById('f-gender').value  = u.gender  || '';
  document.getElementById('f-age').value     = u.age     || '';
  document.getElementById('f-address').value = u.address || '';
  document.getElementById('f-pid').value     = u.public_id || '';

  // Hero bar
  document.getElementById('hero-name').textContent  = u.name;
  document.getElementById('hero-email').textContent = u.email;

  const verBadge = document.getElementById('hero-verified');
  if (u.email_verified == 1) {
    verBadge.textContent = '✓ Verified';
    verBadge.className   = 'badge badge-verified';
  } else {
    verBadge.textContent = '⚠ Unverified';
    verBadge.className   = 'badge badge-unverified';
  }
}
loadProfile();

// ── Save profile ───────────────────────────────────────────────
async function saveProfile() {
  const cityEl = document.getElementById('f-city');
  if (!cityEl.checkValidity()) { cityEl.reportValidity(); return; }
  const btn = document.getElementById('btn-save-profile');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner"></span> Saving…';

  const body = {
    action:       'update_profile',
    csrf_token:   CSRF,
    name:         document.getElementById('f-name').value.trim(),
    phone:        document.getElementById('f-phone').value.trim(),
    organisation: document.getElementById('f-org').value.trim(),
    home_city:    document.getElementById('f-city').value.trim(),
    home_state:   document.getElementById('f-state').value.trim(),
    gender:       document.getElementById('f-gender').value,
    age:          document.getElementById('f-age').value,
    address:      document.getElementById('f-address').value.trim(),
  };

  const res  = await fetch(API, { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body) });
  const data = await res.json();

  btn.disabled = false;
  btn.innerHTML = '<svg viewBox="0 0 24 24" fill="currentColor" style="width:15px;height:15px"><path d="M17 3H5c-1.11 0-2 .9-2 2v14c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V7l-4-4zm-5 16c-1.66 0-3-1.34-3-3s1.34-3 3-3 3 1.34 3 3-1.34 3-3 3zm3-10H5V5h10v4z"/></svg> Save Changes';

  if (data.success) {
    showAlert('personal', 'success', data.message);
    document.getElementById('hero-name').textContent = body.name;
  } else {
    showAlert('personal', 'error', data.error);
  }
}

// ── Update email ───────────────────────────────────────────────
async function updateEmail() {
  const body = {
    action:      'update_email',
    csrf_token:  CSRF,
    email:       document.getElementById('f-new-email').value.trim(),
    password:    document.getElementById('f-email-pw').value,
  };

  const res  = await fetch(API, { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body) });
  const data = await res.json();

  if (data.success) {
    showAlert('account', 'success', data.message);
    document.getElementById('hero-email').textContent = body.email;
    document.getElementById('f-new-email').value = '';
    document.getElementById('f-email-pw').value  = '';
  } else {
    showAlert('account', 'error', data.error);
  }
}

// ── Change password ────────────────────────────────────────────
async function changePassword() {
  const body = {
    action:           'change_password',
    csrf_token:       CSRF,
    current_password: document.getElementById('f-cur-pw').value,
    new_password:     document.getElementById('f-new-pw').value,
    confirm_password: document.getElementById('f-confirm-pw').value,
  };

  const res  = await fetch(API, { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body) });
  const data = await res.json();

  if (data.success) {
    showAlert('account', 'success', data.message);
    document.getElementById('f-cur-pw').value     = '';
    document.getElementById('f-new-pw').value     = '';
    document.getElementById('f-confirm-pw').value = '';
    document.getElementById('pw-bar').style.width = '0';
  } else {
    showAlert('account', 'error', data.error);
  }
}

// ── Password strength meter ────────────────────────────────────
function checkStrength(pw) {
  let score = 0;
  if (pw.length >= 12) score++;
  if (pw.length >= 16) score++;
  if (/[A-Z]/.test(pw)) score++;
  if (/[0-9]/.test(pw)) score++;
  if (/[^a-zA-Z0-9]/.test(pw)) score++;

  const bar   = document.getElementById('pw-bar');
  const hint  = document.getElementById('pw-hint');
  const colors = ['#ef4444','#f97316','#eab308','#22c55e','#16a34a'];
  const labels = ['Too weak','Weak','Fair','Strong','Very strong'];
  bar.style.width      = (score * 20) + '%';
  bar.style.background = colors[score - 1] || '#e5e7eb';
  hint.textContent     = pw ? labels[score - 1] || 'Too weak' : 'Enter a new password';
}

// ── Avatar upload ──────────────────────────────────────────────
function uploadAvatar(input) {
  const file = input.files[0];
  if (!file) return;

  if (file.size > 2 * 1024 * 1024) {
    alert('Image must be under 2 MB.');
    return;
  }

  const reader = new FileReader();
  reader.onload = async e => {
    const body = {
      action:     'upload_avatar',
      csrf_token: CSRF,
      image:      e.target.result,
    };

    const res  = await fetch(API, { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body) });
    const data = await res.json();

    if (data.success) {
      // Update ALL avatar displays: sidebar button, popup header, profile page
      const src = data.picture_url;
      ['avatar-display', 'sb-av', 'popupAv'].forEach(id => {
        const wrap = document.getElementById(id);
        if (!wrap) return; // element may not exist on this page

        let img = wrap.querySelector('img');
        if (!img) {
          // Remove initials text/span safely — do NOT use innerHTML (would
          // destroy child elements like the edit pencil button)
          const initialsEl = wrap.querySelector('span');
          if (initialsEl) initialsEl.remove();
          // Also remove any bare text node carrying the initials
          wrap.childNodes.forEach(node => {
            if (node.nodeType === Node.TEXT_NODE && node.textContent.trim()) {
              node.remove();
            }
          });
          img = document.createElement('img');
          img.alt = 'Avatar';
          wrap.insertBefore(img, wrap.firstChild);
        }
        img.src = src;
      });
    } else {
      alert('Upload failed: ' + data.error);
    }
  };
  reader.readAsDataURL(file);
}

/* H6: profile page event handlers */
(function () {
    var actions = {
        'pick-avatar':     ['click',  function () { document.getElementById('avatar-file').click(); }],
        'upload-avatar':   ['change', function (e) { uploadAvatar(e.currentTarget); }],
        'sanitize-phone':  ['input',  function (e) { sanitizePhoneInput(e.currentTarget); }],
        'save-profile':    ['click',  function () { saveProfile(); }],
        'reset-profile':   ['click',  function () { loadProfile(); }],
        'update-email':    ['click',  function () { updateEmail(); }],
        'check-strength':  ['input',  function (e) { checkStrength(e.currentTarget.value); }],
        'change-password': ['click',  function () { changePassword(); }]
    };
    document.querySelectorAll('[data-profile-action]').forEach(function (el) {
        var a = actions[el.getAttribute('data-profile-action')];
        if (a) { el.addEventListener(a[0], a[1]); }
    });
})();
