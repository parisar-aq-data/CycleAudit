/* js/my_team.js — the "My team" screen (pages/city_team.php).
   Two lists: my team, and the other surveyors of the city. Search and filters only change what is
   shown. Add / Remove sends the surveyor's id to api/admin/team.php; every decision is made on the
   server, and a row moves to the other list only after the server says yes. */
(function () {
  'use strict';
  var app = document.getElementById('tmApp');
  if (!app) { return; }
  var csrf = app.getAttribute('data-csrf') || '';
  var rows = [];
  try { rows = JSON.parse(document.getElementById('tmData').textContent) || []; } catch (e) { rows = []; }

  var listMine = document.getElementById('tmListMine');
  var listAll  = document.getElementById('tmListAll');
  var msgEl    = document.getElementById('tmMsg');
  var search   = document.getElementById('tmSearch');
  var addAll   = document.getElementById('tmAddAll');
  var filter   = 'all';
  var busy     = {};
  var timer    = null;
  var shownFree = [];            // surveyors an "Add all shown" would add

  function byId(id) { return document.getElementById(id); }
  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) { n.className = cls; }
    if (text !== undefined) { n.textContent = text; }
    return n;
  }
  function say(text, isErr) {
    msgEl.textContent = text || '';
    msgEl.className = 'tm-msg' + (isErr ? ' err' : '');
  }
  function matches(r, q) {
    return !q || (r.name + ' ' + r.email).toLowerCase().indexOf(q) !== -1;
  }
  function byName(a, b) { return a.name.localeCompare(b.name) || a.id - b.id; }

  function row(r) {
    var li = el('li', 'tm-item');
    var who = el('div', 'tm-who');
    who.appendChild(el('b', '', r.name));
    who.appendChild(el('small', '', r.email));
    li.appendChild(who);
    if (r.state === 'other') {
      li.appendChild(el('span', 'tm-tag tm-other', 'In ' + r.other_leader + "'s team"));
      li.appendChild(el('span', 'tm-note', 'Only that leader can change this.'));
      return li;
    }
    if (r.state === 'free') { li.appendChild(el('span', 'tm-tag', 'No team yet')); }
    if (r.state === 'mine' && r.removal_pending) { li.appendChild(el('span', 'tm-tag', 'Leaving after current work')); }
    var b = el('button', 'tm-btn' + (r.state === 'mine' ? ' tm-remove' : ''),
      r.state === 'mine' ? (r.removal_pending ? 'Cancel removal' : 'Remove') : 'Add to my team');
    b.type = 'button';
    b.disabled = !!busy[r.id];
    b.addEventListener('click', function () { change(r); });
    li.appendChild(b);
    return li;
  }

  function fill(list, items, emptyText) {
    var frag = document.createDocumentFragment();
    if (!items.length) { frag.appendChild(el('li', 'tm-empty', emptyText)); }
    items.forEach(function (r) { frag.appendChild(row(r)); });
    list.textContent = '';
    list.appendChild(frag);
  }

  function render() {
    var q = search.value.trim().toLowerCase();
    var mine = rows.filter(function (r) { return r.state === 'mine'; }).sort(byName);
    // Surveyors with no team first (they are the ones to act on), then those in other teams.
    var rest = rows.filter(function (r) { return r.state !== 'mine'; }).sort(function (a, b) {
      return (a.state === b.state ? 0 : (a.state === 'free' ? -1 : 1)) || byName(a, b);
    });
    var nFree  = rest.filter(function (r) { return r.state === 'free'; }).length;

    var shownMine = mine.filter(function (r) { return matches(r, q); });
    var shownRest = rest.filter(function (r) { return matches(r, q) && (filter === 'all' || r.state === filter); });
    shownFree = shownRest.filter(function (r) { return r.state === 'free'; });

    fill(listMine, shownMine, mine.length ? 'No one in your team matches your search.' : 'Nobody is in your team yet. Add surveyors from the list.');
    fill(listAll, shownRest, rest.length ? 'No surveyors match this search or filter.' : 'Every surveyor of your city is in your team.');

    byId('tmCountMine').textContent = String(mine.length);
    byId('tmCountAll').textContent  = String(rest.length);
    byId('tmNAll').textContent      = String(rest.length);
    byId('tmNFree').textContent     = String(nFree);
    byId('tmNOther').textContent    = String(rest.length - nFree);

    addAll.hidden = shownFree.length < 2;
    addAll.textContent = 'Add all shown (' + shownFree.length + ')';
    addAll.disabled = Object.keys(busy).length > 0;
  }

  function post(payload) {
    return fetch('../api/admin/team.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf, 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify(payload)
    }).then(function (res) { return res.json(); });
  }

  function change(r) {
    var adding = r.state !== 'mine' || !!r.removal_pending;   // "Cancel removal" adds them back
    busy[r.id] = true; say('', false); render();
    post({ action: adding ? 'add' : 'remove', user_id: r.id })
      .then(function (res) {
        delete busy[r.id];
        if (res && res.success) {
          if (!adding && res.mode === 'wait') {
            r.removal_pending = true;          // stays in the team until the started segments are in
          } else {
            r.state = adding ? 'mine' : 'free';
            r.removal_pending = false;
          }
          r.other_leader = null;
          say(res.message || (r.name + (adding ? ' is now in your team.' : ' was removed from your team.')), false);
        } else {
          say((res && res.error) || 'Something went wrong. Please try again.', true);
        }
        render();
      })
      .catch(function () {
        delete busy[r.id];
        say('Something went wrong. Please try again.', true);
        render();
      });
  }

  addAll.addEventListener('click', function () {
    var batch = shownFree.slice(0, 200);
    if (batch.length < 2) { return; }
    if (!window.confirm('Add ' + batch.length + ' surveyors to your team?')) { return; }
    batch.forEach(function (r) { busy[r.id] = true; });
    say('', false); render();
    post({ action: 'add_many', user_ids: batch.map(function (r) { return r.id; }) })
      .then(function (res) {
        batch.forEach(function (r) { delete busy[r.id]; });
        if (res && res.success) {
          var added = res.added || [];
          rows.forEach(function (r) { if (added.indexOf(r.id) !== -1) { r.state = 'mine'; r.other_leader = null; } });
          var failed = Object.keys(res.failed || {}).length;
          say(added.length + ' added to your team.' + (failed ? ' ' + failed + ' could not be added. Reload the page to see the latest.' : ''), failed > 0);
        } else {
          say((res && res.error) || 'Something went wrong. Please try again.', true);
        }
        render();
      })
      .catch(function () {
        batch.forEach(function (r) { delete busy[r.id]; });
        say('Something went wrong. Please try again.', true);
        render();
      });
  });

  search.addEventListener('input', function () {
    clearTimeout(timer);
    timer = setTimeout(render, 120);
  });
  app.querySelectorAll('.tm-chip').forEach(function (c) {
    c.addEventListener('click', function () {
      filter = c.getAttribute('data-filter');
      app.querySelectorAll('.tm-chip').forEach(function (o) { o.classList.toggle('on', o === c); });
      render();
    });
  });
  render();
})();
