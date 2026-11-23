<?php
declare(strict_types=1);

function handoff_details(PDO $db, array $transaction): array
{
    return service_row($db, 'SELECT * FROM transaction_handoffs WHERE transaction_id = ? AND delivery_method_id = ?', [$transaction['transaction_id'], $transaction['delivery_method_id']]) ?? [];
}

function handoff_messages(PDO $db, int $transactionId): array
{
    return service_rows($db, 'SELECT m.body, m.created_at, u.username FROM transaction_messages m JOIN users u ON u.user_id = m.sender_id WHERE m.transaction_id = ? ORDER BY m.message_id', [$transactionId]);
}

function handoff_collection(array $transaction): bool
{
    return ($transaction['method_name'] ?? '') === 'Collection in person';
}

function handoff_value(array $input, string $field, int $maximum, string $label): string
{
    return service_text(is_string($input[$field] ?? null) ? $input[$field] : '', $maximum, $label);
}

function handoff_check_method(array $transaction, array $input): void
{
    $method = $input['delivery_method_id'] ?? '';
    if (!$transaction['delivery_method_id'] || (!is_string($method) && !is_int($method)) || (string)$transaction['delivery_method_id'] !== (string)$method) {
        throw new InvalidArgumentException('The handover method has changed. Open the order and check its details.');
    }
}

function handoff_save_buyer(PDO $db, int $transactionId, int $user, array $input): void
{
    $db->beginTransaction();
    try {
        $transaction = service_locked_transaction($db, $transactionId, $user);
        if ((int)$transaction['buyer_id'] !== $user || $transaction['delivery_status'] !== 'pending') {
            throw new InvalidArgumentException('Only the buyer can update these details before dispatch or collection.');
        }
        handoff_check_method($transaction, $input);
        $name = $address = $collection = null;
        if (handoff_collection($transaction)) {
            $collection = handoff_value($input, 'collection_request', 500, 'Preferred place and time');
        } else {
            $name = handoff_value($input, 'recipient_name', 120, 'Recipient name');
            $address = handoff_value($input, 'delivery_address', 1000, 'Full delivery address');
        }
        service_execute($db, 'INSERT INTO transaction_handoffs (transaction_id, delivery_method_id, recipient_name, delivery_address, collection_request)
            VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE recipient_name = VALUES(recipient_name), delivery_address = VALUES(delivery_address), collection_request = VALUES(collection_request)',
            [$transactionId, $transaction['delivery_method_id'], $name, $address, $collection]);
        $db->commit();
    } catch (Throwable $error) {
        $db->rollBack();
        throw $error;
    }
}

// Called inside the same locked transaction as the dispatch status and notification.
function handoff_record_dispatch(PDO $db, array $transaction, array $input): void
{
    handoff_check_method($transaction, $input);
    $carrier = $tracking = $place = $time = null;
    if (handoff_collection($transaction)) {
        $place = handoff_value($input, 'collection_place', 250, 'Confirmed collection place');
        $time = handoff_value($input, 'collection_time', 180, 'Confirmed date and time');
    } else {
        if (!isset(handoff_details($db, $transaction)['delivery_address'])) {
            throw new InvalidArgumentException('Ask the buyer to add their delivery address before dispatch.');
        }
        $carrier = handoff_value($input, 'carrier', 120, 'Carrier');
        $tracking = handoff_value($input, 'tracking_number', 160, 'Tracking number');
    }
    service_execute($db, 'INSERT INTO transaction_handoffs (transaction_id, delivery_method_id, carrier, tracking_number, collection_place, collection_time)
        VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE carrier = VALUES(carrier), tracking_number = VALUES(tracking_number), collection_place = VALUES(collection_place), collection_time = VALUES(collection_time)',
        [$transaction['transaction_id'], $transaction['delivery_method_id'], $carrier, $tracking, $place, $time]);
}

function handoff_send_message(PDO $db, int $transactionId, int $user, array $input): void
{
    $db->beginTransaction();
    try {
        $transaction = service_locked_transaction($db, $transactionId, $user);
        $body = handoff_value($input, 'message', 2000, 'Message');
        $key = $input['submission_key'] ?? '';
        if (!is_string($key) || !preg_match('/\A[0-9a-f]{32}\z/D', $key)) {
            throw new InvalidArgumentException('Open the order again to send your message.');
        }
        $existing = service_row($db, 'SELECT body FROM transaction_messages WHERE transaction_id = ? AND sender_id = ? AND submission_key = ?', [$transactionId, $user, $key]);
        if ($existing && $existing['body'] !== $body) {
            throw new InvalidArgumentException('Open the order again to send your message.');
        }
        if (!$existing) {
            service_execute($db, 'INSERT INTO transaction_messages (transaction_id, sender_id, submission_key, body, created_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())', [$transactionId, $user, $key, $body]);
            $recipient = (int)$transaction['buyer_id'] === $user ? (int)$transaction['seller_id'] : (int)$transaction['buyer_id'];
            service_queue_notification($db, $recipient, (int)$transaction['auction_id'], 'order_message', 'New private order message', 'Open the order to read and reply.', 'order_message:' . $transactionId . ':' . $db->lastInsertId());
        }
        $db->commit();
    } catch (Throwable $error) {
        $db->rollBack();
        throw $error;
    }
}

