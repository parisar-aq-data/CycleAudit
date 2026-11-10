/* js/landing_cities.js - city tiles on the home page (was an inline script in index.html) */
(function () {
  var cityEl  = document.getElementById('stat-city');

  // Original line drawings of well-known landmarks, keyed by lower-case city
  // name. Any other city gets the generic skyline.
  var LANDMARKS = {
    // Gateway of India
    'mumbai': '<path d="M4 42h40"/><path d="M8 42V24h32v18"/><path d="M8 24v-6M40 24v-6"/><path d="M6.5 18a3 3 0 0 1 6 0M35.5 18a3 3 0 0 1 6 0"/><path d="M14 24v-5h20v5"/><path d="M19 19a5 5 0 0 1 10 0"/><path d="M24 14v-3"/><path d="M18 42V32a6 6 0 0 1 12 0v10"/>',
    // India Gate
    'delhi': '<path d="M4 42h40"/><path d="M8 42v-3h32v3"/><path d="M11 39V20h26v19"/><path d="M9 20h30"/><path d="M13 20v-4h22v4"/><path d="M21 16v-3h6v3"/><path d="M17 39V29a7 7 0 0 1 14 0v10"/>',
    // Vidhana Soudha
    'bengaluru': '<path d="M4 42h40"/><path d="M6 42V29h36v13"/><path d="M4 29h40"/><path d="M14 29v-6h20v6"/><path d="M19 23a5 5 0 0 1 10 0"/><path d="M24 18v-5"/><path d="M18 42V29M24 42V29M30 42V29"/><path d="M9 34h3M36 34h3"/>',
    // Charminar
    'hyderabad': '<path d="M4 42h40"/><path d="M11 42V26h26v16"/><path d="M11 26h26"/><path d="M9 26V12M39 26V12M17 26V16M31 26V16"/><path d="M7.5 12a3 3 0 0 1 3 0M37.5 12a3 3 0 0 1 3 0M15.5 16a3 3 0 0 1 3 0M29.5 16a3 3 0 0 1 3 0"/><path d="M18 42V34a6 6 0 0 1 12 0v8"/><path d="M20 21h8"/>',
    // Open Hand Monument
    'chandigarh': '<path d="M10 42h28"/><path d="M14 42v-4h20v4"/><path d="M24 38V30"/><path d="M19 30h10"/><path d="M20 30V19M22.5 30V12M25.5 30V10M28 30V17"/><path d="M19 27l-5-6"/>',
    // Teen Darwaza
    'ahmedabad': '<path d="M4 42h40"/><path d="M6 42V20h36v22"/><path d="M6 20v-3h36v3"/><path d="M8 42V31a4 4 0 0 1 8 0v11M20 42V31a4 4 0 0 1 8 0v11M32 42V31a4 4 0 0 1 8 0v11"/><path d="M9 24h30"/>',
    // Shaniwar Wada
    'pune': '<path d="M4 42h40"/><path d="M6 42V24h10v18M32 42V24h10v18"/><path d="M6 24v-3h2.5v3M10.5 24v-3H13v3M16 24v-3M32 24v-3M35.5 24v-3h2.5v3M42 24v-3"/><path d="M16 42V22h16v20"/><path d="M16 22v-3h3v3M22.5 22v-3h3v3M29 22v-3h3v3"/><path d="M19 42V31a5 5 0 0 1 10 0v11"/>',
    // Gopuram
    'chennai': '<path d="M6 42h36"/><path d="M10 42V36h28v6"/><path d="M12 36V30h24v6"/><path d="M14 30V24h20v6"/><path d="M16 24v-5h16v5"/><path d="M19 19v-4h10v4"/><path d="M24 15v-5"/><path d="M22 42v-4h4v4"/>',
    // Howrah Bridge
    'kolkata': '<path d="M4 36h40"/><path d="M4 36l8-22 12 10 12-10 8 22"/><path d="M12 14v22M36 14v22M24 24v12"/><path d="M18 19v17M30 19v17"/><path d="M4 42c4-2 6 2 10 0s6-2 10 0 6 2 10 0 6-2 10 0"/>',
    // Chinese fishing nets
    'kochi': '<path d="M4 42c4-2 6 2 10 0s6-2 10 0 6 2 10 0 6-2 10 0"/><path d="M16 38L24 14M32 38L24 14"/><path d="M6 26L38 10"/><path d="M33 12.5L30 32h12l-3-21"/><path d="M31 22h10M30.5 27h11"/>'
  };
  var GENERIC_ICON = '<path d="M4 42h40"/><path d="M8 42V22h9v20"/><path d="M17 42V10h13v32"/><path d="M30 42V26h10v16"/><path d="M22 16h3M22 22h3M22 28h3M22 34h3"/><path d="M12 28h1M12 34h1M35 31h1M35 37h1"/>';
  var CITY_ALIASES = {
    'new delhi': 'delhi', 'delhi-ncr': 'delhi', 'delhi ncr': 'delhi', 'ncr': 'delhi',
    'bangalore': 'bengaluru', 'bombay': 'mumbai', 'calcutta': 'kolkata', 'cochin': 'kochi'
  };
  function cityKey(name) {
    var k = String(name).trim().toLowerCase();
    return CITY_ALIASES[k] || k;
  }

  function iconFor(name) {
    return '<svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
      (LANDMARKS[cityKey(name)] || GENERIC_ICON) + '</svg>';
  }

  function syncTiles() {
    var els = document.querySelectorAll('#cities-grid .cc');
    var cur = cityEl ? cityEl.value : '';
    for (var i = 0; i < els.length; i++) {
      var on = cur !== '' && els[i].getAttribute('data-id') === cur;
      if (!els[i].hasAttribute('data-id')) continue;
      els[i].classList.toggle('on', on);
      els[i].setAttribute('aria-pressed', on ? 'true' : 'false');
    }
  }

  function selectCity(id) {
    if (!cityEl) return;
    cityEl.value = (cityEl.value === id) ? '' : id;
    syncTiles();
    window.dispatchEvent(new CustomEvent('ca:city', { detail: { id: cityEl.value } }));
    if (cityEl.value) {
      var target = document.getElementById('audits');
      if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  }

  function setCityMsg(text) {
    var el = document.getElementById('cities-msg');
    if (el) el.textContent = text;
  }

  function makeTile(city, i) {
    var b = document.createElement('button');
    b.type = 'button';
    b.className = 'cc has';
    b.innerHTML = iconFor(city.name);
    var n = document.createElement('span');
    n.className = 'cc-n';
    n.textContent = city.name;
    b.appendChild(n);
    var c = document.createElement('small');
    c.textContent = city.count === 1 ? '1 audit' : city.count + ' audits';
    b.appendChild(c);
    b.setAttribute('data-id', String(city.id));
    b.setAttribute('aria-pressed', 'false');
    b.style.setProperty('--i', i);
    b.addEventListener('click', function () { setCityMsg(''); selectCity(String(city.id)); });
    return b;
  }

  // Tiles are built from the published audits: only cities that have one appear.
  function renderCities(detail) {
    var grid = document.getElementById('cities-grid');
    if (!grid) return;
    grid.innerHTML = '';
    var cities = (detail && detail.cities) || [];
    if (detail && detail.failed) {
      grid.innerHTML = '<p class="cg-empty">Cities are not available right now. Please try again later.</p>';
      return;
    }
    if (!cities.length) {
      grid.innerHTML = '<p class="cg-empty">Cities will appear here once the first audit is approved and published.</p>';
      return;
    }
    cities.forEach(function (c, i) {
      grid.appendChild(makeTile(c, i));
      if (cityEl) {
        var opt = document.createElement('option');
        opt.value = String(c.id);
        opt.textContent = c.name;
        cityEl.appendChild(opt);
      }
    });
    syncTiles();
  }

  // The audit list can change the city filter too: keep the tiles in step.
  window.addEventListener('ca:citychange', function (e) {
    if (!cityEl) return;
    cityEl.value = (e.detail && e.detail.id) || '';
    syncTiles();
  });

  window.addEventListener('ca:cities', function (e) { renderCities(e.detail); });
  if (window.__caCities) renderCities(window.__caCities);
})();
