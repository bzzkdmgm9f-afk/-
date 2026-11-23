<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/services.php';

$options = getopt('', ['once', 'interval:']);
$once = array_key_exists('once', $options);
$interval = filter_var($options['interval'] ?? '5', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 3600]]);
if ($interval === false) {
    fwrite(STDERR, "Choose a maintenance interval from 1 to 3600 seconds.\n");
    exit(1);
}

putenv('MAIL_TRANSPORT=file');
$runtime = getenv('AUCTION_RUNTIME_DIR') ?: dirname(__DIR__) . '/runtime';
$running = true;
if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    $stop = static function () use (&$running): void {
        $running = false;
    };
    pcntl_signal(SIGTERM, $stop);
    pcntl_signal(SIGINT, $stop);
    pcntl_signal(SIGHUP, $stop);
}

do {
    try {
        $connection = db();
        $closed = close_due_auctions($connection);
        $messages = send_notifications($connection, $runtime . '/outbox');
        if ($once || $closed > 0 || $messages > 0) {
            echo gmdate('Y-m-d H:i:s') . " UTC — closed $closed auctions; saved $messages local email previews.\n";
        }
    } catch (Throwable $error) {
        fwrite(STDERR, 'Local maintenance: ' . $error->getMessage() . PHP_EOL);
        if ($once) {
            exit(1);
        }
    }
    if ($once || !$running) {
        break;
    }
    sleep($interval);
} while ($running);
