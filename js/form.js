// ═══════════════════════════════════════════════════════════════
//  js/form.js  —  Segment Audit Form Logic
//  Reads segment_id + session_id from URL params.
//  Submits to api/segments/submit.php with X-CSRF-Token header.
// ═══════════════════════════════════════════════════════════════

const urlParams  = new URLSearchParams(window.location.search);
const segmentId  = urlParams.get('segment_id');
const sessionId  = urlParams.get('session_id');
const editMode   = urlParams.get('edit_mode') === '1';

// road_id comes from a PHP-injected hidden field (not URL) since form.php looks it up
function getRoadId() {
  const el = document.getElementById('road_id');
  return el ? el.value : '';
}

// Strips anything but digits and a single decimal point as the user types.
// Used on text-type numeric fields (segment_width/segment_length) instead of
// type="number", since a number input's .value returns "" whenever the
// displayed text isn't a valid parseable number — making JS unable to see
// or clean up invalid characters like "-" or extra "." while typing.
function sanitizeNumericInput(el) {
  let v = el.value.replace(/[^0-9.]/g, '');
  const firstDot = v.indexOf('.');
  if (firstDot !== -1) {
    v = v.slice(0, firstDot + 1) + v.slice(firstDot + 1).replace(/\./g, '');
  }
  if (v !== el.value) el.value = v;
}

// ── Inject hidden fields ───────────────────────────────────────
if (segmentId) {
  const h = document.getElementById('segment_id');
  if (h) h.value = segmentId;
}
if (sessionId) {
  const h = document.getElementById('session_id');
  if (h) h.value = sessionId;
}
// Persist edit_mode so submit.php knows to UPDATE, not INSERT
(function () {
  let hEdit = document.getElementById('edit_mode');
  if (!hEdit) {
    hEdit = document.createElement('input');
    hEdit.type = 'hidden';
    hEdit.name = 'edit_mode';
    hEdit.id   = 'edit_mode';
    const form = document.getElementById('auditForm');
    if (form) form.appendChild(hEdit);
  }
  hEdit.value = editMode ? '1' : '0';
})();

// ── CSRF token ─────────────────────────────────────────────────
function getCsrf() {
  const meta = document.querySelector('meta[name="csrf"]');
  return meta ? meta.content : (window.__CSRF__ || '');
}

// ── Obstruction option lists ───────────────────────────────────
const fixedOptions = [
  'Trees','Poles','CCTV','TrafficSignal','SignBoard',
  'TelephonePanel','ElectricalPanel','BusStand',
  'BuiltEncroachment','Bollards','PropertyEntrance','UtilityChambers',
];
const movableOptions = [
  'Hawkers','GarbageBins','ConstructionMaterial',
  'TrafficBarricade','PeopleSitting','Hoardings',
];
const parkedOptions = [
  'ReligiousLandmark','RestaurantEatery','AutoGarage',
  'CommercialRetailShops','OnStreetVending','PublicSpace',
];

// ── Dropdown open / close ──────────────────────────────────────
function openDropdown(type) {
  renderList(type, '');
  document.getElementById(type + 'List').classList.add('open');
  document.getElementById(type + 'Wrapper').classList.add('dropdown-open');
}
function closeDropdown(type) {
  document.getElementById(type + 'List').classList.remove('open');
  document.getElementById(type + 'Wrapper').classList.remove('dropdown-open');
}
document.addEventListener('click', e => {
  ['fixed','movable','parked'].forEach(type => {
    const wrapper = document.getElementById(type + 'Wrapper');
    if (wrapper && !wrapper.contains(e.target)) closeDropdown(type);
  });
});
function filterList(type, inputEl) {
  document.getElementById(type + 'List').classList.add('open');
  document.getElementById(type + 'Wrapper').classList.add('dropdown-open');
  renderList(type, inputEl.value.toLowerCase());
}
function renderList(type, filter) {
  const all       = type === 'fixed' ? fixedOptions
                  : type === 'movable' ? movableOptions : parkedOptions;
  const container = document.getElementById(type + 'List');
  container.innerHTML = '';
  const filtered  = all.filter(i => i.toLowerCase().includes(filter));
  if (!filtered.length) {
    container.insertAdjacentHTML('beforeend',
      '<div class="no-results">No results found</div>');
    return;
  }
  filtered.forEach(item => {
    const id       = type + '_' + item.replace(/\W/g, '_');
    const existing = document.getElementById(id);
    const checked  = existing ? existing.checked
                              : !!document.getElementById('block_' + id);
    container.insertAdjacentHTML('beforeend', `
      <div class="checkbox-item">
        <label>
          <input type="checkbox" id="${id}"
                 ${checked ? 'checked' : ''}
                 data-form-dyn="toggle-obstruction" data-fa-type="${type}" data-fa-item="${item}">
          ${item}
        </label>
      </div>`);
  });
}

// ════════════════════════════════════════════════════════════════
//  NUMERIC COUNTER ARCHITECTURE  (single source of truth)
//
//  Design principles:
//  • ONE oninput handler does ALL sanitisation — no keydown tricks,
//    no onfocus hacks, no competing listeners.
//  • The field is NEVER mutated during editing except to strip
//    non-digit characters.  Leading-zero removal is NOT deferred;
//    it is done inline but only when the result is unambiguous
//    (i.e. the string starts with 0 AND has more digits after it).
//  • Caret position is restored after every sanitisation so typing
//    feels native on desktop AND mobile / Android virtual keyboards
//    (which send key="Unidentified" — breaking any keydown approach).
//  • blur is the only place a missing value is snapped to "0".
//  • +/- buttons call adjustCounter which normalises via the same
//    numeric read used at submit time.
//  • No global state, no WeakMap, no closures per-field needed.
// ════════════════════════════════════════════════════════════════

