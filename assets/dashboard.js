'use strict';
const bidInput = document.querySelector('#bid-amount');
if (bidInput) {
  const stepper = document.querySelector('[data-bid-stepper]');
  const parsePence = value => {
    const match = String(value).match(/^(\d+)(?:\.(\d{1,2}))?$/);
    return match ? Number(match[1]) * 100 + Number((match[2] || '').padEnd(2, '0')) : null;
  };
  stepper?.querySelectorAll('[data-bid-step]').forEach(button => {
    button.addEventListener('click', () => {
      const minimum = parsePence(bidInput.placeholder);
      const increment = parsePence(stepper.dataset.increment);
      if (minimum === null || increment === null) return;
      const current = parsePence(bidInput.value);
      const amount = Math.min(9999999999, Math.max(minimum, current === null ? minimum : current + Number(button.dataset.bidStep) * increment));
      bidInput.value = `${Math.floor(amount / 100)}.${String(amount % 100).padStart(2, '0')}`;
      bidInput.dispatchEvent(new Event('input', {bubbles:true}));
    });
  });
}

const dashboardLots = [...document.querySelectorAll('[data-dashboard-lot]')];
if (dashboardLots.length) {
  let refreshing = false;
  const endpoint = new URL('index.php', window.location.href);
  endpoint.searchParams.set('page', 'dashboard_activity');
  endpoint.searchParams.set('ids', dashboardLots.map(lot => lot.dataset.dashboardLot).join(','));
  async function refreshDashboard() {
    if (document.hidden || refreshing) return;
    refreshing = true;
    try {
      const response = await fetch(endpoint, {headers:{'Accept':'application/json'}, cache:'no-store'});
      if (!response.ok) return;
      const rows = await response.json();
      if (!Array.isArray(rows)) return;
      const changed = rows.length !== dashboardLots.length || rows.some(row => {
        const lot = dashboardLots.find(lot => Number(lot.dataset.dashboardLot) === Number(row.auction_id));
        return !lot || Number(lot.dataset.bidCount) !== Number(row.bid_count)
          || lot.dataset.auctionState !== row.status
          || Number(lot.dataset.viewerLeading) !== Number(row.viewer_leading);
      });
      if (changed) window.location.reload();
    } catch (_) { /* Saved actions remain available when polling is interrupted. */ }
    finally { refreshing = false; }
  }
  setInterval(refreshDashboard, 8000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) refreshDashboard(); });
}

const interestEditor = document.getElementById('interest-editor');
if (interestEditor) {
  const editLink = document.getElementById('edit-interests');
  const cancelLink = interestEditor.querySelector('[data-cancel-interests]');
  function showInterests(open) {
    interestEditor.reset();
    interestEditor.hidden = !open;
    editLink.setAttribute('aria-expanded', String(open));
    history.replaceState(history.state, '', (open ? editLink : cancelLink).href);
    if (open) interestEditor.querySelector('input[type="checkbox"],button[type="submit"]').focus();
    else editLink.focus();
  }
  [editLink, cancelLink].forEach(link => {
    link.addEventListener('click', event => {
      if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
      event.preventDefault();
      showInterests(link === editLink && interestEditor.hidden);
    });
  });
}

const savedGrid = document.querySelector('[data-collection-grid]');
if (savedGrid) {
  savedGrid.addEventListener('cabinet:watch-change', event => {
    const card = event.target.closest('.collection-lot');
    if (!card || event.detail.watched) return;
    const next = card.nextElementSibling || card.previousElementSibling;
    const needsFocus = card.contains(document.activeElement) || document.activeElement === document.body;
    card.remove();
    const count = savedGrid.children.length;
    const label = document.querySelector('[data-collection-count]');
    label.textContent = label.dataset.format.replace('{n}', String(count));
    const empty = document.querySelector('[data-collection-empty]');
    empty.hidden = count > 0;
    if (needsFocus) (next?.querySelector('.collection-lot-link') || empty.querySelector('a')).focus();
  });
}
