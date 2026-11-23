<?php
declare(strict_types=1);

require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/order_handoff.php';

function service_rows(PDO $db, string $sql, array $params = []): array
{
    $statement = $db->prepare($sql);
    $statement->execute($params);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function service_row(PDO $db, string $sql, array $params = []): ?array
{
    $rows = service_rows($db, $sql, $params);
    return $rows[0] ?? null;
}

function service_execute(PDO $db, string $sql, array $params = []): void
{
    $statement = $db->prepare($sql);
    $statement->execute($params);
}

function price_to_pence(string $price): int
{
    $price = trim($price);
    if (!preg_match('/^(0|[1-9][0-9]{0,7})(?:\.([0-9]{1,2}))?$/D', $price, $matches)) {
        throw new InvalidArgumentException('Enter an amount with no more than two decimal places.');
    }
    return (int)$matches[1] * 100 + (int)str_pad($matches[2] ?? '', 2, '0');
}

function pence_to_price(int $pence): string
{
    if ($pence < 0 || $pence > 9999999999) {
        throw new InvalidArgumentException('The amount is outside the supported range.');
    }
    return intdiv($pence, 100) . '.' . str_pad((string)($pence % 100), 2, '0', STR_PAD_LEFT);
}

function service_text(string $text, int $maximum, string $label): string
{
    $text = trim($text);
    if ($text === '' || mb_strlen($text) > $maximum) {
        throw new InvalidArgumentException(t('{label} must contain between 1 and {maximum} characters.', ['label' => t($label), 'maximum' => $maximum]));
    }
    return $text;
}

function first_item_image_sql(): string
{
    return '(SELECT image_path FROM item_images im WHERE im.item_id = i.item_id
            ORDER BY im.display_order, im.image_id LIMIT 1)';
}

function auction_select_sql(): string
{
    return "SELECT a.*, i.seller_id, i.category_id, i.title, i.description, i.item_condition,
        i.updated_at, u.username AS seller_name, c.category_name,
        COALESCE(bs.current_price, a.starting_price) AS current_price,
        COALESCE(bs.bid_count, 0) AS bid_count, COALESCE(bs.bidder_count, 0) AS bidder_count,
        COALESCE(ws.watch_count, 0) AS watch_count,
         " . first_item_image_sql() . " AS image_path,
        (SELECT ROUND(AVG(r.rating), 1) FROM reviews r WHERE r.reviewee_id = i.seller_id) AS seller_rating,
        EXISTS(SELECT 1 FROM watches vw WHERE vw.auction_id = a.auction_id AND vw.user_id = ?) AS is_watched
        FROM auctions a
        JOIN items i ON i.item_id = a.item_id
        JOIN users u ON u.user_id = i.seller_id
        JOIN categories c ON c.category_id = i.category_id
        LEFT JOIN (SELECT auction_id, MAX(amount) AS current_price, COUNT(*) AS bid_count,
            COUNT(DISTINCT bidder_id) AS bidder_count FROM bids GROUP BY auction_id) bs
            ON bs.auction_id = a.auction_id
        LEFT JOIN (SELECT auction_id, COUNT(*) AS watch_count FROM watches GROUP BY auction_id) ws
            ON ws.auction_id = a.auction_id";
}

