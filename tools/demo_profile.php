<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function demo_profile_records(PDO $connection, int $user): array
{
    return service_rows($connection, 'SELECT a.auction_id, a.item_id, a.status, i.seller_id, i.title FROM auctions a JOIN items i ON i.item_id = a.item_id WHERE i.description LIKE ? ORDER BY a.auction_id', ['Cabinet profile sample v1:%; account ' . $user . '.%']);
}

function demo_profile_lots(int $user, array $users): array
{
    return [
        ['key' => 'outbid', 'title' => '[Demo] Collector camera kit', 'seller' => $users['mira'], 'category' => 'Cameras', 'image' => 'camera', 'start' => '150.00', 'reserve' => '200.00', 'step' => '5.00', 'status' => 'active', 'hours' => 18, 'bids' => [[$user, '150.00'], [$users['noah'], '155.00']]],
        ['key' => 'leading', 'title' => '[Demo] Limited vinyl pressing', 'seller' => $users['ella'], 'category' => 'Records', 'image' => 'vinyl', 'start' => '30.00', 'reserve' => null, 'step' => '2.00', 'status' => 'active', 'hours' => 30, 'bids' => [[$users['noah'], '30.00'], [$user, '32.00']]],
        ['key' => 'watching', 'title' => '[Demo] Retro handheld console', 'seller' => $users['noah'], 'category' => 'Games', 'image' => 'console', 'start' => '55.00', 'reserve' => null, 'step' => '5.00', 'status' => 'active', 'hours' => 36, 'bids' => []],
        ['key' => 'won', 'title' => '[Demo] Collected mechanical watch', 'seller' => $users['mira'], 'category' => 'Watches', 'image' => 'watch', 'start' => '85.00', 'reserve' => '100.00', 'step' => '5.00', 'status' => 'completed', 'hours' => 0, 'bids' => [[$users['noah'], '85.00'], [$user, '110.00']]],
        ['key' => 'won_console', 'title' => '[Demo] Won retro game console', 'seller' => $users['noah'], 'category' => 'Games', 'image' => 'console', 'start' => '45.00', 'reserve' => null, 'step' => '5.00', 'status' => 'completed', 'hours' => 0, 'bids' => [[$users['ella'], '45.00'], [$user, '50.00']]],
        ['key' => 'listing', 'title' => '[Demo] Archive comic bundle', 'seller' => $user, 'category' => 'Comics', 'image' => 'cards', 'start' => '20.00', 'reserve' => null, 'step' => '2.00', 'status' => 'active', 'hours' => 48, 'bids' => [[$users['ella'], '20.00'], [$users['noah'], '22.00']]],
        ['key' => 'listing_camera', 'title' => '[Demo] Pocket camera collection', 'seller' => $user, 'category' => 'Cameras', 'image' => 'camera', 'start' => '90.00', 'reserve' => null, 'step' => '5.00', 'status' => 'active', 'hours' => 60, 'bids' => []],
        ['key' => 'sold', 'title' => '[Demo] Traded science-fiction figures', 'seller' => $user, 'category' => 'Figures', 'image' => 'figure', 'start' => '35.00', 'reserve' => '40.00', 'step' => '5.00', 'status' => 'completed', 'hours' => 0, 'bids' => [[$users['noah'], '35.00'], [$users['ella'], '45.00']]],
        ['key' => 'sold_records', 'title' => '[Demo] Sold vinyl archive', 'seller' => $user, 'category' => 'Records', 'image' => 'vinyl', 'start' => '24.00', 'reserve' => '30.00', 'step' => '2.00', 'status' => 'completed', 'hours' => 0, 'bids' => [[$users['ella'], '24.00'], [$users['noah'], '30.00']]],
    ];
}

