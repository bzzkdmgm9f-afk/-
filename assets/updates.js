'use strict';

(() => {
  const link = document.querySelector('[data-update-endpoint]');
  if (!link) return;
  const feed = document.querySelector('[data-activity-feed]');
  let version = link.dataset.version;
  let lastBadgeVersion = version;
  let timer;
  let pending = false;
  let signedOut = false;

  function schedule() {
    clearTimeout(timer);
    if (!document.hidden && !signedOut) timer = setTimeout(refresh, 10000);
  }

  async function refresh() {
    if (pending || document.hidden || signedOut) return;
    pending = true;
    try {
      const endpoint = new URL(link.dataset.updateEndpoint, location.href);
      if (feed) {
        endpoint.searchParams.set('feed', '1');
        endpoint.searchParams.set('version', version);
      }
      const response = await fetch(endpoint, {headers:{Accept:'application/json'}, cache:'no-store'});
      if (response.status === 401) { signedOut = true; location.reload(); return; }
      if (!response.ok) return;
      const data = await response.json();
      if (!Number.isInteger(data.unread) || !Number.isInteger(data.latest)) return;
      document.querySelectorAll('[data-unread-dot]').forEach(dot => { dot.hidden = data.unread === 0; });
      document.querySelectorAll('[data-unread-count]').forEach(badge => {
        badge.hidden = data.unread === 0;
        badge.textContent = String(data.unread);
      });
      const description = data.unread ? link.dataset.unreadTemplate.replace('{count}', data.unread) : '';
      document.querySelectorAll('[data-unread-label]').forEach(label => { label.textContent = description; });
      const nextVersion = data.latest + '-' + data.unread;
      if (nextVersion !== lastBadgeVersion) {
        lastBadgeVersion = nextVersion;
        document.dispatchEvent(new CustomEvent('cabinet:activity-updated', {
          detail: {unread: data.unread, latest: data.latest}
        }));
      }
      // Keep a focused activity action in place until the reader has moved away.
      if (feed && data.feed !== undefined) {
        if (feed.contains(document.activeElement)) return;
        feed.innerHTML = data.feed;
        feed.querySelectorAll('time[datetime]').forEach(formatTime);
      }
      version = nextVersion;
    } catch {
      // A later poll can pick up activity after a temporary connection failure.
    } finally {
      pending = false;
      schedule();
    }
  }

  document.addEventListener('cabinet:refresh-activity', refresh);
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) clearTimeout(timer);
    else refresh();
  });
  window.addEventListener('pageshow', event => { if (event.persisted) refresh(); });
  window.addEventListener('pagehide', () => clearTimeout(timer));
  schedule();
})();