function catalog_activity(PDO $db, array $ids): array
{
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    return service_rows($db, "SELECT a.auction_id, a.status,
        COALESCE(MAX(b.amount), a.starting_price) AS current_price,
        COUNT(b.bid_id) AS bid_count, COUNT(DISTINCT b.bidder_id) AS bidder_count
        FROM auctions a LEFT JOIN bids b ON b.auction_id = a.auction_id
        WHERE a.auction_id IN ($placeholders)
        GROUP BY a.auction_id, a.status, a.starting_price ORDER BY a.auction_id", $ids);
}

function service_visible_auction(array $auction, ?int $viewer): array
{
    $auction['has_reserve'] = $auction['reserve_price'] !== null;
    $auction['reserve_reached'] = !$auction['has_reserve']
        || ((int)$auction['bid_count'] > 0 && price_to_pence((string)$auction['current_price']) >= price_to_pence((string)$auction['reserve_price']));
    if ($viewer !== (int)$auction['seller_id']) {
        $auction['reserve_price'] = null;
    }
    return $auction;
}

function catalog_status(string $status): string
{
    return in_array($status, ['active', 'scheduled', 'completed', 'unsold', 'all'], true) ? $status : 'active';
}

function catalog_status_condition(string $status): string
{
    $conditions = [
        'active' => "a.status = 'active' AND a.start_time <= UTC_TIMESTAMP() AND a.end_time > UTC_TIMESTAMP()",
        'scheduled' => "a.status = 'scheduled' AND a.start_time > UTC_TIMESTAMP() AND a.end_time > a.start_time",
        'completed' => "a.status = 'completed' AND a.end_time <= UTC_TIMESTAMP() AND EXISTS (SELECT 1 FROM auction_results cr WHERE cr.auction_id = a.auction_id)",
        'unsold' => "a.status = 'unsold' AND a.end_time <= UTC_TIMESTAMP() AND NOT EXISTS (SELECT 1 FROM auction_results ur WHERE ur.auction_id = a.auction_id)",
    ];
    if ($status === 'all') {
        return '(' . implode(') OR (', $conditions) . ')';
    }
    return $conditions[catalog_status($status)];
}

function auction_status_counts(PDO $db): array
{
    $columns = [];
    foreach (['active', 'scheduled', 'completed', 'unsold'] as $status) {
        $columns[] = 'COALESCE(SUM(CASE WHEN ' . catalog_status_condition($status) . ' THEN 1 ELSE 0 END), 0) AS ' . $status;
    }
    $row = service_row($db, 'SELECT ' . implode(', ', $columns) . ' FROM auctions a');
    $counts = array_map('intval', $row ?? ['active' => 0, 'scheduled' => 0, 'completed' => 0, 'unsold' => 0]);
    $counts['all'] = array_sum($counts);
    return $counts;
}

function auction_list(PDO $db, array $filters = [], ?int $viewer = null): array
{
    $where = ['(' . catalog_status_condition(catalog_status((string)($filters['status'] ?? 'active'))) . ')'];
    $params = [$viewer ?? 0];
    $query = trim((string)($filters['q'] ?? ''));
    if ($query !== '') {
        if (mb_strlen($query) > 120) {
            throw new InvalidArgumentException('Keep the search under 120 characters.');
        }
        $matches = ['i.title LIKE ?', 'i.description LIKE ?'];
        array_push($params, '%' . $query . '%', '%' . $query . '%');
        if (locale_code() === 'zh-CN') {
            $aliases = array_keys(array_filter(sample_labels(), fn(string $label): bool => mb_stripos($label, $query) !== false));
            if ($aliases) {
                $places = implode(',', array_fill(0, count($aliases), '?'));
                $matches[] = "c.category_name IN ($places)";
                array_push($params, ...$aliases);
                $matches[] = '(' . first_item_image_sql() . " LIKE 'assets/images/%' AND (i.title IN ($places) OR i.description IN ($places)))";
                array_push($params, ...$aliases, ...$aliases);
            }
        }
        foreach (profile_sample_search_matches($query) as $storedTitle => $sample) {
            $matches[] = '(i.title = ? AND i.description REGEXP ? AND ' . first_item_image_sql() . ' = ?)';
            $params[] = $storedTitle;
            $params[] = '^Cabinet profile sample v1:' . $sample['key'] . '; account [1-9][0-9]*[.] Illustrative local history with sample photography; no real sale or payment[.]$';
            $params[] = 'assets/images/' . $sample['image'] . '.jpg';
        }
        $where[] = '(' . implode(' OR ', $matches) . ')';
    }
    $category = (int)($filters['category'] ?? $filters['category_id'] ?? 0);
    if ($category > 0) {
        $where[] = 'i.category_id = ?';
        $params[] = $category;
    }
    $sorts = [
        'ending' => 'a.end_time ASC, a.auction_id DESC',
        'newest' => 'a.created_at DESC, a.auction_id DESC',
        'price_low' => 'current_price ASC, a.end_time ASC',
        'price_high' => 'current_price DESC, a.end_time ASC',
        'popular' => 'bid_count DESC, watch_count DESC, a.end_time ASC',
    ];
    $order = $sorts[(string)($filters['sort'] ?? 'ending')] ?? $sorts['ending'];
    $rows = service_rows($db, auction_select_sql() . ' WHERE ' . implode(' AND ', $where)
        . ' ORDER BY ' . $order . ' LIMIT 120', $params);
    return array_map(fn(array $row): array => service_visible_auction($row, $viewer), $rows);
}

function auction_detail(PDO $db, int $auctionId, ?int $viewer = null): ?array
{
    $auction = service_row($db, auction_select_sql() . ' WHERE a.auction_id = ?', [$viewer ?? 0, $auctionId]);
    if (!$auction) {
        return null;
    }
    $auction['images'] = service_rows($db, 'SELECT image_path, display_order FROM item_images WHERE item_id = ? ORDER BY display_order, image_id', [$auction['item_id']]);
    $auction['bids'] = service_rows($db, 'SELECT b.amount, b.placed_at, u.username FROM bids b JOIN users u ON u.user_id = b.bidder_id WHERE b.auction_id = ? ORDER BY b.amount DESC, b.placed_at ASC, b.bid_id ASC LIMIT 100', [$auctionId]);
    $auction['comments'] = service_rows($db, 'SELECT c.comment_id, c.content, c.created_at, u.username FROM comments c JOIN users u ON u.user_id = c.user_id WHERE c.auction_id = ? ORDER BY c.created_at DESC, c.comment_id DESC LIMIT 100', [$auctionId]);
    $auction['delivery_methods'] = service_rows($db, 'SELECT d.delivery_method_id, d.method_name FROM delivery_methods d JOIN auction_delivery ad ON ad.delivery_method_id = d.delivery_method_id WHERE ad.auction_id = ? ORDER BY d.delivery_method_id', [$auctionId]);
    $auction['result'] = service_row($db, 'SELECT r.result_id, r.winning_bid_id, b.bidder_id AS winner_id, u.username AS winner_name, b.amount, r.decided_at FROM auction_results r JOIN bids b ON b.bid_id = r.winning_bid_id JOIN users u ON u.user_id = b.bidder_id WHERE r.auction_id = ?', [$auctionId]);
    $auction['last_updated'] = max(
        $auction['updated_at'], $auction['created_at'], $auction['bids'][0]['placed_at'] ?? '',
        $auction['comments'][0]['created_at'] ?? '', $auction['result']['decided_at'] ?? '',
        in_array($auction['status'], ['completed', 'unsold'], true) ? $auction['end_time'] : ''
    );
    $auction['transaction'] = null;
    if ($viewer && $auction['result'] && in_array($viewer, [(int)$auction['seller_id'], (int)$auction['result']['winner_id']], true)) {
        $auction['transaction'] = service_row($db, 'SELECT transaction_id FROM transactions WHERE result_id = ?', [$auction['result']['result_id']]);
    }
    $auction['recommendations'] = [];
    return service_visible_auction($auction, $viewer);
}

function category_price_summary(PDO $db, int $categoryId): array
{
    return service_row($db, 'SELECT c.category_id, c.category_name,
        COUNT(r.result_id) AS sold_count, MIN(b.amount) AS min_price,
        MAX(b.amount) AS max_price, CAST(AVG(b.amount) AS DECIMAL(10,2)) AS average_price
        FROM categories c LEFT JOIN items i ON i.category_id = c.category_id
        LEFT JOIN auctions a ON a.item_id = i.item_id
        LEFT JOIN auction_results r ON r.auction_id = a.auction_id
        LEFT JOIN bids b ON b.bid_id = r.winning_bid_id
        WHERE c.category_id = ? GROUP BY c.category_id, c.category_name', [$categoryId])
        ?? ['category_id' => $categoryId, 'sold_count' => 0, 'min_price' => null, 'max_price' => null, 'average_price' => null];
}

function service_store_images(array $files): array
{
    if (!$files || !isset($files['name'])) {
        return [];
    }
    $names = is_array($files['name']) ? $files['name'] : [$files['name']];
    if (count($names) > 5) {
        throw new InvalidArgumentException('Upload no more than five images.');
    }
    $directory = dirname(__DIR__) . '/uploads';
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('The image directory is not writable.');
    }
    $stored = [];
    try {
        foreach ($names as $index => $name) {
            $error = is_array($files['error']) ? $files['error'][$index] : $files['error'];
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($error !== UPLOAD_ERR_OK) {
                throw new InvalidArgumentException('The image upload failed. Please try again.');
            }
            $temporary = is_array($files['tmp_name']) ? $files['tmp_name'][$index] : $files['tmp_name'];
            $size = is_array($files['size']) ? $files['size'][$index] : $files['size'];
            if (!is_uploaded_file($temporary) || $size <= 0 || $size > 5 * 1024 * 1024) {
                throw new InvalidArgumentException('Each image must be smaller than 5 MB.');
            }
            $information = @getimagesize($temporary);
            $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($temporary);
            if (!$information || !isset($extensions[$mime]) || $information[0] > 12000 || $information[1] > 12000) {
                throw new InvalidArgumentException('Use a JPEG, PNG or WebP image up to 12000 pixels per side.');
            }
            $relative = 'uploads/' . bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
            if (!move_uploaded_file($temporary, dirname(__DIR__) . '/' . $relative)) {
                throw new RuntimeException('The image could not be saved.');
            }
            $stored[] = $relative;
        }
    } catch (Throwable $error) {
        foreach ($stored as $path) {
            @unlink(dirname(__DIR__) . '/' . $path);
        }
        throw $error;
    }
    return $stored;
}

function create_auction(PDO $db, int $seller, array $data, array $files = []): int
{
    $title = service_text((string)($data['title'] ?? ''), 200, 'Title');
    $description = service_text((string)($data['description'] ?? ''), 10000, 'Description');
    $condition = service_text((string)($data['item_condition'] ?? ''), 100, 'Condition');
    $category = (int)($data['category_id'] ?? 0);
    if (!service_row($db, 'SELECT category_id FROM categories WHERE category_id = ?', [$category])) {
        throw new InvalidArgumentException('Choose a valid category.');
    }
    $starting = price_to_pence((string)($data['starting_price'] ?? ''));
    $increment = price_to_pence((string)($data['minimum_increment'] ?? ''));
    $reserveInput = trim((string)($data['reserve_price'] ?? ''));
    $reserve = $reserveInput === '' ? null : price_to_pence($reserveInput);
    if ($starting <= 0 || $increment <= 0 || ($reserve !== null && $reserve < $starting)) {
        throw new InvalidArgumentException('Starting price and bid increment must be positive. A reserve must be at least the starting price.');
    }
    $dateInput = (string)($data['end_time'] ?? '');
    $end = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $dateInput, new DateTimeZone('UTC'));
    if (!$end) {
        $end = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $dateInput, new DateTimeZone('UTC'));
    }
    $dateErrors = DateTimeImmutable::getLastErrors();
    if (!$end || ($dateErrors && ($dateErrors['warning_count'] || $dateErrors['error_count']))
        || $end <= new DateTimeImmutable('now', new DateTimeZone('UTC'))) {
        throw new InvalidArgumentException('Choose a valid future closing time.');
    }
    $methods = array_values(array_unique(array_map('intval', (array)($data['delivery_methods'] ?? []))));
    if (!$methods || count($methods) > 10) {
        throw new InvalidArgumentException('Choose at least one handover method.');
    }
    foreach ($methods as $method) {
        if (!service_row($db, 'SELECT delivery_method_id FROM delivery_methods WHERE delivery_method_id = ?', [$method])) {
            throw new InvalidArgumentException('Choose a valid handover method.');
        }
    }
    $images = service_store_images($files['images'] ?? $files);
    try {
        $db->beginTransaction();
        service_execute($db, 'INSERT INTO items (seller_id, category_id, title, description, item_condition) VALUES (?, ?, ?, ?, ?)', [$seller, $category, $title, $description, $condition]);
        $itemId = (int)$db->lastInsertId();
        service_execute($db, "INSERT INTO auctions (item_id, starting_price, reserve_price, minimum_increment, start_time, end_time, status) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), ?, 'active')", [$itemId, pence_to_price($starting), $reserve === null ? null : pence_to_price($reserve), pence_to_price($increment), $end->format('Y-m-d H:i:s')]);
        $auctionId = (int)$db->lastInsertId();
        foreach ($images as $order => $path) {
            service_execute($db, 'INSERT INTO item_images (item_id, image_path, display_order) VALUES (?, ?, ?)', [$itemId, $path, $order]);
        }
        foreach ($methods as $method) {
            service_execute($db, 'INSERT INTO auction_delivery (auction_id, delivery_method_id) VALUES (?, ?)', [$auctionId, $method]);
        }
        $db->commit();
        return $auctionId;
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        foreach ($images as $path) {
            @unlink(dirname(__DIR__) . '/' . $path);
        }
        throw $error;
    }
}