// ══════════════════════════════════════════════════════════════════
//  NUMERIC COUNTER ARCHITECTURE  v3 — blank-display / zero-store
//
//  Display contract:
//  • Fields are VISUALLY BLANK by default — value="" in HTML
//  • Blank display = numeric value 0 (converted at submit/button)
//  • Typing is uncontrolled mid-edit: only non-digits are stripped.
//  • Leading zeros are collapsed on blur, never during typing.
//
//  Event model — ONE handler per concern, zero overlap:
//    oninput  → counterInput   strips non-digit chars only
//    onblur   → counterBlur    collapses leading zeros, blank stays blank
//    onclick  → adjustCounter  +/- buttons, blank-aware
//
//  Blank-to-zero happens ONLY at:
//    • adjustCounter: reads blank as 0 before applying delta
//    • submitFullAudit: _numVal() reads blank as 0 in FormData
//    • PHP backend: (int)$_POST[field] coerces "" to 0
// ══════════════════════════════════════════════════════════════════

// ── Safe integer read — blank/invalid treated as 0 ————————————
function _numVal(el) {
  if (!el) return 0;
  const n = parseInt(el.value, 10);
  return Number.isFinite(n) && n >= 0 ? n : 0;
}

// ── +/- button handler ——————————————————————————————————————————————
// blank + (+) → "1"     "1" + (-) → blank (0 shown as blank)
function adjustCounter(id, delta) {
  const el = document.getElementById(id);
  if (!el) return;
  const next = Math.max(0, _numVal(el) + delta);
  el.value = next === 0 ? '' : String(next);
}

// ── oninput: strip non-digits ONLY — never reformat mid-type —————
// "034" is intentionally NOT collapsed here — the user might still
// be typing.  Collapse happens on blur only.
function counterInput(id) {
  const el = document.getElementById(id);
  if (!el) return;

  const raw    = el.value;
  const caret  = el.selectionStart;
  const oldLen = raw.length;

  const clean = raw.replace(/[^0-9]/g, '');

  if (clean !== raw) {
    el.value = clean;
    const newCaret = Math.max(0, caret - (oldLen - clean.length));
    try { el.setSelectionRange(newCaret, newCaret); } catch (_) {}
  }
}

// ── onblur: collapse leading zeros; blank stays blank ——————————
// "034" → "34"   "007" → "7"   "0" → blank   "" → blank
function counterBlur(id) {
  const el = document.getElementById(id);
  if (!el) return;
  const n = parseInt(el.value, 10);
  el.value = (Number.isFinite(n) && n > 0) ? String(n) : '';
}

// ── Template: counter row with blank-default input —————————————
function makeCounter(id, labelText) {
  return `
    <div class="counter-row">
      <span class="counter-label">${labelText}</span>
      <div class="counter-ctrl">
        <button type="button" data-form-dyn="adjust-counter" data-fa-id="${id}" data-fa-delta="-1">-</button>
        <input type="text" inputmode="numeric" pattern="[0-9]*"
               id="${id}" name="${id}" value=""
               placeholder="0"
               data-form-dyn="counter" data-fa-id="${id}"
               onwheel="event.preventDefault()">
        <button type="button" data-form-dyn="adjust-counter" data-fa-id="${id}" data-fa-delta="1">+</button>
      </div>
    </div>`;
}

// ── Obstruction toggle ─────────────────────────────────────────
function toggleObstruction(type, label) {
  const id        = type + '_' + label.replace(/\W/g, '_');
  const container = document.getElementById(type + 'Inputs');
  const cb        = document.getElementById(id);
  if (cb && cb.checked) {
    if (document.getElementById('block_' + id)) return;
    const block     = document.createElement('div');
    block.className = 'item-block';
    block.id        = 'block_' + id;
    block.innerHTML = `
      <div class="item-block-header">
        <span class="item-pin">📍</span>
        <strong>${label}</strong>
      </div>
      <div class="item-block-counters">
        ${makeCounter(id + '_slowed',  'Cyclist Slowed Down')}
        ${makeCounter(id + '_partial', 'Partial Obstruction')}
        ${makeCounter(id + '_total',   'Total Obstruction')}
      </div>`;
    container.appendChild(block);
    clearReqError('wrap-noObstructions');
  } else {
    document.getElementById('block_' + id)?.remove();
  }
}

// ── Missing Length field (decimal numeric, not a counter) ──────
// Allows digits and a single decimal point.  No leading zeros before decimal.
function missingLengthInput(el) {
  const raw   = el.value;
  const caret = el.selectionStart;
  const old   = raw.length;

  // Allow digits and a single dot
  let clean = raw.replace(/[^0-9.]/g, '');

  // Keep only the first decimal point
  const dotIdx = clean.indexOf('.');
  if (dotIdx !== -1) {
    clean = clean.slice(0, dotIdx + 1) + clean.slice(dotIdx + 1).replace(/\./g, '');
  }

  // Remove leading zeros before a digit (e.g. "05" → "5", but "0." → "0.")
  clean = clean.replace(/^0+([0-9])/, '$1');

  if (clean !== raw) {
    const removed  = old - clean.length;
    el.value       = clean;
    const newCaret = Math.max(0, caret - removed);
    try { el.setSelectionRange(newCaret, newCaret); } catch (_) {}
  }
}

function missingLengthBlur(el) {
  // Remove trailing dot  ("5." → "5")
  if (el.value.endsWith('.')) el.value = el.value.slice(0, -1);
  // Empty is fine for this optional field — don't snap to 0
}

// ── Missing Length toggle ──────────────────────────────────────
function toggleMissingLength(radio) {
  const box = document.getElementById('missingLengthBox');
  box.style.display = radio.value === 'Yes' ? 'block' : 'none';
  if (radio.value !== 'Yes') {
    document.getElementById('missingLength').value = '';
  }
}

// ── Intersections ──────────────────────────────────────────────
let intersections  = [];
let intUIDCounter  = 0;

function radioRow(name, values) {
  return values
    .map(v => `<label><input type="radio" name="${name}" value="${v}"> ${v}</label>`)
    .join('');
}

