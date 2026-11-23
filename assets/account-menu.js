'use strict';

(() => {
  const menu = document.getElementById('account-menu');
  if (!menu) return;
  const trigger = document.getElementById('account-trigger');
  const navigation = document.getElementById('main-nav');
  const navigationButton = document.querySelector('.menu-toggle');

  menu.addEventListener('toggle', () => {
    if (menu.open && navigation?.classList.contains('is-open')) {
      navigation.classList.remove('is-open');
      navigationButton?.setAttribute('aria-expanded', 'false');
    }
  });

  document.addEventListener('click', event => {
    if (!menu.contains(event.target)) menu.open = false;
  });

  document.addEventListener('focusin', event => {
    if (!menu.contains(event.target)) menu.open = false;
  });

  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && menu.open) {
      menu.open = false;
      trigger.focus();
    }
  });

  window.addEventListener('pagehide', () => { menu.open = false; });
})();