function notification_setting(PDO $db, int $user): array
{
    return service_row($db, 'SELECT * FROM notification_settings WHERE user_id = ?', [$user])
        ?? ['user_id' => $user, 'outbid_enabled' => 1, 'watch_enabled' => 1, 'seller_frequency' => 'immediate'];
}

function service_queue_notification(PDO $db, int $user, int $auctionId, string $type, string $subject, string $content, string $event): void
{
    $setting = notification_setting($db, $user);
    $status = 'queued';
    $available = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    if (($type === 'outbid' && !$setting['outbid_enabled']) || ($type === 'watch_update' && !$setting['watch_enabled'])) {
        $status = 'disabled';
    }
    if ($type === 'seller_bid') {
        if ($setting['seller_frequency'] === 'off') {
            $status = 'disabled';
        } elseif ($setting['seller_frequency'] === 'hourly') {
            $available = $available->modify('+1 hour');
            $available = $available->setTime((int)$available->format('H'), 0);
        } elseif ($setting['seller_frequency'] === 'daily') {
            $available = $available->modify('tomorrow')->setTime(0, 0);
        }
    }
    service_execute($db, 'INSERT INTO notifications (user_id, auction_id, type, subject, content, event_key, status, available_at, is_read) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE notification_id = notification_id', [$user, $auctionId, $type, $subject, $content, $user . ':' . $event, $status, $available->format('Y-m-d H:i:s'), (int) ($type === 'bid_placed')]);
}

