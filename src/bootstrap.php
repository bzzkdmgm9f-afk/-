<?php
declare(strict_types=1);

require_once __DIR__ . '/i18n.php';

date_default_timezone_set('UTC');
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('session.use_strict_mode', '1');
if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
    session_name('cabinet_session');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
}

function db(): PDO
{
    static $connection;
    if ($connection instanceof PDO) {
        return $connection;
    }
    $file = dirname(__DIR__) . '/config/database.php';
    $config = is_file($file) ? require $file : [];
    if (!is_array($config)) {
        throw new RuntimeException('The database configuration must return an array.');
    }
    $host = (string) (getenv('AUCTION_DB_HOST') ?: ($config['host'] ?? '127.0.0.1'));
    $port = (int) (getenv('AUCTION_DB_PORT') ?: ($config['port'] ?? 3306));
    $name = (string) (getenv('AUCTION_DB_NAME') ?: ($config['name'] ?? 'auction_db'));
    $user = (string) (getenv('AUCTION_DB_USER') ?: ($config['user'] ?? 'root'));
    $password = getenv('AUCTION_DB_PASSWORD') !== false
        ? (string) getenv('AUCTION_DB_PASSWORD')
        : (string) ($config['password'] ?? '');
    if (!preg_match('/\A[A-Za-z0-9_]+\z/', $name)) {
        throw new RuntimeException('The database name may contain letters, digits and underscores.');
    }
    $connection = new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $connection->exec("SET time_zone = '+00:00'");
    return $connection;
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $page = 'catalog', array $params = []): string
{
    return 'index.php?' . http_build_query(['page' => $page] + $params);
}

function money(mixed $value): string
{
    $value = (string) ($value ?? '0');
    if (!preg_match('/\A(-?)(\d+)(?:\.(\d{1,2}))?\z/', $value, $parts)) {
        return '£0.00';
    }
    return $parts[1] . '£' . preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $parts[2]) . '.' . str_pad($parts[3] ?? '', 2, '0');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $given = $_POST['csrf'] ?? '';
    if (!is_string($given) || !hash_equals(csrf_token(), $given)) {
        throw new InvalidArgumentException('Your session has changed. Refresh the page and try again.');
    }
}

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    $statement = db()->prepare('SELECT user_id, username, email, created_at FROM users WHERE user_id = ?');
    $statement->execute([(int) $_SESSION['user_id']]);
    $user = $statement->fetch();
    if (!$user) {
        unset($_SESSION['user_id'], $_SESSION['role']);
    }
    return $user ?: null;
}

function active_role(): string
{
    return ($_SESSION['role'] ?? 'buyer') === 'seller' ? 'seller' : 'buyer';
}

function require_user(): array
{
    $user = current_user();
    if (!$user) {
        throw new InvalidArgumentException('Please sign in to continue.');
    }
    return $user;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function consume_flash(): ?array
{
    $message = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_array($message) ? $message : null;
}

function redirect_to(string $target): never
{
    if (!preg_match('/\Aindex\.php\?(?:page=)[A-Za-z0-9_%=&+.-]*\z/', $target)) {
        $target = url();
    }
    header('Location: ' . $target, true, 303);
    exit;
}

function utc_input(string $value, string $offset = '0'): string
{
    if (!preg_match('/\A-?[0-9]{1,3}\z/', $offset) || abs((int) $offset) > 840) {
        throw new InvalidArgumentException('The selected timezone is invalid. Refresh the page and try again.');
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, new DateTimeZone('UTC'));
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || ($errors && ($errors['warning_count'] || $errors['error_count']))) {
        throw new InvalidArgumentException('Choose a valid closing date and time.');
    }
    return $date->modify(sprintf('%+d minutes', (int) $offset))->format('Y-m-d H:i:s');
}

function register_account(PDO $connection, array $input): int
{
    $username = trim((string) ($input['username'] ?? ''));
    $email = strtolower(trim((string) ($input['email'] ?? '')));
    $password = (string) ($input['password'] ?? '');
    if (!preg_match('/\A[\p{L}\p{N}_. -]{2,50}\z/u', $username)) {
        throw new InvalidArgumentException('Use a display name of 2–50 letters, numbers, spaces, dots or underscores.');
    }
    if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Enter a valid email address.');
    }
    if (strlen($password) < 10 || strlen($password) > 72) {
        throw new InvalidArgumentException('Choose a password between 10 and 72 characters.');
    }
    if ($password !== (string) ($input['password_confirmation'] ?? '')) {
        throw new InvalidArgumentException('The passwords do not match.');
    }
    try {
        $statement = $connection->prepare('INSERT INTO users (username,email,password_hash) VALUES (?,?,?)');
        $statement->execute([$username, $email, password_hash($password, PASSWORD_DEFAULT)]);
    } catch (PDOException $error) {
        if ($error->getCode() === '23000') {
            throw new InvalidArgumentException('That username or email is already registered.');
        }
        throw $error;
    }
    return (int) $connection->lastInsertId();
}

function sign_in(PDO $connection, string $identity, string $password): int
{
    $identity = trim($identity);
    $key = hash('sha256', strtolower($identity) . '|' . ($_SERVER['REMOTE_ADDR'] ?? 'local'));
    $directory = getenv('AUCTION_RUNTIME_DIR') ?: dirname(__DIR__) . '/runtime';
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('The application runtime directory is not writable.');
    }
    $handle = fopen($directory . '/login-' . $key . '.json', 'c+');
    if (!$handle || !flock($handle, LOCK_EX)) {
        throw new RuntimeException('Sign-in is temporarily unavailable. Please try again.');
    }
    try {
        $attempt = json_decode(stream_get_contents($handle) ?: '{}', true) ?: [];
        if ((int) ($attempt['until'] ?? 0) > time() && (int) ($attempt['count'] ?? 0) >= 10) {
            throw new InvalidArgumentException('Too many attempts. Try again in 15 minutes.');
        }
        if ((int) ($attempt['until'] ?? 0) <= time()) {
            $attempt = ['count' => 0, 'until' => time() + 900];
        }
        $statement = $connection->prepare('SELECT user_id,password_hash FROM users WHERE email = ? OR username = ? LIMIT 1');
        $statement->execute([strtolower($identity), $identity]);
        $user = $statement->fetch();
        $valid = password_verify($password, $user['password_hash'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
        $attempt['count'] = $user && $valid ? 0 : $attempt['count'] + 1;
        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, json_encode($attempt, JSON_THROW_ON_ERROR));
        if (!$user || !$valid) {
            throw new InvalidArgumentException('The email, username or password is incorrect.');
        }
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $connection->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $user['user_id']]);
        }
        return (int) $user['user_id'];
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function start_user_session(int $id): void
{
    session_regenerate_id(true);
    $_SESSION = ['user_id' => $id, 'role' => 'buyer', 'csrf' => bin2hex(random_bytes(32))];
}
