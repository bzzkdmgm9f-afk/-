<?php
declare(strict_types=1);

require_once __DIR__ . '/spotlight.php';
require_once __DIR__ . '/listing_controls.php';
require_once __DIR__ . '/account_feed.php';
require_once __DIR__ . '/account_menu.php';

function view_fields(string $action, string $returnTo): void
{
    echo csrf_field();
    echo '<input type="hidden" name="action" value="' . e($action) . '">';
    echo '<input type="hidden" name="return_to" value="' . e($returnTo) . '">';
}

function view_old(string $name, mixed $default = ''): mixed
{
    return $GLOBALS['cabinet_old'][$name] ?? $default;
}

function view_category_key(string $name): string
{
    return match (strtolower(trim($name))) {
        'cameras', '相机' => 'cameras',
        'comics', '漫画' => 'comics',
        'computers', '电脑' => 'computers',
        'figures', '手办' => 'figures',
        'games', '游戏' => 'games',
        'phones', '手机' => 'phones',
        'records', '黑胶唱片' => 'records',
        'watches', '腕表' => 'watches',
        'cards', '卡片' => 'cards',
        default => 'collectibles',
    };
}

function view_category_name(string $name): void
{
    ?><span class="category-name"><svg class="category-glyph" width="20" height="20" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><use href="assets/category-icons.svg#<?= view_category_key($name) ?>"></use></svg><span><?= e(ui_text($name)) ?></span></span><?php
}

function view_date(?string $value, bool $short = false): string
{
    if (!$value) {
        return '—';
    }
    try {
        $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        return '<time datetime="' . e($date->format(DateTimeInterface::ATOM)) . '">' . e($date->format(locale_code() === 'zh-CN' ? ($short ? 'n月j日 H:i' : 'Y年n月j日 H:i') : ($short ? 'j M, H:i' : 'j M Y, H:i'))) . ' UTC</time>';
    } catch (Exception $exception) {
        return e($value);
    }
}

function view_image(array $auction, string $class = '', bool $eager = false): void
{
    $path = (string)($auction['image_path'] ?? '');
    if ($path !== '') {
        echo '<img class="' . e($class) . '" src="' . e($path) . '" alt="' . e(isset($auction['title']) ? item_text($auction, 'title') : t('Auction photograph')) . '" loading="' . ($eager ? 'eager' : 'lazy') . '">';
    } else {
        echo '<div class="no-image ' . e($class) . '">
<span>' . e(t('Photograph unavailable')) . '</span>
</div>';
    }
}

function view_status(string $status): string
{
    return t(match ($status) {
        'active' => 'Live auction',
        'scheduled' => 'Upcoming',
        'completed' => 'Sold',
        'unsold' => 'Ended · unsold',
        'cancelled' => dashboard_text('Withdrawn'),
        'pending' => 'Pending',
        'paid', 'paid_demo' => 'Demo payment recorded',
        'shipped' => 'Dispatched',
        'delivered', 'received' => 'Received',
        default => ucfirst(str_replace('_', ' ', $status)),
    });
}

function view_handover_status(array $transaction): string
{
    if (handoff_collection($transaction)) {
        if ($transaction['delivery_status'] === 'shipped') return t('Ready for collection');
        if (in_array($transaction['delivery_status'], ['delivered', 'received'], true)) return t('Collected');
    }
    return view_status($transaction['delivery_status'] ?? 'pending');
}

function view_heart_icon(): void
{
    ?>
    <svg class="heart-icon action-icon" viewBox="0 0 24 24" width="21" height="21" aria-hidden="true" focusable="false">
      <circle class="heart-ripple" cx="12" cy="12" r="9"/>
      <path class="heart-shape" d="M20.1 5.7a5 5 0 0 0-7.1 0l-1 1-1-1a5 5 0 0 0-7.1 7.1L12 21l8.1-8.2a5 5 0 0 0 0-7.1Z"/>
    </svg>
    <?php
}

function view_gavel_icon(): void
{
    ?>
    <svg class="gavel-icon action-icon" viewBox="0 0 24 24" width="21" height="21" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
      <g class="gavel-swing">
        <g transform="rotate(-38 12 10)">
          <path d="M7 4h9v5H7zM6 3v7M17 3v7M11.5 9v9M10 18h3"/>
        </g>
      </g>
      <path class="gavel-base" d="M3 21h12M5 18h8v3H5z"/>
    </svg>
    <?php
}

function view_watch_control(array $auction, ?array $user, string $returnTo, bool $compact = true): void
{
    $id = (int)$auction['auction_id'];
    $watched = !empty($auction['is_watched']);
    if (!$user) {
        if ($compact) { ?>
        <a class="watch-form watch-button" href="<?= e(url('login', ['next' => url('detail', ['id' => $id])])) ?>" aria-label="<?= e(t('Sign in to save this auction')) ?>"><?php view_heart_icon(); ?></a>
        <?php }
        return;
    }
    ?>
    <form method="post" action="index.php" class="<?= $compact ? 'watch-form' : 'watch-detail' ?>" data-watch-control data-watch-error="<?= e(t('Your watchlist could not be updated. Please try again.')) ?>">
      <?php view_fields('watch_state', $returnTo); ?>
      <input type="hidden" name="auction_id" value="<?= $id ?>">
      <input type="hidden" name="wanted" value="<?= $watched ? '0' : '1' ?>">
      <button type="submit" class="<?= $compact ? 'watch-button' : 'button button-outline button-full' ?><?= $watched ? ' is-watched' : '' ?>"
              aria-label="<?= e(t($watched ? 'Remove from watchlist' : 'Add to watchlist')) ?>" aria-pressed="<?= $watched ? 'true' : 'false' ?>">
        <?php view_heart_icon(); ?>
        <?php if (!$compact): ?><span data-watch-label><?= e(t($watched ? 'Remove from watchlist' : 'Add to watchlist')) ?></span><?php endif; ?>
      </button>
      <span class="watch-feedback" data-watch-feedback role="status" aria-live="polite" hidden></span>
    </form>
    <?php
}

function view_password_eye(string $field): void
{
    ?>
    <button type="button" class="password-toggle" data-password-toggle="<?= e($field) ?>" aria-controls="<?= e($field) ?>" aria-label="<?= e(t('Show password')) ?>" aria-pressed="false" hidden>
      <svg viewBox="0 0 24 24" aria-hidden="true" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
        <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/><path class="eye-slash" d="M3 3l18 18"/>
      </svg>
    </button>
    <?php
}

function view_auction_card(array $auction, ?array $user, ?string $returnTo = null): void
{
    $id = (int)$auction['auction_id'];
    $detail = url('detail', ['id' => $id]);
    $status = (string)($auction['status'] ?? 'active');
    ?>
<article class="auction-card" data-status="<?= e($status) ?>" data-lot="<?= $id ?>">
  <div class="card-image-wrap">
    <a href="<?= e($detail) ?>" class="card-image-link" tabindex="-1" aria-hidden="true">
      <?php view_image($auction, 'card-image'); ?>
    </a>
    <span class="auction-status <?= $status === 'active' ? 'is-live' : '' ?>" data-state="<?= e($status) ?>">
      <?= e(view_status($status)) ?>
    </span>
    <?php view_watch_control($auction, $user, $returnTo ?? language_url()); ?>
  </div>
  <div class="card-content">
    <p class="eyebrow">
      <?= e(ui_text($auction['category_name'] ?? 'Collectibles')) ?>
    </p>
    <h3>
      <a href="<?= e($detail) ?>">
        <?= e(item_text($auction, 'title')) ?>
      </a>
    </h3>
    <p class="card-condition">
      <?= e(ui_text($auction['item_condition'] ?? '')) ?>
    </p>
    <?php if (isset($auction['recommendation_reason'])): ?>
    <p class="field-help"><?= e(t($auction['recommendation_reason'])) ?></p>
    <?php endif; ?>
    <div class="card-price-line">
      <div>
        <span class="meta-label" data-lot-price-label>
          <?= e(lot_price_label($status, (int)($auction['bid_count'] ?? 0))) ?>
        </span>
        <strong data-lot-price>
          <?= e(money($auction['current_price'] ?? $auction['starting_price'])) ?>
        </strong>
      </div>
      <span class="card-bid-count">
        <span data-lot-bidders><?= e(lot_count_text((int)($auction['bidder_count'] ?? 0), 'bidder')) ?></span>
        <span data-lot-bids><?= e(lot_count_text((int)($auction['bid_count'] ?? 0), 'bid')) ?></span>
      </span>
    </div>
    <?php if ($status !== 'cancelled'): ?>
    <div class="card-end">
      <span>
        <?= e(t($status === 'scheduled' ? 'Opens' : ($status === 'active' ? 'Ends' : 'Closed'))) ?>
      </span>
      <?= view_date(($status === 'scheduled' ? $auction['start_time'] : $auction['end_time']) ?? null, true) ?>
    </div>
    <?php endif; ?>
  </div>
</article>
<?php
}