function demo_profile_timeline(int $registered, int $seededAt): array
{
    $from = max($registered, $seededAt - 9 * 86400);
    $until = $seededAt - 60;
    if ($until - $from < 120) {
        throw new RuntimeException('This account needs at least three minutes of history before the connected samples can be created. Try again shortly.');
    }
    $positions = [
        'won' => [5, 10, 16, 22], 'won_console' => [12, 18, 25, 32],
        'sold' => [20, 30, 38, 44], 'sold_records' => [34, 44, 50, 58],
        'outbid' => [55, 70, 90, null], 'leading' => [60, 72, 84, null],
        'watching' => [65, null, null, null], 'listing' => [68, 80, 94, null],
        'listing_camera' => [76, null, null, null],
    ];
    $timeline = [];
    foreach ($positions as $key => $points) {
        $timeline[$key] = array_map(fn(?int $point): ?string => $point === null ? null : gmdate('Y-m-d H:i:s', $from + intdiv(($until - $from) * $point, 100)), $points);
    }
    return $timeline;
}

function repair_demo_profile_chronology(PDO $connection, int $user, array $users): int
{
    $lots = demo_profile_lots($user, $users);
    $outbid = service_row($connection, 'SELECT a.end_time FROM auctions a JOIN items i ON i.item_id = a.item_id WHERE i.description = ? AND i.title = ? AND i.seller_id = ? ORDER BY a.auction_id LIMIT 1', [
        'Cabinet profile sample v1:outbid; account ' . $user . '. Illustrative local history with sample photography; no real sale or payment.',
        '[Demo] Collector camera kit', $users['mira'],
    ]);
    if (!$outbid) return 0;
    // The original fixed closing offset anchors this migration across every rerun.
    $seededAt = strtotime($outbid['end_time'] . ' UTC') - 18 * 3600;
    if ($seededAt > time()) return 0;
    $account = service_row($connection, 'SELECT MAX(created_at) AS registered FROM users WHERE user_id IN (?, ?, ?, ?)', [$user, $users['mira'], $users['noah'], $users['ella']]);
    $registered = strtotime($account['registered'] . ' UTC');
    $timeline = demo_profile_timeline($registered, $seededAt);
    $seedTimestamp = gmdate('Y-m-d H:i:s', $seededAt);
    $updated = 0;
    foreach ($lots as $lot) {
        $description = 'Cabinet profile sample v1:' . $lot['key'] . '; account ' . $user . '. Illustrative local history with sample photography; no real sale or payment.';
        $auction = service_row($connection, 'SELECT a.*, i.seller_id, i.title, i.item_condition, i.created_at AS item_created, i.updated_at AS item_updated, c.category_name FROM auctions a JOIN items i ON i.item_id = a.item_id JOIN categories c ON c.category_id = i.category_id WHERE i.description = ? ORDER BY a.auction_id LIMIT 1 FOR UPDATE', [$description]);
        if (!$auction) continue;
        $past = $lot['status'] === 'completed';
        $originalStart = gmdate('Y-m-d H:i:s', max($registered, $seededAt - ($past ? 86400 : 1800)));
        $originalEnd = gmdate('Y-m-d H:i:s', $past ? $seededAt - 60 : $seededAt + $lot['hours'] * 3600);
        // Changed listings and later history remain outside this sample-only repair.
        if ($auction['title'] !== $lot['title'] || (int)$auction['seller_id'] !== $lot['seller']
            || $auction['category_name'] !== $lot['category'] || $auction['item_condition'] !== 'Pre-owned · good condition'
            || $auction['starting_price'] !== $lot['start'] || $auction['reserve_price'] !== $lot['reserve']
            || $auction['minimum_increment'] !== $lot['step'] || $auction['status'] !== $lot['status']
            || $auction['start_time'] !== $originalStart || $auction['created_at'] !== $originalStart
            || $auction['item_created'] !== $originalStart || $auction['item_updated'] !== $originalStart
            || $auction['end_time'] !== $originalEnd) continue;
        $images = service_rows($connection, 'SELECT image_path, display_order FROM item_images WHERE item_id = ? ORDER BY image_id', [$auction['item_id']]);
        if (count($images) !== 1 || $images[0]['image_path'] !== 'assets/images/' . $lot['image'] . '.jpg' || (int)$images[0]['display_order'] !== 0) continue;
        $bids = service_rows($connection, 'SELECT bid_id, bidder_id, amount, placed_at FROM bids WHERE auction_id = ? ORDER BY bid_id FOR UPDATE', [$auction['auction_id']]);
        $matches = count($bids) >= count($lot['bids']);
        foreach ($lot['bids'] as $index => [$bidder, $amount]) {
            $bid = $bids[$index] ?? null;
            $matches = $matches && $bid && (int)$bid['bidder_id'] === $bidder && $bid['amount'] === $amount && $bid['placed_at'] === $originalStart;
        }
        // A purpose-built empty lot remains untouched if someone has since bid on it.
        if (!$lot['bids'] && $bids) $matches = false;
        [$start, $first, $second, $close] = $timeline[$lot['key']];
        if ($matches && count($bids) > count($lot['bids']) && $second !== null) {
            foreach (array_slice($bids, count($lot['bids'])) as $later) if ($later['placed_at'] <= $second) $matches = false;
        }
        if (!$matches) continue;
        if ($past) {
            $result = service_row($connection, 'SELECT result_id, winning_bid_id, decided_at FROM auction_results WHERE auction_id = ? FOR UPDATE', [$auction['auction_id']]);
            if (!$result || (int)$result['winning_bid_id'] !== (int)$bids[count($lot['bids']) - 1]['bid_id'] || $result['decided_at'] !== $originalEnd) continue;
            service_execute($connection, 'UPDATE auction_results SET decided_at = ? WHERE result_id = ?', [$close, $result['result_id']]);
            // Only untouched synthetic order timestamps move with their synthetic close.
            service_execute($connection, 'UPDATE transactions SET created_at = ?, updated_at = ? WHERE result_id = ? AND created_at = ? AND updated_at = ?', [$close, $close, $result['result_id'], $originalEnd, $originalEnd]);
        }
        service_execute($connection, 'UPDATE items SET created_at = ?, updated_at = ? WHERE item_id = ?', [$start, $start, $auction['item_id']]);
        service_execute($connection, 'UPDATE auctions SET start_time = ?, created_at = ?, end_time = ? WHERE auction_id = ?', [$start, $start, $past ? $close : $originalEnd, $auction['auction_id']]);
        foreach ($lot['bids'] as $index => $_bid) service_execute($connection, 'UPDATE bids SET placed_at = ? WHERE bid_id = ? AND placed_at = ?', [[$first, $second][$index], $bids[$index]['bid_id'], $originalStart]);
        $eventSuffix = str_replace('_', '-', $lot['key']);
        $eventTime = $past ? $close : $second;
        if ($eventTime !== null) service_execute($connection, 'UPDATE notifications SET created_at = ? WHERE user_id = ? AND auction_id = ? AND event_key = ? AND created_at = ?', [$eventTime, $user, $auction['auction_id'], $user . ':demo-profile-v1:' . $eventSuffix, $seedTimestamp]);
        if ($lot['key'] === 'outbid') {
            $commentTimes = [gmdate('Y-m-d H:i:s', strtotime($start . ' UTC') + 1), gmdate('Y-m-d H:i:s', strtotime($first . ' UTC') - 1)];
            foreach ([[$user, 'Does the kit include the lens in the photograph?'], [$users['mira'], 'Yes, the photographed lens is included.']] as $index => [$author, $content]) {
                service_execute($connection, 'UPDATE comments SET created_at = ? WHERE auction_id = ? AND user_id = ? AND content = ? AND created_at = ?', [$commentTimes[$index], $auction['auction_id'], $author, $content, $seedTimestamp]);
            }
        }
        $updated++;
    }
    return $updated;
}

