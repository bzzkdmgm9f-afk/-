<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || !array_intersect(['--demo', '--status-samples', '--spotlight-samples', '--test-account', '--profile-samples'], $argv)) {
    fwrite(STDERR, "Run from the command line with --demo to add the illustrative dataset. Use --status-samples or --spotlight-samples to add those examples, or --test-account to add the local test login. Use --profile-samples to add its connected demonstration history.\n");
    exit(1);
}
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/services.php';
require __DIR__ . '/demo_profile.php';

function seed_test_account(PDO $connection): array
{
    $username = '123456';
    $email = '123456@art.com';
    $password = '123456';
    $lock = 'cabinet_demo_identity_' . substr(hash('sha256', (string)$connection->query('SELECT DATABASE()')->fetchColumn()), 0, 32);
    $statement = $connection->prepare('SELECT GET_LOCK(?, 5)');
    $statement->execute([$lock]);
    if ((int)$statement->fetchColumn() !== 1) {
        throw new RuntimeException('Another process is updating the demonstration identity. Try again shortly.');
    }
    try {
        $connection->beginTransaction();
        $target = service_rows($connection, 'SELECT user_id, username, email, password_hash FROM users WHERE username = ? OR email = ? FOR UPDATE', [$username, $email]);
        $legacy = service_row($connection, 'SELECT user_id, username, email, password_hash FROM users WHERE username = ? AND email = ? FOR UPDATE', ['11 123456', '11123456@example.test']);
        $created = false;
        $migrated = false;
        if ($target) {
            if (count($target) !== 1 || $target[0]['username'] !== $username || $target[0]['email'] !== $email || !password_verify($password, $target[0]['password_hash']) || ($legacy && (int)$legacy['user_id'] !== (int)$target[0]['user_id'])) {
                throw new RuntimeException('The requested demonstration username or email conflicts with an existing account. Existing data was left untouched.');
            }
            $id = (int)$target[0]['user_id'];
        } elseif ($legacy) {
            if (!password_verify($password, $legacy['password_hash'])) {
                throw new RuntimeException('The legacy demonstration account has a different password. Existing data was left untouched.');
            }
            $id = (int)$legacy['user_id'];
            service_execute($connection, 'UPDATE users SET username = ?, email = ? WHERE user_id = ?', [$username, $email, $id]);
            $migrated = true;
        } else {
            // This explicit local seed does not change the public registration password policy.
            service_execute($connection, 'INSERT INTO users (username, email, password_hash, created_at) VALUES (?, ?, ?, ?)', [$username, $email, password_hash($password, PASSWORD_DEFAULT), gmdate('Y-m-d H:i:s', time() - 10 * 86400)]);
            $id = (int)$connection->lastInsertId();
            $created = true;
        }
        $connection->commit();
        return ['user_id' => $id, 'username' => $username, 'email' => $email, 'created' => $created, 'migrated' => $migrated];
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

function seed_examples(PDO $connection, array $users, bool $devices = false): array
{
    $lock = 'cabinet_status_samples_' . $connection->query('SELECT DATABASE()')->fetchColumn();
    $statement = $connection->prepare('SELECT GET_LOCK(?, 5)');
    $statement->execute([$lock]);
    if ((int) $statement->fetchColumn() !== 1) {
        throw new RuntimeException('Another process is adding the sample records. Try again shortly.');
    }
    $samples = $devices ? [
        ['key' => 'phone', 'title' => 'First-generation OLED iPhone', 'category' => 'Phones', 'image' => 'phone', 'start_price' => '95.00', 'reserve' => null, 'step' => '5.00', 'status' => 'active', 'start_days' => 0, 'end_days' => 3, 'description' => 'An iPhone X from the first generation of OLED iPhones. Demonstration lot with sample photography.'],
        ['key' => 'laptop', 'title' => 'Aluminium MacBook Pro', 'category' => 'Computers', 'image' => 'laptop', 'start_price' => '175.00', 'reserve' => null, 'step' => '5.00', 'status' => 'active', 'start_days' => 0, 'end_days' => 4, 'description' => 'A silver aluminium MacBook Pro with a backlit display. Demonstration lot with sample photography.'],
    ] : [
        ['key' => 'sold', 'title' => '[Sample] Sold mechanical chronograph', 'category' => 'Watches', 'image' => 'watch', 'start_price' => '85.00', 'reserve' => '110.00', 'step' => '5.00', 'status' => 'completed', 'start_days' => -5, 'end_days' => -2],
        ['key' => 'unsold', 'title' => '[Sample] Reserve not met: vinyl collection', 'category' => 'Records', 'image' => 'vinyl', 'start_price' => '24.00', 'reserve' => '40.00', 'step' => '2.00', 'status' => 'unsold', 'start_days' => -4, 'end_days' => -1],
        ['key' => 'upcoming', 'title' => '[Sample] Upcoming compact camera', 'category' => 'Cameras', 'image' => 'camera', 'start_price' => '120.00', 'reserve' => '180.00', 'step' => '5.00', 'status' => 'scheduled', 'start_days' => 1, 'end_days' => 3],
    ];
    $records = [];
    try {
        $connection->beginTransaction();
        foreach ($samples as $sample) {
            $description = $sample['description'] ?? 'Illustrative status sample ' . $sample['key'] . '-v1. Sample photography and demonstration records; no real sale or payment.';
            $existing = service_row($connection, 'SELECT a.auction_id, a.item_id, a.status FROM auctions a JOIN items i ON i.item_id = a.item_id WHERE i.seller_id = ? AND i.title = ? AND i.description = ? ORDER BY a.auction_id LIMIT 1', [$users['mira'], $sample['title'], $description]);
            if ($existing) {
                $records[] = ['key' => $sample['key'], 'auction_id' => (int) $existing['auction_id'], 'item_id' => (int) $existing['item_id'], 'status' => $existing['status'], 'created' => false];
                continue;
            }
            $category = service_row($connection, 'SELECT category_id FROM categories WHERE category_name = ?', [$sample['category']]);
            if (!$category && !$devices) {
                throw new RuntimeException('Status samples require the existing demonstration categories.');
            }
            if (!$category) {
                service_execute($connection, 'INSERT INTO categories (category_name) VALUES (?)', [$sample['category']]);
            }
            $categoryId = $category ? (int) $category['category_id'] : (int) $connection->lastInsertId();
            $start = gmdate('Y-m-d H:i:s', time() + $sample['start_days'] * 86400);
            $end = gmdate('Y-m-d H:i:s', time() + $sample['end_days'] * 86400);
            $created = min($start, gmdate('Y-m-d H:i:s'));
            service_execute($connection, 'INSERT INTO items (seller_id, category_id, title, description, item_condition, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)', [$users['mira'], $categoryId, $sample['title'], $description, 'Pre-owned · good condition', $created, $created]);
            $itemId = (int) $connection->lastInsertId();
            service_execute($connection, 'INSERT INTO item_images (item_id, image_path, display_order) VALUES (?, ?, 0)', [$itemId, 'assets/images/' . $sample['image'] . '.jpg']);
            service_execute($connection, 'INSERT INTO auctions (item_id, starting_price, reserve_price, minimum_increment, start_time, end_time, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [$itemId, $sample['start_price'], $sample['reserve'], $sample['step'], $start, $end, $sample['status'], $created]);
            $auctionId = (int) $connection->lastInsertId();
            foreach ([1, 2] as $method) {
                service_execute($connection, 'INSERT INTO auction_delivery (auction_id, delivery_method_id) VALUES (?, ?)', [$auctionId, $method]);
            }
            if ($sample['key'] === 'sold') {
                service_execute($connection, 'INSERT INTO bids (auction_id, bidder_id, amount, placed_at) VALUES (?, ?, ?, ?), (?, ?, ?, ?)', [$auctionId, $users['noah'], '110.00', gmdate('Y-m-d H:i:s', time() - 4 * 86400), $auctionId, $users['ella'], '125.00', gmdate('Y-m-d H:i:s', time() - 3 * 86400)]);
                $winning = service_row($connection, 'SELECT bid_id FROM bids WHERE auction_id = ? AND bidder_id = ? AND amount = ? ORDER BY bid_id DESC LIMIT 1', [$auctionId, $users['ella'], '125.00']);
                service_execute($connection, 'INSERT INTO auction_results (auction_id, winning_bid_id, decided_at) VALUES (?, ?, ?)', [$auctionId, $winning['bid_id'], $end]);
                service_execute($connection, 'INSERT INTO transactions (result_id, created_at, updated_at) VALUES (?, ?, ?)', [(int) $connection->lastInsertId(), $end, $end]);
            } elseif ($sample['key'] === 'unsold') {
                service_execute($connection, 'INSERT INTO bids (auction_id, bidder_id, amount, placed_at) VALUES (?, ?, ?, ?)', [$auctionId, $users['noah'], '30.00', gmdate('Y-m-d H:i:s', time() - 3 * 86400)]);
            }
            $records[] = ['key' => $sample['key'], 'auction_id' => $auctionId, 'item_id' => $itemId, 'status' => $sample['status'], 'created' => true];
        }
        $connection->commit();
        return $records;
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

try {
    $connection = db();
    if (in_array('--test-account', $argv, true)) {
        echo json_encode(['status' => 'ok', 'test_account' => seed_test_account($connection)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit;
    }
    if (array_intersect(['--status-samples', '--spotlight-samples', '--profile-samples'], $argv)) {
        $users = [];
        foreach (['mira', 'noah', 'ella'] as $name) {
            $user = service_row($connection, 'SELECT user_id FROM users WHERE username = ? AND email = ?', [$name, $name . '@example.test']);
            if (!$user) {
                throw new RuntimeException('Sample additions require the existing mira, noah and ella demonstration accounts.');
            }
            $users[$name] = (int) $user['user_id'];
        }
        if (in_array('--profile-samples', $argv, true)) {
            $account = seed_test_account($connection);
            echo json_encode(['status' => 'ok', 'test_account' => $account, 'profile' => seed_demo_profile($connection, (int)$account['user_id'], $users)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
            exit;
        }
        $samples = seed_examples($connection, $users, in_array('--spotlight-samples', $argv, true));
        echo json_encode(['status' => 'ok', 'samples' => $samples], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit;
    }
    if ((int) $connection->query('SELECT COUNT(*) FROM users')->fetchColumn() !== 0) {
        throw new RuntimeException('Demo seeding requires an empty users table. Your existing records were left untouched.');
    }
    $password = 'CabinetDemo2026!';
    $users = [];
    foreach (['mira', 'noah', 'ella'] as $name) {
        service_execute($connection, 'INSERT INTO users (username, email, password_hash, created_at) VALUES (?, ?, ?, ?)', [$name, $name . '@example.test', password_hash($password, PASSWORD_DEFAULT), gmdate('Y-m-d H:i:s', time() - 14 * 86400)]);
        $users[$name] = (int)$connection->lastInsertId();
    }
    $names = ['Cameras', 'Records', 'Watches', 'Games', 'Comics', 'Figures'];
    $category = [];
    foreach ($names as $name) {
        $statement = $connection->prepare('INSERT INTO categories (category_name) VALUES (?)');
        $statement->execute([$name]);
        $category[$name] = (int) $connection->lastInsertId();
    }
    $lots = [
        ['Compact mirrorless camera', 'Cameras', 'camera', '120.00', '180.00', '5.00', 6, 'A compact camera and lens for a growing photography collection. Illustrative lot with sample photography.'],
        ['Vinyl listening collection', 'Records', 'vinyl', '24.00', null, '2.00', 18, 'A listening collection with clean sleeves and carefully stored records. Illustrative listing.'],
        ['Mechanical chronograph', 'Watches', 'watch', '85.00', '110.00', '5.00', 28, 'A classic dial, leather strap and mechanical movement. Illustrative listing for the auction demonstration.'],
        ['Yellow handheld console', 'Games', 'console', '45.00', null, '5.00', 36, 'An iconic yellow handheld console for a retro gaming shelf. Illustrative lot; inspect photographs before bidding.'],
        ['Illustrated comic collection', 'Comics', 'cards', '18.00', null, '1.00', 48, 'An illustrated collection for readers and collectors. Sample lot used to demonstrate category search and bidding.'],
        ['Miniature sci-fi figures', 'Figures', 'figure', '30.00', '45.00', '2.00', 60, 'A small display set for a collector’s cabinet. Illustrative lot with sample photography.'],
    ];
    $seededAt = time();
    $auctionIds = [];
    foreach ($lots as [$title, $cat, $image, $start, $reserve, $step, $hours, $description]) {
        $id = create_auction($connection, $users['mira'], ['title' => $title, 'description' => $description, 'item_condition' => 'Pre-owned · good condition', 'category_id' => $category[$cat], 'starting_price' => $start, 'reserve_price' => $reserve ?? '', 'minimum_increment' => $step, 'end_time' => gmdate('Y-m-d H:i:s', time() + $hours * 3600), 'delivery_methods' => [1,2]]);
        $statement = $connection->prepare('INSERT INTO item_images (item_id,image_path,display_order) SELECT item_id,?,0 FROM auctions WHERE auction_id = ?');
        $statement->execute(['assets/images/' . $image . '.jpg', $id]);
        $opening = gmdate('Y-m-d H:i:s', $seededAt - 2 * 86400 + count($auctionIds) * 3600);
        service_execute($connection, 'UPDATE auctions SET created_at = ?, start_time = ? WHERE auction_id = ?', [$opening, $opening, $id]);
        service_execute($connection, 'UPDATE items i JOIN auctions a ON a.item_id = i.item_id SET i.created_at = ?, i.updated_at = ? WHERE a.auction_id = ?', [$opening, $opening, $id]);
        $auctionIds[] = $id;
    }
    foreach ([[$auctionIds[0], $users['noah'], '120.00', 24], [$auctionIds[0], $users['ella'], '125.00', 22],
        [$auctionIds[1], $users['noah'], '24.00', 18], [$auctionIds[1], $users['ella'], '26.00', 17],
        [$auctionIds[3], $users['ella'], '45.00', 14]] as [$auction, $bidder, $amount, $hoursAgo]) {
        $bid = place_bid($connection, $auction, $bidder, $amount);
        $placedAt = gmdate('Y-m-d H:i:s', $seededAt - $hoursAgo * 3600);
        service_execute($connection, 'UPDATE bids SET placed_at = ? WHERE bid_id = ?', [$placedAt, $bid['bid_id']]);
        service_execute($connection, 'UPDATE notifications SET created_at = ? WHERE auction_id = ? AND event_key LIKE ?', [$placedAt, $auction, '%:bid:' . $bid['bid_id'] . ':%']);
    }
    toggle_watch($connection, $users['noah'], $auctionIds[0]);
    toggle_watch($connection, $users['noah'], $auctionIds[2]);
    service_execute($connection, 'UPDATE watches SET watched_at = ? WHERE user_id = ? AND auction_id IN (?, ?)', [gmdate('Y-m-d H:i:s', $seededAt - 26 * 3600), $users['noah'], $auctionIds[0], $auctionIds[2]]);
    save_preferences($connection, $users['noah'], [$category['Cameras'], $category['Records']]);
    service_execute($connection, 'INSERT INTO comments (auction_id, user_id, content, created_at) VALUES (?, ?, ?, ?), (?, ?, ?, ?)', [
        $auctionIds[0], $users['noah'], 'Is the lens included with the camera?', gmdate('Y-m-d H:i:s', $seededAt - 25 * 3600),
        $auctionIds[0], $users['mira'], 'Yes, the lens shown in the photograph is included.', gmdate('Y-m-d H:i:s', $seededAt - 24 * 3600 - 1800),
    ]);
    $statusSamples = seed_examples($connection, $users);
    seed_examples($connection, $users, true);
    $testAccount = seed_test_account($connection);
    seed_demo_profile($connection, (int)$testAccount['user_id'], $users);
    echo "Illustrative dataset created.\n";
    echo "Local demonstration accounts: mira, noah, ella\nPassword: {$password}\n";
    echo "All accounts can switch between buyer and seller.\n";
    echo 'Additional local test login: ' . $testAccount['username'] . ' or ' . $testAccount['email'] . "\nPassword: 123456\n";
    echo 'Additional illustrative sold, unsold and upcoming auction IDs: ' . implode(', ', array_column($statusSamples, 'auction_id')) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
