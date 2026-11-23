<?php
declare(strict_types=1);

function locale_code(): string
{
    return ($_COOKIE['cabinet_language'] ?? '') === 'zh-CN' ? 'zh-CN' : 'en';
}

function language_url(?string $language = null): string
{
    $query = $_GET;
    unset($query['page'], $query['lang']);
    if ($language !== null) {
        $query['lang'] = $language;
    }
    $page = is_string($_GET['page'] ?? null) ? $_GET['page'] : 'catalog';
    return url($page, $query);
}

function select_requested_language(): void
{
    $requested = $_GET['lang'] ?? null;
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET' || !in_array($requested, ['en', 'zh-CN'], true)) {
        return;
    }
    $directory = dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php');
    setcookie('cabinet_language', $requested, [
        'expires' => time() + 31536000,
        'path' => rtrim($directory, '/') . '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    header('Location: ' . language_url(), true, 303);
    exit;
}

function t(string $english, array $values = []): string
{
    static $translations;
    if (locale_code() === 'zh-CN') {
        $translations ??= (require __DIR__ . '/i18n_zh.php') + (require __DIR__ . '/i18n_catalog.php') + (require __DIR__ . '/i18n_handoff.php');
        $english = $translations[$english] ?? $english;
    }
    $placeholders = [];
    foreach ($values as $name => $value) {
        $placeholders['{' . $name . '}'] = (string)$value;
    }
    return strtr($english, $placeholders);
}

// Categories, condition labels and the supplied sample catalogue have bilingual display names.
function sample_labels(): array
{
    static $labels;
    return $labels ??= require __DIR__ . '/i18n_samples.php';
}

function ui_text(string $value): string
{
    return locale_code() === 'zh-CN' ? (sample_labels()[$value] ?? $value) : $value;
}

function profile_sample_labels(): array
{
    static $profiles;
    return $profiles ??= require __DIR__ . '/i18n_profile_samples.php';
}

function profile_sample_search_matches(string $query): array
{
    return array_filter(profile_sample_labels(), static function (array $sample) use ($query): bool {
        foreach (['en', 'zh-CN'] as $language) {
            foreach (['title', 'description'] as $field) {
                if (mb_stripos($sample[$language][$field], $query) !== false) {
                    return true;
                }
            }
        }
        return false;
    });
}

// Only bundled demonstration items use assets/images. Seller uploads are stored separately.
function item_text(array $item, string $field): string
{
    $text = (string)($item[$field] ?? '');
    if (in_array($field, ['title', 'auction_title', 'description'], true)) {
        $sample = profile_sample_labels()[(string)($item['title'] ?? $item['auction_title'] ?? '')] ?? null;
        $description = (string)($item['description'] ?? $item['auction_description'] ?? '');
        if ($sample
            && ($item['image_path'] ?? '') === 'assets/images/' . $sample['image'] . '.jpg'
            && preg_match('/\ACabinet profile sample v1:' . preg_quote($sample['key'], '/') . '; account [1-9][0-9]*\. Illustrative local history with sample photography; no real sale or payment\.\z/D', $description)) {
            return $sample[locale_code()][$field === 'description' ? 'description' : 'title'];
        }
    }
    return str_starts_with((string)($item['image_path'] ?? ''), 'assets/images/') ? ui_text($text) : $text;
}

function notification_text(array $notification): string
{
    $content = $notification['content'] ?? $notification['message'] ?? 'There is an update to this auction.';
    if (empty($notification['auction_title'])) {
        return t($content);
    }
    $title = $notification['auction_title'];
    // Rebuild only the known service templates. Titles and legacy/custom messages stay intact.
    $templates = [
        'bid_placed' => ['Your bid of {price} on {title} was accepted.'],
        'outbid' => ['The current bid on {title} is {price}.'],
        'seller_bid' => ['{title} has received a bid of {price}.'],
        'watch_update' => ['{title} is now at {price}.'],
        'won' => ['You won {title} for {price}. Choose a handover method in your collection.'],
        'sold' => ['{title} sold for {price}.'],
        'unsold' => ['{title}: No bids were received.', '{title}: The reserve price was not reached.'],
        'transaction' => [
            'The buyer recorded a demonstration payment for {title}. No real money was transferred.',
            'The seller marked {title} as dispatched or ready for collection.',
        ],
    ];
    foreach ($templates[$notification['type'] ?? ''] ?? [] as $template) {
        $pattern = strtr(preg_quote($template, '~'), [
            preg_quote('{title}', '~') => preg_quote($title, '~'),
            preg_quote('{price}', '~') => '(£[0-9]+(?:\.[0-9]{1,2})?)',
        ]);
        if (preg_match('~\A' . $pattern . '\z~u', $content, $parts)) {
            return t($template, ['title' => item_text($notification, 'auction_title'), 'price' => $parts[1] ?? '']);
        }
    }
    return t($content);
}

function client_labels(): array
{
    if (locale_code() === 'en') {
        return [];
    }
    $keys = [
        'Remove from watchlist', 'Add to watchlist', 'Days', 'Hours', 'Minutes', 'Seconds',
        'Show', 'Hide', 'Show password', 'Hide password', 'Opening now', 'Auction ended',
        'Enter your local date and time. It will be stored in UTC.',
        'Choose no more than five photographs.', 'Each photograph must be no larger than 5 MB.',
        'The passwords must match.', 'Winning bid', 'Highest bid', 'Starting price · no bids',
        'Current bid', 'Starting bid', 'Upcoming', 'Sold', 'Ended · unsold',
        '{count} bids. Current price {price}.', 'The maximum supported bid has been reached.',
        'Minimum next bid {price}.', 'Bidder', 'Placed', 'Bid',
        'Your bid could not be submitted. Please try again.',
        'Your watchlist could not be updated. Please try again.',
        'Reserve met.', 'Reserve not met.',
    ];
    return array_combine($keys, array_map('t', $keys));
}
