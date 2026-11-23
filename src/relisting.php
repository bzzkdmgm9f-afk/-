<?php
declare(strict_types=1);

function relisting_source(PDO $db, int $auctionId, int $seller, bool $lock = false): array
{
    $source = service_row($db, 'SELECT item_id FROM auctions WHERE auction_id = ?', [$auctionId]);
    if (!$source) {
        throw new InvalidArgumentException('This auction could not be found.');
    }
    // The item is the common lock for competing relists from different historical auctions.
    $item = service_row($db, 'SELECT i.item_id, i.seller_id, i.title, ' . first_item_image_sql() . ' AS image_path FROM items i WHERE i.item_id = ?' . ($lock ? ' FOR UPDATE' : ''), [$source['item_id']]);
    if (!$item || (int)$item['seller_id'] !== $seller) {
        throw new InvalidArgumentException('Only the seller can relist this collectible.');
    }
    $auctions = service_rows($db, 'SELECT *, (end_time <= UTC_TIMESTAMP()) AS has_ended FROM auctions WHERE item_id = ? ORDER BY auction_id DESC' . ($lock ? ' FOR UPDATE' : ''), [$item['item_id']]);
    $latest = $auctions[0] ?? null;
    foreach ($auctions as $auction) {
        if (in_array($auction['status'], ['active', 'scheduled'], true)) {
            throw new InvalidArgumentException('This collectible already has a live or upcoming auction.');
        }
        if ($auction['status'] === 'completed' || service_row($db, 'SELECT result_id FROM auction_results WHERE auction_id = ?', [$auction['auction_id']])) {
            throw new InvalidArgumentException('A sold collectible cannot be relisted.');
        }
    }
    if (!$latest || (int)$latest['auction_id'] !== $auctionId) {
        throw new InvalidArgumentException('Open the most recent auction to relist this collectible.');
    }
    if (!in_array($latest['status'], ['unsold', 'ended'], true) || !(int)$latest['has_ended']) {
        throw new InvalidArgumentException('Only an auction that ended without a sale can be relisted.');
    }
    // Older records can use "ended" before outcome processing. A winning bid prevents resale.
    $highest = service_row($db, 'SELECT amount FROM bids WHERE auction_id = ? ORDER BY amount DESC, placed_at, bid_id LIMIT 1', [$auctionId]);
    if ($highest && ($latest['reserve_price'] === null || price_to_pence((string)$highest['amount']) >= price_to_pence((string)$latest['reserve_price']))) {
        throw new InvalidArgumentException('A sold collectible cannot be relisted.');
    }
    return $latest + $item;
}

function relist_auction(PDO $db, int $seller, int $auctionId, array $input): int
{
    $starting = price_to_pence((string)($input['starting_price'] ?? ''));
    $increment = price_to_pence((string)($input['minimum_increment'] ?? ''));
    $reserveInput = trim((string)($input['reserve_price'] ?? ''));
    $reserve = $reserveInput === '' ? null : price_to_pence($reserveInput);
    if ($starting <= 0 || $increment <= 0 || ($reserve !== null && $reserve < $starting)) {
        throw new InvalidArgumentException('Starting price and bid increment must be positive. A reserve must be at least the starting price.');
    }
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $startInput = trim((string)($input['start_time'] ?? ''));
    $start = $startInput === '' ? $now : relisting_date($startInput);
    $end = relisting_date((string)($input['end_time'] ?? ''));
    if (($startInput !== '' && $start <= $now) || $end <= $start || $end <= $now) {
        throw new InvalidArgumentException('Choose a future closing time after the opening time.');
    }
    for ($attempt = 0; ; $attempt++) {
        $db->beginTransaction();
        try {
            $source = relisting_source($db, $auctionId, $seller, true);
            if ($end <= new DateTimeImmutable('now', new DateTimeZone('UTC'))) {
                throw new InvalidArgumentException('Choose a future closing time after the opening time.');
            }
            $status = $startInput === '' ? 'active' : 'scheduled';
            service_execute($db, 'INSERT INTO auctions (item_id, starting_price, reserve_price, minimum_increment, start_time, end_time, status) VALUES (?, ?, ?, ?, ?, ?, ?)', [$source['item_id'], pence_to_price($starting), $reserve === null ? null : pence_to_price($reserve), pence_to_price($increment), $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'), $status]);
            $newId = (int)$db->lastInsertId();
            service_execute($db, 'INSERT INTO auction_delivery (auction_id, delivery_method_id) SELECT ?, delivery_method_id FROM auction_delivery WHERE auction_id = ?', [$newId, $auctionId]);
            $db->commit();
            return $newId;
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            // MariaDB can invalidate a locking scan after another relist commits.
            // Re-read the entire transaction so it reports the new auction normally.
            if ($error instanceof PDOException && in_array((int)($error->errorInfo[1] ?? 0), [1020, 1213], true) && $attempt < 2) {
                continue;
            }
            throw $error;
        }
    }
}

function relisting_date(string $input): DateTimeImmutable
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $input, new DateTimeZone('UTC'));
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || ($errors && ($errors['warning_count'] || $errors['error_count']))) {
        throw new InvalidArgumentException('Choose valid opening and closing times.');
    }
    return $date;
}

