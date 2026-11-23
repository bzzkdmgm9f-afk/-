'use strict';

(() => {
  const dialog = document.querySelector('#listing-calendar');
  if (!dialog || !dialog.showModal) return;
  const locale = document.documentElement.lang === 'zh-CN' ? 'zh-CN' : 'en-GB';
  const monthFormat = new Intl.DateTimeFormat(locale, {month: 'long', year: 'numeric'});
  const dateFormat = new Intl.DateTimeFormat(locale, {dateStyle: 'full'});
  const valueFormat = new Intl.DateTimeFormat(locale, {dateStyle: 'medium', timeStyle: 'short', hourCycle:'h23'});
  const weekdayFormat = new Intl.DateTimeFormat(locale, {weekday: 'short'});
  const form = dialog.querySelector('form');
  const grid = dialog.querySelector('.calendar-days');
  const heading = dialog.querySelector('[data-month-name]');
  const previous = dialog.querySelector('[data-month-step="-1"]');
  const clear = dialog.querySelector('[data-calendar-clear]');
  const error = dialog.querySelector('.calendar-error');
  const pad = value => String(value).padStart(2, '0');
  const dayKey = value => [value.getFullYear(), pad(value.getMonth() + 1), pad(value.getDate())].join('-');
  let field;
  let trigger;
  let selected;
  let month;

  const week = dialog.querySelector('.calendar-week');
  for (let index = 0; index < 7; index++) {
    const label = document.createElement('span');
    label.textContent = weekdayFormat.format(new Date(2024, 0, 1 + index));
    week.append(label);
  }

  function renderMonth(focusDay = null) {
    const today = dayKey(new Date());
    heading.textContent = monthFormat.format(month);
    previous.disabled = month <= new Date(new Date().getFullYear(), new Date().getMonth(), 1);
    grid.replaceChildren();
    const lastDay = new Date(month.getFullYear(), month.getMonth() + 1, 0).getDate();
    let focusTarget;
    for (let day = 1; day <= lastDay; day++) {
      const date = new Date(month.getFullYear(), month.getMonth(), day, 12);
      const key = dayKey(date);
      const choice = document.createElement('button');
      choice.type = 'button';
      choice.dataset.day = String(day);
      choice.textContent = String(day);
      choice.setAttribute('aria-label', dateFormat.format(date));
      choice.setAttribute('aria-pressed', String(key === dayKey(selected)));
      choice.disabled = key < today;
      choice.tabIndex = -1;
      if (!choice.disabled && (!focusTarget || key === dayKey(selected))) focusTarget = choice;
      if (key === today) choice.setAttribute('aria-current', 'date');
      if (day === 1) choice.style.gridColumnStart = String((date.getDay() + 6) % 7 + 1);
      grid.append(choice);
    }
    if (focusDay !== null) focusTarget = grid.querySelector('[data-day="' + focusDay + '"]:not(:disabled)') || focusTarget;
    if (focusTarget) {
      focusTarget.tabIndex = 0;
      if (focusDay !== null) focusTarget.focus();
    }
  }

  function updateField(value) {
    field.value = value;
    field.dispatchEvent(new Event('change', {bubbles: true}));
    trigger.textContent = value ? valueFormat.format(new Date(value)) : dialog.dataset.placeholder;
    trigger.removeAttribute('aria-invalid');
    dialog.close();
  }

  function open(fieldToEdit, button, showError = false) {
    field = fieldToEdit;
    trigger = button;
    const now = new Date();
    const nextHour = new Date(now.getTime() + 3600000);
    nextHour.setMinutes(0, 0, 0);
    selected = field.value ? new Date(field.value) : nextHour;
    if (selected < now || Number.isNaN(selected.getTime())) selected = nextHour;
    month = new Date(selected.getFullYear(), selected.getMonth(), 1);
    form.elements.hours.value = pad(selected.getHours());
    form.elements.minutes.value = pad(selected.getMinutes());
    dialog.querySelector('h2').textContent = field.labels[0]?.textContent.trim() || field.dataset.caption;
    clear.hidden = field.required || !field.value;
    error.hidden = !showError;
    error.textContent = showError ? dialog.dataset.invalid : '';
    dialog.showModal();
    renderMonth(selected.getDate());
  }

  document.querySelectorAll('[data-listing-date]').forEach(input => {
    const label = input.labels[0];
    const button = document.createElement('button');
    button.type = 'button';
    button.id = input.id + '-trigger';
    button.className = 'listing-date-trigger';
    button.textContent = input.value ? valueFormat.format(new Date(input.value)) : dialog.dataset.placeholder;
    button.setAttribute('aria-haspopup', 'dialog');
    button.setAttribute('aria-controls', dialog.id);
    input.dataset.caption = label.textContent.trim();
    label.id = input.id + '-label';
    button.setAttribute('aria-labelledby', label.id + ' ' + button.id);
    const help = input.parentElement.querySelector('[data-timezone-help]');
    if (help) {
      help.id = input.id + '-help';
      button.setAttribute('aria-describedby', help.id);
    }
    label.htmlFor = button.id;
    input.after(button);
    input.classList.add('listing-native-date');
    input.tabIndex = -1;
    input.setAttribute('aria-hidden', 'true');
    button.addEventListener('click', () => open(input, button));
    input.addEventListener('invalid', event => {
      event.preventDefault();
      button.setAttribute('aria-invalid', 'true');
      if (input.form.querySelector(':invalid') === input && !dialog.open) open(input, button, true);
    });
  });

  dialog.addEventListener('close', () => trigger?.focus());
  dialog.querySelector('[data-calendar-close]').addEventListener('click', () => dialog.close());
  clear.addEventListener('click', () => updateField(''));
  dialog.querySelectorAll('[data-month-step]').forEach(button => button.addEventListener('click', () => {
    month.setMonth(month.getMonth() + Number(button.dataset.monthStep));
    renderMonth();
  }));
  grid.addEventListener('click', event => {
    const button = event.target.closest('[data-day]');
    if (!button) return;
    selected = new Date(month.getFullYear(), month.getMonth(), Number(button.dataset.day), 12);
    renderMonth(selected.getDate());
  });
  grid.addEventListener('keydown', event => {
    const offsets = {ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7};
    const offset = offsets[event.key];
    if (!offset || !event.target.dataset.day) return;
    event.preventDefault();
    const focusDate = new Date(month.getFullYear(), month.getMonth(), Number(event.target.dataset.day) + offset, 12);
    if (dayKey(focusDate) < dayKey(new Date())) return;
    month = new Date(focusDate.getFullYear(), focusDate.getMonth(), 1);
    renderMonth(focusDate.getDate());
  });

  form.addEventListener('submit', event => {
    event.preventDefault();
    const {hours, minutes} = form.elements;
    const hour = hours.valueAsNumber;
    const minute = minutes.valueAsNumber;
    const wallTime = dayKey(selected) + 'T' + pad(hour) + ':' + pad(minute);
    const date = new Date(wallTime);
    if (!hours.validity.valid || !minutes.validity.valid || !(date > new Date()) ||
        date.getHours() !== hour || date.getMinutes() !== minute) {
      error.textContent = dialog.dataset.invalid;
      error.hidden = false;
      (!hours.validity.valid ? hours : minutes).focus();
      return;
    }
    updateField(wallTime);
  });
})();