function buildIntersectionBody(uid) {
  const p = `int${uid}_`;
  return `
    <div class="int-gps-row">
      <div>
        <label>GPS Coordinates</label>
        <div class="gps-input-row"><input type="text" id="${p}gps" name="${p}gps" readonly placeholder="Tap GPS to capture your location">
                 <button type="button" class="gps-btn" data-form-dyn="fill-gps" data-fa-target="${p}gps"
                         title="Auto-fill from device location">
                   <span class="gps-btn-icon">GPS</span>
                 </button>
               </div>
               <div class="gps-error" id="${p}gps-error"></div>
      </div>
      <div>
        <label>Landmark Name</label>
        <input type="text" id="${p}name" name="${p}name"
               placeholder="e.g. Near signal">
      </div>
    </div>
    <div class="int-fields">
      <div class="int-field">
        <label>Ramp off track</label>
        <div class="options">
          ${radioRow(p + 'offRamp', ['Comfortable','Uncomfortable','No Ramp'])}
        </div>
      </div>
      <div class="int-field">
        <label>Ramp back to track</label>
        <div class="options">
          ${radioRow(p + 'onRamp', ['Comfortable','Uncomfortable','No Ramp'])}
        </div>
      </div>
      <div class="int-field">
        <label>Markings</label>
        <div class="options">
          ${radioRow(p + 'Markings', ['Present','Absent'])}
        </div>
      </div>
      <div class="int-field">
        <label>Signage</label>
        <div class="options">
          ${radioRow(p + 'Signage', ['Present','Absent'])}
        </div>
      </div>
      <div class="int-field">
        <label>Traffic Calming Device</label>
        <div class="options">
          ${radioRow(p + 'TrafficCalming', ['Present','Absent'])}
        </div>
      </div>
      <div class="int-field">
        <label>Discontinuity</label>
        <div class="options">
          ${radioRow(p + 'Discontinuity', ['Yes','No','NA'])}
        </div>
      </div>
      <div class="int-field">
        <label>Tapering of Track Width at Intersection</label>
        <div class="options">
          ${radioRow(p + 'Tapering', ['Yes','No','NA'])}
        </div>
      </div>
      <div class="int-field">
        <label>Obstruction Type</label>
        <div class="options">
          ${radioRow(p + 'ObstructionType', ['Partial','Total','None'])}
        </div>
      </div>
    </div>
    <div class="int-divider"></div>
    <button type="button" class="btn-remove-int"
            data-form-dyn="remove-intersection" data-fa-uid="${uid}">
      <svg viewBox="0 0 24 24" fill="currentColor" width="13" height="13"><path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></svg>
      Remove
    </button>`;
}

function addIntersection() {
  intUIDCounter++;
  const uid  = intUIDCounter;
  intersections.push(uid);
  const card = document.createElement('div');
  card.className = 'intersection-card';
  card.id        = 'intCard_' + uid;
  card.innerHTML = `
    <div class="int-header open" id="intHeader_${uid}"
         data-form-dyn="toggle-intersection" data-fa-uid="${uid}">
      <div class="int-header-left">
        <div class="int-badge">${intersections.length}</div>
        <span class="int-title">Intersection ${intersections.length}</span>
        <span class="int-subtitle" id="intSubtitle_${uid}">
          — click to collapse
        </span>
      </div>
      <span class="int-chevron">▾</span>
    </div>
    <div class="int-body open" id="intBody_${uid}">
      ${buildIntersectionBody(uid)}
    </div>`;
  document.getElementById('intersectionsContainer').appendChild(card);
  clearReqError('wrap-noIntersections');
}

function toggleIntersection(uid) {
  const header = document.getElementById('intHeader_' + uid);
  const body   = document.getElementById('intBody_'   + uid);
  if (!header || !body) return;
  const isOpen = body.classList.contains('open');
  body.classList.toggle('open',   !isOpen);
  header.classList.toggle('open', !isOpen);
  const sub = document.getElementById('intSubtitle_' + uid);
  if (sub) sub.textContent = isOpen
    ? '— click to expand'
    : '— click to collapse';
}

function removeIntersection(uid) {
  document.getElementById('intCard_' + uid)?.remove();
  intersections = intersections.filter(id => id !== uid);
}

// ── Footpath score ─────────────────────────────────────────────
function updateFootpathScore() {
  const weights = {
    minWidth: 30, obstructionFree: 30,
    continuous: 20, disabledFriendly: 15, comfort: 5
  };
  const checked = new Set(
    [...document.querySelectorAll('input[name="footpath_rating[]"]:checked')]
    .map(el => el.value)
  );
  const score = Object.entries(weights)
    .reduce((sum, [key, w]) => sum + (checked.has(key) ? w : 0), 0);
  document.getElementById('footpathScore').textContent = score + '%';
}

// ── Form submit ────────────────────────────────────────────────
async function submitFullAudit() {
  if (!validateForm()) return;

  const form     = document.getElementById('auditForm');
  const formData = new FormData(form);

  // Build clean intersection payload
  const intersectionData = intersections.map(uid => {
    const p = 'int' + uid + '_';
    return {
      gps_coords:       document.getElementById(p + 'gps')?.value  || null,
      landmark_name:    document.getElementById(p + 'name')?.value || null,
      off_ramp:         document.querySelector(`input[name="${p}offRamp"]:checked`)?.value         || null,
      on_ramp:          document.querySelector(`input[name="${p}onRamp"]:checked`)?.value          || null,
      markings:         document.querySelector(`input[name="${p}Markings"]:checked`)?.value        || null,
      signage:          document.querySelector(`input[name="${p}Signage"]:checked`)?.value         || null,
      traffic_calming:  document.querySelector(`input[name="${p}TrafficCalming"]:checked`)?.value  || null,
      discontinuity:    document.querySelector(`input[name="${p}Discontinuity"]:checked`)?.value   || null,
      tapering:         document.querySelector(`input[name="${p}Tapering"]:checked`)?.value        || null,
      obstruction_type: document.querySelector(`input[name="${p}ObstructionType"]:checked`)?.value || null,
    };
  });
  formData.set('intersections', JSON.stringify(intersectionData));

  // Ensure session_id is in FormData (belt-and-suspenders over hidden field)
  if (sessionId) formData.set('session_id', sessionId);

  try {
    const response = await fetch('../api/segments/submit.php', {
      method:  'POST',
      headers: { 'X-CSRF-Token': getCsrf() },
      body:    formData,
    });

    const result = await response.json();

    if (result.success) {
        FormStateManager.clear();
      showFormToast('✅ Audit saved.');
      // Back to segment.php — segment.js picks up ?status=done.
      // A short pause lets the surveyor see the confirmation before the page changes.
      const roadId = getRoadId();
      const next =
        `segment.php?segment_id=${segmentId}&status=done` +
        (sessionId ? `&session_id=${sessionId}` : '') +
        (roadId    ? `&road_id=${roadId}`        : '');
      setTimeout(() => { window.location.href = next; }, 900);
    } else {
      showFormToast('Could not save: ' + (result.error || result.message || 'Unknown error'), 'error', 6000);
      console.error('Submit error:', result);
    }
  } catch (error) {
    console.error('Fetch failed:', error);
    showFormToast('Network error. Please try again.', 'error', 6000);
  }
}

