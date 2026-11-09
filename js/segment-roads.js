/* js/segment-roads.js — road dropdown + page logic, extracted from pages/segment.php */

// ═══════════════════════════════════════════════════════
//  Searchable Road Dropdown
// ═══════════════════════════════════════════════════════

let ROAD_LIST = [];
let ROAD_CITY_NAME = null; // viewer's city, from api/roads/groups.php (null = unknown/all cities)
let _rdListLoaded = false;

function loadRoadList() {
  fetch('../api/roads/groups.php', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (data.success) {
        ROAD_LIST = data.roads;
        ROAD_CITY_NAME = data.city_name || null;
      }
      _rdListLoaded = true;
      if (_rdOpen) roadRenderDropdown(document.getElementById('roadSearchInput').value);
    })
    .catch(function () {
      _rdListLoaded = true;
      if (_rdOpen) roadRenderDropdown(document.getElementById('roadSearchInput').value);
    });
}
loadRoadList();

let _rdHighlight = -1;
let _rdOpen = false;

function roadDropdownOpen() {
  roadRenderDropdown(document.getElementById('roadSearchInput').value);
  document.getElementById('roadDropdown').classList.add('open');
  _rdOpen = true;
}

function roadDropdownClose() {
  document.getElementById('roadDropdown').classList.remove('open');
  _rdOpen = false;
  _rdHighlight = -1;
}

function roadSearchFilter(q) {
  const inp = document.getElementById('roadSearchInput');
  inp.classList.toggle('has-value', q.length > 0);
  roadRenderDropdown(q);
  if (!_rdOpen) {
    document.getElementById('roadDropdown').classList.add('open');
    _rdOpen = true;
  }
}

// Escape text before it goes into innerHTML / an HTML attribute. Road names and
// the user's typed search text are untrusted: an apostrophe or quote in a name
// used to break the inline onclick, and markup would render as HTML.
function rdEsc(s) {
  return String(s).replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
}

// Escape the road name, wrapping the matched part of the search in <span class="match-bold">.
function rdHighlightMatch(road, raw) {
  const i = raw.length > 0 ? road.indexOf(raw) : -1;
  if (i < 0) return rdEsc(road);
  return rdEsc(road.slice(0, i)) +
         '<span class="match-bold">' + rdEsc(raw) + '</span>' +
         rdEsc(road.slice(i + raw.length));
}

function roadRenderDropdown(q) {
  const dd  = document.getElementById('roadDropdown');
  const raw = q.trim().toUpperCase();
  _rdHighlight = -1;

  const filtered = raw.length === 0
    ? ROAD_LIST
    : ROAD_LIST.filter(r => r.includes(raw));

  let html = '';

  // ── Pinned: Other / Custom — admin only, always at top ──
  if (window.IS_ADMIN) {
    html += `<div class="road-dropdown-item pinned" data-segment-dyn="road-select-custom">
      <span>✦</span> Other / Custom Road…
    </div>`;
  }

  if (filtered.length > 0) {
    const cityLabel = ROAD_CITY_NAME ? rdEsc(ROAD_CITY_NAME) + ' ' : '';
    html += `<div class="road-dropdown-section-label">${cityLabel}Cycle Track Roads</div>`;
    filtered.forEach((road, i) => {
      html += `<div class="road-dropdown-item" data-idx="${i}" data-val="${rdEsc(road)}">
        <span>🛣</span> <span>${rdHighlightMatch(road, raw)}</span>
      </div>`;
    });
  } else if (!_rdListLoaded) {
    html += `<div class="road-dropdown-empty">Loading roads…</div>`;
  } else if (raw.length > 0) {
    // No match
    if (window.IS_ADMIN) {
      html += `<div class="road-dropdown-empty">
        No match for "<strong>${rdEsc(raw)}</strong>"
      </div>
      <div class="road-dropdown-item pinned" data-fill="${rdEsc(raw)}">
        <span>＋</span> Add "<strong>${rdEsc(raw)}</strong>" as custom road
      </div>`;
    } else {
      html += `<div class="road-dropdown-empty">
        No match for "<strong>${rdEsc(raw)}</strong>". Can't find your road? Ask an admin to add it.
      </div>`;
    }
  }

  dd.innerHTML = html;
}

function roadSelectItem(val) {
  // Set hidden select
  const sel = document.getElementById('roadName');
  sel.value = val;
  // Update visible input
  document.getElementById('roadSearchInput').value = val;
  document.getElementById('roadSearchInput').classList.add('has-value');
  // Hide custom wrap (admin-only element — doesn't exist in the DOM for
  // non-admin users, so guard with optional chaining or this throws and
  // aborts before roadDropdownClose() below ever runs, leaving the
  // dropdown stuck open)
  document.getElementById('customRoadWrap')?.classList.remove('open');
  const customRoadNameEl = document.getElementById('customRoadName');
  if (customRoadNameEl) customRoadNameEl.value = '';
  // Hide error
  const err = document.getElementById('err-roadName');
  if (err) err.style.display = 'none';
  roadDropdownClose();
  document.getElementById('roadSearchInput').blur();
}