function view_empty(string $title, string $description, ?string $link = null, string $label = 'Explore auctions'): void
{
    ?>
<div class="empty-state">
  <div class="empty-mark" aria-hidden="true">
    ◇
  </div>
  <h2>
    <?= e(t($title)) ?>
  </h2>
  <p>
    <?= e(t($description)) ?>
  </p>
  <?php if ($link): ?>
  <a class="button button-primary" href="<?= e($link) ?>">
    <?= e(t($label)) ?>
  </a>
  <?php endif; ?>
</div>
<?php
}

function view_language_switch(): void
{
    ?>
  <a class="language-switch" href="<?= e(language_url(locale_code() === 'en' ? 'zh-CN' : 'en')) ?>" hreflang="<?= locale_code() === 'en' ? 'zh-CN' : 'en' ?>" lang="<?= locale_code() === 'en' ? 'zh-CN' : 'en' ?>" aria-label="<?= locale_code() === 'en' ? '切换到简体中文' : 'Switch to English' ?>">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><circle cx="12" cy="12" r="9"/><ellipse cx="12" cy="12" rx="4" ry="9"/><path d="M3 12h18"/></svg>
    <?= locale_code() === 'en' ? '中文' : 'English' ?>
  </a>
    <?php
}

function render_page(string $page, array $context): void
{
    $GLOBALS['cabinet_old'] = $context['old'] ?? [];
    $user = $context['user'] ?? null;
    $role = $context['role'] ?? 'buyer';
    $data = $context['data'] ?? [];
    $flash = $context['flash'] ?? null;
    $titles = [
        'catalog' => 'Collectible auctions',
        'detail' => $data['auction']['title'] ?? 'Auction',
        'login' => 'Welcome back',
        'register' => 'Create an account',
        'sell' => 'List a collectible',
        'relist' => 'Relist this collectible',
        'dashboard' => in_array($data['section'] ?? '', ['watches', 'preferences'], true)
            ? (locale_code() === 'zh-CN' ? '我的收藏' : 'My collection') : 'Your collection',
        'notifications' => 'Updates',
        'preferences' => 'Your interests',
        'transaction' => 'Order details',
        'setup' => 'Connect your database',
        'error' => 'Something went wrong',
        'not_found' => 'Page not found',
    ];
    $title = $titles[$page] ?? 'Cabinet';
    ?>
<!doctype html>
<html lang="<?= e(locale_code()) ?>">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0b100f">
    <meta name="description" content="<?= e(t('Discover, bid on and sell distinctive collectibles at Cabinet.')) ?>">
    <title>
      <?= e($page === 'detail' && isset($data['auction']) ? item_text($data['auction'], 'title') : t($title)) ?> · Cabinet
    </title>
    <link rel="stylesheet" href="assets/style.css?v=2">
    <link rel="stylesheet" href="assets/gallery.css?v=10">
    <link rel="stylesheet" href="assets/action-motion.css?v=1">
    <link rel="stylesheet" href="assets/spotlight.css?v=3">
    <link rel="stylesheet" href="assets/language.css?v=1">
    <link rel="stylesheet" href="assets/dashboard.css?v=4">
    <script src="assets/dashboard.js?v=2" defer></script>
    <?php if ($user): ?>
    <link rel="stylesheet" href="assets/updates.css?v=1">
    <script src="assets/updates.js?v=2" defer></script>
    <link rel="stylesheet" href="assets/account-menu.css?v=3">
    <script src="assets/account-menu.js?v=1" defer></script>
    <link rel="stylesheet" href="assets/account-feed.css?v=1">
    <script src="assets/account-feed.js?v=2" defer></script>
    <?php endif; ?>
    <?php if (in_array($page, ['sell', 'relist'], true)): ?>
    <link rel="stylesheet" href="assets/listing-controls.css?v=1">
    <script src="assets/listing-controls.js?v=1" defer></script>
    <?php endif; ?>
    <link rel="stylesheet" href="assets/category-icons.css?v=1">
    <link rel="stylesheet" href="assets/select-menu.css?v=1">
    <script src="assets/category-icons.js?v=1" defer></script>
    <script src="assets/select-menu.js?v=1" defer></script>
    <script src="assets/spotlight.js?v=3" defer id="catalog-client"
      data-endpoint="<?= e(url('catalog_activity')) ?>"
      data-bidder="<?= e(t('{count} bidder')) ?>" data-bidders="<?= e(t('{count} bidders')) ?>"
      data-bid="<?= e(t('{count} bid')) ?>" data-bids="<?= e(t('{count} bids')) ?>"
      data-current="<?= e(t('Current bid')) ?>" data-starting="<?= e(t('Starting bid')) ?>"></script>
    <script src="assets/atmosphere.js?v=4" defer></script>
    <script src="assets/app.js?v=10" defer></script>
    <?php if ($page === 'detail' && in_array($data['auction']['status'] ?? '', ['scheduled', 'active'], true)): ?>
    <script src="assets/clock-face.js?v=5" defer></script>
    <script src="assets/auction-clock.js?v=4" defer></script>
    <?php endif; ?>
  </head>
  <body class="page-<?= e($page) ?>" data-ui-labels="<?= e(json_encode(client_labels(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) ?>">
    <div class="atmosphere" aria-hidden="true"><canvas id="atmosphere-canvas"></canvas></div>
    <a class="skip-link" href="#main">
      <?= e(t('Skip to content')) ?>
    </a>
    <header class="site-header">
      <div class="nav-shell<?= $user ? ' has-account' : '' ?>">
        <a class="wordmark" href="<?= e(url()) ?>" aria-label="<?= e(t('Cabinet home')) ?>">
          <span class="brand-mark" aria-hidden="true">
            <i>
            </i>
            <i>
            </i>
            <i>
            </i>
            <i>
            </i>
          </span>
          Cabinet
          <span class="wordmark-dot">
            .
          </span>
        </a>
        <?php if (!$user) view_language_switch(); ?>
        <button class="menu-toggle" type="button" aria-controls="main-nav" aria-expanded="false">
          <span>
          </span>
          <span>
          </span>
          <span class="sr-only">
            <?= e(t('Open navigation')) ?>
          </span>
        </button>
        <nav id="main-nav" class="main-nav" aria-label="<?= e(t('Main navigation')) ?>">
          <?php
          $navStatus = $data['filters']['status'] ?? $data['auction']['status'] ?? 'active';
          $archiveCurrent = $navStatus === 'completed' ? match ($page) {
              'catalog' => 'page', 'detail' => 'location', default => null,
          } : null;
          $navItems = ['active' => 'Explore', 'scheduled' => 'Upcoming'];
          if (!$user) $navItems['completed'] = 'Sold archive';
          foreach ($navItems as $state => $label):
              $current = in_array($page, ['catalog', 'detail'], true)
                  && ($state === $navStatus || ($state === 'active' && !in_array($navStatus, ['scheduled','completed'], true)));
              $target = $state === 'active' ? url() : url('catalog', ['status' => $state]) . '#auctions';
          ?>
          <a class="nav-link <?= $current ? 'is-current' : '' ?>" href="<?= e($target) ?>" <?= $current ? 'aria-current="' . ($page === 'catalog' ? 'page' : 'location') . '"' : '' ?>>
            <?= e(t($label)) ?>
          </a>
          <?php endforeach; ?>
          <?php if ($user): ?>
          <div class="nav-account">
            <a class="button button-small button-primary" href="<?= e(url('sell')) ?>"><?= e(t('Sell an item')) ?></a>
          </div>
          <?php else: ?>
          <div class="nav-account">
            <a class="nav-link" href="<?= e(url('login')) ?>">
              <?= e(t('Sign in')) ?>
            </a>
            <a class="button button-small button-primary" href="<?= e(url('register')) ?>">
              <?= e(t('Join Cabinet')) ?>
            </a>
          </div>
          <?php endif; ?>
        </nav>
        <?php if ($user) view_language_switch(); ?>
        <?php if ($user) view_account_menu($user, $role, $context['activity'] ?? [], $archiveCurrent); ?>
      </div>
    </header>
    <main id="main" class="main-shell">
      <?php if ($flash && isset($flash['message'])): ?>
      <div class="notice notice-<?= e($flash['type'] ?? 'info') ?>" role="status">
        <span>
          <?= e(t($flash['message'])) ?>
        </span>
        <button type="button" data-dismiss-notice aria-label="<?= e(t('Dismiss message')) ?>">
          ×
        </button>
      </div>
      <?php endif; ?>
      <?php
      switch ($page) {
          case 'catalog': view_catalog($data, $user); break;
          case 'detail': view_detail($data, $user, $role); break;
          case 'login': view_auth(false); break;
          case 'register': view_auth(true); break;
          case 'sell': view_sell($data, $role); break;
          case 'relist': view_relist($data, $role); break;
          case 'dashboard': view_dashboard($data, $user); break;
          case 'notifications': view_notifications($data); break;
          case 'preferences': view_preferences($data); break;
          case 'transaction': view_transaction($data, $user); break;
          case 'setup': view_empty('Let’s connect your collection.', (string)($data['message'] ?? 'Set your database connection in config/database.php, then import the schema and migrations in the order listed in README.md.')); break;
          case 'not_found': view_empty('This page has moved on.', (string)($data['message'] ?? 'We could not find that auction or page.'), url()); break;
          default: view_empty('We couldn’t complete that request.', (string)($data['message'] ?? 'Please try again in a moment.'), url()); break;
      }
      ?>
    </main>
    <?php if (in_array($page, ['sell', 'relist'], true)) view_listing_calendar(); ?>
    <footer class="site-footer">
      <div>
        <a class="wordmark footer-wordmark" href="<?= e(url()) ?>">
          Cabinet.
        </a>
        <p>
          <?= e(t('A place for your next great find.')) ?>
        </p>
      </div>
      <div class="footer-links">
        <a href="<?= e(url()) ?>">
          <?= e(t('Explore auctions')) ?>
        </a>
        <a href="<?= e(url('sell')) ?>">
          <?= e(t('Sell a collectible')) ?>
        </a>
        <?php if ($user): ?>
        <a href="<?= e(url('dashboard', ['section' => 'preferences'])) ?>">
          <?= e(t('Your interests')) ?>
        </a>
        <?php endif; ?>
      </div>
      <p class="footer-note">
        <?= e(t('Prices in GBP. Times shown in your local time when available.')) ?>
      </p>
    </footer>
  </body>
</html>
<?php
}