// ── Validation ─────────────────────────────────────────────────


// ═══════════════════════════════════════════════════════
//  GPS Auto-fill
// ═══════════════════════════════════════════════════════
function fillGPS(inputId) {
  const input   = document.getElementById(inputId);
  const errorEl = document.getElementById(inputId + '-error');
  const btn     = input?.closest('.gps-input-row')?.querySelector('.gps-btn');
  if (!input) return;
  if (errorEl) errorEl.textContent = '';
  if (location.protocol !== 'https:' && location.hostname !== 'localhost') {
    if (errorEl) errorEl.textContent = 'GPS requires HTTPS on this page. Please contact an admin.';
    return;
  }
  if (!navigator.geolocation) {
    if (errorEl) errorEl.textContent = 'Geolocation not supported by this browser.';
    return;
  }
  if (btn) { btn.disabled = true; btn.querySelector('.gps-btn-icon').textContent = '...'; }
  navigator.geolocation.getCurrentPosition(
    (pos) => {
      const lat = pos.coords.latitude.toFixed(6);
      const lng = pos.coords.longitude.toFixed(6);
      input.value = lat + ', ' + lng;
      input.dispatchEvent(new Event('change', { bubbles: true }));
      if (btn) { btn.disabled = false; btn.querySelector('.gps-btn-icon').textContent = 'GPS'; }
    },
    (err) => {
      const msgs = {
        1: 'Location access denied. Allow location access in your browser settings, then tap GPS again.',
        2: 'Location unavailable. Move to an open area and try again.',
        3: 'Location request timed out. Try again.'
      };
      if (errorEl) errorEl.textContent = msgs[err.code] || 'Could not get location.';
      if (btn) { btn.disabled = false; btn.querySelector('.gps-btn-icon').textContent = 'GPS'; }
    },
    { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
  );
}

// Answers the scoring engine reads. A blank is not "unknown" to the engine
// (blank buffer zone scores worst, blank shade scores best), so each of
// these must be answered. Keep in sync with helpers/SegmentAnswers.php.
const REQUIRED_RADIOS = [
  ['cycle_track_missing', 'Cycle Track Missing'],
  ['surface_material',    'Cycle track surface material'],
  ['shade',               'Shade'],
  ['light_after_sunset',  'Light (after sunset)'],
  ['buffer_zone',         'Presence of Buffer Zone'],
];
const INTERSECTION_REQUIRED = [
  ['offRamp',        'Ramp off track'],
  ['onRamp',         'Ramp back to track'],
  ['Markings',       'Markings'],
  ['Signage',        'Signage'],
  ['TrafficCalming', 'Traffic Calming Device'],
];

function setReqError(el, msg) {
  if (!el) return;
  el.classList.add('req-error');
  let m = el.querySelector(':scope > .req-msg');
  if (!m) {
    m = document.createElement('div');
    m.className = 'req-msg';
    el.appendChild(m);
  }
  m.textContent = msg;
}

function clearReqError(idOrEl) {
  const el = typeof idOrEl === 'string' ? document.getElementById(idOrEl) : idOrEl;
  if (!el) return;
  el.classList.remove('req-error');
  el.querySelector(':scope > .req-msg')?.remove();
}

function clearAllReqErrors() {
  document.querySelectorAll('#auditForm .req-error').forEach(el => clearReqError(el));
}

// Total of every obstruction count the surveyor typed in.
function obstructionCountTotal() {
  let sum = 0;
  ['fixed', 'movable', 'parked'].forEach(t => {
    document.querySelectorAll(`#${t}Inputs .item-block input`).forEach(el => { sum += _numVal(el); });
  });
  return sum;
}

function validateScoringAnswers() {
  let ok = true;
  const form = document.getElementById('auditForm');

  // 1. Radio questions
  REQUIRED_RADIOS.forEach(([name, label]) => {
    const group = form.querySelector(`input[name="${name}"]`)?.closest('.field-group');
    if (!group) return;
    if (form.querySelector(`input[name="${name}"]:checked`)) { clearReqError(group); return; }
    setReqError(group, 'Please answer: ' + label);
    ok = false;
  });

  // 2. Missing length (only when the track is missing)
  const box = document.getElementById('missingLengthBox');
  if (form.querySelector('input[name="cycle_track_missing"]:checked')?.value === 'Yes') {
    const raw = (document.getElementById('missingLength')?.value || '').trim();
    const len = parseFloat(raw);
    const max = parseFloat(form.dataset.segmentLength || '0');
    let msg = '';
    if (raw === '' || !Number.isFinite(len)) msg = 'Enter the missing cycle track length in metres.';
    else if (len <= 0)                       msg = 'The missing length must be more than 0 m.';
    else if (max > 0 && len > max)           msg = 'The missing length cannot be more than the segment length (' + max + ' m).';
    if (msg) { setReqError(box, msg); ok = false; } else { clearReqError(box); }
  } else {
    clearReqError(box);
  }

  // 3. Obstructions: some counted, or "none" confirmed
  const noObs = document.getElementById('noObstructions')?.checked;
  const obsN  = obstructionCountTotal();
  const obsWrap = document.getElementById('wrap-noObstructions');
  let obsMsg = '';
  if (noObs && obsN > 0)       obsMsg = 'You ticked "No obstructions" but also entered counts. Untick one of them.';
  else if (!noObs && obsN === 0) obsMsg = 'Add the obstructions you found (with a count), or tick "No obstructions on this segment".';
  if (obsMsg) { setReqError(obsWrap, obsMsg); ok = false; } else { clearReqError(obsWrap); }

  // 4. Intersections: complete rows, or "none" confirmed
  const noInt   = document.getElementById('noIntersections')?.checked;
  const intWrap = document.getElementById('wrap-noIntersections');
  let intMsg = '';
  if (noInt && intersections.length > 0)        intMsg = 'You ticked "No intersections" but also added one. Untick it or remove the intersection.';
  else if (!noInt && intersections.length === 0) intMsg = 'Add the intersections in this segment, or tick "No intersections in this segment".';
  if (intMsg) { setReqError(intWrap, intMsg); ok = false; } else { clearReqError(intWrap); }

  intersections.forEach(uid => {
    const p = 'int' + uid + '_';
    INTERSECTION_REQUIRED.forEach(([suffix, label]) => {
      const radio = form.querySelector(`input[name="${p}${suffix}"]`);
      const field = radio?.closest('.int-field');
      if (!field) return;
      if (form.querySelector(`input[name="${p}${suffix}"]:checked`)) { clearReqError(field); return; }
      setReqError(field, 'Please answer');
      ok = false;
      // make sure a collapsed card shows its problem
      if (!document.getElementById('intBody_' + uid)?.classList.contains('open')) toggleIntersection(uid);
    });
  });

  return ok;
}

function validateForm() {
  let ok = true;
  ['startLandmark','endLandmark','gpsStart','gpsEnd'].forEach(id => {
    const val  = document.getElementById(id)?.value.trim();
    const wrap = document.getElementById('wrap-' + id);
    if (!val) { wrap?.classList.add('field-error'); ok = false; }
    else       { wrap?.classList.remove('field-error'); }
  });
  // The end landmark and GPS sit at the bottom of the page; the scoring
  // answers are checked too so the surveyor sees everything missing at once.
  if (!validateScoringAnswers()) ok = false;
  if (!ok) {
    const first = Array.from(document.querySelectorAll('.field-error, .req-error'))
      .sort((a, b) => (a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING) ? -1 : 1)[0];
    first?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    showFormToast('Some required answers are missing. Check the red fields.', 'error', 5000);
  }
  return ok;
}

// Clear a field's red state as soon as it is answered.
document.addEventListener('change', e => {
  const t = e.target;
  if (!t || !t.closest || !t.closest('#auditForm')) return;
  if (t.type === 'radio') {
    clearReqError(t.closest('.int-field') || t.closest('.field-group'));
  }
});
document.addEventListener('input', e => {
  const t = e.target;
  if (!t || !t.closest) return;
  if (t.id === 'missingLength') clearReqError('missingLengthBox');
  if (t.closest('#fixedInputs, #movableInputs, #parkedInputs')) clearReqError('wrap-noObstructions');
});
function clearError(id) {
  document.getElementById(id)?.classList.remove('field-error');
}

// ── Progress bar ───────────────────────────────────────────────


// ── Reset / Confirm ────────────────────────────────────────────
function resetForm()    { document.getElementById('confirmOverlay').classList.add('active');    }
function closeConfirm() { document.getElementById('confirmOverlay').classList.remove('active'); }

async function doReset() {
  // Close the confirm overlay immediately
  closeConfirm();

  // Show a loading state on the reset button
  const resetBtn = document.querySelector('.btn-reset');
  const origText = resetBtn ? resetBtn.textContent : '';
  if (resetBtn) { resetBtn.textContent = '⏳ Resetting…'; resetBtn.disabled = true; }

  try {
    // ── 1. Clear audit data from the database ─────────────────
    const segId  = document.getElementById('segment_id')?.value || segmentId;
    const sessId = document.getElementById('session_id')?.value || sessionId;

    if (segId && sessId) {
      const resp = await fetch('../api/segments/reset.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': getCsrf(),
        },
        body: JSON.stringify({ segment_id: parseInt(segId), session_id: parseInt(sessId) }),
      });
      const result = await resp.json();
      if (!result.success) {
        console.error('Reset failed:', result.error);
        showFormToast('Reset failed: ' + (result.error || 'Unknown error'), 'error', 6000);
        if (resetBtn) { resetBtn.textContent = origText; resetBtn.disabled = false; }
        return;
      }
    }

    // ── 2. Clear the form DOM in-place ─────────────────────────
    // Text inputs & textareas
    document.querySelectorAll('#auditForm input[type="text"], #auditForm input[type="number"], #auditForm textarea')
      .forEach(el => {
        if (el.id === 'segment_id' || el.id === 'session_id' || el.id === 'road_id' || el.name === 'edit_mode') return;
        el.value = '';
      });

    // Radio buttons
    document.querySelectorAll('#auditForm input[type="radio"]').forEach(el => { el.checked = false; });

    // Checkboxes
    document.querySelectorAll('#auditForm input[type="checkbox"]').forEach(el => { el.checked = false; });

    // Obstruction tags & counter blocks
    ['fixed', 'movable', 'parked'].forEach(type => {
      const tags   = document.getElementById(type + 'Tags');
      const inputs = document.getElementById(type + 'Inputs');
      if (tags)   tags.innerHTML   = '';
      if (inputs) inputs.innerHTML = '';
      // Uncheck all checkboxes in the dropdown
      document.querySelectorAll(`#${type}List input[type="checkbox"]`).forEach(cb => { cb.checked = false; });
    });

    // Intersections
    const intContainer = document.getElementById('intersectionsContainer');
    if (intContainer) intContainer.innerHTML = '';

    // Missing length box
    const missingBox = document.getElementById('missingLengthBox');
    if (missingBox) missingBox.style.display = 'none';

    clearAllReqErrors();

    // Footpath score badge
    updateFootpathScore();

    // ── 3. Strip edit_mode from URL and remove the edit banner ─
    const hEdit = document.getElementById('edit_mode');
    if (hEdit) hEdit.value = '0';

    // Remove the edit-mode banner if present
    document.querySelectorAll('.form-page-heading ~ div').forEach(el => {
      if (el.style.background?.includes('#fff8e1')) el.remove();
    });

    // Update the URL to remove edit_mode without reloading
    const newUrl = new URL(window.location.href);
    newUrl.searchParams.delete('edit_mode');
    window.history.replaceState({}, '', newUrl.toString());

    // Scroll to top
    window.scrollTo({ top: 0, behavior: 'smooth' });

    // Show a brief success toast
    showFormToast('🔄 Form cleared — all data has been reset.');

  } catch (err) {
    console.error('doReset error:', err);
    showFormToast('An unexpected error occurred during reset. Please try again.', 'error', 6000);
  } finally {
    if (resetBtn) { resetBtn.textContent = origText; resetBtn.disabled = false; }
  }
}

