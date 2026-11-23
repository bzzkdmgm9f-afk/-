<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function import_database_file(PDO $connection, string $filename): void
{
    $sql = file_get_contents(dirname(__DIR__) . '/database/' . $filename);
    if ($sql === false || trim($sql) === '') {
        throw new RuntimeException('Missing database file: ' . $filename);
    }
    $connection->exec($sql);
    echo 'Applied ' . $filename . PHP_EOL;
}

function migrate_database(PDO $connection): void
{
    $tables = $connection->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $baseline = ['users', 'categories', 'items', 'item_images', 'auctions', 'bids', 'auction_results', 'watches', 'user_preferences', 'comments'];
    $extension = ['notification_settings', 'notifications', 'delivery_methods', 'auction_delivery', 'transactions', 'reviews', 'after_sales_requests'];
    if (array_diff($baseline, $tables)) {
        throw new RuntimeException('Import the original ten-table schema before upgrading.');
    }
    $present = array_intersect($extension, $tables);
    if (!$present) {
        import_database_file($connection, 'migration_001_extensions.sql');
    } elseif (count($present) !== count($extension)) {
        throw new RuntimeException('The original extension is partially installed. Back up the database and inspect migration 001 before continuing.');
    }
    // These additions can be replayed if installation was interrupted between tables.
    foreach (['migration_002_handoff.sql', 'migration_003_after_sales.sql'] as $filename) {
        import_database_file($connection, $filename);
    }
}
