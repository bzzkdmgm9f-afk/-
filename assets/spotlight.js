'use strict';

(() => {
  const root = document.querySelector('[data-spotlight]');
  if (!root) return;
  const slides = [...root.querySelectorAll('.spotlight-slide')];
  const controls = root.querySelector('.spotlight-steps');
  if (!controls || slides.length < 2) return;
  const steps = [...controls.querySelectorAll('[data-slide-select]')];
  const announcement = root.querySelector('[data-slide-announcement]');
  const motion = matchMedia('(prefers-reduced-motion: reduce)');
  let index = 0;
  let timer = 0;
  let intersecting = true;
  let suspended = false;
  let animations = [];

  function schedule() {
    clearTimeout(timer);
    if (!motion.matches && !suspended && intersecting && !document.hidden && document.activeElement !== slides[index]) {
      timer = setTimeout(() => show((index + 1) % slides.length, 1, false), 5500);
    }
  }

  function stopTransition() {
    for (const animation of animations) animation.cancel();
    animations = [];
    slides.forEach((slide, position) => { slide.hidden = position !== index; });
  }

  function preloadNext() {
    const image = slides[(index + 1) % slides.length].querySelector('img');
    if (image) image.loading = 'eager';
  }

  function show(next, direction, manual) {
    if (next === index) { schedule(); return; }
    stopTransition();
    const old = slides[index];
    const current = slides[next];
    old.inert = true;
    current.hidden = false;
    current.inert = false;
    index = next;
    preloadNext();
    steps.forEach((button, position) => {
      if (position === index) button.setAttribute('aria-current', 'true');
      else button.removeAttribute('aria-current');
    });
    if (manual) announcement.textContent = current.dataset.slideTitle;
    if (current.animate && !motion.matches) {
      const timing = {duration:600, easing:'cubic-bezier(.3,.02,.2,1)'};
      const delta = direction * 100;
      const incoming = current.animate([{transform:'translateX(' + delta + '%)'}, {transform:'translateX(0)'}], timing);
      const outgoing = old.animate([{transform:'translateX(0)'}, {transform:'translateX(' + (-delta) + '%)'}], timing);
      animations = [incoming, outgoing];
      outgoing.onfinish = () => { if (slides[index] !== old) old.hidden = true; };
    } else {
      old.hidden = true;
    }
    schedule();
  }

  controls.hidden = false;
  preloadNext();
  steps.forEach((button, position) => button.addEventListener('click', () => show(position, position < index ? -1 : 1, true)));
  controls.addEventListener('keydown', event => {
    if (!['ArrowLeft','ArrowRight'].includes(event.key)) return;
    event.preventDefault();
    const direction = event.key === 'ArrowRight' ? 1 : -1;
    const next = (index + direction + slides.length) % slides.length;
    steps[next].focus();
    show(next, direction, true);
  });
  root.addEventListener('focusin', schedule);
  root.addEventListener('focusout', () => requestAnimationFrame(schedule));
  function motionChange() { if (motion.matches) stopTransition(); schedule(); }
  motion.addEventListener('change', motionChange);
  document.addEventListener('visibilitychange', schedule);
  if ('IntersectionObserver' in window) {
    new IntersectionObserver(entries => { intersecting = entries[0].isIntersecting; schedule(); }).observe(root);
  }
  window.addEventListener('pagehide', () => { suspended = true; clearTimeout(timer); });
  window.addEventListener('pageshow', () => { suspended = false; schedule(); });
  schedule();
})();

(() => {
  const config = document.querySelector('#catalog-client');
  const groups = new Map();
  // Completed lots have final totals. Live and upcoming lots continue to receive activity.
  document.querySelectorAll('[data-lot]').forEach(node => {
    if (!['active','scheduled'].includes(node.dataset.status)) return;
    const id = node.dataset.lot;
    if (!groups.has(id)) groups.set(id, []);
    groups.get(id).push(node);
  });
  if (!config || !groups.size) return;
  const currency = new Intl.NumberFormat(document.documentElement.lang, {style:'currency', currency:'GBP', currencyDisplay:'narrowSymbol'});
  const ids = [...groups.keys()];
  const batches = [];
  for (let start = 0; start < ids.length; start += 128) batches.push(ids.slice(start, start + 128));
  let timer = 0;
  let inFlight = false;
  let suspended = false;

  function render(row) {
    for (const node of groups.get(String(row.auction_id)) || []) {
      if (node.dataset.status !== row.status) {
        window.location.reload();
        return false;
      }
      const labels = config.dataset;
      const bidders = Number(row.bidder_count);
      const bids = Number(row.bid_count);
      if (!Number.isInteger(bidders) || !Number.isInteger(bids) || !Number.isFinite(Number(row.current_price))) continue;
      const updates = [
        ['[data-lot-price]', currency.format(Number(row.current_price))],
        ['[data-lot-bidders]', (bidders === 1 ? labels.bidder : labels.bidders).replace('{count}', bidders)],
        ['[data-lot-bids]', (bids === 1 ? labels.bid : labels.bids).replace('{count}', bids)],
        ['[data-lot-price-label]', bids ? labels.current : labels.starting],
      ];
      for (const [selector, value] of updates) {
        const field = node.querySelector(selector);
        if (field && field.textContent.trim() !== value) field.textContent = value;
      }
    }
    return true;
  }

  async function refresh() {
    clearTimeout(timer);
    if (document.hidden || suspended || inFlight) return;
    inFlight = true;
    try {
      for (const batch of batches) {
        const url = new URL(config.dataset.endpoint, location.href);
        url.searchParams.set('ids', batch.join(','));
        const response = await fetch(url, {headers:{Accept:'application/json'}, cache:'no-store', credentials:'same-origin'});
        if (!response.ok) break;
        const rows = await response.json();
        if (!Array.isArray(rows) || rows.some(row => !render(row))) break;
        if (document.hidden || suspended) break;
      }
    } catch (_) { /* Keep the last confirmed totals until the next refresh. */ }
    finally { inFlight = false; schedule(); }
  }

  function schedule() {
    clearTimeout(timer);
    if (!suspended && !document.hidden && !inFlight) timer = setTimeout(refresh, 8000);
  }
  document.addEventListener('visibilitychange', () => { if (document.hidden) clearTimeout(timer); else refresh(); });
  window.addEventListener('pagehide', () => { suspended = true; clearTimeout(timer); });
  window.addEventListener('pageshow', event => { suspended = false; if (event.persisted) refresh(); else schedule(); });
  schedule();
})();