// Non-blocking message at the bottom of the screen (replaces alert()).
// type: 'success' (green, default) or 'error' (red). Text is set with textContent.
function showFormToast(msg, type = 'success', ms = 3000) {
  let toast = document.getElementById('formToast');
  if (!toast) {
    toast = document.createElement('div');
    toast.id = 'formToast';
    toast.setAttribute('role', 'status');
    toast.setAttribute('aria-live', 'polite');
    toast.style.cssText =
      'position:fixed;bottom:24px;left:50%;transform:translateX(-50%);' +
      'max-width:calc(100% - 32px);text-align:center;' +
      'color:#fff;padding:12px 24px;border-radius:8px;' +
      'font-size:14px;font-weight:500;z-index:9999;box-shadow:0 4px 16px rgba(0,0,0,.25);' +
      'transition:opacity .3s ease;';
    document.body.appendChild(toast);
  }
  toast.style.background = type === 'error' ? '#b91c1c' : '#2d6a2d';
  toast.textContent = msg;
  toast.style.opacity = '1';
  clearTimeout(toast._hideTimer);
  toast._hideTimer = setTimeout(() => { toast.style.opacity = '0'; }, ms);
}

// ── Scroll to top ──────────────────────────────────────────────
window.addEventListener('scroll', () => {
  const btn = document.getElementById('scrollTopBtn');
  if (btn) btn.classList.toggle('visible', window.scrollY > 300);
});

