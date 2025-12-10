<?php
function loadEnv($path) {
    if (!file_exists($path)) return;

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        list($name, $value) = explode('=', $line, 2);
        putenv(trim($name) . '=' . trim($value));
    }
}

loadEnv(__DIR__ . '/.env');

$host = getenv('DB_HOST');
$port = getenv('DB_PORT');
$dbname = getenv('DB_NAME');
$user = getenv('DB_USER');
$pass = getenv('DB_PASS');

$dsn = "pgsql:host=$host;port=$port;dbname=$dbname";

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false // ВАЖЛИВО: вимикаємо емульовані prepares
    ]);
} catch (PDOException $e) {
    // Лог помилки в файл, але не виводимо прямо (щоб не ламати headers)
    $errMsg = "DB CONNECT ERROR: " . $e->getMessage();
    @file_put_contents(__DIR__ . '/db_error.log', "[".date('Y-m-d H:i:s')."] " . $errMsg . PHP_EOL, FILE_APPEND);
    // Надаємо зрозумілий JSON, якщо викликається через HTTP
    if (php_sapi_name() !== 'cli') {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(500);
        echo json_encode(['ok'=>false,'error'=>'db: internal']);
        exit;
    } else {
        // Для CLI просто вийти з кодом помилки
        fwrite(STDERR, $errMsg . PHP_EOL);
        exit(1);
    }
}


function log_action($user_id, $action, $details = '') {
    global $pdo;
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $stmt = $pdo->prepare("INSERT INTO user_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
    $stmt->execute([$user_id, $action, $details, $ip]);
}