function seed_demo_profile(PDO $connection, int $user, array $users): array
{
    $lock = 'cabinet_demo_profile_' . substr(hash('sha256', (string)$connection->query('SELECT DATABASE()')->fetchColumn() . ':' . $user), 0, 32);
    $statement = $connection->prepare('SELECT GET_LOCK(?, 5)');
    $statement->execute([$lock]);
    if ((int)$statement->fetchColumn() !== 1) {
        throw new RuntimeException('Another process is preparing this demonstration profile. Try again shortly.');
    }
    try {
        $connection->beginTransaction();
        $marker = service_row($connection, 'SELECT a.auction_id FROM auctions a JOIN items i ON i.item_id = a.item_id JOIN auction_results r ON r.auction_id = a.auction_id JOIN bids b ON b.bid_id = r.winning_bid_id WHERE i.description = ? AND b.bidder_id = ? LIMIT 1', ['Cabinet profile sample v1:won; account ' . $user . '. Illustrative local history with sample photography; no real sale or payment.', $user]);
        if ($marker) {
            $timelineUpdated = repair_demo_profile_chronology($connection, $user, $users);
            $connection->commit();
            return ['created' => false, 'timeline_updated' => $timelineUpdated, 'marker_auction_id' => (int)$marker['auction_id'], 'auctions' => demo_profile_records($connection, $user)];
        }
        $category = [];
        foreach (['Cameras', 'Records', 'Games', 'Watches', 'Comics', 'Figures'] as $name) {
            $row = service_row($connection, 'SELECT category_id FROM categories WHERE category_name = ?', [$name]);
            if (!$row) {
                throw new RuntimeException('The connected profile requires the original demonstration categories.');
            }
            $category[$name] = (int)$row['category_id'];
        }
        $latestAccount = service_row($connection, 'SELECT MAX(created_at) AS registered FROM users WHERE user_id IN (?, ?, ?, ?)', [$user, $users['mira'], $users['noah'], $users['ella']]);
        $registered = strtotime($latestAccount['registered'] . ' UTC');
        if ($registered > time() + 1) {
            throw new RuntimeException('Correct the future-dated demonstration account before creating its history.');
        }
        $seededAt = time();
        $timeline = demo_profile_timeline($registered, $seededAt);
        $lots = demo_profile_lots($user, $users);
        $ids = [];
        $eventTimes = [];
        foreach ($lots as $lot) {
            $past = $lot['status'] === 'completed';
            [$start, $firstBidTime, $secondBidTime, $closingTime] = $timeline[$lot['key']];
            $end = $past ? $closingTime : gmdate('Y-m-d H:i:s', $seededAt + $lot['hours'] * 3600);
            $eventTimes[$lot['key']] = $past ? $end : $secondBidTime;
            $description = 'Cabinet profile sample v1:' . $lot['key'] . '; account ' . $user . '. Illustrative local history with sample photography; no real sale or payment.';
            service_execute($connection, 'INSERT INTO items (seller_id, category_id, title, description, item_condition, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)', [$lot['seller'], $category[$lot['category']], $lot['title'], $description, 'Pre-owned · good condition', $start, $start]);
            $item = (int)$connection->lastInsertId();
            service_execute($connection, 'INSERT INTO item_images (item_id, image_path, display_order) VALUES (?, ?, 0)', [$item, 'assets/images/' . $lot['image'] . '.jpg']);
            service_execute($connection, 'INSERT INTO auctions (item_id, starting_price, reserve_price, minimum_increment, start_time, end_time, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [$item, $lot['start'], $lot['reserve'], $lot['step'], $start, $end, $lot['status'], $start]);
            $auction = (int)$connection->lastInsertId();
            $ids[$lot['key']] = $auction;
            foreach ([1, 2] as $method) {
                service_execute($connection, 'INSERT INTO auction_delivery (auction_id, delivery_method_id) VALUES (?, ?)', [$auction, $method]);
            }
            $winningBid = null;
            foreach ($lot['bids'] as $index => [$bidder, $amount]) {
                service_execute($connection, 'INSERT INTO bids (auction_id, bidder_id, amount, placed_at) VALUES (?, ?, ?, ?)', [$auction, $bidder, $amount, [$firstBidTime, $secondBidTime][$index]]);
                $winningBid = (int)$connection->lastInsertId();
            }
            if ($past) {
                service_execute($connection, 'INSERT INTO auction_results (auction_id, winning_bid_id, decided_at) VALUES (?, ?, ?)', [$auction, $winningBid, $end]);
                service_execute($connection, 'INSERT INTO transactions (result_id, created_at, updated_at) VALUES (?, ?, ?)', [(int)$connection->lastInsertId(), $end, $end]);
            }
        }
        foreach (['outbid', 'leading', 'watching'] as $key) {
            service_execute($connection, 'INSERT INTO watches (user_id, auction_id, watched_at) VALUES (?, ?, ?)', [$user, $ids[$key], $timeline[$key][0]]);
        }
        foreach (['Cameras', 'Records', 'Watches'] as $name) {
            service_execute($connection, 'INSERT INTO user_preferences (user_id, category_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE category_id = category_id', [$user, $category[$name]]);
        }
        service_queue_notification($connection, $user, $ids['outbid'], 'outbid', 'You have been outbid', 'The current bid on [Demo] Collector camera kit is £155.00.', 'demo-profile-v1:outbid');
        service_queue_notification($connection, $user, $ids['leading'], 'bid_placed', 'Your bid was placed', 'Your bid of £32.00 on [Demo] Limited vinyl pressing was accepted.', 'demo-profile-v1:leading');
        service_queue_notification($connection, $user, $ids['won'], 'won', 'You won an auction', 'You won [Demo] Collected mechanical watch for £110.00. Choose a handover method in your collection.', 'demo-profile-v1:won');
        service_queue_notification($connection, $user, $ids['won_console'], 'won', 'You won an auction', 'You won [Demo] Won retro game console for £50.00. Choose a handover method in your collection.', 'demo-profile-v1:won-console');
        service_queue_notification($connection, $user, $ids['listing'], 'seller_bid', 'A new bid on your listing', '[Demo] Archive comic bundle has received a bid of £22.00.', 'demo-profile-v1:listing');
        service_queue_notification($connection, $user, $ids['sold'], 'sold', 'Your auction has sold', '[Demo] Traded science-fiction figures sold for £45.00.', 'demo-profile-v1:sold');
        service_queue_notification($connection, $user, $ids['sold_records'], 'sold', 'Your auction has sold', '[Demo] Sold vinyl archive sold for £30.00.', 'demo-profile-v1:sold-records');
        foreach (['outbid', 'leading', 'won', 'won_console', 'listing', 'sold', 'sold_records'] as $key) {
            service_execute($connection, 'UPDATE notifications SET created_at = ? WHERE user_id = ? AND auction_id = ? AND event_key = ?', [$eventTimes[$key], $user, $ids[$key], $user . ':demo-profile-v1:' . str_replace('_', '-', $key)]);
        }
        $questionTime = gmdate('Y-m-d H:i:s', strtotime($timeline['outbid'][0] . ' UTC') + 1);
        $answerTime = gmdate('Y-m-d H:i:s', strtotime($timeline['outbid'][1] . ' UTC') - 1);
        service_execute($connection, 'INSERT INTO comments (auction_id, user_id, content, created_at) VALUES (?, ?, ?, ?), (?, ?, ?, ?)', [$ids['outbid'], $user, 'Does the kit include the lens in the photograph?', $questionTime, $ids['outbid'], $users['mira'], 'Yes, the photographed lens is included.', $answerTime]);
        $connection->commit();
        return ['created' => true, 'marker_auction_id' => $ids['won'], 'keys' => $ids, 'auctions' => demo_profile_records($connection, $user)];
    } catch (Throwable $error) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $error;
    } finally {
        $statement = $connection->prepare('SELECT RELEASE_LOCK(?)');
        $statement->execute([$lock]);
    }
}