// ── Pre-fill helpers ───────────────────────────────────────────

function prefillObstructions(obstructions) {
  if (!obstructions || !obstructions.length) return;
  obstructions.forEach(o => {
    const type    = o.category;   // 'fixed' | 'movable' | 'parked'
    const label   = o.type;       // e.g. 'Trees'
    const id      = type + '_' + label.replace(/\W/g, '_');

    // Tick the checkbox in the dropdown (renderList builds the DOM)
    const cb = document.getElementById(id);
    if (cb) {
      cb.checked = true;
      // Fire toggleObstruction to create the counter block
      toggleObstruction(type, label);
    }

    // Set counter values
    const setCounter = (suffix, val) => {
      const el = document.getElementById(id + suffix);
      // Blank represents 0 in display; only show a value if it is genuinely > 0
      if (el) el.value = (val && parseInt(val, 10) > 0) ? String(parseInt(val, 10)) : '';
    };
    setCounter('_slowed',  o.slowed);
    setCounter('_partial', o.partial);
    setCounter('_total',   o.total);
  });
}

function prefillIntersections(intersections) {
  if (!intersections || !intersections.length) return;
  intersections.forEach(saved => {
    addIntersection();                           // creates card, pushes uid
    const uid = intUIDCounter;                   // uid just assigned
    const p   = 'int' + uid + '_';

    const setVal = (id, val) => {
      const el = document.getElementById(id);
      if (el && val != null) el.value = val;
    };
    const setRadio = (name, val) => {
      if (!val) return;
      const el = document.querySelector(`input[name="${name}"][value="${val}"]`);
      if (el) el.checked = true;
    };

    setVal(p + 'gps',  saved.gps_coords);
    setVal(p + 'name', saved.landmark_name);
    setRadio(p + 'offRamp',         saved.off_ramp);
    setRadio(p + 'onRamp',          saved.on_ramp);
    setRadio(p + 'Markings',        saved.markings);
    setRadio(p + 'Signage',         saved.signage);
    setRadio(p + 'TrafficCalming',  saved.traffic_calming);
    setRadio(p + 'Discontinuity',   saved.discontinuity);
    setRadio(p + 'Tapering',        saved.tapering);
    setRadio(p + 'ObstructionType', saved.obstruction_type);
  });
}

