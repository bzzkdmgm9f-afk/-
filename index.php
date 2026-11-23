<?php
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/src/services.php';
require __DIR__ . '/src/dashboard.php';
require __DIR__ . '/src/after_sales.php';
require __DIR__ . '/src/relisting.php';
require __DIR__ . '/src/views.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; script-src 'self'; style-src 'self'; font-src 'self'; connect-src 'self'; form-action 'self'; base-uri 'self'; frame-ancestors 'none'");
header('Cache-Control: no-store');

select_requested_language();
header('Content-Language: ' . locale_code());

$page = is_string($_GET['page'] ?? null) ? $_GET['page'] : 'catalog';
$context = ['user' => null, 'role' => active_role(), 'flash' => in_array($page, ['catalog_activity', 'bid_status', 'notification_activity', 'dashboard_activity'], true) ? null : consume_flash(), 'page' => $page, 'data' => [], 'old' => $_SESSION['old'] ?? []];
if (!in_array($page, ['catalog_activity', 'bid_status', 'notification_activity', 'dashboard_activity'], true)) {
    unset($_SESSION['old']);
}

try {
    $connection = db();
    close_due_auctions($connection);
    $user = current_user();
    $context['user'] = $user;

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $limit = (string) ini_get('post_max_size');
        $number = (int) $limit;
        $postBytes = $number * match (strtolower(substr($limit, -1))) {
            'g' => 1024 * 1024 * 1024,
            'm' => 1024 * 1024,
            'k' => 1024,
            default => 1,
        };
        if ($postBytes > 0 && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $postBytes) {
            flash('error', 'This upload is larger than the server accepts. Use smaller photographs or increase PHP post_max_size as described in README.');
            redirect_to(url('sell'));
        }
        verify_csrf();
        $action = text_input('action');
        $target = text_input('return_to') ?: url('dashboard');
        if ($action === 'login') {
            $afterLogin = $_SESSION['after_login'] ?? url('dashboard');
            start_user_session(sign_in($connection, text_input('identity'), text_input('password')));
            flash('success', 'Welcome back.');
            redirect_to(is_string($afterLogin) ? $afterLogin : url('dashboard'));
        }
        if ($action === 'register') {
            start_user_session(register_account($connection, $_POST));
            flash('success', 'Your account is ready. Choose a few interests to get started.');
            redirect_to(url('preferences'));
        }
        $user = require_user();
        $userId = (int) $user['user_id'];
        switch ($action) {
            case 'logout':
                $_SESSION = [];
                session_regenerate_id(true);
                flash('success', 'You have signed out.');
                $target = url();
                break;
            case 'switch_role':
                $role = text_input('role');
                if (!in_array($role, ['buyer', 'seller'], true)) {
                    throw new InvalidArgumentException('Choose buyer or seller.');
                }
                $_SESSION['role'] = $role;
                flash('success', 'You are now browsing as a ' . $role . '.');
                break;
            case 'create_auction':
                if (active_role() !== 'seller') {
                    throw new InvalidArgumentException('Switch to seller to publish a lot.');
                }
                $input = $_POST;
                $input['end_time'] = utc_input(text_input('end_time'), text_input('timezone_offset') ?: '0');
                $id = create_auction($connection, $userId, $input, $_FILES);
                flash('success', 'Your lot has been published.');
                $target = url('detail', ['id' => $id]);
                break;
            case 'relist_auction':
                if (active_role() !== 'seller') {
                    throw new InvalidArgumentException('Switch to seller to publish a lot.');
                }
                $input = $_POST;
                $input['end_time'] = utc_input(text_input('end_time'), text_input('timezone_offset') ?: '0');
                $startOffset = text_input('start_timezone_offset');
                if ($startOffset === '') {
                    $startOffset = text_input('timezone_offset') ?: '0';
                }
                $input['start_time'] = text_input('start_time') === '' ? '' : utc_input(text_input('start_time'), $startOffset);
                $id = relist_auction($connection, $userId, int_input('auction_id'), $input);
                flash('success', 'Your collectible has been relisted.');
                $target = url('detail', ['id' => $id]);
                break;
            case 'bid':
                if (active_role() !== 'buyer') {
                    throw new InvalidArgumentException('Switch to buyer to place a bid.');
                }
                place_bid($connection, int_input('auction_id'), $userId, text_input('amount'));
                flash('success', 'Your bid has been placed.');
                $target = url('detail', ['id' => int_input('auction_id')]);
                break;
            case 'watch_state':
                $wanted = text_input('wanted');
                if (!in_array($wanted, ['0', '1'], true)) {
                    throw new InvalidArgumentException('Choose valid options.');
                }
                $auctionId = int_input('auction_id');
                $watched = toggle_watch($connection, $userId, $auctionId, $wanted === '1');
                if (($_SERVER['HTTP_ACCEPT'] ?? '') === 'application/json') {
                    header('Content-Type: application/json; charset=utf-8');
                    $total = service_row($connection, 'SELECT COUNT(*) AS total FROM watches WHERE auction_id = ?', [$auctionId]);
                    echo json_encode(['watched' => $watched, 'count' => (int)$total['total']], JSON_THROW_ON_ERROR);
                    exit;
                }
                flash('success', $watched ? 'Added to your watchlist.' : 'Removed from your watchlist.');
                break;
            case 'watch':
                $watched = toggle_watch($connection, $userId, int_input('auction_id'));
                flash('success', $watched ? 'Added to your watchlist.' : 'Removed from your watchlist.');
                break;
            case 'dashboard_unwatch':
                dashboard_unwatch($connection, $userId, int_input('auction_id'));
                flash('success', 'Removed from your watchlist.');
                $target = url('dashboard', ['section' => 'watches']);
                break;
            case 'dashboard_remove_interest':
                dashboard_remove_interest($connection, $userId, int_input('category_id'));
                flash('success', 'Your interests have been updated.');
                $target = url('dashboard', ['section' => 'watches']);
                break;
            case 'dashboard_preferences':
                save_preferences($connection, $userId, array_input('category_ids'));
                flash('success', 'Your interests have been updated.');
                $target = url('dashboard', ['section' => 'watches']);
                break;
            case 'withdraw_listing':
                if (active_role() !== 'seller') {
                    throw new InvalidArgumentException('Switch to seller to publish a lot.');
                }
                dashboard_withdraw_listing($connection, $userId, int_input('auction_id'));
                flash('success', dashboard_text('Your listing has been withdrawn. Its history has been preserved.'));
                $target = url('dashboard', ['section' => 'published']);
                break;
            case 'comment':
                add_comment($connection, $userId, int_input('auction_id'), text_input('content'));
                flash('success', 'Your comment has been posted.');
                $target = url('detail', ['id' => int_input('auction_id')]);
                break;
            case 'preferences':
                save_preferences($connection, $userId, array_input('category_ids'));
                flash('success', 'Your interests have been updated.');
                $target = url();
                break;
            case 'settings':
                save_notification_settings($connection, $userId, [
                    'outbid_enabled' => isset($_POST['outbid_enabled']) ? 1 : 0,
                    'watch_enabled' => isset($_POST['watch_enabled']) ? 1 : 0,
                    'seller_frequency' => text_input('seller_frequency'),
                ]);
                flash('success', 'Notification preferences saved.');
                $target = url('notifications');
                break;
            case 'open_notification':
                $target = open_notification($connection, $userId, int_input('notification_id'));
                break;
            case 'mark_read':
                mark_notifications_read($connection, $userId);
                if (($_SERVER['HTTP_ACCEPT'] ?? '') === 'application/json') {
                    account_feed_response($connection, $userId);
                    exit;
                }
                flash('success', 'All notifications marked as read.');
                $target = url('notifications');
                break;
            case 'delivery':
                save_delivery($connection, $userId, int_input('transaction_id'), int_input('delivery_method_id'));
                flash('success', 'Handover preference saved.');
                $target = url('transaction', ['id' => int_input('transaction_id')]);
                break;
            case 'transaction':
                update_transaction($connection, $userId, int_input('transaction_id'), text_input('operation'), $_POST);
                flash('success', 'The transaction record has been updated.');
                $target = url('transaction', ['id' => int_input('transaction_id')]);
                break;
            case 'handoff_buyer':
                handoff_save_buyer($connection, int_input('transaction_id'), $userId, $_POST);
                flash('success', 'Handover details saved.');
                $target = url('transaction', ['id' => int_input('transaction_id')]);
                break;
            case 'handoff_message':
                handoff_send_message($connection, int_input('transaction_id'), $userId, $_POST);
                flash('success', 'Your private message has been sent.');
                $target = url('transaction', ['id' => int_input('transaction_id')]);
                break;
            case 'review':
                add_review($connection, $userId, int_input('transaction_id'), int_input('rating'), text_input('comment'));
                flash('success', 'Your review has been saved.');
                $target = url('transaction', ['id' => int_input('transaction_id')]);
                break;
            case 'after_sales':
                after_sales_create_request($connection, $userId, int_input('transaction_id'), text_input('description'));
                flash('success', after_sales_text('Your after-sales request has been recorded.'));
                $target = url('transaction', ['id' => int_input('transaction_id')]);
                break;
            case 'after_sales_update':
                $operation = text_input('operation');
                after_sales_update_request($connection, $userId, int_input('transaction_id'), int_input('request_id'), $operation, int_input('request_version'), text_input('message'));
                flash('success', after_sales_text(match ($operation) {
                    'reply' => 'Your reply has been recorded.',
                    'resolved' => 'The request has been marked as resolved.',
                    default => 'The request has been withdrawn.',
                }));
                $target = url('transaction', ['id' => int_input('transaction_id')]);
                break;
            default:
                throw new InvalidArgumentException('That action is not available.');
        }
        redirect_to($target);
    }

    if ($page === 'notification_activity') {
        header('Content-Type: application/json; charset=utf-8');
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'signed_out']);
            exit;
        }
        $userId = (int)$user['user_id'];
        if (($_GET['menu'] ?? '') === '1') {
            account_feed_response($connection, $userId);
            exit;
        }
        $activity = notification_activity($connection, $userId);
        $version = $activity['latest'] . '-' . $activity['unread'];
        if (isset($_GET['feed']) && ($_GET['version'] ?? '') !== $version) {
            ob_start();
            view_activity_feed(notification_list($connection, $userId), $activity['unread']);
            $activity['feed'] = ob_get_clean();
        }
        echo json_encode($activity, JSON_THROW_ON_ERROR);
        exit;
    }

    if ($page === 'dashboard_activity') {
        header('Content-Type: application/json; charset=utf-8');
        $requested = $_GET['ids'] ?? '';
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'sign_in_required']);
        } elseif (!is_string($requested) || !preg_match('/\A[1-9][0-9]{0,9}(?:,[1-9][0-9]{0,9}){0,119}\z/', $requested)) {
            http_response_code(400);
            echo json_encode(['error' => 'invalid_ids']);
        } else {
            echo json_encode(dashboard_activity($connection, (int)$user['user_id'], array_values(array_unique(array_map('intval', explode(',', $requested))))), JSON_THROW_ON_ERROR);
        }
        exit;
    }

    if ($page === 'catalog_activity') {
        header('Content-Type: application/json; charset=utf-8');
        $requested = $_GET['ids'] ?? '';
        if (!is_string($requested) || !preg_match('/\A[1-9][0-9]{0,9}(?:,[1-9][0-9]{0,9}){0,127}\z/', $requested)) {
            http_response_code(400);
            echo json_encode(['error' => 'invalid_ids']);
        } else {
            $ids = array_values(array_unique(array_map('intval', explode(',', $requested))));
            echo json_encode(catalog_activity($connection, $ids), JSON_THROW_ON_ERROR);
        }
        exit;
    }

    if ($page === 'bid_status') {
        $auction = auction_detail($connection, query_id(), $user ? (int) $user['user_id'] : null);
        header('Content-Type: application/json; charset=utf-8');
        if (!$auction) {
            http_response_code(404);
            echo json_encode(['error' => 'Auction not found.'], JSON_THROW_ON_ERROR);
        } else {
            echo json_encode(array_intersect_key($auction, array_flip(['current_price', 'minimum_increment', 'bid_count', 'bidder_count', 'watch_count', 'status', 'start_time', 'end_time', 'last_updated', 'reserve_reached', 'has_reserve', 'bids'])), JSON_THROW_ON_ERROR);
        }
        exit;
    }

    if ($user) {
        $context['activity'] = notification_activity($connection, (int)$user['user_id']);
    }
    switch ($page) {
        case 'catalog':
            $filters = [
                'q' => is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '',
                'category' => max(0, (int) ($_GET['category'] ?? 0)),
                'sort' => is_string($_GET['sort'] ?? null) ? $_GET['sort'] : 'ending',
                'status' => catalog_status(is_string($_GET['status'] ?? null) ? $_GET['status'] : 'active'),
            ];
            $statusCounts = auction_status_counts($connection);
            $context['data'] = [
                'auctions' => auction_list($connection, $filters, $user ? (int) $user['user_id'] : null),
                'categories' => categories($connection),
                'filters' => $filters,
                'status_counts' => $statusCounts,
                'recently_sold' => $filters['status'] === 'active' && $filters['q'] === '' && $filters['category'] === 0
                    ? array_slice(auction_list($connection, ['status' => 'completed', 'sort' => 'newest'], $user ? (int) $user['user_id'] : null), 0, 4)
                    : [],
                'recommendations' => $user ? recommended_auctions($connection, (int) $user['user_id']) : [],
                'stats' => [
                    'active' => $statusCounts['active'],
                    'collectors' => (int) $connection->query('SELECT COUNT(*) FROM users')->fetchColumn(),
                    'categories' => count(categories($connection)),
                ],
            ];
            break;
        case 'detail':
            $auction = auction_detail($connection, query_id(), $user ? (int) $user['user_id'] : null);
            if (!$auction) {
                $page = 'not_found';
                http_response_code(404);
            }
            $context['data'] = ['auction' => $auction];
            if ($auction && $user && (int)$auction['seller_id'] === (int)$user['user_id'] && in_array($auction['status'], ['unsold', 'ended'], true)) {
                try {
                    relisting_source($connection, (int)$auction['auction_id'], (int)$user['user_id']);
                    $context['data']['can_relist'] = true;
                } catch (InvalidArgumentException) {
                    $context['data']['can_relist'] = false;
                }
            }
            break;
        case 'relist':
            if (!$user) {
                redirect_to(url('login', ['next' => url('relist', ['id' => query_id()])]));
            }
            $context['data'] = ['source' => relisting_source($connection, query_id(), (int)$user['user_id'])];
            break;
        case 'login':
        case 'register':
            if ($user) {
                redirect_to(url('dashboard'));
            }
            if (is_string($_GET['next'] ?? null) && preg_match('/\Aindex\.php\?page=[A-Za-z0-9_%=&+.-]*\z/', $_GET['next'])) {
                $_SESSION['after_login'] = $_GET['next'];
            }
            break;
        case 'sell':
            if (!$user) {
                flash('info', 'Sign in to publish a lot.');
                redirect_to(url('login'));
            }
            $guidance = [];
            foreach (categories($connection) as $category) {
                $guidance[] = category_price_summary($connection, (int) $category['category_id']);
            }
            $context['data'] = ['categories' => categories($connection), 'delivery_methods' => $connection->query('SELECT * FROM delivery_methods ORDER BY delivery_method_id')->fetchAll(), 'price_guidance' => $guidance];
            break;
        case 'dashboard':
            $section = dashboard_section($_GET['section'] ?? null, active_role());
            if (!$user) {
                redirect_to(url('login', ['next' => url('dashboard', ['section' => $section])]));
            }
            $userId = (int)$user['user_id'];
            $context['data'] = ['section' => $section, 'dashboard' => in_array($section, ['watches', 'preferences'], true)
                ? dashboard_collection($connection, $userId) : dashboard_data($connection, $userId)];
            break;
        case 'preferences':
            if (!$user) {
                redirect_to(url('login'));
            }
            $statement = $connection->prepare('SELECT category_id FROM user_preferences WHERE user_id = ?');
            $statement->execute([$user['user_id']]);
            $context['data'] = ['categories' => categories($connection), 'selected' => array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN))];
            break;
        case 'notifications':
            if (!$user) {
                redirect_to(url('login'));
            }
            $statement = $connection->prepare('SELECT * FROM notification_settings WHERE user_id = ?');
            $statement->execute([$user['user_id']]);
            $context['data'] = ['notifications' => notification_list($connection, (int) $user['user_id']), 'unread' => (int)($context['activity']['unread'] ?? 0), 'settings' => $statement->fetch() ?: ['outbid_enabled' => 1, 'watch_enabled' => 1, 'seller_frequency' => 'immediate']];
            break;
        case 'transaction':
            if (!$user) {
                redirect_to(url('login'));
            }
            $transaction = transaction_detail($connection, query_id(), (int) $user['user_id']);
            if ($transaction) {
                $transaction['requests'] = after_sales_requests($connection, (int)$transaction['transaction_id'], (int)$user['user_id']);
            }
            if (!$transaction) {
                $page = 'not_found';
                http_response_code(404);
            }
            $context['data'] = ['transaction' => $transaction, 'delivery_methods' => $transaction['delivery_methods'] ?? [], 'reviews' => $transaction['reviews'] ?? [], 'requests' => $transaction['requests'] ?? []];
            break;
        default:
            $page = 'not_found';
            http_response_code(404);
    }
} catch (InvalidArgumentException $error) {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $input = array_intersect_key($_POST, array_flip(['username', 'email', 'identity', 'title', 'description', 'category_id', 'item_condition', 'starting_price', 'reserve_price', 'minimum_increment', 'start_time', 'end_time', 'delivery_methods']));
        $_SESSION['old'] = $input;
        flash('error', $error->getMessage());
        $submittedAction = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
        $fallback = match ($submittedAction) {
            'login' => url('login'),
            'register' => url('register'),
            'create_auction' => url('sell'),
            'relist_auction' => url('relist', ['id' => int_input('auction_id')]),
            'bid', 'comment' => url('detail', ['id' => is_scalar($_POST['auction_id'] ?? null) ? (int) $_POST['auction_id'] : 0]),
            default => url('dashboard'),
        };
        $returnTo = is_string($_POST['return_to'] ?? null) ? $_POST['return_to'] : '';
        redirect_to(in_array($submittedAction, ['login', 'register'], true) ? $fallback : ($returnTo ?: $fallback));
    }
    $page = 'error';
    $context['data'] = ['message' => $error->getMessage()];
    http_response_code(400);
} catch (Throwable $error) {
    error_log($error->__toString());
    $page = 'setup';
    $context['data'] = ['message' => 'The database is not ready. Check the local connection settings, then import the schema and migration in the order shown in README.'];
    http_response_code(503);
}

$context['page'] = $page;
$context['role'] = active_role();
render_page($page, $context);

function text_input(string $name): string
{
    $value = $_POST[$name] ?? '';
    if (!is_string($value)) {
        throw new InvalidArgumentException('One of the submitted fields has an invalid format.');
    }
    return $value;
}

function int_input(string $name): int
{
    $value = text_input($name);
    return preg_match('/\A[0-9]{1,10}\z/', $value) ? (int) $value : 0;
}

function array_input(string $name): array
{
    $value = $_POST[$name] ?? [];
    if (!is_array($value) || count($value) > 50) {
        throw new InvalidArgumentException('Choose valid options.');
    }
    return $value;
}

function query_id(): int
{
    $id = $_GET['id'] ?? '';
    return is_string($id) && preg_match('/\A[0-9]{1,10}\z/', $id) ? (int) $id : 0;
}

function categories(PDO $connection): array
{
    return $connection->query('SELECT * FROM categories ORDER BY category_name')->fetchAll();
}
