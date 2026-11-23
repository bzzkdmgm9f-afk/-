'use strict';

const pageLocale = document.documentElement?.lang === 'zh-CN' ? 'zh-CN' : 'en-GB';
const pageLabels = JSON.parse(document.body?.dataset?.uiLabels || '{}');
function uiLabel(key, values = {}) {
  return (pageLabels[key] || key).replace(/\{(\w+)\}/g, (placeholder, name) => values[name] ?? placeholder);
}

const numberTimers = new WeakMap();
const iconMotionTimers = new WeakMap();
const pendingBidForms = new WeakMap();

function animateWatch(button, watched) {
  clearTimeout(iconMotionTimers.get(button));
  button.classList.remove('motion-watch-added', 'motion-watch-removed');
  if (matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  void button.offsetWidth;
  button.classList.add(watched ? 'motion-watch-added' : 'motion-watch-removed');
  iconMotionTimers.set(button, setTimeout(() => {
    button.classList.remove('motion-watch-added', 'motion-watch-removed');
  }, watched ? 440 : 320));
}

function restoreBidForm(form) {
  const pending = pendingBidForms.get(form);
  if (!pending) return;
  clearTimeout(pending.timer);
  pending.controls.forEach(([control, disabled]) => { control.disabled = disabled; });
  if (pending.input) pending.input.readOnly = pending.readOnly;
  form.classList.remove('is-submitting');
  form.removeAttribute('aria-busy');
  pendingBidForms.delete(form);
}

document.addEventListener('submit', event => {
  const form = event.target;
  if (!form.matches('.bid-form')) return;
  event.preventDefault();
  if (pendingBidForms.has(form)) return;
  if (!form.reportValidity()) return;
  const controls = [...form.querySelectorAll('button')].map(button => [button, button.disabled]);
  const input = form.querySelector('[name="amount"]');
  const pending = {controls, input, readOnly: input?.readOnly, timer: null};
  pendingBidForms.set(form, pending);
  form.setAttribute('aria-busy', 'true');
  controls.forEach(([button]) => { button.disabled = true; });
  if (input) input.readOnly = true;
  const reduced = matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (!reduced) form.classList.add('is-submitting');
  const endClock = form.closest('[data-auction-id]')?.querySelector('[data-countdown-kind="ends"]');
  const deadline = Date.parse(endClock?.dataset.countdown || '');
  const nearDeadline = Number.isFinite(deadline) && deadline - Date.now() <= 600;
  const submit = () => {
    try {
      HTMLFormElement.prototype.submit.call(form);
    } catch {
      restoreBidForm(form);
      const announcement = document.querySelector('[data-bid-announcement]');
      if (announcement) announcement.textContent = uiLabel('Your bid could not be submitted. Please try again.');
    }
  };
  if (reduced || nearDeadline) {
    submit();
  } else {
    pending.timer = setTimeout(submit, 380);
  }
});

window.addEventListener('pageshow', () => {
  document.querySelectorAll('.bid-form[aria-busy="true"]').forEach(restoreBidForm);
});

const menuToggle = document.querySelector('.menu-toggle');
const mainNav = document.querySelector('#main-nav');
if (menuToggle && mainNav) {
  menuToggle.addEventListener('click', () => {
    const open = menuToggle.getAttribute('aria-expanded') !== 'true';
    menuToggle.setAttribute('aria-expanded', String(open));
    mainNav.classList.toggle('is-open', open);
  });
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && mainNav.classList.contains('is-open')) {
      mainNav.classList.remove('is-open');
      menuToggle.setAttribute('aria-expanded', 'false');
      menuToggle.focus();
    }
  });
}

document.querySelectorAll('[data-dismiss-notice]').forEach(button => {
  button.addEventListener('click', () => button.closest('.notice')?.remove());
});

document.querySelectorAll('[data-password-toggle]').forEach(button => {
  button.hidden = false;
  button.addEventListener('click', () => {
    const input = document.getElementById(button.dataset.passwordToggle);
    if (!input) return;
    const visible = input.type === 'password';
    input.type = visible ? 'text' : 'password';
    button.setAttribute('aria-pressed', String(visible));
    button.setAttribute('aria-label', uiLabel(visible ? 'Hide password' : 'Show password'));
  });
});