function handoff_form_fields(string $action, array $transaction): void
{
    $id = (int)$transaction['transaction_id'];
    view_fields($action, url('transaction', ['id' => $id]));
    echo '<input type="hidden" name="transaction_id" value="' . $id . '">';
    echo '<input type="hidden" name="delivery_method_id" value="' . (int)$transaction['delivery_method_id'] . '">';
}

function handoff_field(string $name, string $label, int $maximum, string $value = '', bool $multiline = false): void
{
    ?>
    <div class="form-field">
      <label for="handoff-<?= e($name) ?>"><?= e(t($label)) ?></label>
      <?php if ($multiline): ?>
      <textarea id="handoff-<?= e($name) ?>" name="<?= e($name) ?>" maxlength="<?= $maximum ?>" rows="3" required><?= e($value) ?></textarea>
      <?php else: ?>
      <input id="handoff-<?= e($name) ?>" name="<?= e($name) ?>" value="<?= e($value) ?>" maxlength="<?= $maximum ?>" required>
      <?php endif; ?>
    </div>
    <?php
}

function view_handoff(array $transaction, bool $isBuyer): void
{
    $details = $transaction['handoff'] ?? [];
    $buyerCanEdit = $isBuyer && $transaction['delivery_status'] === 'pending';
    $fields = handoff_collection($transaction)
        ? ['collection_request' => 'Preferred place and time', 'collection_place' => 'Confirmed collection place', 'collection_time' => 'Confirmed date and time']
        : ['recipient_name' => 'Recipient name', 'delivery_address' => 'Full delivery address', 'carrier' => 'Carrier', 'tracking_number' => 'Tracking number'];
    ?>
    <?php if ($transaction['delivery_method_id']): ?>
    <section class="content-panel">
      <h2><?= e(t('Handover details')) ?></h2>
      <?php if ($details && !$buyerCanEdit): ?>
      <dl class="item-facts">
        <?php foreach ($fields as $field => $label): if (!isset($details[$field])) continue; ?>
        <div><dt><?= e(t($label)) ?></dt><dd><?= nl2br(e($details[$field])) ?></dd></div>
        <?php endforeach; ?>
      </dl>
      <?php endif; ?>
      <?php if ($buyerCanEdit): ?>
      <form method="post" action="index.php" class="stack-form">
        <?php handoff_form_fields('handoff_buyer', $transaction);
        if (handoff_collection($transaction)) {
            handoff_field('collection_request', 'Preferred place and time', 500, $details['collection_request'] ?? '', true);
        } else {
            handoff_field('recipient_name', 'Recipient name', 120, $details['recipient_name'] ?? '');
            handoff_field('delivery_address', 'Full delivery address', 1000, $details['delivery_address'] ?? '', true);
        } ?>
        <button class="button button-outline" type="submit"><?= e(t('Save handover details')) ?></button>
      </form>
      <?php elseif (!$details && $transaction['delivery_status'] === 'pending'): ?>
      <p class="muted"><?= e(t('The buyer has not added handover details yet.')) ?></p>
      <?php elseif (!$details): ?>
      <p class="muted"><?= e(t('No handover details were recorded for this order.')) ?></p>
      <?php endif; ?>
    </section>
    <?php endif; ?>
    <section class="content-panel">
      <h2><?= e(t('Private order messages')) ?></h2>
      <p class="field-help"><?= e(t('Only the buyer and seller can read this conversation.')) ?></p>
      <?php foreach ($transaction['messages'] ?? [] as $message): ?>
      <article class="comment">
        <strong><?= e($message['username']) ?></strong> <?= view_date($message['created_at'], true) ?>
        <p><?= nl2br(e($message['body'])) ?></p>
      </article>
      <?php endforeach; ?>
      <form method="post" action="index.php" class="stack-form">
        <?php handoff_form_fields('handoff_message', $transaction); ?>
        <input type="hidden" name="submission_key" value="<?= bin2hex(random_bytes(16)) ?>">
        <?php handoff_field('message', 'Message', 2000, '', true); ?>
        <button class="button button-outline" type="submit"><?= e(t('Send private message')) ?></button>
      </form>
    </section>
    <?php
}

function view_handoff_dispatch(array $transaction): void
{
    $collection = handoff_collection($transaction);
    if (!$collection && !isset($transaction['handoff']['delivery_address'])) {
        echo '<p class="field-help">' . e(t('Ask the buyer to add their delivery address before dispatch.')) . '</p>';
        return;
    }
    ?>
    <form method="post" action="index.php" class="stack-form">
      <?php handoff_form_fields('transaction', $transaction); ?>
      <input type="hidden" name="operation" value="ship">
      <?php if ($collection) {
          handoff_field('collection_place', 'Confirmed collection place', 250);
          handoff_field('collection_time', 'Confirmed date and time', 180);
      } else {
          handoff_field('carrier', 'Carrier', 120);
          handoff_field('tracking_number', 'Tracking number', 160);
      } ?>
      <button class="button button-primary button-full" type="submit"><?= e(t($collection ? 'Confirm collection arrangements' : 'Confirm dispatch')) ?></button>
    </form>
    <?php
}
