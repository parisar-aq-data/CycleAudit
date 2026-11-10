/* js/landing_cities.js - city tiles on the home page: one small tile (the city name) per city that has a published audit. Tapping a tile filters the audit list to that city. */
(function () {
  var cityEl  = document.getElementById('stat-city');

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

  // Every city gets the same small tile: just its name. Tapping it filters the audit list.
  function makeTile(city, i) {
    var b = document.createElement('button');
    b.type = 'button';
    b.className = 'cc has';
    b.textContent = city.name;
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