// A lot can appear in both the catalogue and recommendations on the same page.
document.addEventListener('submit', async event => {
  const form = event.target;
  if (!form.matches('form[data-watch-control]')) return;
  if (!window.fetch || !window.FormData) return;
  event.preventDefault();
  if (!form.reportValidity()) return;
  const fields = new FormData(form);
  const lot = fields.get('auction_id');
  const copies = [...document.querySelectorAll('form[data-watch-control]')].filter(copy => copy.elements.auction_id.value === lot);
  if (copies.some(copy => copy.getAttribute('aria-busy') === 'true')) return;
  const buttons = copies.map(copy => [copy.querySelector('button'), copy.querySelector('button').disabled]);
  copies.forEach(copy => {
    copy.setAttribute('aria-busy', 'true');
    copy.querySelector('button').disabled = true;
    const feedback = copy.querySelector('[data-watch-feedback]');
    if (feedback) { feedback.hidden = true; feedback.textContent = ''; }
  });
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), 12000);
  try {
    const response = await fetch(form.getAttribute('action') || window.location.href, {
      method: 'POST', body: fields, headers: {Accept: 'application/json'},
      credentials: 'same-origin', cache: 'no-store', signal: controller.signal
    });
    if (response.redirected || !response.ok || !response.headers.get('content-type')?.includes('application/json')) {
      throw new Error('Watch state was not confirmed');
    }
    const result = await response.json();
    if (typeof result.watched !== 'boolean') throw new Error('Invalid watch state');
    const label = uiLabel(result.watched ? 'Remove from watchlist' : 'Add to watchlist');
    copies.forEach(copy => {
      const button = copy.querySelector('button');
      copy.elements.wanted.value = result.watched ? '0' : '1';
      button.setAttribute('aria-pressed', String(result.watched));
      button.setAttribute('aria-label', label);
      button.classList.toggle('is-watched', result.watched);
      const text = button.querySelector('[data-watch-label]');
      if (text) text.textContent = label;
      animateWatch(button, result.watched);
    });
    const detail = document.querySelector('[data-auction-id]');
    if (detail?.dataset.auctionId === lot) {
      const count = detail.querySelector('[data-watch-count]');
      if (count && Number.isInteger(Number(result.count)) && Number(result.count) >= 0) {
        transitionNumber(count, String(result.count));
      }
    }
    form.dispatchEvent(new CustomEvent('cabinet:watch-change', {bubbles:true, detail:{watched:result.watched}}));
    if (form.closest('.dashboard-section')) {
      setTimeout(() => location.reload(), matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 440);
    }
  } catch {
    const feedback = form.querySelector('[data-watch-feedback]');
    if (feedback) {
      feedback.textContent = form.dataset.watchError;
      feedback.hidden = false;
    }
  } finally {
    clearTimeout(timeout);
    copies.forEach(copy => { copy.removeAttribute('aria-busy'); });
    buttons.forEach(([button, disabled]) => { button.disabled = disabled; });
  }
});

function formatTime(element) {
  const date = new Date(element.dateTime);
  if (!Number.isNaN(date.getTime())) {
    element.textContent = new Intl.DateTimeFormat(pageLocale, {
      day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit'
    }).format(date);
    element.title = date.toLocaleString(pageLocale, {timeZoneName: 'short'});
  }
}
document.querySelectorAll('time[datetime]').forEach(formatTime);

[
  ['#end-time', '[data-timezone-offset]'],
  ['#start-time', '[data-start-timezone-offset]']
].forEach(([inputSelector, offsetSelector]) => {
  const dateInput = document.querySelector(inputSelector);
  if (!dateInput) return;
  const date = new Date(Date.now() + 60 * 1000);
  const localDate = new Date(date.getTime() - date.getTimezoneOffset() * 60000);
  dateInput.min = localDate.toISOString().slice(0, 16);
  const offset = dateInput.form?.querySelector(offsetSelector);
  dateInput.form?.querySelectorAll('[data-timezone-help]').forEach(help => {
    help.textContent = uiLabel('Enter your local date and time. It will be stored in UTC.');
  });
  const updateOffset = () => {
    const selected = dateInput.value ? new Date(dateInput.value) : new Date();
    if (offset && !Number.isNaN(selected.getTime())) {
      offset.value = String(selected.getTimezoneOffset());
    }
  };
  dateInput.addEventListener('change', updateOffset);
  dateInput.form?.addEventListener('submit', updateOffset);
  updateOffset();
});

document.querySelectorAll('.photo-thumbnail').forEach(button => {
  button.addEventListener('click', () => {
    const image = document.querySelector('.detail-image');
    if (!image) return;
    image.src = button.dataset.image;
    document.querySelectorAll('.photo-thumbnail').forEach(thumbnail => {
      const active = thumbnail === button;
      thumbnail.classList.toggle('is-active', active);
      thumbnail.setAttribute('aria-pressed', String(active));
    });
  });
});

