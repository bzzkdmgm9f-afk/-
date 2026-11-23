<?php
declare(strict_types=1);

function view_account_icon(string $id): void
{
    ?><svg class="account-glyph" width="18" height="18" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><use href="assets/account-icons.svg?v=2#<?= e($id) ?>"/></svg><?php
}

function view_account_menu(array $user, string $role, array $activity, ?string $archiveCurrent): void
{
    $unread = (int)($activity['unread'] ?? 0);
    $unreadLabel = $unread ? t('{count} unread updates', ['count' => $unread]) : '';
    $collection = locale_code() === 'zh-CN' ? '我的收藏' : 'My collection';
    $accountLabel = locale_code() === 'zh-CN' ? '账户' : 'Account';
    ?>
    <details class="account-menu" id="account-menu">
      <summary class="account-trigger" id="account-trigger" aria-controls="account-panel">
        <img class="account-portrait" src="assets/account-portrait.svg" width="36" height="36" alt="">
        <span class="sr-only"><?= e($accountLabel . ' · ' . $user['username']) ?></span>
        <i class="unread-dot account-unread" data-unread-dot aria-hidden="true" <?= $unread ? '' : 'hidden' ?>></i>
        <span class="sr-only" data-unread-label><?= e($unreadLabel) ?></span>
      </summary>
      <div class="account-panel" id="account-panel">
        <div data-account-main>
          <div class="account-identity">
            <img class="account-portrait" src="assets/account-portrait.svg" width="36" height="36" alt="">
            <strong><?= e($user['username']) ?></strong>
            <span class="account-role"><?= e(t($role === 'seller' ? 'Seller' : 'Buyer')) ?></span>
          </div>
          <a class="account-row" href="<?= e(url('notifications')) ?>" data-account-feed-trigger aria-controls="account-feed" aria-expanded="false"
              data-update-endpoint="<?= e(url('notification_activity')) ?>" data-unread-template="<?= e(t('{count} unread updates')) ?>"
              data-version="<?= (int)($activity['latest'] ?? 0) ?>-<?= $unread ?>">
            <?php view_account_icon('bell'); ?><?= e(t('Updates')) ?>
            <span class="account-count" data-unread-count aria-hidden="true" <?= $unread ? '' : 'hidden' ?>><?= $unread ?></span>
            <span class="sr-only" data-unread-label><?= e($unreadLabel) ?></span>
          </a>
          <a class="account-row" href="<?= e(url('dashboard', ['section' => 'watches'])) ?>">
            <?php view_account_icon('cab'); ?><?= e($collection) ?>
          </a>
          <a class="account-row" href="<?= e(url('catalog', ['status' => 'completed'])) ?>#auctions"<?= $archiveCurrent === null ? '' : ' aria-current="' . e($archiveCurrent) . '"' ?>>
            <?php view_account_icon('archive'); ?><?= e(t('Sold archive')) ?>
          </a>
          <form class="account-group" method="post" action="index.php">
            <?php view_fields('switch_role', url('dashboard')); ?>
            <input type="hidden" name="role" value="<?= $role === 'seller' ? 'buyer' : 'seller' ?>">
            <button class="account-row" type="submit"><?php view_account_icon('icon-swap'); ?><?= e(t($role === 'seller' ? 'Switch to buyer mode' : 'Switch to seller mode')) ?></button>
          </form>
          <form class="account-group" method="post" action="index.php">
            <?php view_fields('logout', url()); ?>
            <button class="account-row account-signout" type="submit"><?php view_account_icon('exit'); ?><?= e(t('Sign out')) ?></button>
          </form>
        </div>
        <?php view_account_feed(); ?>
      </div>
    </details>
    <?php
}
