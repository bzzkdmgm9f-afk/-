<?php
declare(strict_types=1);

function view_price_input(string $id, string $name, string $value, string $placeholder = ''): void
{
    ?>
    <div class="listing-money">
      <span aria-hidden="true">£</span>
      <input type="number" id="<?= e($id) ?>" name="<?= e($name) ?>" value="<?= e($value) ?>"
             inputmode="decimal" autocomplete="off" min="0.01" max="99999999.99" step="0.01"
             placeholder="<?= e($placeholder) ?>" <?= $name !== 'reserve_price' ? 'required' : '' ?>>
    </div>
    <?php
}

function view_listing_calendar(): void
{
    ?>
    <dialog id="listing-calendar" class="listing-calendar" aria-labelledby="calendar-title"
            data-placeholder="<?= e(t('Choose date and time')) ?>" data-invalid="<?= e(t('Choose a valid future date and time.')) ?>">
      <form method="dialog" novalidate>
        <header class="calendar-header">
          <h2 id="calendar-title"></h2>
          <button type="button" data-calendar-close aria-label="<?= e(t('Cancel')) ?>">×</button>
        </header>
        <div class="calendar-month">
          <button type="button" data-month-step="-1" aria-label="<?= e(t('Previous month')) ?>">‹</button>
          <strong data-month-name aria-live="polite"></strong>
          <button type="button" data-month-step="1" aria-label="<?= e(t('Next month')) ?>">›</button>
        </div>
        <div class="calendar-week" aria-hidden="true"></div>
        <div class="calendar-days" role="group" aria-labelledby="calendar-title"></div>
        <div class="calendar-time">
          <span><?= e(t('Local time')) ?></span>
          <label><span class="sr-only"><?= e(t('Hour')) ?></span><input type="number" inputmode="numeric" autocomplete="off" name="hours" min="0" max="23" step="1" required></label>
          <span aria-hidden="true">:</span>
          <label><span class="sr-only"><?= e(t('Minute')) ?></span><input type="number" inputmode="numeric" autocomplete="off" name="minutes" min="0" max="59" step="1" required></label>
        </div>
        <p class="calendar-error" role="alert" hidden></p>
        <div class="calendar-actions">
          <button type="button" class="text-link" data-calendar-clear><?= e(t('Clear date')) ?></button>
          <button type="submit" class="button button-primary"><?= e(t('Set date and time')) ?></button>
        </div>
      </form>
    </dialog>
    <?php
}
