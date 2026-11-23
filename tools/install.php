<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/database_steps.php';
try {
    $connection = db();
    if ($connection->query('SHOW TABLES')->fetchColumn() !== false) {
        throw new RuntimeException('Installation requires a new, empty database. Existing tables have been left untouched.');
    }
    import_database_file($connection, 'schema.sql');
    migrate_database($connection);
    echo "Database installed. Run php tools/seed_demo.php --demo to add sample records, or import your own data.\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