async function prefillFormIfEditMode() {
  if (!editMode || !segmentId) return;

  // Show a subtle banner so the user knows data was loaded
  const heading = document.querySelector('.form-page-heading');
  if (heading) {
    const banner = document.createElement('div');
    banner.style.cssText =
      'background:#fff8e1;border:1px solid #f6c90e;border-radius:8px;' +
      'padding:10px 16px;margin-bottom:16px;font-size:13px;color:#7a5c00;';
    banner.innerHTML =
      '✏️ <strong>Edit mode</strong> — your previous answers have been loaded. ' +
      'Review, correct anything, then re-submit.';
    heading.insertAdjacentElement('afterend', banner);
  }

  try {
    const csrf = getCsrf();
    const res  = await fetch(
      `../api/segments/audit-data.php?segment_id=${segmentId}`,
      { headers: { 'X-CSRF-Token': csrf } }
    );
    const data = await res.json();
    if (!data.success || !data.audit) return;

    const a = data.audit;

    // ── Text inputs ─────────────────────────────────────────
    const setVal = (id, val) => {
      const el = document.getElementById(id);
      if (el && val != null) el.value = val;
    };
    setVal('startLandmark', a.start_landmark);
    setVal('endLandmark',   a.end_landmark);
    setVal('gpsStart',      a.gps_start);
    setVal('gpsEnd',        a.gps_end);
    setVal('missingLength', a.missing_length);
    // Counter fields: show blank for 0, show value only if > 0
    const setCounter = (id, val) => {
      const el = document.getElementById(id);
      if (!el) return;
      const n = parseInt(val, 10);
      el.value = (Number.isFinite(n) && n > 0) ? String(n) : '';
    };
    setCounter('signageCount', a.signage_count);

    // ── Radio buttons ────────────────────────────────────────
    const setRadio = (name, val) => {
      if (!val) return;
      const el = document.querySelector(`input[name="${name}"][value="${val}"]`);
      if (el) {
        el.checked = true;
        el.dispatchEvent(new Event('change'));   // trigger any onchange handlers
      }
    };
    setRadio('cycle_track_missing', a.cycle_track_missing);
    setRadio('cyclist_use',         a.cyclist_use);
    setRadio('better_surface',      a.better_surface);
    setRadio('surface_material',    a.surface_material);
    setRadio('people_walking',      a.people_walking);
    setRadio('shade',               a.shade);
    setRadio('light_after_sunset',  a.light_after_sunset);
    setRadio('track_geometry',      a.track_geometry);
    setRadio('buffer_zone',         a.buffer_zone);

    // ── Checkboxes (surface_issues, overhead_issues, footpath_rating)
    const tickCheckboxes = (name, values) => {
      if (!Array.isArray(values)) return;
      values.forEach(val => {
        const el = document.querySelector(
          `input[name="${name}"][value="${val}"]`
        );
        if (el) {
          el.checked = true;
          el.dispatchEvent(new Event('change'));
        }
      });
    };
    tickCheckboxes('surface_issues[]',  a.surface_issues);
    tickCheckboxes('overhead_issues[]', a.overhead_issues);
    tickCheckboxes('footpath_rating[]', a.footpath_rating);
    updateFootpathScore();

    // ── Dimensions / comments ────────────────────────────────
    const setByName = (name, val) => {
      const el = document.querySelector(`[name="${name}"]`);
      if (el && val != null) el.value = val;
    };
    setByName('segment_width',  a.segment_width);
    setByName('comments',       a.comments);

    // ── Obstructions ─────────────────────────────────────────
    prefillObstructions(data.obstructions);
    if (!data.obstructions || !data.obstructions.length) {
      const c = document.getElementById('noObstructions');
      if (c) c.checked = true;
    }

    // ── Intersections ────────────────────────────────────────
    prefillIntersections(data.intersections);
    if (!data.intersections || !data.intersections.length) {
      const c = document.getElementById('noIntersections');
      if (c) c.checked = true;
    }

  } catch (err) {
    console.warn('Could not pre-fill form:', err);
  }
}

// ── Radio toggle (click same radio again to uncheck) ───────────
(function initRadioToggle() {
  // When a <label> wraps a <input type="radio"> (no `for` attr),
  // clicking the label fires: mousedown(label) → mousedown(input) → click(input) → click(label)
  // We must snapshot on the FIRST mousedown (label) and act on the LAST click (label).
  // Clicking the circle directly fires: mousedown(input) → click(input) only.

  function getRadio(el) {
    if (el.type === 'radio') return el;
    const label = el.tagName === 'LABEL' ? el : el.closest('label');
    if (!label) return null;
    return label.htmlFor
      ? document.getElementById(label.htmlFor)
      : label.querySelector('input[type="radio"]');
  }

  // Snapshot on mousedown — earliest possible moment
  document.addEventListener('mousedown', e => {
    const radio = getRadio(e.target);
    if (radio && !radio._snapDone) {
      radio._wasChecked = radio.checked;
      // Mark so the synthetic second mousedown (on the input itself) doesn't overwrite
      radio._snapDone = true;
      // Clear the guard after the click sequence ends
      setTimeout(() => { radio._snapDone = false; }, 300);
    }
  }, true);

  // Act on click — use capture so we run before any other handler
  document.addEventListener('click', e => {
    const radio = getRadio(e.target);
    if (!radio) return;

    // Only act on the outermost trigger (label click or direct input click)
    // Ignore the synthetic input click that the browser fires after a label click
    if (e.target.type !== 'radio' && e.target.tagName !== 'LABEL' && !e.target.closest('label')) return;

    if (radio._wasChecked) {
      // Prevent the browser from re-checking it
      e.preventDefault();
      radio.checked = false;
      radio._wasChecked = false;
      radio.dispatchEvent(new Event('change', { bubbles: true }));
    } else {
      radio._wasChecked = false;
    }
  }, true);
})();

// ── Init ───────────────────────────────────────────────────────
['fixed','movable','parked'].forEach(t => renderList(t, ''));
prefillFormIfEditMode();

