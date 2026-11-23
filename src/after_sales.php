<?php
declare(strict_types=1);

function after_sales_text(string $english): string
{
    $chinese = [
        'After-sales support' => '售后协商',
        'Both the buyer and seller can request help with this order. This records your discussion and does not issue a refund or transfer money.' => '买卖双方均可为此订单申请售后协商。此处记录协商过程，不执行退款或转账。',
        'What would you like help with?' => '请说明需要协商的问题',
        'Send request' => '提交申请',
        'You already have an active request for this order. Complete or withdraw it before opening another.' => '你在此订单已有进行中的申请，请先完成或撤回后再提交新申请。',
        'Open' => '等待对方答复',
        'Replied' => '对方已答复',
        'Resolved' => '已解决',
        'Withdrawn' => '已撤回',
        'Closed' => '已关闭',
        'Reply' => '答复',
        'Your reply' => '你的答复',
        'Send reply' => '提交答复',
        'Confirm resolved' => '确认已解决',
        'Withdraw request' => '撤回申请',
        'Request history' => '申请记录',
        'Waiting for the other participant to reply.' => '等待交易对方答复。',
        'Waiting for the requester to confirm a resolution or withdraw the request.' => '等待申请人确认已解决或撤回申请。',
        'You confirmed that this request is resolved.' => '你已确认此申请的问题已解决。',
        'The requester confirmed that the issue is resolved.' => '申请人已确认问题解决。',
        'The requester withdrew this request.' => '申请人已撤回此申请。',
        'Your after-sales request has been recorded.' => '售后申请已提交。',
        'Your reply has been recorded.' => '答复已保存。',
        'The request has been marked as resolved.' => '申请已标记为已解决。',
        'The request has been withdrawn.' => '申请已撤回。',
        'After-sales requests become available after the demonstration payment is recorded.' => '记录演示付款后即可申请售后协商。',
        'Describe the issue using between 1 and 3000 characters.' => '请用 1 至 3000 个字符说明问题。',
        'Write a reply using between 1 and 3000 characters.' => '请用 1 至 3000 个字符填写答复。',
        'This after-sales request is not available for this order.' => '此订单中不存在该售后申请。',
        'This request has changed. Refresh the page before trying again.' => '申请已更新，请刷新页面后再操作。',
        'Only the other participant can reply to an open request.' => '只有交易对方可以答复待处理的申请。',
        'Only the requester can confirm a resolution after receiving a reply.' => '收到答复后，只有申请人可以确认问题已解决。',
        'Only the requester can withdraw an active request.' => '只有申请人可以撤回进行中的申请。',
        'Choose a valid after-sales action.' => '请选择有效的售后操作。',
    ];
    return locale_code() === 'zh-CN' ? ($chinese[$english] ?? t($english)) : $english;
}

function after_sales_message(string $value, bool $reply = false): string
{
    $value = trim($value);
    if ($value === '' || mb_strlen($value) > 3000) {
        throw new InvalidArgumentException(after_sales_text($reply
            ? 'Write a reply using between 1 and 3000 characters.'
            : 'Describe the issue using between 1 and 3000 characters.'));
    }
    return $value;
}

function after_sales_state(array $request, array $events): string
{
    // Legacy requests retain their original status even when they have no events.
    $state = (string)$request['status'];
    foreach ($events as $event) {
        $state = match ($event['event_type']) {
            'reply' => 'replied',
            'resolved' => 'resolved',
            'withdrawn' => 'withdrawn',
            default => $state,
        };
    }
    return $state;
}

function after_sales_requests(PDO $db, int $transactionId, int $user): array
{
    if (!service_row($db, transaction_select_sql() . ' WHERE t.transaction_id = ? AND (i.seller_id = ? OR b.bidder_id = ?)', [$transactionId, $user, $user])) {
        throw new InvalidArgumentException(t('This transaction is not available to your account.'));
    }
    $requests = service_rows($db, 'SELECT r.*, u.username FROM after_sales_requests r JOIN users u ON u.user_id = r.requester_id WHERE r.transaction_id = ? ORDER BY r.created_at DESC, r.request_id DESC', [$transactionId]);
    $events = service_rows($db, 'SELECT e.*, u.username FROM after_sales_events e JOIN after_sales_requests r ON r.request_id = e.request_id JOIN users u ON u.user_id = e.actor_id WHERE r.transaction_id = ? ORDER BY e.event_id', [$transactionId]);
    $byRequest = [];
    foreach ($events as $event) {
        $byRequest[(int)$event['request_id']][] = $event;
    }
    foreach ($requests as &$request) {
        $request['events'] = $byRequest[(int)$request['request_id']] ?? [];
        $request['state'] = after_sales_state($request, $request['events']);
        $request['version'] = $request['events'] ? (int)end($request['events'])['event_id'] : 0;
    }
    unset($request);
    return $requests;
}

