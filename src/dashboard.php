<?php
declare(strict_types=1);

function dashboard_text(string $english): string
{
    $zh = [
        'My collection' => '我的收藏', 'My account' => '我的账户', 'My interests' => '我的兴趣',
        'Edit interests' => '编辑兴趣', 'No interests selected' => '尚未选择', 'Cancel' => '取消',
        'Which categories interest you?' => '你对哪些类别感兴趣？', 'Saved lots' => '收藏的拍品',
        'Auction activity' => '交易记录', 'My bids' => '我的出价', 'Lots won' => '已拍下',
        'My listings' => '我发布的', 'Lots sold' => '已售出',
        'Use the heart on an auction to save it here.' => '逛拍场时，点爱心就能把拍品收藏到这里。',
        'Withdraw listing' => '撤回拍卖', 'Withdraw this listing? It will remain in your history.' => '确定撤回这场拍卖？记录仍会保留。',
        'Withdrawn' => '已撤回', 'Your highest bid' => '你的最高出价', 'You are leading' => '目前领先', 'You have been outbid' => '已被超过',
        'You won' => '竞拍成功', 'Auction ended' => '拍卖已结束', 'Bid again' => '再次出价', 'Place a bid' => '参与出价',
        'Remove interest' => '移除兴趣', 'Confirm withdrawal' => '确认撤回', 'Save interests' => '保存兴趣', 'Follow categories to find your next collectible.' => '关注分类，发现下一件藏品。',
        'A listing with bids cannot be withdrawn.' => '已有出价的拍卖不能撤回。',
        'Only your live or upcoming listings without bids can be withdrawn.' => '只能撤回你自己尚无出价的进行中或即将开始的拍卖。',
        'Your listing has been withdrawn. Its history has been preserved.' => '拍卖已撤回，历史记录已保留。',
        'Decrease bid' => '减少出价', 'Increase bid' => '增加出价', 'View auction' => '查看拍卖',
    ];
    return locale_code() === 'zh-CN' && isset($zh[$english]) ? $zh[$english] : t($english);
}

function dashboard_section(mixed $requested, string $role): string
{
    $aliases = ['watching' => 'watches', 'bids' => 'bidding', 'listings' => 'published', 'interests' => 'preferences'];
    $section = is_string($requested) ? ($aliases[$requested] ?? $requested) : '';
    return in_array($section, ['watches', 'bidding', 'won', 'published', 'sold', 'preferences'], true)
        ? $section : ($role === 'seller' ? 'published' : 'watches');
}

