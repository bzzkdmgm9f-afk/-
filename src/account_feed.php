<?php
declare(strict_types=1);

function view_account_feed(): void
{
    $zh = locale_code() === 'zh-CN';
    ?>
    <section id="account-feed" class="account-feed" hidden aria-labelledby="account-feed-title"
             data-endpoint="<?= e(url('notification_activity', ['menu' => '1'])) ?>"
             data-loading="<?= e($zh ? '正在加载消息…' : 'Loading messages…') ?>"
             data-error="<?= e($zh ? '未能加载消息，请打开消息中心重试。' : 'Could not load messages. Open the message centre to try again.') ?>">
        <button type="button" class="account-feed-back" data-account-feed-back>
            <span aria-hidden="true">‹</span> <?= $zh ? '返回账户' : 'Back to account' ?>
        </button>
        <div class="account-feed-heading">
            <h2 id="account-feed-title"><?= $zh ? '消息' : 'Messages' ?></h2>
            <form method="post" action="index.php" data-account-feed-clear hidden>
                <?php view_fields('mark_read', url('notifications')); ?>
                <button type="submit"><?= e(t('Mark all as read')) ?></button>
            </form>
        </div>
        <p class="account-feed-status" role="status"></p>
        <div data-account-feed-items></div>
        <a class="account-feed-all" href="<?= e(url('notifications')) ?>"><?= $zh ? '查看全部消息与设置' : 'All messages and settings' ?> <span aria-hidden="true">→</span></a>
    </section>
    <?php
}

function view_account_feed_items(array $notifications): void
{
    if (!$notifications) {
        echo '<p class="account-feed-empty">' . e(t('You’re all caught up. Auction updates will appear here.')) . '</p>';
        return;
    }
    foreach ($notifications as $notification):
        $unread = empty($notification['is_read']);
        ?>
        <form class="account-feed-item" action="index.php" method="post">
            <?php view_fields('open_notification', url('notifications')); ?>
            <input type="hidden" name="notification_id" value="<?= (int)$notification['notification_id'] ?>">
            <button class="account-feed-message" type="submit">
                <i class="account-feed-indicator<?= $unread ? ' is-unread' : '' ?>" aria-hidden="true"></i>
                <span>
                    <?php if ($unread): ?><span class="sr-only"><?= e(t('Unread')) ?> </span><?php endif; ?>
                    <strong><?= e(t($notification['subject'])) ?></strong>
                    <span class="account-feed-summary"><?= e(notification_text($notification)) ?></span>
                    <?= view_date($notification['created_at'], true) ?>
                </span>
            </button>
        </form>
        <?php
    endforeach;
}

function account_feed_response(PDO $connection, int $userId): void
{
    $activity = notification_activity($connection, $userId);
    ob_start();
    view_account_feed_items(notification_list($connection, $userId, 4));
    $activity['items'] = ob_get_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($activity, JSON_THROW_ON_ERROR);
}