const uploadInput = document.querySelector('[data-file-input]');
if (uploadInput) {
  let previews = [];
  uploadInput.addEventListener('change', () => {
    const container = document.querySelector('[data-upload-preview]');
    if (!container) return;
    previews.forEach(url => URL.revokeObjectURL(url));
    previews = [];
    container.replaceChildren();
    if (uploadInput.files.length > 5) {
      uploadInput.setCustomValidity(uiLabel('Choose no more than five photographs.'));
      uploadInput.reportValidity();
      return;
    }
    for (const file of uploadInput.files) {
      if (file.size > 5 * 1024 * 1024) {
        uploadInput.setCustomValidity(uiLabel('Each photograph must be no larger than 5 MB.'));
        uploadInput.reportValidity();
        return;
      }
    }
    uploadInput.setCustomValidity('');
    for (const file of uploadInput.files) {
      const src = URL.createObjectURL(file);
      previews.push(src);
      const image = document.createElement('img');
      image.src = src;
      image.alt = file.name;
      container.append(image);
    }
  });
}

const authForm = document.querySelector('[data-auth-form]');
if (authForm) {
  const password = authForm.querySelector('[name="password"]');
  const confirmation = authForm.querySelector('[name="password_confirmation"]');
  if (password && confirmation) {
    const comparePasswords = () => {
      confirmation.setCustomValidity(confirmation.value && confirmation.value !== password.value
        ? uiLabel('The passwords must match.') : '');
    };
    password.addEventListener('input', comparePasswords);
    confirmation.addEventListener('input', comparePasswords);
  }
}

const auctionPage = document.querySelector('[data-auction-id][data-poll-url]');
if (auctionPage) {
  const currency = new Intl.NumberFormat(pageLocale, {style: 'currency', currency: 'GBP'});
  let stopped = false;
  async function refreshBids() {
    if (stopped || document.hidden) return;
    try {
      const response = await fetch(auctionPage.dataset.pollUrl, {
        headers: {'Accept': 'application/json'}, credentials: 'same-origin', cache: 'no-store'
      });
      if (!response.ok) {
        if (response.status === 404 || response.status === 401) stopped = true;
        return;
      }
      const data = await response.json();
      if (data.status !== auctionPage.dataset.initialStatus) {
        window.location.reload();
        return;
      }
      if (data.status === 'cancelled') { stopped = true; return; }
      if (typeof data.current_price !== 'undefined') {
        transitionNumber(document.querySelector('[data-current-price]'), currency.format(Number(data.current_price)));
        const label = document.querySelector('[data-price-label]');
        if (label) label.textContent = uiLabel(data.status === 'completed' ? 'Winning bid' : (data.status === 'unsold' ? (Number(data.bid_count) ? 'Highest bid' : 'Starting price · no bids') : (Number(data.bid_count) ? 'Current bid' : 'Starting bid')));
      }
      if (Number.isInteger(Number(data.bid_count))) {
        transitionNumber(document.querySelector('[data-bid-count]'), String(data.bid_count));
      }
      if (Number.isInteger(Number(data.bidder_count))) {
        transitionNumber(document.querySelector('[data-bidder-count]'), String(data.bidder_count));
      }
      updateNextBid(data, auctionPage, currency);
      if (data.watch_count != null) {
        transitionNumber(document.querySelector('[data-watch-count]'), String(data.watch_count));
      }
      const reserve = document.querySelector('[data-reserve-status]');
      if (reserve && data.has_reserve) {
        reserve.textContent = uiLabel(data.reserve_reached ? 'Reserve met.' : 'Reserve not met.');
      }
      const updated = document.querySelector('[data-last-updated] time');
      if (updated && data.last_updated) {
        updated.dateTime = data.last_updated.replace(' ', 'T') + 'Z';
        formatTime(updated);
      }
      if (data.status === 'scheduled') {
        const status = document.querySelector('[data-auction-status]');
        document.querySelectorAll('[data-auction-status]').forEach(status => { status.textContent = uiLabel('Upcoming'); status.dataset.state = 'scheduled'; });
      }
      if (data.status === 'completed' || data.status === 'unsold') {
        document.querySelectorAll('[data-auction-status]').forEach(status => {
          status.textContent = uiLabel(data.status === 'completed' ? 'Sold' : 'Ended · unsold');
          status.dataset.state = data.status;
          status.classList.remove('is-live');
        });
        document.querySelectorAll('.bid-form button[type="submit"]').forEach(button => {
          button.disabled = true;
        });
        stopped = true;
      }
      const announcement = document.querySelector('[data-bid-announcement]');
      const summary = uiLabel('{count} bids. Current price {price}.', {count: data.bid_count, price: currency.format(Number(data.current_price))});
      if (announcement && announcement.textContent !== summary) announcement.textContent = summary;
      const history = document.querySelector('[data-bid-history]');
      if (history && Array.isArray(data.bids)) {
        renderBidHistory(history, data.bids, currency);
      }
    } catch (error) {
      // The current page stays usable when a refresh is temporarily unavailable.
    }
  }
  const transitionKey = `cabinet-bid-${auctionPage.dataset.auctionId}`;
  try {
    const saved = JSON.parse(sessionStorage.getItem(transitionKey) || 'null');
    sessionStorage.removeItem(transitionKey);
    if (saved && Date.now() - saved.at < 30000) {
      for (const [selector, previous] of Object.entries(saved.values)) {
        const element = document.querySelector(selector);
        if (element && typeof previous === 'string') {
          const finalValue = element.textContent.trim();
          if (finalValue !== previous) {
            element.textContent = previous;
            transitionNumber(element, finalValue);
          }
        }
      }
    }
    document.querySelector('.bid-form')?.addEventListener('submit', () => {
      const values = {};
      ['[data-current-price]', '[data-bid-count]', '[data-bidder-count]'].forEach(selector => {
        values[selector] = document.querySelector(selector)?.dataset.finalValue || document.querySelector(selector)?.textContent.trim() || '';
      });
      try { sessionStorage.setItem(transitionKey, JSON.stringify({at:Date.now(), values})); } catch (_) { /* Animation is optional. */ }
    });
  } catch (_) { /* Bidding works without browser storage. */ }
  setInterval(refreshBids, 8000);
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) refreshBids();
  });
}