function view_catalog(array $data, ?array $user): void
{
    $auctions = $data['auctions'] ?? [];
    $filters = $data['filters'] ?? [];
    $status = $filters['status'] ?? 'active';
    $query = trim((string)($filters['q'] ?? ''));
    $hasSearchFilters = $query !== '' || !empty($filters['category']);
    $hasFilters = $hasSearchFilters || $status !== 'active';
    $resultCount = count($auctions);
    $statusCount = isset($data['status_counts'][$status]) ? (int)$data['status_counts'][$status] : null;
    $showResultCount = $hasSearchFilters || $statusCount === null || $statusCount !== $resultCount;
    $categoryLabel = '';
    if (!empty($filters['category'])) {
        foreach ($data['categories'] ?? [] as $category) {
            if ((int)$category['category_id'] === (int)$filters['category']) {
                $categoryLabel = ui_text($category['category_name']);
                break;
            }
        }
        if ($categoryLabel === '') {
            $categoryLabel = t('Category') . ' #' . (int)$filters['category'];
        }
    }
    $featured = $hasFilters ? [] : spotlight_lots($auctions);
    ?>
<?php if ($featured): ?>
<section class="catalog-hero" aria-labelledby="hero-title">
  <div class="hero-copy">
    <p class="eyebrow">
      <?= e(t('An auction house for the curious')) ?>
    </p>
    <h1 id="hero-title">
      <?= e(t('Good things.')) ?>
      <br>
      <em>
        <?= e(t('New stories.')) ?>
      </em>
    </h1>
    <p class="hero-description">
      <?= e(t('The rare. The remembered. The unexpected.')) ?><br>
      <?= e(t('Discover objects you’ll want to hold on to.')) ?>
    </p>
    <a class="button button-light" href="#auctions">
      <?= e(t('Explore the auctions')) ?>
    </a>
    <div class="hero-caption">
      <span class="caption-line">
      </span>
      <p>
        <?= e(t('A considered collection.')) ?><br>
        <?= e(t('Yours to discover.')) ?>
      </p>
    </div>
  </div>
  <?php view_spotlight($featured); ?>
</section>
<?php else: ?>
<div class="page-heading">
  <p class="eyebrow">
    <?= e(t('The collectible marketplace')) ?>
  </p>
  <h1>
    <?= e(t('Find something worth keeping.')) ?>
  </h1>
</div>
<?php endif; ?>
<section id="auctions" class="auction-section" aria-labelledby="auction-title">
  <div class="section-heading">
    <div>
      <p class="eyebrow">
        <?= e(t('Explore the collection')) ?>
      </p>
      <h2 id="auction-title">
        <?= e(t($status === 'completed' ? 'Objects, collected.' : ($status === 'scheduled' ? 'Worth the wait.' : ($status === 'unsold' ? 'The auction archive.' : ($hasFilters ? 'Your discoveries' : 'On the block'))))) ?>
      </h2>
    </div>
    <?php if ($showResultCount): ?>
    <p class="results-count">
      <?= e(t($hasSearchFilters ? ($resultCount === 1 ? '{count} auction found' : '{count} auctions found') : ($resultCount === 1 ? '{count} auction to explore' : '{count} auctions to explore'), ['count' => $resultCount])) ?>
    </p>
    <?php endif; ?>
  </div>
  <nav class="auction-tabs" aria-label="<?= e(t('Auction status')) ?>">
    <?php foreach (['active'=>'Live now','scheduled'=>'Coming soon','completed'=>'Sold','unsold'=>'Unsold','all'=>'All auctions'] as $state=>$label): ?>
    <a href="<?= e(url('catalog', ['status'=>$state,'q'=>$filters['q']??'','category'=>$filters['category']??'','sort'=>$filters['sort']??'ending'])) ?>#auctions" class="auction-tab <?= $status===$state?'is-selected':'' ?>" <?= $status===$state?'aria-current="page"':'' ?>>
      <?php if ($state==='active'): ?><span class="live-indicator" aria-hidden="true"></span><?php endif; ?>
      <?= e(t($label)) ?><span class="tab-count"><?= (int)($data['status_counts'][$state]??0) ?></span>
    </a>
    <?php endforeach; ?>
  </nav>
  <form class="catalog-filters" method="get" action="index.php" role="search">
    <input type="hidden" name="page" value="catalog">
    <input type="hidden" name="status" value="<?= e($status) ?>">
    <label class="search-field">
      <span class="sr-only">
        <?= e(t('Search auctions')) ?>
      </span>
      <span class="search-icon" aria-hidden="true">
      </span>
      <input type="search" name="q" placeholder="<?= e(t('Search for your next find')) ?>" value="<?= e($filters['q'] ?? '') ?>">
    </label>
    <label class="select-field">
      <span class="sr-only">
        <?= e(t('Category')) ?>
      </span>
      <select name="category">
        <option value="" data-icon="all">
          <?= e(t('All categories')) ?>
        </option>
        <?php foreach ($data['categories'] ?? [] as $category): ?>
        <option value="<?= (int)$category['category_id'] ?>" data-icon="<?= view_category_key($category['category_name']) ?>" <?= (string)($filters['category'] ?? '') === (string)$category['category_id'] ? 'selected' : '' ?>>
          <?= e(ui_text($category['category_name'])) ?>
        </option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="select-field sort-field">
      <span class="sr-only">
        <?= e(t('Sort auctions')) ?>
      </span>
      <select name="sort">
        <?php foreach (['ending'=>'Ending soon','newest'=>'Recently listed','price_low'=>'Price · low to high','price_high'=>'Price · high to low','popular'=>'Most bids'] as $value=>$label): ?>
        <option value="<?= e($value) ?>" <?= ($filters['sort'] ?? 'ending') === $value ? 'selected' : '' ?>>
          <?= e(t($label)) ?>
        </option>
        <?php endforeach; ?>
      </select>
    </label>
    <button class="button button-primary" type="submit">
      <?= e(t('Search')) ?>
    </button>
  </form>
  <?php if ($hasSearchFilters): ?>
  <div class="filter-summary">
    <span>
      <?= e($query !== '' ? t('Search for “{query}”', ['query' => $query]) : $categoryLabel) ?>
      <?php if ($query !== '' && $categoryLabel !== ''): ?> · <?= e($categoryLabel) ?><?php endif; ?>
    </span>
    <a href="<?= e(url('catalog', ['status' => $status, 'sort' => $filters['sort'] ?? 'ending'])) ?>#auctions">
      <?= e(t('Clear filters')) ?>
    </a>
  </div>
  <?php endif; ?>
  <?php if ($auctions): ?>
  <div class="auction-grid">
    <?php foreach ($auctions as $auction) { view_auction_card($auction, $user); } ?>
  </div>
  <?php else: view_empty('No finds just yet.', 'Try another search or choose a different category.', url(), 'View all auctions'); endif; ?>
</section>
<?php if (!empty($data['recently_sold'])): ?>
<section class="auction-section archive-section" aria-labelledby="archive-title">
  <div class="section-heading">
    <div><p class="eyebrow"><?= e(t('A new chapter')) ?></p><h2 id="archive-title"><?= e(t('Recently collected')) ?></h2></div>
    <a class="subtle-link" href="<?= e(url('catalog',['status'=>'completed'])) ?>#auctions"><?= e(t('View the sold archive')) ?></a>
  </div>
  <div class="auction-grid">
    <?php foreach ($data['recently_sold'] as $auction) { view_auction_card($auction, $user); } ?>
  </div>
</section>
<?php endif; ?>
<?php if (!empty($data['recommendations'])): ?>
<section class="auction-section recommendation-section">
  <div class="section-heading">
    <div>
      <p class="eyebrow">
        <?= e(t('For your collection')) ?>
      </p>
      <h2>
        <?= e(t('You might also like')) ?>
      </h2>
    </div>
    <a class="subtle-link" href="<?= e(url('dashboard', ['section' => 'preferences'])) ?>">
      <?= e(t('Edit your interests')) ?>
    </a>
  </div>
  <div class="auction-grid">
    <?php foreach ($data['recommendations'] as $auction) { view_auction_card($auction, $user); } ?>
  </div>
</section>
<?php endif; ?>
<?php
}

