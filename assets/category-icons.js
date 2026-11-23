(() => {
  'use strict';
  const keys = new Set(['all', 'cameras', 'comics', 'computers', 'figures', 'games', 'phones', 'records', 'watches', 'cards', 'collectibles']);
  window.CabinetCategoryIcon = name => {
    const key = String(name || '').trim().toLowerCase();
    const id = keys.has(key) ? key : 'collectibles';
    return `<svg class="category-glyph" width="20" height="20" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><use href="assets/category-icons.svg#${id}"></use></svg>`;
  };
})();
