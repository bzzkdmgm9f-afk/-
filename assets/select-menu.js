(() => {
  'use strict';

  let openMenu;
  let sequence = 0;
  const gap = 6;
  const edge = 8;
  const span = className => Object.assign(document.createElement('span'), {className});
  const labelOf = option => option.label.trim();
  const usable = option => !!option && !option.disabled && !option.hidden && !option.parentElement.disabled;

  function enhance(select) {
    const field = select.closest('.select-field');
    if (!field || select.multiple || select.size > 1 || field.classList.contains('has-choice-menu')) return;
    const id = 'cabinet-choice-' + ++sequence;
    const trigger = span('choice-trigger');
    const value = span('choice-value');
    const names = Array.from(select.labels || [], label => label.querySelector('.sr-only') || label);
    const list = document.createElement('div');
    const error = span('choice-error');
    let active = -1, query = '', queryTime = 0;
    trigger.tabIndex = 0;
    trigger.id = id + '-trigger';
    trigger.setAttribute('role', 'combobox');
    trigger.setAttribute('aria-haspopup', 'listbox');
    trigger.setAttribute('aria-expanded', 'false');
    trigger.setAttribute('aria-controls', id);
    if (select.required) trigger.setAttribute('aria-required', 'true');
    if (select.hasAttribute('aria-describedby')) trigger.setAttribute('aria-describedby', select.getAttribute('aria-describedby'));
    const labelIds = names.map((node, i) => node.id || (node.id = id + '-label-' + i)).join(' ');
    list.id = id;
    list.className = 'choice-list';
    list.setAttribute('role', 'listbox');
    for (const node of [trigger, list]) {
      if (labelIds) node.setAttribute('aria-labelledby', labelIds);
      else node.setAttribute('aria-label', select.getAttribute('aria-label') || select.name);
    }
    const chevron = span('choice-chevron');
    chevron.setAttribute('aria-hidden', 'true');
    trigger.append(value, chevron);
    error.id = id + '-error';
    error.hidden = true;
    error.setAttribute('aria-live', 'polite');
    error.textContent = /^zh/i.test(document.documentElement.lang) ? '请选择一项。' : 'Please choose an option.';
    list.hidden = true;

    const options = [...select.options];
    function paint(node, option) {
      node.replaceChildren();
      if (!option) return;
      if (option.dataset.icon && typeof window.CabinetCategoryIcon === 'function') {
        const icon = span('choice-icon');
        icon.setAttribute('aria-hidden', 'true');
        icon.innerHTML = window.CabinetCategoryIcon(option.dataset.icon);
        if (icon.childElementCount) node.append(icon);
      }
      const text = span('choice-text');
      text.textContent = labelOf(option);
      node.append(text);
    }
    const rows = options.map((option, i) => {
      const row = document.createElement('div');
      row.className = 'choice-option';
      row.id = id + '-option-' + i;
      row.dataset.index = i;
      row.setAttribute('role','option');
      if (option.hidden) row.hidden = true;
      if (!usable(option)) row.setAttribute('aria-disabled','true');
      paint(row, option);
      const check = span('choice-check');
      check.setAttribute('aria-hidden', 'true');
      row.append(check);
      list.append(row);
      return row;
    });
    function sync() {
      paint(value, options[select.selectedIndex]);
      rows.forEach((r,i) => r.setAttribute('aria-selected', String(i === select.selectedIndex)));
      const disabled = select.matches(':disabled');
      trigger.setAttribute('aria-disabled', String(disabled));
      trigger.tabIndex = disabled ? -1 : 0;
      if (select.validity.valid) {
        error.hidden = true;
        trigger.removeAttribute('aria-invalid');
        trigger.removeAttribute('aria-errormessage');
      }
    }
    function highlight(i, scroll) {
      if (!options[i] || !usable(options[i])) return;
      if (rows[active]) rows[active].classList.remove('is-active');
      active = i;
      rows[i].classList.add('is-active');
      trigger.setAttribute('aria-activedescendant', rows[i].id);
      if (scroll) {
        const r = rows[i].getBoundingClientRect(), bounds = list.getBoundingClientRect();
        if (r.bottom > bounds.bottom - 5) list.scrollTop += r.bottom - bounds.bottom + 5;
        else if (r.top < bounds.top + 5) list.scrollTop -= bounds.top + 5 - r.top;
      }
    }
    function position() {
      const r = trigger.getBoundingClientRect();
      const h = document.documentElement.clientHeight, w = document.documentElement.clientWidth;
      if (r.bottom <= 0 || r.top >= h || r.right <= 0 || r.left >= w) return close();
      const width = Math.min(w - edge * 2, Math.max(194, r.width));
      list.style.width = width + 'px';
      list.style.left = Math.max(edge, Math.min(r.left, w - width - edge)) + 'px';
      const above = r.top - gap - edge, below = h - r.bottom - gap - edge;
      const up = below < Math.min(list.scrollHeight + 2, 320) && above > below;
      list.style.maxHeight = Math.max(0, Math.min(336, up ? above : below)) + 'px';
      list.style.top = up ? 'auto' : (r.bottom + gap) + 'px';
      list.style.bottom = up ? (h - r.top + gap) + 'px' : 'auto';
    }
    function close() {
      if (openMenu?.trigger !== trigger) return;
      list.hidden = true;
      trigger.setAttribute('aria-expanded', 'false');
      trigger.removeAttribute('aria-activedescendant');
      rows[active]?.classList.remove('is-active');
      query = '';
      openMenu = null;
    }
    function open() {
      if (select.matches(':disabled')) return;
      if (openMenu?.trigger === trigger) return;
      openMenu?.close();
      openMenu = {trigger, list, close, position};
      list.hidden = false;
      trigger.setAttribute('aria-expanded','true');
      position();
      highlight(usable(options[select.selectedIndex]) ? select.selectedIndex : options.findIndex(usable), true);
    }
    function commit(i) {
      if (!options[i] || !usable(options[i])) return;
      const changed = select.selectedIndex !== i;
      select.selectedIndex = i;
      sync();
      if (changed) {
        select.dispatchEvent(new Event('input', {bubbles: true}));
        select.dispatchEvent(new Event('change', {bubbles: true}));
      }
    }
    function seek(key) {
      const now = performance.now();
      query = now - queryTime > 650 ? key : query + key;
      queryTime = now;
      const repeated = Array.from(query).every(c => c === key);
      const term = repeated ? key : query;
      const base = list.hidden ? select.selectedIndex : active;
      const start = repeated ? base + 1 : Math.max(0, base);
      for (let j = 0; j < options.length; j++) {
        const i = (start + j) % options.length;
        if (usable(options[i]) && labelOf(options[i]).toLocaleLowerCase().startsWith(term)) {
          if (list.hidden) commit(i); else highlight(i, true);
          break;
        }
      }
    }
    trigger.addEventListener('click', event => {
      event.preventDefault();
      if (select.matches(':disabled')) return;
      trigger.focus({preventScroll: true});
      if (list.hidden) open(); else close();
    });
    trigger.addEventListener('keydown', event => {
      if (select.matches(':disabled') || event.ctrlKey || event.metaKey || event.isComposing) return;
      const key = event.key;
      if (key === 'Escape' && !list.hidden) { event.preventDefault(); close(); return; }
      if (key === 'Tab') { if (!list.hidden) { commit(active); close(); } return; }
      if (key === 'Enter' || key === ' ') {
        event.preventDefault();
        if (list.hidden) open(); else { commit(active); close(); }
      } else if (['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(key)) {
        event.preventDefault();
        if (list.hidden) { open(); if (key.startsWith('Arrow')) return; }
        const dir = ['ArrowDown','Home'].includes(key) ? 1 : -1;
        let i = key === 'Home' ? 0 : key === 'End' ? options.length - 1 : active + dir;
        while (i >= 0 && i < options.length && !usable(options[i])) i += dir;
        highlight(i, true);
      } else if (key.length === 1 && !event.altKey) {
        event.preventDefault(); seek(key.toLocaleLowerCase());
      }
    });
    list.addEventListener('pointerover', event => {
      const row = event.target.closest('[role=option]');
      if (row && event.pointerType === 'mouse') highlight(+row.dataset.index, false);
    });
    list.addEventListener('mousedown', event => event.preventDefault());
    list.addEventListener('click', event => {
      const row = event.target.closest('[role=option]');
      if (!row || !usable(options[+row.dataset.index])) return;
      commit(+row.dataset.index);
      close();
      trigger.focus({preventScroll: true});
    });
    for (const label of select.labels || []) {
      label.addEventListener('click', event => {
        if (trigger.contains(event.target)) return;
        event.preventDefault();
        if (select.matches(':disabled')) return;
        trigger.focus();
        open();
      });
    }
    select.addEventListener('focus', () => trigger.focus({preventScroll:true}));
    select.addEventListener('change', sync);
    select.addEventListener('invalid', event => {
      event.preventDefault();
      error.hidden = false;
      trigger.setAttribute('aria-invalid','true');
      trigger.setAttribute('aria-errormessage',error.id);
      if (!select.form || Array.from(select.form.elements).find(control => control.willValidate && !control.validity.valid) === select) trigger.focus();
    });
    select.form?.addEventListener('reset', () => setTimeout(sync, 0));
    window.addEventListener('pageshow', sync);
    sync();
    select.after(trigger);
    field.after(error);
    document.body.append(list);
    select.tabIndex = -1;
    select.setAttribute('aria-hidden','true');
    field.classList.add('has-choice-menu');
  }

  document.addEventListener('pointerdown', event => {
    if (openMenu && !openMenu.list.contains(event.target) && !openMenu.trigger.contains(event.target)) openMenu.close();
  });
  document.addEventListener('focusin', event => {
    if (openMenu && !openMenu.trigger.contains(event.target) && !openMenu.list.contains(event.target)) openMenu.close();
  });
  document.addEventListener('scroll', event => {
    if (openMenu && !openMenu.list.contains(event.target)) openMenu.position();
  }, true);
  window.addEventListener('resize', () => openMenu?.position());

  function install() { document.querySelectorAll('.select-field select').forEach(enhance); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install, {once:true});
  else install();
})();