function roadSelectCustom() {
  const sel = document.getElementById('roadName');
  sel.value = '__custom__';
  document.getElementById('roadSearchInput').value = '✦ Other / Custom Road';
  document.getElementById('roadSearchInput').classList.add('has-value');
  const wrap = document.getElementById('customRoadWrap');
  wrap.classList.add('open');
  setTimeout(() => document.getElementById('customRoadName').focus(), 350);
  const err = document.getElementById('err-roadName');
  if (err) err.style.display = 'none';
  roadDropdownClose();
  document.getElementById('roadSearchInput').blur();
}

function roadSelectCustomFill(val) {
  // Pre-fill custom input with what user typed
  const sel = document.getElementById('roadName');
  sel.value = '__custom__';
  document.getElementById('roadSearchInput').value = '✦ Other / Custom Road';
  document.getElementById('roadSearchInput').classList.add('has-value');
  const wrap = document.getElementById('customRoadWrap');
  wrap.classList.add('open');
  const custInput = document.getElementById('customRoadName');
  custInput.value = val.toUpperCase();
  validateCustomRoad(custInput);
  setTimeout(() => custInput.focus(), 350);
  roadDropdownClose();
  document.getElementById('roadSearchInput').blur();
}

// Keyboard navigation
function roadSearchKeydown(e) {
  const dd    = document.getElementById('roadDropdown');
  const items = dd.querySelectorAll('.road-dropdown-item');
  if (!_rdOpen || items.length === 0) return;
  if (e.key === 'ArrowDown') {
    e.preventDefault();
    _rdHighlight = Math.min(_rdHighlight + 1, items.length - 1);
    items.forEach((el, i) => el.classList.toggle('highlighted', i === _rdHighlight));
  } else if (e.key === 'ArrowUp') {
    e.preventDefault();
    _rdHighlight = Math.max(_rdHighlight - 1, 0);
    items.forEach((el, i) => el.classList.toggle('highlighted', i === _rdHighlight));
  } else if (e.key === 'Enter') {
    e.preventDefault();
    if (_rdHighlight >= 0) items[_rdHighlight].click();
  } else if (e.key === 'Escape') {
    roadDropdownClose();
  }
}

// Clicks on dropdown rows. Values come from data- attributes (already escaped when
// rendered; dataset gives back the plain text), not from inline onclick strings.
document.addEventListener('click', function(e) {
  const row = e.target.closest ? e.target.closest('#roadDropdown .road-dropdown-item') : null;
  if (!row) return;
  if (row.dataset.val !== undefined)       roadSelectItem(row.dataset.val);
  else if (row.dataset.fill !== undefined) roadSelectCustomFill(row.dataset.fill);
});

// Close on outside click
document.addEventListener('click', function(e) {
  const wrap = document.getElementById('roadSearchWrap');
  if (wrap && !wrap.contains(e.target)) roadDropdownClose();
});

// ── Custom road text input validation ──
function validateCustomRoad(input) {
  input.value = input.value.toUpperCase();
  const hint = document.getElementById('customRoadHint');
  const v    = input.value.trim();
  if (!hint) return;
  if (v.length === 0)    { hint.textContent = 'Min 3 characters. Use official road name if possible.'; hint.className = 'road-hint'; }
  else if (v.length < 3) { hint.textContent = '⚠ Too short (min 3 characters).'; hint.className = 'road-hint err'; }
  else if (!/^[A-Z0-9\s\.\-\/]+$/.test(v)) { hint.textContent = '⚠ Invalid characters.'; hint.className = 'road-hint err'; }
  else                   { hint.textContent = '✓ Looks good!'; hint.className = 'road-hint'; }
}

// ── Patch #roadName.value so segment.js reads custom text transparently ──
(function patchRoadSelect() {
  const sel  = document.getElementById('roadName');
  const cust = document.getElementById('customRoadName');
  if (!sel || !cust) return;
  const proto = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value');
  Object.defineProperty(sel, 'value', {
    get() {
      const raw = proto.get.call(this);
      if (raw === '__custom__') {
        const v = cust.value.trim().toUpperCase();
        return v.length > 0 ? v : '__custom__';
      }
      return raw;
    },
    set(v) { proto.set.call(this, v); },
    configurable: true,
  });
})();

// ── Helpers ──
function goToDashboard()  { window.location.href = 'dashboard.php'; }
function viewRoadResult() { window.location.href = 'road_result.php'; }

function downloadRoadScore() {
  const sessionId = window._currentSessionId;
  if (!sessionId) { alert('No active session found. Please save segments first.'); return; }
  window.open('report.php?session_id=' + sessionId, '_blank');
}