// ── FormStateManager ─────────────────────────────────────────────────────
// Auto-saves form state to localStorage every 30s + on every change.
// Restores on page load if same segment_id and not expired (24h).
// Cleared on successful submit or reset.
const FormStateManager = (() => {
  const TTL    = 24 * 60 * 60 * 1000; // 24 hours in ms
  const PREFIX = 'parisar_form_';

  function _key() {
    return PREFIX + (segmentId || 'noseg');
  }

  function save() {
    const form = document.getElementById('auditForm');
    if (!form) return;

    const data = {};

    // Text / number / textarea inputs
    form.querySelectorAll('input[type="text"], input[type="number"], textarea').forEach(el => {
      if (el.name) data[el.name] = el.value;
    });

    // Radio buttons — save the checked one
    const radios = {};
    form.querySelectorAll('input[type="radio"]').forEach(el => {
      if (el.name && el.checked) radios[el.name] = el.value;
    });
    data.__radios = radios;

    // Checkboxes — save array of checked values per name
    const checks = {};
    form.querySelectorAll('input[type="checkbox"]').forEach(el => {
      if (!el.name) return;
      const base = el.name.replace('[]', '');
      if (!checks[base]) checks[base] = [];
      if (el.checked) checks[base].push(el.value);
    });
    data.__checks = checks;

    // Intersections array (already tracked in JS)
    try {
      const intData = [];
      document.querySelectorAll('.intersection-card').forEach(card => {
        const uid = card.dataset.uid;
        if (!uid) return;
        const p = 'int_' + uid + '_';
        const get  = id => { const el = document.getElementById(id); return el ? el.value : ''; };
        const getR = n  => { const el = card.querySelector(`input[name="${n}"]:checked`); return el ? el.value : ''; };
        intData.push({
          gps_coords:       get(p + 'gps'),
          landmark_name:    get(p + 'name'),
          off_ramp:         getR(p + 'offRamp'),
          on_ramp:          getR(p + 'onRamp'),
          markings:         getR(p + 'Markings'),
          signage:          getR(p + 'Signage'),
          traffic_calming:  getR(p + 'TrafficCalming'),
          discontinuity:    getR(p + 'Discontinuity'),
          tapering:         getR(p + 'Tapering'),
          obstruction_type: getR(p + 'ObstructionType'),
        });
      });
      data.__intersections = intData;
    } catch(e) { /* non-fatal */ }

    const payload = { ts: Date.now(), seg: segmentId, data };
    try {
      localStorage.setItem(_key(), JSON.stringify(payload));
    } catch(e) { /* storage full or disabled */ }
  }

  function restore() {
    let raw;
    try { raw = localStorage.getItem(_key()); } catch(e) { return; }
    if (!raw) return;

    let payload;
    try { payload = JSON.parse(raw); } catch(e) { clear(); return; }

    // Expire after 24h
    if (!payload.ts || (Date.now() - payload.ts) > TTL) { clear(); return; }
    // Must match current segment
    if (payload.seg !== segmentId) { clear(); return; }

    const d = payload.data || {};

    // Restore text/number/textarea
    Object.entries(d).forEach(([name, val]) => {
      if (name.startsWith('__')) return;
      const el = document.querySelector(`[name="${name}"]`);
      if (el && (el.type === 'text' || el.type === 'number' || el.tagName === 'TEXTAREA')) {
        el.value = val;
      }
    });

    // Restore radios
    Object.entries(d.__radios || {}).forEach(([name, val]) => {
      const el = document.querySelector(`input[type="radio"][name="${name}"][value="${CSS.escape(val)}"]`);
      if (el) { el.checked = true; el.dispatchEvent(new Event('change', { bubbles: true })); }
    });

    // Restore checkboxes
    Object.entries(d.__checks || {}).forEach(([base, vals]) => {
      document.querySelectorAll(`input[type="checkbox"][name="${base}[]"], input[type="checkbox"][name="${base}"]`).forEach(el => {
        el.checked = vals.includes(el.value);
        if (el.checked) el.dispatchEvent(new Event('change', { bubbles: true }));
      });
    });

    // Show a subtle banner
    _showBanner();
  }

  function clear() {
    try { localStorage.removeItem(_key()); } catch(e) {}
  }

  function _showBanner() {
    const existing = document.getElementById('fsm-banner');
    if (existing) return;
    const div = document.createElement('div');
    div.id = 'fsm-banner';
    div.style.cssText = 'position:fixed;bottom:16px;left:50%;transform:translateX(-50%);' +
      'background:#1a1a2e;color:#fff;padding:10px 20px;border-radius:8px;font-size:13px;' +
      'z-index:9999;box-shadow:0 4px 12px rgba(0,0,0,.3);display:flex;gap:12px;align-items:center;';
    div.innerHTML = '💾 Draft restored from your last session. ' +
      '<button data-form-dyn="fsm-reload" ' +
      'style="background:transparent;border:1px solid #fff;color:#fff;padding:2px 10px;border-radius:4px;cursor:pointer;font-size:12px;">Discard</button>';
    document.body.appendChild(div);
    setTimeout(() => { if (div.parentNode) div.remove(); }, 8000);
  }

  // Auto-save on any input/change inside the form
  document.addEventListener('input',  () => save(), true);
  document.addEventListener('change', () => save(), true);

  // Auto-save every 30 seconds
  setInterval(save, 30_000);

  return { save, restore, clear };
})();


// ── Dirty-form guard ────────────────────────────────────────────────────────
// Warns before navigating away with unsaved (unsubmitted) form changes.
// FormStateManager.clear is monkey-patched here so both submit-success and
// reset automatically clear the flag without modifying those call sites.
(function () {
  let _dirty = false;

  const _form = document.getElementById('auditForm');
  if (_form) {
    _form.addEventListener('input',  function () { _dirty = true; }, true);
    _form.addEventListener('change', function () { _dirty = true; }, true);
  }

  const _origClear = FormStateManager.clear.bind(FormStateManager);
  FormStateManager.clear = function () { _origClear(); _dirty = false; };

  window.addEventListener('beforeunload', function (e) {
    if (_dirty) {
      e.preventDefault();
      e.returnValue = '';
    }
  });
})();

// Hook: clear on successful submit
const _origSubmit = submitFullAudit;
submitFullAudit = async function() {
  await _origSubmit.apply(this, arguments);
  // Only clear if submit succeeded (check for success toast or no error alert)
  // We patch via the response — override happens inside submitFullAudit already,
  // so we attach a one-time listener on the form's custom success event instead.
};

// Cleaner approach: patch doReset to also clear FSM
const _origDoReset = doReset;
doReset = async function() {
  FormStateManager.clear();
  await _origDoReset.apply(this, arguments);
};

// Restore on load (after prefill runs so edit-mode data wins)
setTimeout(() => {
  if (!editMode) FormStateManager.restore();
}, 100);


(function () {
  var LANDMARK_FIELDS = ['startLandmark', 'endLandmark'];
  LANDMARK_FIELDS.forEach(function (id) {
    var el = document.getElementById(id);
    if (!el) return;
    el.addEventListener('input', function () {
      var cleaned = el.value.replace(/[^a-zA-Z0-9\s]/g, '');
      if (cleaned !== el.value) el.value = cleaned;
    });
  });
})();

// GPS fields (gpsStart, gpsEnd, and per-intersection "${p}gps" fields)
// are read-only and can only be filled via the GPS button — see
// fillGPS() above. No manual-entry keystroke filtering or format
// validation is needed here anymore: a successful fillGPS() call
// always produces a well-formed "lat, lng" value.