function place_bid(PDO $db, int $auctionId, int $buyer, string $amount): array
{
    $pence = price_to_pence($amount);
    $db->beginTransaction();
    try {
        $auction = service_row($db, 'SELECT a.*, i.seller_id, i.title, (a.start_time <= UTC_TIMESTAMP() AND a.end_time > UTC_TIMESTAMP()) AS within_time FROM auctions a JOIN items i ON i.item_id = a.item_id WHERE a.auction_id = ? FOR UPDATE', [$auctionId]);
        if (!$auction) {
            throw new InvalidArgumentException('This auction could not be found.');
        }
        if ((int)$auction['seller_id'] === $buyer) {
            throw new InvalidArgumentException('You cannot bid on your own listing.');
        }
        if ($auction['status'] !== 'active' || !(int)$auction['within_time']) {
            throw new InvalidArgumentException('This auction is not open for bidding.');
        }
        $highest = service_row($db, 'SELECT bid_id, bidder_id, amount FROM bids WHERE auction_id = ? ORDER BY amount DESC, placed_at ASC, bid_id ASC LIMIT 1', [$auctionId]);
        $minimum = $highest
            ? price_to_pence((string)$highest['amount']) + price_to_pence((string)$auction['minimum_increment'])
            : price_to_pence((string)$auction['starting_price']);
        if ($pence < $minimum) {
            throw new InvalidArgumentException(t('The next bid must be at least {price}.', ['price' => '£' . pence_to_price($minimum)]));
        }
        $insert = $db->prepare("INSERT INTO bids (auction_id, bidder_id, amount, placed_at)
            SELECT auction_id, ?, ?, UTC_TIMESTAMP() FROM auctions
            WHERE auction_id = ? AND status = 'active' AND start_time <= UTC_TIMESTAMP() AND end_time > UTC_TIMESTAMP()");
        $insert->execute([$buyer, pence_to_price($pence), $auctionId]);
        if ($insert->rowCount() !== 1) {
            throw new InvalidArgumentException('This auction has just closed. Your bid was not placed.');
        }
        $bidId = (int)$db->lastInsertId();
        $price = '£' . pence_to_price($pence);
        // Regaining the lead settles previous outbid alerts for this lot only.
        service_execute($db, "UPDATE notifications SET is_read = TRUE WHERE user_id = ? AND auction_id = ? AND type = 'outbid' AND is_read = FALSE", [$buyer, $auctionId]);
        service_queue_notification($db, $buyer, $auctionId, 'bid_placed', 'Your bid was placed', 'Your bid of ' . $price . ' on ' . $auction['title'] . ' was accepted.', 'bid:' . $bidId . ':placed');
        if ($highest && (int)$highest['bidder_id'] !== $buyer) {
            service_queue_notification($db, (int)$highest['bidder_id'], $auctionId, 'outbid', 'You have been outbid', 'The current bid on ' . $auction['title'] . ' is ' . $price . '.', 'bid:' . $bidId . ':outbid');
        }
        service_queue_notification($db, (int)$auction['seller_id'], $auctionId, 'seller_bid', 'A new bid on your listing', $auction['title'] . ' has received a bid of ' . $price . '.', 'bid:' . $bidId . ':seller');
        $watchers = service_rows($db, 'SELECT user_id FROM watches WHERE auction_id = ? AND user_id <> ? AND user_id <> ?', [$auctionId, $buyer, (int)$auction['seller_id']]);
        foreach ($watchers as $watcher) {
            $watcherId = (int)$watcher['user_id'];
            if ($highest && $watcherId === (int)$highest['bidder_id']) {
                continue;
            }
            service_queue_notification($db, $watcherId, $auctionId, 'watch_update', 'An auction you follow has a new bid', $auction['title'] . ' is now at ' . $price . '.', 'bid:' . $bidId . ':watch');
        }
        $db->commit();
        return ['bid_id' => $bidId, 'auction_id' => $auctionId, 'amount' => pence_to_price($pence)];
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}

function close_due_auctions(PDO $db): int
{
    service_execute($db, "UPDATE auctions SET status = 'active' WHERE status = 'scheduled' AND start_time <= UTC_TIMESTAMP() AND end_time > UTC_TIMESTAMP()");
    $candidates = service_rows($db, "SELECT auction_id FROM auctions WHERE status IN ('active', 'scheduled') AND end_time <= UTC_TIMESTAMP() ORDER BY auction_id LIMIT 200");
    $closed = 0;
    foreach ($candidates as $candidate) {
        $db->beginTransaction();
        try {
            // Lock by primary key so concurrent workers do not hold competing status-index gaps.
            $auctionId = (int)$candidate['auction_id'];
            $auction = service_row($db, 'SELECT a.*, i.seller_id, i.title, (a.end_time <= UTC_TIMESTAMP()) AS is_due FROM auctions a JOIN items i ON i.item_id = a.item_id WHERE a.auction_id = ? FOR UPDATE', [$auctionId]);
            if (!$auction || !in_array($auction['status'], ['active', 'scheduled'], true) || !(int)$auction['is_due']) {
                $db->commit();
                continue;
            }
            $winning = service_row($db, 'SELECT bid_id, bidder_id, amount FROM bids WHERE auction_id = ? ORDER BY amount DESC, placed_at ASC, bid_id ASC LIMIT 1', [$auctionId]);
            $reserve = $auction['reserve_price'] === null ? null : price_to_pence((string)$auction['reserve_price']);
            $sold = $winning && ($reserve === null || price_to_pence((string)$winning['amount']) >= $reserve);
            service_execute($db, 'UPDATE auctions SET status = ? WHERE auction_id = ?', [$sold ? 'completed' : 'unsold', $auctionId]);
            if ($sold) {
                service_execute($db, 'INSERT INTO auction_results (auction_id, winning_bid_id, decided_at) VALUES (?, ?, UTC_TIMESTAMP())', [$auctionId, $winning['bid_id']]);
                $resultId = (int)$db->lastInsertId();
                service_execute($db, 'INSERT INTO transactions (result_id) VALUES (?)', [$resultId]);
                service_queue_notification($db, (int)$winning['bidder_id'], $auctionId, 'won', 'You won an auction', 'You won ' . $auction['title'] . ' for £' . $winning['amount'] . '. Choose a handover method in your collection.', 'close:' . $auctionId . ':won');
                service_queue_notification($db, (int)$auction['seller_id'], $auctionId, 'sold', 'Your auction has sold', $auction['title'] . ' sold for £' . $winning['amount'] . '.', 'close:' . $auctionId . ':sold');
            } else {
                $reason = $winning ? 'The reserve price was not reached.' : 'No bids were received.';
                service_queue_notification($db, (int)$auction['seller_id'], $auctionId, 'unsold', 'Your auction closed without a sale', $auction['title'] . ': ' . $reason, 'close:' . $auctionId . ':unsold');
            }
            $db->commit();
            $closed++;
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
    }
    return $closed;
}

function toggle_watch(PDO $db, int $user, int $auctionId, ?bool $desired = null): bool
{
    $db->beginTransaction();
    try {
        if (!service_row($db, 'SELECT auction_id FROM auctions WHERE auction_id = ? FOR UPDATE', [$auctionId])) {
            throw new InvalidArgumentException('This auction could not be found.');
        }
        $existing = service_row($db, 'SELECT user_id FROM watches WHERE user_id = ? AND auction_id = ?', [$user, $auctionId]);
        $watch = $desired ?? !$existing;
        if ($existing && !$watch) {
            service_execute($db, 'DELETE FROM watches WHERE user_id = ? AND auction_id = ?', [$user, $auctionId]);
        } elseif (!$existing && $watch) {
            service_execute($db, 'INSERT INTO watches (user_id, auction_id, watched_at) VALUES (?, ?, UTC_TIMESTAMP())', [$user, $auctionId]);
        }
        $db->commit();
        return $watch;
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}

function add_comment(PDO $db, int $user, int $auctionId, string $content): void
{
    $content = service_text($content, 2000, 'Comment');
    if (!service_row($db, 'SELECT auction_id FROM auctions WHERE auction_id = ?', [$auctionId])) {
        throw new InvalidArgumentException('This auction could not be found.');
    }
    service_execute($db, 'INSERT INTO comments (auction_id, user_id, content, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP())', [$auctionId, $user, $content]);
}

function save_preferences(PDO $db, int $user, array $categoryIds): void
{
    $ids = array_values(array_unique(array_map('intval', $categoryIds)));
    if (count($ids) > 50) {
        throw new InvalidArgumentException('Too many categories selected.');
    }
    foreach ($ids as $id) {
        if (!service_row($db, 'SELECT category_id FROM categories WHERE category_id = ?', [$id])) {
            throw new InvalidArgumentException('Choose an existing category.');
        }
    }
    $db->beginTransaction();
    try {
        service_row($db, 'SELECT user_id FROM users WHERE user_id = ? FOR UPDATE', [$user]);
        service_execute($db, 'DELETE FROM user_preferences WHERE user_id = ?', [$user]);
        foreach ($ids as $id) {
            service_execute($db, 'INSERT INTO user_preferences (user_id, category_id, selected_at) VALUES (?, ?, UTC_TIMESTAMP())', [$user, $id]);
        }
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}

function save_notification_settings(PDO $db, int $user, array $settings): void
{
    $frequency = (string)($settings['seller_frequency'] ?? 'immediate');
    if (!in_array($frequency, ['immediate', 'hourly', 'daily', 'off'], true)) {
        throw new InvalidArgumentException('Choose a valid notification frequency.');
    }
    $outbid = !empty($settings['outbid_enabled']) ? 1 : 0;
    $watch = !empty($settings['watch_enabled']) ? 1 : 0;
    service_execute($db, 'INSERT INTO notification_settings (user_id, outbid_enabled, watch_enabled, seller_frequency) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE outbid_enabled = VALUES(outbid_enabled), watch_enabled = VALUES(watch_enabled), seller_frequency = VALUES(seller_frequency)', [$user, $outbid, $watch, $frequency]);
}

function recommended_auctions(PDO $db, int $user): array
{
    $rows = service_rows($db, auction_select_sql() . "
        JOIN (
            SELECT other_bids.auction_id, COUNT(DISTINCT other_bids.bidder_id) AS common_bidders
            FROM bids other_bids
            JOIN (SELECT DISTINCT peers.bidder_id
                FROM bids mine JOIN bids peers ON peers.auction_id = mine.auction_id
                WHERE mine.bidder_id = ? AND peers.bidder_id <> ?) neighbours
                ON neighbours.bidder_id = other_bids.bidder_id
            GROUP BY other_bids.auction_id
        ) recommendation ON recommendation.auction_id = a.auction_id
        WHERE a.status = 'active' AND a.start_time <= UTC_TIMESTAMP() AND a.end_time > UTC_TIMESTAMP()
        AND i.seller_id <> ?
        AND NOT EXISTS (SELECT 1 FROM bids own_bid WHERE own_bid.auction_id = a.auction_id AND own_bid.bidder_id = ?)
        ORDER BY recommendation.common_bidders DESC, a.end_time ASC LIMIT 8", [$user, $user, $user, $user, $user]);
    foreach ($rows as &$row) {
        $row['recommendation_reason'] = 'Collectors who bid alongside you also bid here.';
        $row['recommendation_type'] = 'collaborative';
    }
    unset($row);
    if (count($rows) >= 4) {
        return array_map(fn(array $row): array => service_visible_auction($row, $user), $rows);
    }
    $fallback = service_rows($db, auction_select_sql() . "
        WHERE a.status = 'active' AND a.start_time <= UTC_TIMESTAMP() AND a.end_time > UTC_TIMESTAMP()
        AND i.seller_id <> ? AND EXISTS (SELECT 1 FROM user_preferences p WHERE p.user_id = ? AND p.category_id = i.category_id)
        AND NOT EXISTS (SELECT 1 FROM bids mine WHERE mine.auction_id = a.auction_id AND mine.bidder_id = ?)
        ORDER BY a.end_time ASC, a.auction_id ASC LIMIT 8", [$user, $user, $user, $user]);
    $seen = array_fill_keys(array_column($rows, 'auction_id'), true);
    foreach ($fallback as $row) {
        if (isset($seen[$row['auction_id']])) {
            continue;
        }
        $row['recommendation_reason'] = 'From a category you follow.';
        $row['recommendation_type'] = 'preference';
        $rows[] = $row;
        if (count($rows) >= 8) {
            break;
        }
    }
    return array_map(fn(array $row): array => service_visible_auction($row, $user), $rows);
}

function user_dashboard(PDO $db, int $user): array
{
    $queries = [
        'published' => ['i.seller_id = ?', [$user]],
        'bidding' => ['EXISTS (SELECT 1 FROM bids mine WHERE mine.auction_id = a.auction_id AND mine.bidder_id = ?)', [$user]],
        'won' => ['EXISTS (SELECT 1 FROM auction_results r JOIN bids winning ON winning.bid_id = r.winning_bid_id WHERE r.auction_id = a.auction_id AND winning.bidder_id = ?)', [$user]],
        'sold' => ["i.seller_id = ? AND a.status = 'completed'", [$user]],
        'watches' => ['EXISTS (SELECT 1 FROM watches own_watch WHERE own_watch.auction_id = a.auction_id AND own_watch.user_id = ?)', [$user]],
    ];
    $dashboard = [];
    foreach ($queries as $key => [$where, $params]) {
        $dashboard[$key] = service_rows($db, auction_select_sql() . ' WHERE ' . $where . ' ORDER BY a.created_at DESC, a.auction_id DESC LIMIT 120', array_merge([$user], $params));
        $dashboard[$key] = array_map(fn(array $row): array => service_visible_auction($row, $user), $dashboard[$key]);
    }
    $dashboard['transactions'] = service_rows($db, transaction_select_sql() . ' WHERE i.seller_id = ? OR b.bidder_id = ? ORDER BY t.created_at DESC, t.transaction_id DESC LIMIT 120', [$user, $user]);
    $dashboard['preferences'] = array_map('intval', array_column(service_rows($db, 'SELECT category_id FROM user_preferences WHERE user_id = ?', [$user]), 'category_id'));
    return $dashboard;
}

function notification_list(PDO $db, int $user, int $limit = 100): array
{
    $limit = max(1, min(100, $limit));
    return service_rows($db, 'SELECT n.*, i.title AS auction_title, i.description AS auction_description, ' . first_item_image_sql() . ' AS image_path FROM notifications n JOIN auctions a ON a.auction_id = n.auction_id JOIN items i ON i.item_id = a.item_id WHERE n.user_id = ? ORDER BY n.created_at DESC, n.notification_id DESC LIMIT ' . $limit, [$user]);
}

function notification_activity(PDO $db, int $user): array
{
    $row = service_row($db, 'SELECT MAX(notification_id) AS latest, SUM(is_read = FALSE) AS unread FROM notifications WHERE user_id = ?', [$user]);
    return ['latest' => (int)$row['latest'], 'unread' => (int)$row['unread']];
}

function open_notification(PDO $db, int $user, int $id): string
{
    $notice = service_row($db, 'SELECT auction_id, type, event_key FROM notifications WHERE notification_id = ? AND user_id = ?', [$id, $user]);
    if (!$notice) {
        return url('notifications');
    }
    service_execute($db, 'UPDATE notifications SET is_read = TRUE WHERE notification_id = ? AND user_id = ? AND is_read = FALSE', [$id, $user]);
    if ($notice['type'] === 'order_message' && preg_match('/^[0-9]+:order_message:([0-9]+):[0-9]+$/D', $notice['event_key'], $parts)) {
        return url('transaction', ['id' => (int)$parts[1]]);
    }
    return url('detail', ['id' => (int)$notice['auction_id']]);
}

function mark_notifications_read(PDO $db, int $user): void
{
    service_execute($db, 'UPDATE notifications SET is_read = TRUE WHERE user_id = ? AND is_read = FALSE', [$user]);
}

function transaction_select_sql(): string
{
    return 'SELECT t.*, r.auction_id, r.winning_bid_id, b.bidder_id AS buyer_id, b.amount,
        i.seller_id, i.title, i.description AS auction_description, ' . first_item_image_sql() . ' AS image_path, a.item_id, buyer.username AS buyer_name, seller.username AS seller_name,
        d.method_name FROM transactions t
        JOIN auction_results r ON r.result_id = t.result_id
        JOIN auctions a ON a.auction_id = r.auction_id
        JOIN items i ON i.item_id = a.item_id
        JOIN bids b ON b.bid_id = r.winning_bid_id
        JOIN users buyer ON buyer.user_id = b.bidder_id
        JOIN users seller ON seller.user_id = i.seller_id
        LEFT JOIN delivery_methods d ON d.delivery_method_id = t.delivery_method_id';
}

function transaction_detail(PDO $db, int $transactionId, int $user): ?array
{
    $transaction = service_row($db, transaction_select_sql() . ' WHERE t.transaction_id = ? AND (i.seller_id = ? OR b.bidder_id = ?)', [$transactionId, $user, $user]);
    if (!$transaction) {
        return null;
    }
    $transaction['handoff'] = handoff_details($db, $transaction);
    $transaction['messages'] = handoff_messages($db, $transactionId);
    $transaction['delivery_methods'] = service_rows($db, 'SELECT d.delivery_method_id, d.method_name FROM delivery_methods d JOIN auction_delivery ad ON ad.delivery_method_id = d.delivery_method_id WHERE ad.auction_id = ? ORDER BY d.delivery_method_id', [$transaction['auction_id']]);
    $transaction['reviews'] = service_rows($db, 'SELECT r.*, u.username FROM reviews r JOIN users u ON u.user_id = r.reviewer_id WHERE r.transaction_id = ? ORDER BY r.created_at, r.review_id', [$transactionId]);
    $transaction['requests'] = service_rows($db, 'SELECT r.*, u.username FROM after_sales_requests r JOIN users u ON u.user_id = r.requester_id WHERE r.transaction_id = ? ORDER BY r.created_at DESC, r.request_id DESC', [$transactionId]);
    return $transaction;
}

function service_locked_transaction(PDO $db, int $transactionId, int $user): array
{
    $transaction = service_row($db, transaction_select_sql() . ' WHERE t.transaction_id = ? FOR UPDATE', [$transactionId]);
    if (!$transaction || !in_array($user, [(int)$transaction['buyer_id'], (int)$transaction['seller_id']], true)) {
        throw new InvalidArgumentException('This transaction is not available to your account.');
    }
    return $transaction;
}

function save_delivery(PDO $db, int $user, int $transactionId, int $methodId): void
{
    $db->beginTransaction();
    try {
        $transaction = service_locked_transaction($db, $transactionId, $user);
        if ($user !== (int)$transaction['buyer_id'] || $transaction['payment_status'] !== 'pending') {
            throw new InvalidArgumentException('Only the buyer can choose a handover method before payment.');
        }
        if (!service_row($db, 'SELECT auction_id FROM auction_delivery WHERE auction_id = ? AND delivery_method_id = ?', [$transaction['auction_id'], $methodId])) {
            throw new InvalidArgumentException('Choose a handover method offered by this seller.');
        }
        service_execute($db, 'UPDATE transactions SET delivery_method_id = ? WHERE transaction_id = ?', [$methodId, $transactionId]);
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}

function update_transaction(PDO $db, int $user, int $transactionId, string $operation, ?array $dispatchDetails = null): void
{
    $db->beginTransaction();
    try {
        $transaction = service_locked_transaction($db, $transactionId, $user);
        $isBuyer = $user === (int)$transaction['buyer_id'];
        $isSeller = $user === (int)$transaction['seller_id'];
        if ($operation === 'pay_demo' && $isBuyer) {
            if ($transaction['payment_status'] === 'paid_demo') {
                $db->commit();
                return;
            }
            if (!$transaction['delivery_method_id'] || $transaction['payment_status'] !== 'pending') {
                throw new InvalidArgumentException('Choose a handover method before recording a demonstration payment.');
            }
            service_execute($db, "UPDATE transactions SET payment_status = 'paid_demo' WHERE transaction_id = ?", [$transactionId]);
            service_queue_notification($db, (int)$transaction['seller_id'], (int)$transaction['auction_id'], 'transaction', 'Demonstration payment recorded', 'The buyer recorded a demonstration payment for ' . $transaction['title'] . '. No real money was transferred.', 'transaction:' . $transactionId . ':paid');
        } elseif ($operation === 'ship' && $isSeller) {
            if (in_array($transaction['delivery_status'], ['shipped', 'delivered'], true)) {
                $db->commit();
                return;
            }
            if ($transaction['payment_status'] !== 'paid_demo' || $transaction['delivery_status'] !== 'pending') {
                throw new InvalidArgumentException('Record the demonstration payment before arranging delivery.');
            }
            if ($dispatchDetails !== null) {
                handoff_record_dispatch($db, $transaction, $dispatchDetails);
            }
            service_execute($db, "UPDATE transactions SET delivery_status = 'shipped' WHERE transaction_id = ?", [$transactionId]);
            $subject = handoff_collection($transaction) ? 'Your item is ready for collection' : 'Your item is on its way';
            service_queue_notification($db, (int)$transaction['buyer_id'], (int)$transaction['auction_id'], 'transaction', $subject, 'The seller marked ' . $transaction['title'] . ' as dispatched or ready for collection.', 'transaction:' . $transactionId . ':shipped');
        } elseif ($operation === 'receive' && $isBuyer) {
            if ($transaction['delivery_status'] === 'delivered') {
                $db->commit();
                return;
            }
            if ($transaction['delivery_status'] !== 'shipped') {
                throw new InvalidArgumentException('The seller must arrange delivery before you can confirm receipt.');
            }
            service_execute($db, "UPDATE transactions SET delivery_status = 'delivered' WHERE transaction_id = ?", [$transactionId]);
        } else {
            throw new InvalidArgumentException('This action is not available to your account.');
        }
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}

function add_review(PDO $db, int $user, int $transactionId, int $rating, string $comment): void
{
    if ($rating < 1 || $rating > 5) {
        throw new InvalidArgumentException('Choose a rating from 1 to 5.');
    }
    $comment = service_text($comment, 2000, 'Review');
    $db->beginTransaction();
    try {
        $transaction = service_locked_transaction($db, $transactionId, $user);
        if ($transaction['delivery_status'] !== 'delivered') {
            throw new InvalidArgumentException('Reviews become available after the buyer confirms receipt.');
        }
        if (service_row($db, 'SELECT review_id FROM reviews WHERE transaction_id = ? AND reviewer_id = ?', [$transactionId, $user])) {
            throw new InvalidArgumentException('You have already reviewed this transaction.');
        }
        $reviewee = $user === (int)$transaction['buyer_id'] ? (int)$transaction['seller_id'] : (int)$transaction['buyer_id'];
        service_execute($db, 'INSERT INTO reviews (transaction_id, reviewer_id, reviewee_id, rating, comment, created_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())', [$transactionId, $user, $reviewee, $rating, $comment]);
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}

function add_after_sales_request(PDO $db, int $user, int $transactionId, string $description): void
{
    $description = service_text($description, 4000, 'Request');
    $db->beginTransaction();
    try {
        $transaction = service_locked_transaction($db, $transactionId, $user);
        if ($transaction['payment_status'] !== 'paid_demo') {
            throw new InvalidArgumentException('After-sales requests become available after the demonstration payment is recorded.');
        }
        if (service_row($db, "SELECT request_id FROM after_sales_requests WHERE transaction_id = ? AND requester_id = ? AND status = 'open'", [$transactionId, $user])) {
            throw new InvalidArgumentException('You already have an open request for this transaction.');
        }
        service_execute($db, "INSERT INTO after_sales_requests (transaction_id, requester_id, description, status, created_at) VALUES (?, ?, ?, 'open', UTC_TIMESTAMP())", [$transactionId, $user, $description]);
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}

function send_notifications(PDO $db, string $outboxDirectory): int
{
    $transport = getenv('MAIL_TRANSPORT') ?: 'file';
    if (!in_array($transport, ['file', 'mail'], true)) {
        throw new RuntimeException('MAIL_TRANSPORT must be file or mail.');
    }
    $sender = getenv('MAIL_FROM') ?: '';
    if ($transport === 'mail' && (!filter_var($sender, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $sender))) {
        throw new RuntimeException('Set a valid MAIL_FROM address before enabling mail transport.');
    }
    if (!is_dir($outboxDirectory) && !mkdir($outboxDirectory, 0700, true) && !is_dir($outboxDirectory)) {
        throw new RuntimeException('The notification outbox is not writable.');
    }
    $written = 0;
    $db->beginTransaction();
    try {
        $notifications = service_rows($db, "SELECT n.*, u.email FROM notifications n JOIN users u ON u.user_id = n.user_id WHERE n.status = 'queued' AND n.available_at <= UTC_TIMESTAMP() ORDER BY n.notification_id LIMIT 200 FOR UPDATE");
        $groups = [];
        foreach ($notifications as $notification) {
            $settings = notification_setting($db, (int)$notification['user_id']);
            $disabled = ($notification['type'] === 'outbid' && !$settings['outbid_enabled'])
                || ($notification['type'] === 'watch_update' && !$settings['watch_enabled'])
                || ($notification['type'] === 'seller_bid' && $settings['seller_frequency'] === 'off');
            if ($disabled) {
                service_execute($db, "UPDATE notifications SET status = 'disabled' WHERE notification_id = ?", [$notification['notification_id']]);
                continue;
            }
            $digest = $notification['type'] === 'seller_bid' && in_array($settings['seller_frequency'], ['hourly', 'daily'], true);
            $key = $digest ? 'seller:' . $notification['user_id'] : 'single:' . $notification['notification_id'];
            $groups[$key][] = $notification;
        }
        foreach ($groups as $group) {
            $notification = $group[0];
            $id = (int)$notification['notification_id'];
            $filename = rtrim($outboxDirectory, '/') . '/' . $id . '.eml';
            $headerEmail = preg_replace('/[\r\n]/', '', (string)$notification['email']);
            $subject = preg_replace('/[\r\n]/', '', (string)$notification['subject']);
            if (count($group) > 1) {
                $subject = 'Updates on your auctions';
            }
            $content = implode("\r\n\r\n", array_column($group, 'content'));
            $message = 'To: ' . $headerEmail . "\r\n"
                . 'Subject: ' . $subject . "\r\n"
                . 'Message-ID: <cabinet-' . $id . "@localhost>\r\n"
                . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n"
                . $content . "\r\n";
            $temporary = tempnam($outboxDirectory, '.mail-');
            if ($temporary === false) {
                throw new RuntimeException('The notification message could not be created.');
            }
            if (file_put_contents($temporary, $message, LOCK_EX) === false || !rename($temporary, $filename)) {
                @unlink($temporary);
                throw new RuntimeException('The notification message could not be saved.');
            }
            $status = 'saved';
            if ($transport === 'mail') {
                $headers = ['From' => $sender, 'MIME-Version' => '1.0', 'Content-Type' => 'text/plain; charset=UTF-8', 'Message-ID' => '<cabinet-' . $id . '@localhost>'];
                if (!mail($headerEmail, $subject, $content, $headers)) {
                    throw new RuntimeException('Mail transport did not accept the message. It remains queued.');
                }
                $status = 'sent';
            }
            foreach ($group as $record) {
                service_execute($db, 'UPDATE notifications SET status = ?, sent_at = UTC_TIMESTAMP() WHERE notification_id = ?', [$status, $record['notification_id']]);
                $written++;
            }
        }
        $db->commit();
        return $written;
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}
