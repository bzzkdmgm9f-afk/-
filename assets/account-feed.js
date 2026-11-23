'use strict';

(() => {
  const menu = document.getElementById('account-menu');
  if (!menu) return;
  const trigger = menu.querySelector('[data-account-feed-trigger]');
  const main = menu.querySelector('[data-account-main]');
  const feed = document.getElementById('account-feed');
  const back = feed.querySelector('[data-account-feed-back]');
  const clear = feed.querySelector('[data-account-feed-clear]');
  const items = feed.querySelector('[data-account-feed-items]');
  const status = feed.querySelector('[role="status"]');
  let controller;
  let clearing = false;
  let version = '';

  function render(data) {
    if (typeof data.items !== 'string' || !Number.isInteger(data.unread) || !Number.isInteger(data.latest)) {
      throw new Error('Invalid message response');
    }
    items.innerHTML = data.items;
    if (typeof formatTime === 'function') items.querySelectorAll('time').forEach(formatTime);
    clear.hidden = data.unread === 0;
    version = data.latest + '-' + data.unread;
    status.textContent = '';
  }

  async function load() {
    if (clearing) return;
    controller?.abort();
    const request = new AbortController();
    controller = request;
    try {
      const response = await fetch(feed.dataset.endpoint, {
        headers: {Accept: 'application/json'}, cache: 'no-store', signal: request.signal
      });
      if (response.status === 401) { window.location.reload(); return; }
      if (!response.ok) throw new Error('Could not load messages');
      const data = await response.json();
      if (!request.signal.aborted && !feed.hidden) render(data);
    } catch (error) {
      if (!request.signal.aborted) status.textContent = feed.dataset.error;
    }
  }

  function showMain(focusLink) {
    controller?.abort();
    main.hidden = false;
    feed.hidden = true;
    trigger.setAttribute('aria-expanded', 'false');
    if (focusLink) trigger.focus();
  }

  trigger.addEventListener('click', event => {
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    event.preventDefault();
    main.hidden = true;
    feed.hidden = false;
    trigger.setAttribute('aria-expanded', 'true');
    if (!version) status.textContent = feed.dataset.loading;
    back.focus();
    load();
  });
  back.addEventListener('click', () => showMain(true));
  menu.addEventListener('toggle', () => { if (!menu.open) showMain(false); });
  document.addEventListener('cabinet:activity-updated', event => {
    if (!feed.hidden && version !== event.detail.latest + '-' + event.detail.unread &&
        !items.contains(document.activeElement)) load();
  });
  clear.addEventListener('submit', async event => {
    event.preventDefault();
    if (clearing) return;
    clearing = true;
    controller?.abort();
    const button = clear.querySelector('button');
    button.disabled = true;
    try {
      const response = await fetch(clear.getAttribute('action'), {
        method: 'POST', headers: {Accept: 'application/json'}, body: new FormData(clear)
      });
      if (!response.ok) throw new Error('Could not mark messages as read');
      render(await response.json());
      if (!feed.hidden && clear.hidden) back.focus();
      document.dispatchEvent(new Event('cabinet:refresh-activity'));
    } catch {
      status.textContent = feed.dataset.error;
    } finally {
      clearing = false;
      button.disabled = false;
    }
  });
})();
