<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/services.php';
try {
    $runtime = getenv('AUCTION_RUNTIME_DIR') ?: dirname(__DIR__) . '/runtime';
    $count = send_notifications(db(), $runtime . '/outbox');
    echo 'Notifications processed: ' . $count . PHP_EOL;
    echo (getenv('MAIL_TRANSPORT') === 'mail' ? 'Configured mail transport was used.' : 'Email previews were saved in the private runtime outbox. No external email was sent.') . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
