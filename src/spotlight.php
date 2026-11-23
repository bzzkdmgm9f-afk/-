<?php
declare(strict_types=1);

function spotlight_lots(array $auctions): array
{
    // Start with a photograph from each collection; use available lots when a collection is absent.
    $photographed = array_filter($auctions, static fn(array $lot): bool => !empty($lot['image_path']));
    $pool = array_values($photographed ?: $auctions);
    $order = array_flip(['Cameras', 'Phones', 'Computers', 'Watches', 'Records']);
    usort($pool, static function (array $left, array $right) use ($order): int {
        return [$order[$left['category_name']] ?? 100, (int)$left['auction_id']]
            <=> [$order[$right['category_name']] ?? 100, (int)$right['auction_id']];
    });
    $first = [];
    $remaining = [];
    foreach ($pool as $lot) {
        $category = (string)$lot['category_name'];
        if (isset($first[$category])) {
            $remaining[] = $lot;
        } else {
            $first[$category] = $lot;
        }
    }
    return array_slice(array_merge(array_values($first), $remaining), 0, 5);
}

function lot_price_label(string $status, int $bids): string
{
    return match ($status) {
        'completed' => t('Winning bid'),
        'unsold' => $bids ? t('Highest bid') : t('Starting price · no bids'),
        default => $bids ? t('Current bid') : t('Starting bid'),
    };
}

function lot_count_text(int $count, string $metric): string
{
    if ($metric === 'bidder') {
        return t($count === 1 ? '{count} bidder' : '{count} bidders', ['count' => $count]);
    }
    return t($count === 1 ? '{count} bid' : '{count} bids', ['count' => $count]);
}

function view_spotlight(array $lots): void
{
    $count = count($lots);
    ?>
    <section class="hero-object spotlight" data-spotlight aria-label="<?= e(t('Featured auctions')) ?>">
      <div class="spotlight-stage">
        <?php foreach ($lots as $index => $lot): ?>
        <a class="spotlight-slide" href="<?= e(url('detail', ['id' => $lot['auction_id']])) ?>"
           data-lot="<?= (int)$lot['auction_id'] ?>" data-status="<?= e($lot['status']) ?>"
           data-slide-title="<?= e(item_text($lot, 'title')) ?>" <?= $index ? 'hidden inert' : '' ?>>
          <?php view_image($lot, 'hero-image', $index === 0); ?>
          <div class="hero-object-caption">
            <div class="spotlight-identity">
              <span class="eyebrow"><?= e(ui_text($lot['category_name'])) ?></span>
              <h2><?= e(item_text($lot, 'title')) ?></h2>
            </div>
            <div class="hero-object-price">
              <span data-lot-price-label><?= e(lot_price_label($lot['status'], (int)$lot['bid_count'])) ?></span>
              <strong data-lot-price><?= e(money($lot['current_price'])) ?></strong>
              <span class="spotlight-bidders" data-lot-bidders><?= e(lot_count_text((int)$lot['bidder_count'], 'bidder')) ?></span>
            </div>
          </div>
        </a>
        <?php endforeach; ?>
        <span class="hero-feature-label"><span class="live-indicator" aria-hidden="true"></span><?= e(t('Live auction')) ?></span>
        <?php if ($count > 1): ?>
        <div class="spotlight-steps" role="group" aria-label="<?= e(t('Choose a featured auction')) ?>" hidden>
          <?php foreach ($lots as $index => $lot): ?>
          <button type="button" data-slide-select="<?= $index ?>" aria-label="<?= e(t('Show {title}', ['title' => item_text($lot, 'title')])) ?>"
             <?= $index === 0 ? 'aria-current="true"' : '' ?>></button>
          <?php endforeach; ?>
        </div>
        <span class="sr-only" aria-live="polite" data-slide-announcement></span>
        <?php endif; ?>
      </div>
    </section>
    <?php
}
