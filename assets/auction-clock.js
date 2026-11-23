'use strict';

(() => {
  const clock = document.querySelector('[data-countdown]');
  if (!clock) return;

  const deadline = Date.parse(clock.dataset.countdown);
  if (!Number.isFinite(deadline)) return;

  const canvas = clock.querySelector('[data-clock-face]');
  const text = clock.querySelector('[data-clock-text]');
  const dayNote = document.querySelector('[data-clock-days]');
  const reduced = matchMedia('(prefers-reduced-motion: reduce)');
  const contrast = matchMedia('(forced-colors: active)');
  const context = canvas.getContext('2d');
  let canPaint = Boolean(context && window.paperClocks?.flap);
  let onScreen = true;
  let previousSecond;
  let currentFace = '';
  let frame, timeout;

  function draw(oldFace, phase) {
    if (!canPaint || contrast.matches || !onScreen) return;
    canvas.hidden = false;
    const density = Math.min(window.devicePixelRatio || 1, 2);
    const width = Math.round(canvas.getBoundingClientRect().width * density);
    if (!width) return;
    const height = Math.round(width * 94 / 396);
    if (canvas.width !== width || canvas.height !== height) {
      canvas.width = width;
      canvas.height = height;
    }
    context.setTransform(width / 396, 0, 0, height / 94, 0, 0);
    context.clearRect(0, 0, 396, 94);
    try {
      window.paperClocks.flap(context, oldFace, currentFace, phase);
      clock.classList.add('is-illustrated');
    } catch {
      canPaint = false;
      showText();
    }
  }

  function showText() {
    canvas.hidden = true;
    clock.classList.remove('is-illustrated');
  }

  function update(animate = false) {
    clearTimeout(timeout);
    cancelAnimationFrame(frame);
    if (document.hidden || clock.dataset.ended === 'true') return;

    const remaining = deadline - Date.now();
    if (remaining <= 0) {
      const opening = clock.dataset.countdownKind === 'opens';
      clock.dataset.ended = 'true';
      if (dayNote) dayNote.textContent = '';
      showText();
      text.textContent = uiLabel(opening ? 'Opening now' : 'Auction ended');
      if (!opening) {
        document.querySelectorAll('.bid-form button[type="submit"]').forEach(button => { button.disabled = true; });
      }
      return;
    }

    const seconds = Math.ceil(remaining / 1000);
    const units = [
      ['Days', Math.floor(seconds / 86400)],
      ['Hours', Math.floor(seconds / 3600) % 24],
      ['Minutes', Math.floor(seconds / 60) % 60],
      ['Seconds', seconds % 60]
    ];
    if (units[0][1] === 0) units.shift();
    const oldFace = currentFace;
    currentFace = units.slice(-3).map(([, value]) => String(value).padStart(2, '0')).join('');
    const days = Math.floor(seconds / 86400);
    if (dayNote) dayNote.textContent = days ? ` +${days}${document.documentElement.lang.startsWith('zh') ? '天' : 'd'}` : '';
    if (seconds !== previousSecond) {
      text.textContent = units.map(([unit, value]) => value + ' ' + uiLabel(unit)).join(' ');
    }

    if (!canPaint || contrast.matches) {
      showText();
    } else if (onScreen) {
      if (animate && !reduced.matches && seconds === previousSecond - 1 && oldFace.length === currentFace.length) {
        const started = performance.now();
        function flip(now) {
          const progress = Math.min(1, (now - started) / 360);
          draw(oldFace, progress);
          if (progress < 1 && canPaint) frame = requestAnimationFrame(flip);
        }
        frame = requestAnimationFrame(flip);
      } else {
        draw(currentFace, 1);
      }
    }
    previousSecond = seconds;
    timeout = setTimeout(() => update(true), remaining - (seconds - 1) * 1000 + 2);
  }

  const refresh = () => update(false);
  document.addEventListener('visibilitychange', refresh);
  window.addEventListener('pageshow', refresh);
  window.addEventListener('resize', refresh);
  for (const preference of [reduced, contrast]) preference.addEventListener('change', refresh);
  if ('IntersectionObserver' in window) {
    new IntersectionObserver(([entry]) => {
      if (onScreen === entry.isIntersecting) return;
      onScreen = entry.isIntersecting;
      refresh();
    }).observe(clock);
  }
  if (document.fonts && !document.fonts.check('400 48px Inter')) {
    canPaint = false;
    document.fonts.load('400 48px Inter').catch(() => {}).then(() => {
      canPaint = Boolean(context && window.paperClocks?.flap);
      refresh();
    });
  }
  refresh();
})();