function view_detail(array $data, ?array $user, string $role): void
{
    $auction = $data['auction'] ?? null;
    if (!$auction) { view_empty('Auction not found.', 'This collectible may no longer be available.', url()); return; }
    $id = (int)$auction['auction_id'];
    $detail = url('detail', ['id'=>$id]);
    $images = $auction['images'] ?? [];
    $isOwner = $user && (int)$user['user_id'] === (int)$auction['seller_id'];
    $isActive = ($auction['status'] ?? '') === 'active';
    $isScheduled = ($auction['status'] ?? '') === 'scheduled';
    $countdownTime = $isScheduled ? $auction['start_time'] : $auction['end_time'];
    $nextPence = (int)$auction['bid_count'] > 0
        ? price_to_pence((string)$auction['current_price']) + price_to_pence((string)$auction['minimum_increment'])
        : price_to_pence((string)$auction['starting_price']);
    $nextBid = $nextPence <= 9999999999 ? pence_to_price($nextPence) : null;
    ?>
<div class="breadcrumb">
  <a href="<?= e(url()) ?>">
    <?= e(t('Explore')) ?>
  </a>
  <span>
    /
  </span>
  <a href="<?= e(url('catalog', ['category'=>$auction['category_id']])) ?>">
    <?= e(ui_text($auction['category_name'])) ?>
  </a>
  <span>
    /
  </span>
  <span>
    <?= e(item_text($auction, 'title')) ?>
  </span>
</div>
<article class="detail-layout detail-layout--aligned" data-auction-id="<?= $id ?>" data-initial-status="<?= e($auction['status']) ?>" data-starting-price="<?= e($auction['starting_price']) ?>" data-minimum-increment="<?= e($auction['minimum_increment']) ?>" data-poll-url="<?= e(url('bid_status', ['id'=>$id])) ?>">
  <header class="detail-mast">
    <div class="detail-overline"><span class="auction-status inline-status <?= $isActive ? 'is-live' : '' ?>" data-auction-status data-state="<?= e($auction['status']) ?>"><?= e(view_status($auction['status'])) ?></span><span class="eyebrow"><?= e(ui_text($auction['category_name'])) ?></span></div>
    <h1>
      <?= e(item_text($auction, 'title')) ?>
    </h1>
    <p class="detail-condition">
      <?= e(ui_text($auction['item_condition'])) ?>
      <span>
        ·
      </span>
      <?= e(t('Sold by')) ?> <?= e($auction['seller_name']) ?>
    </p>
  </header>
  <div class="detail-gallery">
    <div class="main-photo">
      <?php view_image($auction, 'detail-image', true); ?>
    </div>
    <?php if (count($images)>1): ?>
    <div class="photo-thumbnails" aria-label="<?= e(t('Item photographs')) ?>">
      <?php foreach ($images as $number=>$image): ?>
      <button type="button" class="photo-thumbnail <?= $number===0?'is-active':'' ?>" data-image="<?= e($image['image_path']) ?>" aria-label="<?= e(t('View photograph {number}', ['number' => $number + 1])) ?>" aria-pressed="<?= $number===0?'true':'false' ?>">
        <img src="<?= e($image['image_path']) ?>" alt="" loading="lazy">
      </button>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

  </div>
  <div class="detail-purchase">
    <div class="bidding-panel">
      <div class="bid-heading">
        <?php if (!$isActive && !$isScheduled): ?>
        <div class="bidding-room-label"><?= e(t('Auction result')) ?></div>
        <?php endif; ?>
        <span class="meta-label">
          <span data-price-label><?= e(t($auction['status']==='completed' ? 'Winning bid' : ($auction['status']==='unsold' ? ((int)$auction['bid_count'] > 0 ? 'Highest bid' : 'Starting price · no bids') : ((int)$auction['bid_count']>0?'Current bid':'Starting bid')))) ?></span>
        </span>
        <strong class="detail-price" data-current-price aria-live="off">
          <?= e(money($auction['current_price'])) ?>
        </strong>
      </div>
      <p class="sr-only" data-bid-announcement aria-live="polite" aria-atomic="true"></p>
      <div class="auction-metrics">
        <span>
          <strong data-bid-count>
            <?= (int)$auction['bid_count'] ?>
          </strong>
          <?= e(t('bids')) ?>
        </span>
        <span>
          <strong data-bidder-count>
            <?= (int)$auction['bidder_count'] ?>
          </strong>
          <?= e(t('bidders')) ?>
        </span>
        <span>
          <strong data-watch-count><?= (int)$auction['watch_count'] ?></strong> <?= e(t('watching')) ?>
        </span>
      </div>
      <?php if ($auction['status'] !== 'cancelled'): ?>
      <div class="end-panel<?= $isScheduled || $isActive ? ' end-panel--clock' : '' ?>" id="auction-clock">
        <span><?= e(t($isScheduled ? 'Bidding opens' : ($isActive ? 'Auction ends' : 'Auction ended'))) ?><?php if ($isScheduled || $isActive): ?><small data-clock-days aria-hidden="true"></small><?php endif; ?></span>
        <?php if ($isScheduled || $isActive): ?>
        <div class="auction-clock" role="timer" aria-live="off" data-countdown="<?= e((new DateTimeImmutable($countdownTime,new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM)) ?>" data-countdown-kind="<?= $isScheduled ? 'opens' : 'ends' ?>" data-ended="false">
          <span data-clock-text></span>
          <canvas data-clock-face aria-hidden="true" hidden></canvas>
        </div>
        <?php endif; ?>
        <small><?= view_date($countdownTime) ?></small>
      </div>
      <?php endif; ?>
      <?php if ($isScheduled): ?>
      <p class="panel-message"><?= e(t('This auction is upcoming. Bidding opens')) ?> <?= view_date($auction['start_time']) ?>.</p>
      <?php elseif ($isActive && !$isOwner && $user && $role==='buyer'): ?>
      <form method="post" action="index.php" class="bid-form" autocomplete="off">
        <?php view_fields('bid',$detail); ?>
        <input type="hidden" name="auction_id" value="<?= $id ?>">
        <label for="bid-amount">
          <?= e(t('Your bid')) ?>
        </label>
        <div class="bid-stepper" data-bid-stepper data-increment="<?= e($auction['minimum_increment']) ?>">
          <button class="bid-step-button" type="button" data-bid-step="-1" aria-label="<?= e(dashboard_text('Decrease bid')) ?>" <?= $nextBid === null ? 'disabled' : '' ?>>−</button>
          <div class="currency-input"><span>£</span><input id="bid-amount" name="amount" type="number" autocomplete="off" inputmode="decimal" step="0.01" min="<?= e($nextBid ?? '0.01') ?>" max="99999999.99" required placeholder="<?= $nextBid !== null ? e($nextBid) : e(t('Maximum reached')) ?>" aria-describedby="bid-rule"></div>
          <button class="bid-step-button" type="button" data-bid-step="1" aria-label="<?= e(dashboard_text('Increase bid')) ?>" <?= $nextBid === null ? 'disabled' : '' ?>>+</button>
        </div>
        <button class="button button-primary button-full bid-submit" type="submit" <?= $nextBid === null ? 'disabled' : '' ?>>
          <?php view_gavel_icon(); ?>
          <span><?= e(t('Place bid')) ?></span>
        </button>
      </form>
      <?php elseif ($isActive && !$user): ?>
      <a class="button button-primary button-full" href="<?= e(url('login',['next'=>$detail])) ?>">
        <?= e(t('Sign in to bid')) ?>
      </a>
      <?php elseif ($isActive && $isOwner): ?>
      <p class="panel-message">
        <?= e(t('This is your listing. You can follow the bidding here.')) ?>
      </p>
      <a class="button button-outline button-full" href="<?= e(url('dashboard')) ?>">
        <?= e(t('View your listings')) ?>
      </a>
      <?php elseif ($isActive && $role==='seller'): ?>
      <form method="post" action="index.php">
        <?php view_fields('switch_role',$detail); ?>
        <input type="hidden" name="role" value="buyer">
        <button class="button button-primary button-full" type="submit">
          <?= e(t('Switch to buyer mode')) ?>
        </button>
      </form>
      <?php elseif ($auction['status'] === 'cancelled'): ?>
      <div class="auction-result"><strong><?= e(t('The seller withdrew this listing.')) ?></strong></div>
      <?php else: ?>
      <div class="auction-result">
        <strong>
          <?= e(t(($auction['status']??'')==='completed'?'This collectible has sold.':'This auction has ended.')) ?>
        </strong>
        <?php if (!empty($auction['result']['winner_name'])): ?>
        <p>
          <?= e(t('Winning bid {price} by {winner}.', ['price' => money($auction['result']['amount']), 'winner' => $auction['result']['winner_name']])) ?>
        </p>
        <?php else: ?>
        <p>
          <?= e(t('The item did not reach a successful sale.')) ?>
        </p>
        <?php endif; ?>
      </div>
      <?php endif; ?>
      <?php if (!empty($data['can_relist'])): ?>
      <a class="button button-primary button-full" href="<?= e(url('relist', ['id' => $id])) ?>"><?= e(t('Relist this collectible')) ?></a>
      <?php endif; ?>
      <?php if ($user): ?>
      <?php view_watch_control($auction, $user, $detail, false); ?>
      <?php endif; ?>
      <?php if (!empty($auction['transaction'])): ?>
      <a class="button button-primary button-full" href="<?= e(url('transaction',['id'=>$auction['transaction']['transaction_id']])) ?>">
        <?= e(t('View order')) ?>
      </a>
      <?php endif; ?>
      <?php if ($isActive || $isScheduled): ?>
      <p class="field-help" id="bid-rule">
        <span data-next-bid aria-live="polite"><?= e($nextBid !== null ? t('Minimum next bid {price}.', ['price' => money($nextBid)]) : t('The maximum supported bid has been reached.')) ?></span>
        <?= e(t('Raise an existing bid by at least {price}.', ['price' => money($auction['minimum_increment'])])) ?>
      </p>
      <?php endif; ?>
      <p class="bidding-note">
        <?php if ($auction['has_reserve']): ?>
          <span data-reserve-status><?= e(t($auction['reserve_reached'] ? 'Reserve met.' : 'Reserve not met.')) ?></span>
          <?php if ($isOwner): ?><?= e(t('Your reserve is {price}.', ['price' => money($auction['reserve_price'])])) ?><?php endif; ?>
        <?php else: ?>
          <?= e(t('This auction has no reserve price.')) ?>
        <?php endif; ?>
      </p>
    </div>
    <section class="delivery-note">
      <h2>
        <?= e(t('Getting it to you')) ?>
      </h2>
      <?php if (!empty($auction['delivery_methods'])): ?>
      <p>
        <?= e(implode(' · ', array_map('ui_text', array_column($auction['delivery_methods'], 'method_name')))) ?>
      </p>
      <?php else: ?>
      <p>
        <?= e(t('Arrange handover with the seller after the auction.')) ?>
      </p>
      <?php endif; ?>
    </section>
  </div>
  <section class="detail-story">
    <h2>
      <?= e(t('A closer look')) ?>
    </h2>
    <p class="preserve-lines">
      <?= e(item_text($auction, 'description')) ?>
    </p>
    <dl class="item-facts">
      <div>
        <dt>
          <?= e(t('Condition')) ?>
        </dt>
        <dd>
          <?= e(ui_text($auction['item_condition'])) ?>
        </dd>
      </div>
      <div>
        <dt>
          <?= e(t('Category')) ?>
        </dt>
        <dd>
          <?= e(ui_text($auction['category_name'])) ?>
        </dd>
      </div>
      <div>
        <dt>
          <?= e(t('Seller')) ?>
        </dt>
        <dd>
          <?= e($auction['seller_name']) ?>
          <?php if (isset($auction['seller_rating']) && $auction['seller_rating'] !== null): ?>
          <span class="seller-rating">
            <?= e(number_format((float)$auction['seller_rating'],1)) ?> / 5
          </span>
          <?php endif; ?>
        </dd>
      </div>
      <div>
        <dt>
          <?= e(t('Listed')) ?>
        </dt>
        <dd>
          <?= view_date($auction['created_at'] ?? $auction['start_time'], true) ?>
        </dd>
      </div>
      <div>
        <dt><?= e(t('Last updated')) ?></dt>
        <dd data-last-updated><?= view_date($auction['last_updated']) ?></dd>
      </div>
    </dl>
  </section>
</article>
<div class="detail-lower">
  <section class="content-panel bid-history">
    <div class="section-heading compact">
      <h2>
        <?= e(t('Bid history')) ?>
      </h2>
      <span class="muted">
        <?= e(t('Latest first')) ?>
      </span>
    </div>
    <div data-bid-history>
      <?php view_bid_history($auction['bids']??[]); ?>
    </div>
  </section>
  <section class="content-panel">
    <div class="section-heading compact">
      <h2>
        <?= e(t('Questions & comments')) ?>
      </h2>
      <span class="muted">
        <?= count($auction['comments']??[]) ?>
      </span>
    </div>
    <div class="comment-list">
      <?php if (empty($auction['comments'])): ?>
      <p class="muted">
        <?= e(t('Start a conversation about this collectible.')) ?>
      </p>
      <?php else: foreach ($auction['comments'] as $comment): ?>
      <article class="comment">
        <div>
          <strong>
            <?= e($comment['username']) ?>
          </strong>
          <?= view_date($comment['created_at'],true) ?>
        </div>
        <p class="preserve-lines">
          <?= e($comment['content']) ?>
        </p>
      </article>
      <?php endforeach; endif; ?>
    </div>
    <?php if ($user): ?>
    <form method="post" action="index.php" class="comment-form">
      <?php view_fields('comment',$detail); ?>
      <input type="hidden" name="auction_id" value="<?= $id ?>">
      <label for="comment">
        <?= e(t('Your comment')) ?>
      </label>
      <textarea id="comment" name="content" rows="3" maxlength="2000" required placeholder="<?= e(t('Ask about condition, provenance or delivery')) ?>"></textarea>
      <button class="button button-outline" type="submit">
        <?= e(t('Post comment')) ?>
      </button>
    </form>
    <?php else: ?>
    <a class="subtle-link" href="<?= e(url('login',['next'=>$detail])) ?>">
      <?= e(t('Sign in to leave a comment')) ?>
    </a>
    <?php endif; ?>
  </section>
</div>
<?php if (!empty($auction['recommendations'])): ?>
<section class="auction-section">
  <div class="section-heading">
    <h2>
      <?= e(t('More to discover')) ?>
    </h2>
  </div>
  <div class="auction-grid">
    <?php foreach ($auction['recommendations'] as $recommended) { view_auction_card($recommended,$user); } ?>
  </div>
</section>
<?php endif; ?>
<?php
}

