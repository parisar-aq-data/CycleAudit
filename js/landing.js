/* js/landing.js */

const nav = document.getElementById('nav');
window.addEventListener('scroll', () => {
  nav.classList.toggle('sc', scrollY > 40);
  const max = document.documentElement.scrollHeight - innerHeight;
  nav.style.setProperty('--p', max > 0 ? Math.min(1, scrollY / max).toFixed(4) : 0);
  document.getElementById('topBtn').classList.toggle('show', scrollY > 300);
});

// Back-to-top button (was an inline onclick, which the page policy does not allow)
const topBtn = document.getElementById('topBtn');
if (topBtn) {
  topBtn.addEventListener('click', () => window.scrollTo({ top: 0, behavior: 'smooth' }));
}

// Hamburger menu toggle
const ham = document.getElementById('ham');
const nl = document.getElementById('nl');
if (ham && nl) {
  ham.addEventListener('click', () => {
    nl.classList.toggle('open');
    ham.classList.toggle('active');
  });
  // Close menu when a nav link is clicked
  nl.querySelectorAll('a').forEach(link => {
    link.addEventListener('click', () => {
      nl.classList.remove('open');
      ham.classList.remove('active');
    });
  });
  // Close menu on outside click
  document.addEventListener('click', (e) => {
    if (!ham.contains(e.target) && !nl.contains(e.target)) {
      nl.classList.remove('open');
      ham.classList.remove('active');
    }
  });
}

// Scroll reveal
const obs = new IntersectionObserver(es => es.forEach(e => {
  if (e.isIntersecting) e.target.classList.add('vs');
}), { threshold: .1 });
document.querySelectorAll('.rv').forEach(el => obs.observe(el));

const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

// Hero sample card: scores count up once
document.querySelectorAll('[data-count]').forEach(el => {
  const to = Number(el.dataset.count);
  if (reduceMotion || !isFinite(to)) return;
  const t0 = performance.now() + 500, dur = 1300;
  el.textContent = '0';
  const tick = now => {
    const k = Math.max(0, Math.min(1, (now - t0) / dur));
    el.textContent = String(Math.round(to * (1 - Math.pow(1 - k, 3))));
    if (k < 1) requestAnimationFrame(tick);
  };
  requestAnimationFrame(tick);
});

// Rating scale bar fills in when it scrolls into view
const scaleBar = document.getElementById('scale-bar');
if (scaleBar) new IntersectionObserver((es, o) => es.forEach(e => {
  if (e.isIntersecting) { scaleBar.classList.add('vs'); o.disconnect(); }
}), { threshold: .4 }).observe(scaleBar);

// Audit process: the timeline line fills as the section scrolls into view
(function () {
  const flow = document.getElementById('flow');
  const fill = document.getElementById('flow-fill');
  if (!flow || !fill) return;
  const steps = Array.from(flow.querySelectorAll('.fstep'));
  let queued = false;
  function update() {
    queued = false;
    const r = flow.getBoundingClientRect();
    const mid = innerHeight * 0.55;
    const h = reduceMotion ? r.height : Math.max(0, Math.min(r.height, mid - r.top));
    fill.style.height = h + 'px';
    steps.forEach(s => {
      const n = s.querySelector('.fnode');
      const y = n.getBoundingClientRect().top + 23 - r.top;
      s.classList.toggle('on', reduceMotion || y <= h);
    });
  }
  const queue = () => { if (!queued) { queued = true; requestAnimationFrame(update); } };
  window.addEventListener('scroll', queue, { passive: true });
  window.addEventListener('resize', queue);
  update();
})();

// Nav: highlight the link for the section being read
(function () {
  const links = Array.from(document.querySelectorAll('#nl a[href^="#"]'));
  const map = new Map(links.map(a => [a.getAttribute('href').slice(1), a]));
  const secs = Array.from(map.keys()).map(id => document.getElementById(id)).filter(Boolean);
  if (!secs.length) return;
  let queued = false;
  function update() {
    queued = false;
    const line = innerHeight * 0.35;
    let cur = null;
    secs.forEach(s => { const r = s.getBoundingClientRect(); if (r.top <= line && r.bottom > line) cur = s.id; });
    links.forEach(a => a.classList.toggle('act', a === map.get(cur)));
  }
  window.addEventListener('scroll', () => { if (!queued) { queued = true; requestAnimationFrame(update); } }, { passive: true });
  update();
})();