function after_sales_create_request(PDO $db, int $user, int $transactionId, string $description): void
{
    $description = after_sales_message($description);
    $db->beginTransaction();
    try {
        $transaction = service_locked_transaction($db, $transactionId, $user);
        if ($transaction['payment_status'] !== 'paid_demo') {
            throw new InvalidArgumentException(after_sales_text('After-sales requests become available after the demonstration payment is recorded.'));
        }
        // The transaction row serializes concurrent creation and state transitions.
        if (service_row($db, "SELECT request_id FROM after_sales_requests WHERE transaction_id = ? AND requester_id = ? AND status IN ('open', 'replied') LIMIT 1", [$transactionId, $user])) {
            throw new InvalidArgumentException(after_sales_text('You already have an active request for this order. Complete or withdraw it before opening another.'));
        }
        service_execute($db, "INSERT INTO after_sales_requests (transaction_id, requester_id, description, status, created_at) VALUES (?, ?, ?, 'open', UTC_TIMESTAMP())", [$transactionId, $user, $description]);
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}

function after_sales_update_request(PDO $db, int $user, int $transactionId, int $requestId, string $operation, int $version, string $message = ''): void
{
    if (!in_array($operation, ['reply', 'resolved', 'withdrawn'], true)) {
        throw new InvalidArgumentException(after_sales_text('Choose a valid after-sales action.'));
    }
    if ($operation === 'reply') {
        $message = after_sales_message($message, true);
    } else {
        $message = '';
    }
    $db->beginTransaction();
    try {
        service_locked_transaction($db, $transactionId, $user);
        $request = service_row($db, 'SELECT * FROM after_sales_requests WHERE request_id = ? AND transaction_id = ? FOR UPDATE', [$requestId, $transactionId]);
        if (!$request) {
            throw new InvalidArgumentException(after_sales_text('This after-sales request is not available for this order.'));
        }
        $events = service_rows($db, 'SELECT * FROM after_sales_events WHERE request_id = ? ORDER BY event_id', [$requestId]);
        $latestVersion = $events ? (int)end($events)['event_id'] : 0;
        if ($version !== $latestVersion) {
            throw new InvalidArgumentException(after_sales_text('This request has changed. Refresh the page before trying again.'));
        }
        $state = after_sales_state($request, $events);
        $isRequester = $user === (int)$request['requester_id'];
        if ($operation === 'reply' && ($isRequester || $state !== 'open')) {
            throw new InvalidArgumentException(after_sales_text('Only the other participant can reply to an open request.'));
        }
        if ($operation === 'resolved' && (!$isRequester || $state !== 'replied')) {
            throw new InvalidArgumentException(after_sales_text('Only the requester can confirm a resolution after receiving a reply.'));
        }
        if ($operation === 'withdrawn' && (!$isRequester || !in_array($state, ['open', 'replied'], true))) {
            throw new InvalidArgumentException(after_sales_text('Only the requester can withdraw an active request.'));
        }
        service_execute($db, 'INSERT INTO after_sales_events (request_id, actor_id, event_type, message, created_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())', [$requestId, $user, $operation, $message]);
        // Keep the original row compatible with existing open-request checks.
        if ($operation !== 'reply') {
            service_execute($db, 'UPDATE after_sales_requests SET status = ? WHERE request_id = ?', [$operation, $requestId]);
        }
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}

function view_after_sales(array $transaction, array $requests, int $user): void
{
    $transactionId = (int)$transaction['transaction_id'];
    $returnTo = url('transaction', ['id' => $transactionId]);
    $hasActive = false;
    foreach ($requests as $request) {
        if ((int)$request['requester_id'] === $user && in_array($request['state'], ['open', 'replied'], true)) {
            $hasActive = true;
        }
    }
    ?>
    <section class="content-panel">
      <h2><?= e(after_sales_text('After-sales support')) ?></h2>
      <p class="muted"><?= e(after_sales_text('Both the buyer and seller can request help with this order. This records your discussion and does not issue a refund or transfer money.')) ?></p>
      <?php if ($transaction['payment_status'] === 'paid_demo' && !$hasActive): ?>
      <form method="post" action="index.php" class="stack-form">
        <?php view_fields('after_sales', $returnTo); ?>
        <input type="hidden" name="transaction_id" value="<?= $transactionId ?>">
        <label for="after-sales-description"><?= e(after_sales_text('What would you like help with?')) ?></label>
        <textarea id="after-sales-description" name="description" rows="3" required maxlength="3000"></textarea>
        <button class="button button-outline" type="submit"><?= e(after_sales_text('Send request')) ?></button>
      </form>
      <?php elseif ($hasActive): ?>
      <p class="field-help"><?= e(after_sales_text('You already have an active request for this order. Complete or withdraw it before opening another.')) ?></p>
      <?php else: ?>
      <p class="field-help"><?= e(after_sales_text('After-sales requests become available after the demonstration payment is recorded.')) ?></p>
      <?php endif; ?>
      <?php if ($requests): ?>
      <h3><?= e(after_sales_text('Request history')) ?></h3>
      <div class="after-sales-list">
        <?php foreach ($requests as $request):
            $requestId = (int)$request['request_id'];
            $state = $request['state'];
            $isRequester = (int)$request['requester_id'] === $user;
            $statusLabel = match ($state) { 'open' => 'Open', 'replied' => 'Replied', 'resolved' => 'Resolved', 'withdrawn' => 'Withdrawn', default => 'Closed' };
        ?>
        <article>
          <strong>#<?= $requestId ?> · <?= e(after_sales_text($statusLabel)) ?></strong>
          <p><?= e($request['username']) ?> · <?= view_date($request['created_at'], true) ?></p>
          <p><?= nl2br(e($request['description'])) ?></p>
          <?php foreach ($request['events'] as $event): ?>
          <div class="content-panel">
            <strong><?= e($event['username']) ?> · <?= view_date($event['created_at'], true) ?></strong>
            <p><?= $event['event_type'] === 'reply' ? nl2br(e($event['message'])) : e(after_sales_text($event['event_type'] === 'resolved' ? 'The requester confirmed that the issue is resolved.' : 'The requester withdrew this request.')) ?></p>
          </div>
          <?php endforeach; ?>
          <?php if (!$isRequester && $state === 'open'): ?>
          <form method="post" action="index.php" class="stack-form">
            <?php view_fields('after_sales_update', $returnTo); ?>
            <input type="hidden" name="transaction_id" value="<?= $transactionId ?>">
            <input type="hidden" name="request_id" value="<?= $requestId ?>">
            <input type="hidden" name="request_version" value="<?= (int)$request['version'] ?>">
            <input type="hidden" name="operation" value="reply">
            <label for="after-sales-reply-<?= $requestId ?>"><?= e(after_sales_text('Your reply')) ?></label>
            <textarea id="after-sales-reply-<?= $requestId ?>" name="message" rows="3" required maxlength="3000"></textarea>
            <button class="button button-outline" type="submit"><?= e(after_sales_text('Send reply')) ?></button>
          </form>
          <?php elseif ($state === 'open'): ?>
          <p class="field-help"><?= e(after_sales_text('Waiting for the other participant to reply.')) ?></p>
          <?php elseif ($state === 'replied' && !$isRequester): ?>
          <p class="field-help"><?= e(after_sales_text('Waiting for the requester to confirm a resolution or withdraw the request.')) ?></p>
          <?php endif; ?>
          <?php if ($isRequester && in_array($state, ['open', 'replied'], true)): ?>
          <form method="post" action="index.php" class="form-actions">
            <?php view_fields('after_sales_update', $returnTo); ?>
            <input type="hidden" name="transaction_id" value="<?= $transactionId ?>">
            <input type="hidden" name="request_id" value="<?= $requestId ?>">
            <input type="hidden" name="request_version" value="<?= (int)$request['version'] ?>">
            <?php if ($state === 'replied'): ?>
            <button class="button button-primary" type="submit" name="operation" value="resolved"><?= e(after_sales_text('Confirm resolved')) ?></button>
            <?php endif; ?>
            <button class="button button-outline" type="submit" name="operation" value="withdrawn"><?= e(after_sales_text('Withdraw request')) ?></button>
          </form>
          <?php endif; ?>
        </article>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </section>
    <?php
}