function view_bid_history(array $bids): void
{
    if (!$bids) { echo '<p class="muted">' . e(t('No bids yet. You could be the first.')) . '</p>'; return; }
    echo '<div class="table-scroll">
<table class="data-table">
<thead>
<tr>
<th scope="col">' . e(t('Bidder')) . '</th>
<th scope="col">' . e(t('Placed')) . '</th>
<th scope="col" class="align-right">' . e(t('Bid')) . '</th>
</tr>
</thead>
<tbody>';
    foreach ($bids as $bid) { echo '<tr>
<td>'.e($bid['username']).'</td>
<td>'.view_date($bid['placed_at'],true).'</td>
<td class="align-right">
<strong>'.e(money($bid['amount'])).'</strong>
</td>
</tr>'; }
    echo '</tbody>
</table>
</div>';
}

function view_auth(bool $register): void
{
    ?>
<section class="auth-layout">
  <div class="auth-intro">
    <p class="eyebrow">
      <?= e(t('For the things that stay with you')) ?>
    </p>
    <h1>
      <?= e(t($register ? 'Make room for' : 'Your eye.')) ?><br>
      <em><?= e(t($register ? 'something special.' : 'Your collection.')) ?></em>
    </h1>
    <p>
      <?= e(t($register?'Join the collection. Discover auctions, keep an eye on your favourites and share objects of your own.':'Sign in to keep bidding, follow your favourite finds and manage your collection.')) ?>
    </p>
    <figure class="auth-collection">
      <img class="auth-object auth-object-watch" src="assets/images/watch.jpg" alt="<?= e(t('Vintage watch from the Cabinet sample collection')) ?>">
      <img class="auth-object auth-object-camera" src="assets/images/camera.jpg" alt="<?= e(t('Classic camera from the Cabinet sample collection')) ?>">
      <figcaption><span><?= e(t('Objects with a past.')) ?></span><strong><?= e(t('A collection with a future.')) ?></strong></figcaption>
    </figure>
  </div>
  <div class="auth-form-wrap">
    <p class="eyebrow">
      Cabinet
    </p>
    <h2>
      <?= e(t($register?'Create your account':'Welcome back')) ?>
    </h2>
    <p class="form-intro">
      <?= e(t($register?'One account for buying and selling.':'Your watchlist. Your next bid. Your Cabinet.')) ?>
    </p>
    <form method="post" action="index.php" class="stack-form" data-auth-form>
      <?php view_fields($register?'register':'login',url($register?'register':'login')); ?>
      <?php if ($register): ?>
      <div class="form-field">
        <label for="username">
          <?= e(t('Username')) ?>
        </label>
        <input id="username" name="username" autocomplete="username" required minlength="3" maxlength="50" placeholder="<?= e(t('Choose a username')) ?>" value="<?= e(view_old('username')) ?>">
      </div>
      <div class="form-field">
        <label for="email">
          <?= e(t('Email address')) ?>
        </label>
        <input id="email" type="email" name="email" autocomplete="email" required maxlength="255" placeholder="you@example.com" value="<?= e(view_old('email')) ?>">
      </div>
      <?php else: ?>
      <div class="form-field">
        <label for="identity">
          <?= e(t('Email or username')) ?>
        </label>
        <input id="identity" name="identity" autocomplete="username" required placeholder="<?= e(t('Email address or username')) ?>" value="<?= e(view_old('identity')) ?>">
      </div>
      <?php endif; ?>
      <div class="form-field">
        <label for="password">
          <?= e(t('Password')) ?>
        </label>
        <div class="password-field">
          <input id="password" type="password" name="password" autocomplete="<?= $register?'new-password':'current-password' ?>" required <?= $register?'minlength="10"':'' ?>>
          <?php view_password_eye('password'); ?>
        </div>
        <?php if ($register): ?>
        <p class="field-help">
          <?= e(t('Use at least 10 characters.')) ?>
        </p>
        <?php endif; ?>
      </div>
      <?php if ($register): ?>
      <div class="form-field">
        <label for="password-confirmation">
          <?= e(t('Confirm password')) ?>
        </label>
        <div class="password-field">
          <input id="password-confirmation" type="password" name="password_confirmation" autocomplete="new-password" required minlength="10">
          <?php view_password_eye('password-confirmation'); ?>
        </div>
      </div>
      <?php endif; ?>
      <button class="button button-primary button-full" type="submit">
        <?= e(t($register?'Create account':'Sign in')) ?>
      </button>
    </form>
    <p class="auth-alternative">
      <?= e(t($register?'Already a member?':'New to Cabinet?')) ?>
      <a href="<?= e(url($register?'login':'register')) ?>">
        <?= e(t($register?'Sign in':'Create an account')) ?>
      </a>
    </p>
  </div>
</section>
<?php
}

function view_sell(array $data, string $role): void
{
    ?>
<div class="page-heading">
  <p class="eyebrow">
    <?= e(t('Give it a new chapter')) ?>
  </p>
  <h1>
    <?= e(t('List your collectible.')) ?>
  </h1>
  <p>
    <?= e(t('The details make the difference. Tell buyers what makes your item worth keeping.')) ?>
  </p>
</div>
<?php if ($role!=='seller'): ?>
<div class="notice notice-info">
  <span>
    <?= e(t('Switch to seller mode to publish an auction.')) ?>
  </span>
  <form method="post" action="index.php">
    <?php view_fields('switch_role',url('sell')); ?>
    <input type="hidden" name="role" value="seller">
    <button type="submit" class="button button-small button-primary">
      <?= e(t('Switch to seller')) ?>
    </button>
  </form>
</div>
<?php endif; ?>
<form method="post" action="index.php" enctype="multipart/form-data" class="sell-layout">
  <?php view_fields('create_auction',url('sell')); ?>
  <div class="sell-main">
    <section class="content-panel">
      <p class="eyebrow">
        <?= e(t('The object')) ?>
      </p>
      <h2>
        <?= e(t('Tell its story')) ?>
      </h2>
      <div class="form-field">
        <label for="title">
          <?= e(t('Item title')) ?>
        </label>
        <input id="title" name="title" maxlength="200" required placeholder="<?= e(t('A clear, specific title for your collectible')) ?>" value="<?= e(view_old('title')) ?>">
      </div>
      <div class="form-row">
        <div class="form-field">
          <label for="category">
            <?= e(t('Category')) ?>
          </label>
          <div class="select-field">
            <select id="category" name="category_id" required>
              <option value="" data-icon="all">
                <?= e(t('Choose a category')) ?>
              </option>
              <?php foreach ($data['categories']??[] as $category): ?>
              <option value="<?= (int)$category['category_id'] ?>" data-icon="<?= view_category_key($category['category_name']) ?>" <?= (string)view_old('category_id')===(string)$category['category_id']?'selected':'' ?>>
                <?= e(ui_text($category['category_name'])) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="form-field">
          <label for="condition">
            <?= e(t('Condition')) ?>
          </label>
          <div class="select-field">
            <select id="condition" name="item_condition" required>
              <option value="">
                <?= e(t('Choose condition')) ?>
              </option>
              <?php foreach (['New / sealed','Like new','Excellent','Good','Fair'] as $condition): ?>
              <option value="<?= e($condition) ?>" <?= view_old('item_condition')===$condition?'selected':'' ?>>
                <?= e(ui_text($condition)) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>
      <div class="form-field">
        <label for="description">
          <?= e(t('Description')) ?>
        </label>
        <textarea id="description" name="description" rows="7" required maxlength="10000" placeholder="<?= e(t('Describe the item, its condition, known flaws and any provenance.')) ?>"><?= e(view_old('description')) ?></textarea>
      </div>
      <div class="form-field">
        <label for="images">
          <?= e(t('Photographs')) ?>
        </label>
        <div class="upload-box">
          <span class="upload-symbol" aria-hidden="true">
            +
          </span>
          <strong>
            <?= e(t('Show the details')) ?>
          </strong>
          <span>
            <?= e(t('Choose up to 5 JPEG, PNG or WebP images, up to 5 MB each.')) ?>
          </span>
          <input id="images" type="file" name="images[]" accept="image/jpeg,image/png,image/webp" multiple data-file-input>
        </div>
        <div class="upload-preview" data-upload-preview>
        </div>
      </div>
    </section>
  </div>
  <aside class="sell-sidebar">
    <section class="content-panel">
      <p class="eyebrow">
        <?= e(t('The auction')) ?>
      </p>
      <h2>
        <?= e(t('Set the terms')) ?>
      </h2>
      <div class="form-field">
        <label for="starting-price">
          <?= e(t('Starting price (£)')) ?>
        </label>
        <?php view_price_input('starting-price', 'starting_price', (string)view_old('starting_price'), '10.00'); ?>
      </div>
      <div class="form-field">
        <label for="reserve-price">
          <?= e(t('Reserve price (£)')) ?>
          <span class="optional">
            <?= e(t('Optional')) ?>
          </span>
        </label>
        <?php view_price_input('reserve-price', 'reserve_price', (string)view_old('reserve_price'), t('Minimum price you will accept')); ?>
        <p class="field-help">
          <?= e(t('Leave blank for no reserve.')) ?>
        </p>
      </div>
      <div class="form-field">
        <label for="minimum-increment">
          <?= e(t('Minimum bid increase (£)')) ?>
        </label>
        <?php view_price_input('minimum-increment', 'minimum_increment', (string)view_old('minimum_increment', '1.00'), ''); ?>
      </div>
      <div class="form-field">
        <label for="end-time">
          <?= e(t('Ends at')) ?>
        </label>
        <input id="end-time" name="end_time" type="datetime-local" data-listing-date autocomplete="off" required value="<?= e(view_old('end_time')) ?>">
        <input type="hidden" name="timezone_offset" value="0" data-timezone-offset>
        <p class="field-help" data-timezone-help>
          <?= e(t('Enter the closing date and time in UTC.')) ?>
        </p>
      </div>
      <fieldset class="form-field">
        <legend>
          <?= e(t('Handover options')) ?>
        </legend>
        <?php foreach ($data['delivery_methods']??[] as $method): ?>
        <label class="checkbox-row">
          <input type="checkbox" name="delivery_methods[]" value="<?= (int)$method['delivery_method_id'] ?>" <?= in_array((string)$method['delivery_method_id'],array_map('strval',(array)view_old('delivery_methods',[])),true)?'checked':'' ?>>
          <span>
            <?= e(ui_text($method['method_name'])) ?>
          </span>
        </label>
        <?php endforeach; ?>
      </fieldset>
      <button class="button button-primary button-full" type="submit" <?= $role!=='seller'?'disabled':'' ?>>
        <?= e(t('Publish auction')) ?>
      </button>
      <div class="price-guidance">
        <h3><?= e(t('Recent prices by category')) ?></h3>
        <?php $priceGuidance = array_filter($data['price_guidance'] ?? [], fn(array $row): bool => (int)$row['sold_count'] > 0); ?>
        <?php if ($priceGuidance): ?>
          <?php foreach ($priceGuidance as $summary): ?>
            <p><strong><?= e(ui_text($summary['category_name'])) ?></strong><br>
            <?= e(money($summary['min_price'])) ?> – <?= e(money($summary['max_price'])) ?>
            <?= e(t('Across {count} completed sales.', ['count' => $summary['sold_count']])) ?></p>
          <?php endforeach; ?>
        <?php else: ?>
          <p><?= e(t('Completed sales will appear here as a reference for your starting price.')) ?></p>
        <?php endif; ?>
      </div>
    </section>
  </aside>
</form>
<?php
}

function view_dashboard(array $data, ?array $user): void
{
    view_dashboard_content($data, $user);
}

function view_activity_feed(array $notifications, ?int $totalUnread = null): void
{
    $hasUnread = ($totalUnread ?? count(array_filter($notifications, static fn(array $notification): bool => empty($notification['is_read'])))) > 0;
    ?>
    <div class="section-heading compact">
      <h2>
        <?= e(t('Recent activity')) ?>
      </h2>
      <?php if ($hasUnread): ?>
      <form method="post" action="index.php">
        <?php view_fields('mark_read',url('notifications')); ?>
        <button type="submit" class="text-button">
          <?= e(t('Mark all as read')) ?>
        </button>
      </form>
      <?php endif; ?>
    </div>
    <?php if (empty($notifications)): ?>
    <p class="muted">
      <?= e(t('You’re all caught up. Auction updates will appear here.')) ?>
    </p>
    <?php else: ?>
    <div class="notification-list">
      <?php foreach ($notifications as $notification): ?>
      <article class="notification <?= !empty($notification['is_read'])?'':'is-unread' ?>">
        <div class="notification-dot" aria-hidden="true">
        </div>
        <div>
          <?php if (empty($notification['is_read'])): ?><span class="sr-only"><?= e(t('Unread')) ?> </span><?php endif; ?>
          <strong>
            <?= e(t($notification['subject']??$notification['title']??ucfirst(str_replace('_',' ',$notification['type']??'Auction update')))) ?>
          </strong>
          <p>
            <?= e(notification_text($notification)) ?>
          </p>
          <div class="notification-meta">
            <?= view_date($notification['created_at'],true) ?>
            <form method="post" action="index.php">
              <?php view_fields('open_notification', url('notifications')); ?>
              <input type="hidden" name="notification_id" value="<?= (int)$notification['notification_id'] ?>">
              <button type="submit" class="text-button"><?= e(t($notification['type'] === 'order_message' ? 'View order' : 'View auction')) ?></button>
            </form>
          </div>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
<?php
}

function view_notifications(array $data): void
{
    $settings=$data['settings']??[];
    ?>
<div class="page-heading">
  <p class="eyebrow">
    <?= e(t('Stay in the loop')) ?>
  </p>
  <h1>
    <?= e(t('Your updates.')) ?>
  </h1>
  <p>
    <?= e(t('New bids, favourite finds and the auctions that matter to you.')) ?>
  </p>
</div>
<div class="settings-layout">
  <section class="content-panel" data-activity-feed>
    <?php view_activity_feed($data['notifications'] ?? [], $data['unread'] ?? null); ?>
  </section>
  <aside>
    <section class="content-panel">
      <p class="eyebrow">
        <?= e(t('Make it yours')) ?>
      </p>
      <h2>
        <?= e(t('Notification preferences')) ?>
      </h2>
      <form method="post" action="index.php" class="stack-form">
        <?php view_fields('settings',url('notifications')); ?>
        <label class="checkbox-row">
          <input type="checkbox" name="outbid_enabled" value="1" <?= !isset($settings['outbid_enabled'])||$settings['outbid_enabled']?'checked':'' ?>>
          <span>
            <?= e(t('When someone outbids me')) ?>
          </span>
        </label>
        <label class="checkbox-row">
          <input type="checkbox" name="watch_enabled" value="1" <?= !isset($settings['watch_enabled'])||$settings['watch_enabled']?'checked':'' ?>>
          <span>
            <?= e(t('New bids on watched auctions')) ?>
          </span>
        </label>
        <div class="form-field">
          <label for="seller-frequency">
            <?= e(t('Updates on my listings')) ?>
          </label>
          <div class="select-field">
            <select id="seller-frequency" name="seller_frequency">
              <?php foreach (['immediate'=>'As they happen','hourly'=>'Hourly digest','daily'=>'Daily digest','off'=>'Turn off bid updates'] as $value=>$label): ?>
              <option value="<?= e($value) ?>" <?= ($settings['seller_frequency']??'immediate')===$value?'selected':'' ?>>
                <?= e(t($label)) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <button class="button button-primary" type="submit">
          <?= e(t('Save preferences')) ?>
        </button>
      </form>
      <p class="field-help">
        <?= e(t('Email delivery depends on the server’s configured mail transport. Updates remain available here.')) ?>
      </p>
    </section>
  </aside>
</div>
<?php
}

function view_preferences(array $data): void
{
    $selected=array_map('intval',$data['selected']??[]);
    ?>
<section class="preferences-page">
  <div class="page-heading">
    <p class="eyebrow">
      <?= e(t('For your collection')) ?>
    </p>
    <h1>
      <?= e(t('What catches your eye?')) ?>
    </h1>
    <p>
      <?= e(t('Choose your interests to help shape what you discover.')) ?>
    </p>
  </div>
  <form method="post" action="index.php">
    <?php view_fields('preferences',url('catalog')); ?>
    <fieldset class="interest-grid">
      <legend class="sr-only">
        <?= e(t('Choose collectible categories')) ?>
      </legend>
      <?php foreach ($data['categories']??[] as $category): ?>
      <label class="interest-option">
        <input type="checkbox" name="category_ids[]" value="<?= (int)$category['category_id'] ?>" <?= in_array((int)$category['category_id'],$selected,true)?'checked':'' ?>>
        <span>
          <strong><?php view_category_name($category['category_name']); ?></strong>
          <i aria-hidden="true">
            ✓
          </i>
        </span>
      </label>
      <?php endforeach; ?>
    </fieldset>
    <div class="preferences-actions">
      <button class="button button-primary" type="submit">
        <?= e(t('Save my interests')) ?>
      </button>
      <a href="<?= e(url()) ?>">
        <?= e(t('Back to auctions')) ?>
      </a>
    </div>
  </form>
</section>
<?php
}

function view_transaction(array $data, ?array $user): void
{
    $transaction=$data['transaction']??null;
    if (!$transaction) { view_empty('Order not found.', 'Please open an order from your cabinet.',url('dashboard'),'Go to your cabinet'); return; }
    $id=(int)$transaction['transaction_id'];
    $returnTo=url('transaction',['id'=>$id]);
    $isBuyer=$user && (int)$user['user_id']===(int)$transaction['buyer_id'];
    $isSeller=$user && (int)$user['user_id']===(int)$transaction['seller_id'];
    $paid=($transaction['payment_status']??'pending')==='paid_demo';
    $delivered=in_array($transaction['delivery_status']??'pending',['delivered','received'],true);
    ?>
<div class="breadcrumb">
  <a href="<?= e(url('dashboard')) ?>">
    <?= e(t('Your cabinet')) ?>
  </a>
  <span>
    /
  </span>
  <span>
    <?= e(t('Order #')) ?><?= $id ?>
  </span>
</div>
<div class="page-heading">
  <p class="eyebrow">
    <?= e(t('A new chapter')) ?>
  </p>
  <h1>
    <?= e(isset($transaction['title']) ? item_text($transaction, 'title') : t('Your collectible')) ?>
  </h1>
  <p>
    <?= e(t('Order #')) ?><?= $id ?> · <?= e(t($isBuyer?'You bought this collectible.':'You sold this collectible.')) ?>
  </p>
</div>
<div class="transaction-layout">
  <div>
    <section class="content-panel">
      <div class="section-heading compact">
        <h2>
          <?= e(t('Order summary')) ?>
        </h2>
        <?php if (isset($transaction['amount'])||isset($transaction['final_price'])): ?>
        <strong class="order-total">
          <?= e(money($transaction['amount']??$transaction['final_price'])) ?>
        </strong>
        <?php endif; ?>
      </div>
      <dl class="item-facts">
        <div>
          <dt>
            <?= e(t('Buyer')) ?>
          </dt>
          <dd>
            <?= e($transaction['buyer_name']??'Buyer') ?>
          </dd>
        </div>
        <div>
          <dt>
            <?= e(t('Seller')) ?>
          </dt>
          <dd>
            <?= e($transaction['seller_name']??'Seller') ?>
          </dd>
        </div>
        <div>
          <dt>
            <?= e(t('Payment')) ?>
          </dt>
          <dd>
            <?= e(view_status($transaction['payment_status'])) ?>
          </dd>
        </div>
        <div>
          <dt>
            <?= e(t('Handover')) ?>
          </dt>
          <dd>
            <?= e(view_handover_status($transaction)) ?>
          </dd>
        </div>
        <div>
          <dt>
            <?= e(t('Delivery method')) ?>
          </dt>
          <dd>
            <?= e(isset($transaction['method_name']) ? ui_text($transaction['method_name']) : t('Not selected')) ?>
          </dd>
        </div>
      </dl>
      <?php if (!empty($transaction['auction_id'])): ?>
      <a class="subtle-link" href="<?= e(url('detail',['id'=>$transaction['auction_id']])) ?>">
        <?= e(t('View original auction')) ?>
      </a>
      <?php endif; ?>
    </section>
    <?php if ($isBuyer && !$paid): ?>
    <section class="content-panel">
      <h2>
        <?= e(t('Arrange handover')) ?>
      </h2>
      <form method="post" action="index.php" class="stack-form">
        <?php view_fields('delivery',$returnTo); ?>
        <input type="hidden" name="transaction_id" value="<?= $id ?>">
        <div class="form-field">
          <label for="delivery-method">
            <?= e(t('Choose an available method')) ?>
          </label>
          <div class="select-field">
            <select id="delivery-method" name="delivery_method_id" required>
              <option value="">
                <?= e(t('Select a method')) ?>
              </option>
              <?php foreach ($data['delivery_methods']??[] as $method): ?>
              <option value="<?= (int)$method['delivery_method_id'] ?>" <?= (int)($transaction['delivery_method_id']??0)===(int)$method['delivery_method_id']?'selected':'' ?>>
                <?= e(ui_text($method['method_name'])) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <button class="button button-outline" type="submit">
          <?= e(t('Save handover method')) ?>
        </button>
      </form>
    </section>
    <?php endif; ?>
    <?php view_handoff($transaction, $isBuyer); ?>
    <section class="content-panel">
      <h2>
        <?= e(t('Reviews')) ?>
      </h2>
      <?php if (empty($data['reviews'])): ?>
      <p class="muted">
        <?= e(t('Reviews appear after the handover is complete.')) ?>
      </p>
      <?php else: foreach ($data['reviews'] as $review): ?>
      <article class="comment">
        <div>
          <strong>
            <?= e($review['reviewer_name']??$review['username']??'Collector') ?>
          </strong>
          <span class="review-score">
            <?= (int)$review['rating'] ?> / 5
          </span>
        </div>
        <p>
          <?= e($review['comment']??'') ?>
        </p>
        <?= view_date($review['created_at']??null,true) ?>
      </article>
      <?php endforeach; endif; ?>
      <?php $hasReviewed=false;foreach($data['reviews']??[] as $review){if((int)($review['reviewer_id']??0)===(int)($user['user_id']??0)){$hasReviewed=true;}} ?>
      <?php if ($delivered&&!$hasReviewed): ?>
      <form method="post" action="index.php" class="stack-form review-form">
        <?php view_fields('review',$returnTo); ?>
        <input type="hidden" name="transaction_id" value="<?= $id ?>">
        <div class="form-field">
          <label for="rating">
            <?= e(t('Rate your experience')) ?>
          </label>
          <div class="select-field">
            <select id="rating" name="rating" required>
              <option value="">
                <?= e(t('Choose a rating')) ?>
              </option>
              <?php for($rating=5;$rating>=1;$rating--): ?>
              <option value="<?= $rating ?>">
                <?= $rating ?> / 5
              </option>
              <?php endfor; ?>
            </select>
          </div>
        </div>
        <div class="form-field">
          <label for="review-comment">
            <?= e(t('Your review')) ?>
          </label>
          <textarea id="review-comment" name="comment" rows="3" maxlength="2000" required></textarea>
        </div>
        <button class="button button-outline" type="submit">
          <?= e(t('Publish review')) ?>
        </button>
      </form>
      <?php endif; ?>
    </section>
  </div>
  <aside>
    <section class="content-panel order-progress">
      <p class="eyebrow">
        <?= e(t('Next steps')) ?>
      </p>
      <h2>
        <?= e(t($delivered?'Enjoy your find.':($paid?'Arrange the handover.':'Ready for handover.'))) ?>
      </h2>
      <ol class="progress-list">
        <li class="is-complete">
          <strong>
            <?= e(t('Auction won')) ?>
          </strong>
          <span>
            <?= e(t('The winning bid is confirmed.')) ?>
          </span>
        </li>
        <li class="<?= $paid?'is-complete':'' ?>">
          <strong>
            <?= e(t('Payment recorded')) ?>
          </strong>
          <span>
            <?= e(t('Record the demonstration payment.')) ?>
          </span>
        </li>
        <li class="<?= in_array($transaction['delivery_status'],['shipped','delivered','received'],true)?'is-complete':'' ?>">
          <strong>
            <?= e(t(handoff_collection($transaction) ? 'Ready for collection' : 'Dispatched or handed over')) ?>
          </strong>
          <span>
            <?= e(t(handoff_collection($transaction) ? 'The seller confirms the pickup arrangements.' : 'The seller confirms dispatch.')) ?>
          </span>
        </li>
        <li class="<?= $delivered?'is-complete':'' ?>">
          <strong>
            <?= e(t(handoff_collection($transaction) ? 'Collected' : 'Received')) ?>
          </strong>
          <span>
            <?= e(t('The buyer confirms receipt.')) ?>
          </span>
        </li>
      </ol>
      <?php if ($isBuyer&&!$paid): ?>
      <form method="post" action="index.php">
        <?php view_fields('transaction',$returnTo); ?>
        <input type="hidden" name="transaction_id" value="<?= $id ?>">
        <input type="hidden" name="operation" value="pay_demo">
        <button class="button button-primary button-full" type="submit" <?= empty($transaction['delivery_method_id'])?'disabled':'' ?>>
          <?= e(t('Record demo payment')) ?>
        </button>
      </form>
      <p class="field-help">
        <?= e(t('Demonstration only. This records an order status and does not transfer money.')) ?>
      </p>
      <?php endif; ?>
      <?php if ($isSeller&&$paid&&!in_array($transaction['delivery_status'],['shipped','delivered','received'],true)): ?>
      <?php view_handoff_dispatch($transaction); ?>
      <?php endif; ?>
      <?php if ($isBuyer&&$transaction['delivery_status']==='shipped'): ?>
      <form method="post" action="index.php">
        <?php view_fields('transaction',$returnTo); ?>
        <input type="hidden" name="transaction_id" value="<?= $id ?>">
        <input type="hidden" name="operation" value="receive">
        <button class="button button-primary button-full" type="submit">
          <?= e(t(handoff_collection($transaction) ? 'Confirm collection' : 'Confirm received')) ?>
        </button>
      </form>
      <?php endif; ?>
    </section>
    <?php view_after_sales($transaction, $data['requests'] ?? [], (int)$user['user_id']); ?>
  </aside>
</div>
<?php
}