function view_relist(array $data, string $role): void
{
    $source = $data['source'];
    $returnTo = url('relist', ['id' => (int)$source['auction_id']]);
    ?>
<div class="page-heading"><p class="eyebrow"><?= e(t('Your listings')) ?></p><h1><?= e(t('Relist this collectible')) ?></h1><p><?= e(item_text($source, 'title')) ?></p></div>
<section class="content-panel">
  <p><?= e(t('The collectible and photographs stay the same. This auction starts with a new bid history.')) ?></p>
  <?php if ($role !== 'seller'): ?>
  <form method="post" action="index.php"><?php view_fields('switch_role', $returnTo); ?><input type="hidden" name="role" value="seller"><button class="button button-primary" type="submit"><?= e(t('Switch to seller')) ?></button></form>
  <?php endif; ?>
  <form method="post" action="index.php">
    <?php view_fields('relist_auction', $returnTo); ?>
    <input type="hidden" name="auction_id" value="<?= (int)$source['auction_id'] ?>">
    <?php foreach (['starting_price' => 'Starting price (£)', 'reserve_price' => 'Reserve price (£)', 'minimum_increment' => 'Minimum bid increase (£)'] as $name => $label): ?>
    <div class="form-field"><label for="relist-<?= e($name) ?>"><?= e(t($label)) ?></label><?php view_price_input('relist-' . $name, $name, (string)view_old($name, $source[$name])); ?></div>
    <?php endforeach; ?>
    <p class="field-help"><?= e(t('Leave blank for no reserve.')) ?></p>
    <div class="form-field"><label for="start-time"><?= e(t('Opens at (optional)')) ?></label><input id="start-time" name="start_time" type="datetime-local" data-listing-date autocomplete="off" value="<?= e(view_old('start_time')) ?>"><input type="hidden" name="start_timezone_offset" value="0" data-start-timezone-offset><p class="field-help"><?= e(t('Leave blank to open immediately.')) ?></p><p class="field-help" data-timezone-help><?= e(t('Enter the opening date and time in UTC.')) ?></p></div>
    <div class="form-field"><label for="end-time"><?= e(t('Ends at')) ?></label><input id="end-time" name="end_time" type="datetime-local" data-listing-date autocomplete="off" required value="<?= e(view_old('end_time')) ?>"><input type="hidden" name="timezone_offset" value="0" data-timezone-offset><p class="field-help" data-timezone-help><?= e(t('Enter the closing date and time in UTC.')) ?></p></div>
    <button class="button button-primary" type="submit" <?= $role !== 'seller' ? 'disabled' : '' ?>><?= e(t('Publish new auction')) ?></button>
    <a class="button button-outline" href="<?= e(url('detail', ['id' => (int)$source['auction_id']])) ?>"><?= e(t('Cancel')) ?></a>
  </form>
</section>
<?php
}