function decimalPence(value) {
  const parts = String(value).match(/^(\d+)(?:\.(\d{1,2}))?$/);
  if (!parts) return null;
  return Number(parts[1]) * 100 + Number((parts[2] || '').padEnd(2, '0'));
}

function updateNextBid(data, auctionPage, currency) {
  const label = document.querySelector('[data-next-bid]');
  if (!label) return;
  const current = decimalPence(data.current_price);
  const starting = decimalPence(auctionPage.dataset.startingPrice);
  const increment = decimalPence(auctionPage.dataset.minimumIncrement);
  if (current === null || starting === null || increment === null) return;
  const next = Number(data.bid_count) > 0 ? current + increment : starting;
  if (next > 9999999999) {
    label.textContent = uiLabel('The maximum supported bid has been reached.');
    const button = document.querySelector('.bid-form button[type="submit"]');
    if (button) button.disabled = true;
    return;
  }
  const message = uiLabel('Minimum next bid {price}.', {price: currency.format(next / 100)});
  if (label.textContent !== message) label.textContent = message;
  const input = document.querySelector('#bid-amount');
  if (input) {
    input.min = `${Math.floor(next / 100)}.${String(next % 100).padStart(2, '0')}`;
    input.placeholder = input.min;
  }
}

function renderBidHistory(container, bids, currency) {
  if (!bids.length) return;
  const wrap = document.createElement('div');
  wrap.className = 'table-scroll';
  const table = document.createElement('table');
  table.className = 'data-table';
  const thead = table.createTHead();
  const header = thead.insertRow();
  ['Bidder', 'Placed', 'Bid'].forEach((label, index) => {
    const cell = document.createElement('th');
    cell.scope = 'col';
    cell.textContent = uiLabel(label);
    if (index === 2) cell.className = 'align-right';
    header.append(cell);
  });
  const body = table.createTBody();
  bids.forEach(bid => {
    const row = body.insertRow();
    row.insertCell().textContent = bid.username;
    const date = new Date(String(bid.placed_at).replace(' ', 'T') + (String(bid.placed_at).includes('Z') ? '' : 'Z'));
    row.insertCell().textContent = Number.isNaN(date.getTime())
      ? bid.placed_at : new Intl.DateTimeFormat(pageLocale, {day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit'}).format(date);
    const amount = row.insertCell();
    amount.className = 'align-right';
    const strong = document.createElement('strong');
    strong.textContent = currency.format(Number(bid.amount));
    amount.append(strong);
  });
  wrap.append(table);
  container.replaceChildren(wrap);
}


function transitionNumber(element, next) {
  if (!element) return;
  const previous = element.dataset.finalValue || element.textContent.trim();
  if (previous === next) return;
  clearTimeout(numberTimers.get(element));
  element.dataset.finalValue = next;
  element.setAttribute('aria-label', next);
  if (matchMedia('(prefers-reduced-motion: reduce)').matches) {
    element.classList.remove('number-transition');
    element.textContent = next;
    return;
  }
  const oldValue = document.createElement('span');
  const newValue = document.createElement('span');
  oldValue.className = 'number-old';
  newValue.className = 'number-new';
  oldValue.textContent = previous;
  newValue.textContent = next;
  oldValue.setAttribute('aria-hidden', 'true');
  newValue.setAttribute('aria-hidden', 'true');
  element.classList.add('number-transition');
  element.replaceChildren(oldValue, newValue);
  numberTimers.set(element, setTimeout(() => {
    element.textContent = next;
    element.classList.remove('number-transition');
  }, 500));
}