function dashboard_collection(PDO $db, int $user): array
{
    $watches = service_rows($db, 'SELECT w.auction_id, 1 AS is_watched, i.title, i.description, c.category_name, '
        . first_item_image_sql() . ' AS image_path
        FROM watches w JOIN auctions a ON a.auction_id = w.auction_id
        JOIN items i ON i.item_id = a.item_id JOIN categories c ON c.category_id = i.category_id
        WHERE w.user_id = ? ORDER BY w.watched_at DESC, w.auction_id DESC', [$user]);
    $categories = service_rows($db, 'SELECT c.category_id, c.category_name, p.user_id AS selected
        FROM categories c LEFT JOIN user_preferences p ON p.category_id = c.category_id AND p.user_id = ?
        ORDER BY c.category_name', [$user]);
    return ['watches' => $watches, 'categories' => $categories];
}

function dashboard_activity_labels(): array
{
    return ['bidding' => 'My bids', 'won' => 'Lots won', 'published' => 'My listings', 'sold' => 'Lots sold'];
}

function view_collection(array $dashboard, array $user, bool $editing): void
{
    $home = url('dashboard', ['section' => 'watches']);
    $editor = url('dashboard', ['section' => 'preferences']);
    $interests = array_filter($dashboard['categories'], static fn(array $row): bool => $row['selected'] !== null);
    $count = count($dashboard['watches']);
    $countLabel = locale_code() === 'zh-CN' ? '{n} 件' : '{n}';
    ?>
<section class="my-collection" aria-labelledby="collection-title">
  <p class="collection-crumb"><?= e(dashboard_text('My account')) ?> <span aria-hidden="true">/</span> <?= e(dashboard_text('My collection')) ?></p>
  <h1 id="collection-title"><?= e(dashboard_text('My collection')) ?></h1>
  <div class="collection-interests">
    <strong><?= e(dashboard_text('My interests')) ?></strong>
    <?php foreach ($interests as $category): ?><a class="interest-chip" href="<?= e(url('catalog', ['category' => $category['category_id']])) ?>#auctions"><?= e(ui_text($category['category_name'])) ?></a><?php endforeach; ?>
    <?php if (!$interests): ?><span class="muted"><?= e(dashboard_text('No interests selected')) ?></span><?php endif; ?>
    <a class="collection-edit" id="edit-interests" href="<?= e($editor) ?>#interest-editor" aria-controls="interest-editor" aria-expanded="<?= $editing ? 'true' : 'false' ?>"><?= e(dashboard_text('Edit interests')) ?></a>
  </div>
  <form id="interest-editor" class="collection-editor" action="index.php" method="post" <?= $editing ? '' : 'hidden' ?>>
    <?php view_fields('dashboard_preferences', $editor); ?>
    <fieldset>
      <legend><?= e(dashboard_text('Which categories interest you?')) ?></legend>
      <div class="collection-choices">
        <?php foreach ($dashboard['categories'] as $category): ?>
        <label><input type="checkbox" name="category_ids[]" value="<?= (int)$category['category_id'] ?>" <?= $category['selected'] !== null ? 'checked' : '' ?>><?php view_category_name($category['category_name']); ?></label>
        <?php endforeach; ?>
      </div>
    </fieldset>
    <div class="collection-editor-actions">
      <button class="button button-small button-primary" type="submit"><?= e(dashboard_text('Save interests')) ?></button>
      <a class="button button-small button-outline" href="<?= e($home) ?>" data-cancel-interests><?= e(dashboard_text('Cancel')) ?></a>
    </div>
  </form>
  <div class="collection-heading"><h2><?= e(dashboard_text('Saved lots')) ?></h2><span data-collection-count data-format="<?= e($countLabel) ?>" aria-live="polite"><?= e(str_replace('{n}', (string)$count, $countLabel)) ?></span></div>
  <div class="collection-grid" data-collection-grid>
    <?php foreach ($dashboard['watches'] as $auction): $id = (int)$auction['auction_id']; ?>
    <article class="collection-lot" data-lot-id="<?= $id ?>">
      <a class="collection-lot-link" href="<?= e(url('detail', ['id' => $id])) ?>"><div class="collection-image"><?php view_image($auction); ?></div>
        <div class="collection-info"><small><?= e(ui_text($auction['category_name'])) ?></small><h3><?= e(item_text($auction, 'title')) ?></h3></div>
      </a>
      <?php view_watch_control($auction, $user, $home); ?>
    </article>
    <?php endforeach; ?>
  </div>
  <div class="empty-inline" data-collection-empty <?= $count ? 'hidden' : '' ?>><p><?= e(dashboard_text('Use the heart on an auction to save it here.')) ?></p><a href="<?= e(url('catalog')) ?>#auctions"><?= e(t('Explore auctions')) ?></a></div>
  <nav class="collection-activity" aria-label="<?= e(dashboard_text('Auction activity')) ?>">
    <strong><?= e(dashboard_text('Auction activity')) ?></strong>
    <?php foreach (dashboard_activity_labels() as $key => $label): ?><a href="<?= e(url('dashboard', ['section' => $key])) ?>"><?= e(dashboard_text($label)) ?></a><?php endforeach; ?>
  </nav>
</section>
<?php
}

function dashboard_data(PDO $db, int $user): array
{
    $dashboard = user_dashboard($db, $user);
    $ids = [];
    foreach (['watches', 'bidding', 'won', 'published', 'sold'] as $key) {
        foreach ($dashboard[$key] as $auction) $ids[(int)$auction['auction_id']] = true;
    }
    $activity = [];
    if ($ids) {
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $rows = service_rows($db, "SELECT a.auction_id,
            (SELECT MAX(m.amount) FROM bids m WHERE m.auction_id = a.auction_id AND m.bidder_id = ?) AS my_bid,
            (SELECT leader.bidder_id FROM bids leader WHERE leader.auction_id = a.auction_id ORDER BY leader.amount DESC, leader.placed_at, leader.bid_id LIMIT 1) AS leader_id,
            EXISTS(SELECT 1 FROM auction_results result WHERE result.auction_id = a.auction_id) AS has_result,
            (a.end_time > UTC_TIMESTAMP()) AS before_end
            FROM auctions a WHERE a.auction_id IN ($marks)", array_merge([$user], array_keys($ids)));
        foreach ($rows as $row) $activity[(int)$row['auction_id']] = $row;
    }
    $orders = [];
    foreach ($dashboard['transactions'] as $order) $orders[(int)$order['auction_id']] = $order;
    foreach (['watches', 'bidding', 'won', 'published', 'sold'] as $key) {
        foreach ($dashboard[$key] as &$auction) {
            $auction += $activity[(int)$auction['auction_id']] ?? [];
            $auction['order'] = $orders[(int)$auction['auction_id']] ?? null;
            $auction['can_withdraw'] = (int)$auction['seller_id'] === $user
                && in_array($auction['status'], ['active', 'scheduled'], true)
                && !empty($auction['before_end']) && (int)$auction['bid_count'] === 0 && empty($auction['has_result']);
        }
        unset($auction);
    }
    return $dashboard;
}

function dashboard_unwatch(PDO $db, int $user, int $auctionId): void
{
    // Explicit removal is idempotent, including a repeated form submission.
    service_execute($db, 'DELETE FROM watches WHERE user_id = ? AND auction_id = ?', [$user, $auctionId]);
}

function dashboard_remove_interest(PDO $db, int $user, int $categoryId): void
{
    service_execute($db, 'DELETE FROM user_preferences WHERE user_id = ? AND category_id = ?', [$user, $categoryId]);
}

function dashboard_withdraw_listing(PDO $db, int $user, int $auctionId): void
{
    $db->beginTransaction();
    try {
        // Bidding and deadline processing acquire this same auction lock first.
        $auction = service_row($db, 'SELECT a.*, i.seller_id, (a.end_time > UTC_TIMESTAMP()) AS before_end FROM auctions a JOIN items i ON i.item_id = a.item_id WHERE a.auction_id = ? FOR UPDATE', [$auctionId]);
        if (!$auction || (int)$auction['seller_id'] !== $user || !in_array($auction['status'], ['active', 'scheduled'], true) || !(int)$auction['before_end']) {
            throw new InvalidArgumentException(dashboard_text('Only your live or upcoming listings without bids can be withdrawn.'));
        }
        if (service_row($db, 'SELECT bid_id FROM bids WHERE auction_id = ? LIMIT 1', [$auctionId])
            || service_row($db, 'SELECT result_id FROM auction_results WHERE auction_id = ?', [$auctionId])) {
            throw new InvalidArgumentException(dashboard_text('A listing with bids cannot be withdrawn.'));
        }
        service_execute($db, "UPDATE auctions SET status = 'cancelled' WHERE auction_id = ?", [$auctionId]);
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}

function dashboard_activity(PDO $db, int $user, array $ids): array
{
    if (!$ids) return [];
    $marks = implode(',', array_fill(0, count($ids), '?'));
    // Only the signed-in viewer's relationship is exposed, never other bidder identities.
    return service_rows($db, "SELECT a.auction_id, a.status,
        (SELECT COUNT(*) FROM bids count_bid WHERE count_bid.auction_id = a.auction_id) AS bid_count,
        COALESCE((SELECT leader.bidder_id = ? FROM bids leader WHERE leader.auction_id = a.auction_id ORDER BY leader.amount DESC, leader.placed_at, leader.bid_id LIMIT 1), 0) AS viewer_leading
        FROM auctions a JOIN items i ON i.item_id = a.item_id WHERE a.auction_id IN ($marks)
        AND (i.seller_id = ? OR EXISTS (SELECT 1 FROM watches w WHERE w.auction_id = a.auction_id AND w.user_id = ?)
        OR EXISTS (SELECT 1 FROM bids own_bid WHERE own_bid.auction_id = a.auction_id AND own_bid.bidder_id = ?))",
        array_merge([$user], $ids, [$user, $user, $user]));
}

function view_dashboard_content(array $data, ?array $user): void
{
    $dashboard = $data['dashboard'];
    $role = active_role();
    $selected = dashboard_section($data['section'] ?? null, $role);
    if (in_array($selected, ['watches', 'preferences'], true)) {
        view_collection($dashboard, $user, $selected === 'preferences');
        return;
    }
    $labels = dashboard_activity_labels();
    $returnTo = url('dashboard', ['section' => $selected]);
    ?>
<div class="page-heading heading-with-action">
  <div><a class="collection-return" href="<?= e(url('dashboard', ['section' => 'watches'])) ?>"><?= e(dashboard_text('My collection')) ?></a><h1><?= e(dashboard_text('Auction activity')) ?></h1></div>
  <?php if ($role === 'seller'): ?><a class="button button-primary" href="<?= e(url('sell')) ?>"><?= e(t('List a collectible')) ?></a><?php endif; ?>
</div>
<nav class="section-tabs cabinet-tabs" aria-label="<?= e(dashboard_text('Auction activity')) ?>">
  <?php foreach ($labels as $key => $label): ?><a href="<?= e(url('dashboard', ['section' => $key])) ?>" <?= $selected === $key ? 'class="is-current" aria-current="page"' : '' ?>><?= e(dashboard_text($label)) ?><span><?= count($dashboard[$key] ?? []) ?></span></a><?php endforeach; ?>
</nav>
<section class="auction-section dashboard-section" aria-labelledby="cabinet-section-title">
  <h2 class="sr-only" id="cabinet-section-title"><?= e(dashboard_text($labels[$selected])) ?></h2>
  <?php if (!empty($dashboard[$selected])): ?>
    <div class="auction-grid dashboard-grid">
      <?php foreach ($dashboard[$selected] as $auction): $id = (int)$auction['auction_id']; $detail = url('detail', ['id' => $id]); ?>
      <div class="dashboard-lot" data-dashboard-lot="<?= $id ?>" data-bid-count="<?= (int)$auction['bid_count'] ?>" data-auction-state="<?= e($auction['status']) ?>" data-viewer-leading="<?= $auction['my_bid'] !== null && (int)$auction['leader_id'] === (int)$user['user_id'] ? '1' : '0' ?>">
        <?php view_auction_card($auction, $user, $returnTo); ?>
        <div class="dashboard-lot-actions">
          <?php if ($auction['my_bid'] !== null): ?>
          <p class="dashboard-own-bid"><?= e(dashboard_text('Your highest bid')) ?> <strong><?= e(money($auction['my_bid'])) ?></strong></p>
          <?php if ($auction['status'] === 'active'): ?><p class="bid-position <?= (int)$auction['leader_id'] === (int)$user['user_id'] ? 'is-leading' : 'is-outbid' ?>"><?= e(dashboard_text((int)$auction['leader_id'] === (int)$user['user_id'] ? 'You are leading' : 'You have been outbid')) ?></p>
          <?php elseif ($selected === 'won'): ?><p class="bid-position is-leading"><?= e(dashboard_text('You won')) ?></p><?php endif; ?>
          <?php endif; ?>
          <div class="dashboard-action-row">
            <a class="button button-small button-outline" href="<?= e($detail) ?>"><?= e(dashboard_text('View auction')) ?></a>
            <?php if ($role === 'buyer' && $auction['status'] === 'active' && (int)$auction['seller_id'] !== (int)$user['user_id']): ?><a class="button button-small button-primary" href="<?= e($detail) ?>#bid-amount"><?= e(dashboard_text($auction['my_bid'] !== null ? 'Bid again' : 'Place a bid')) ?></a><?php endif; ?>
            <?php if (!empty($auction['order'])): ?><a class="button button-small button-primary" href="<?= e(url('transaction', ['id' => (int)$auction['order']['transaction_id']])) ?>"><?= e(t('View order')) ?></a><?php endif; ?>
          </div>
          <?php if ($role === 'seller' && !empty($auction['can_withdraw'])): ?><form method="post" action="index.php" class="dashboard-withdraw-form"><?php view_fields('withdraw_listing', $returnTo); ?><input type="hidden" name="auction_id" value="<?= $id ?>"><details><summary class="dashboard-withdraw"><?= e(dashboard_text('Withdraw listing')) ?></summary><p><?= e(dashboard_text('Withdraw this listing? It will remain in your history.')) ?></p><button type="submit" class="button button-small button-outline"><?= e(dashboard_text('Confirm withdrawal')) ?></button></details></form><?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="empty-inline"><p><?= e(t(['watches'=>'Save an auction to keep it close.','bidding'=>'Your bidding activity will appear here.','won'=>'Collectibles you win will appear here.','published'=>'Ready to pass something on? Your listings will appear here.','sold'=>'Your completed sales will appear here.'][$selected])) ?></p><a href="<?= e(url($selected === 'published' && $role === 'seller' ? 'sell' : 'catalog')) ?>"><?= e(t($selected === 'published' && $role === 'seller' ? 'Create a listing' : 'Explore auctions')) ?></a></div>
  <?php endif; ?>
</section>
<?php
}
