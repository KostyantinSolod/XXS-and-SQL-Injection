<?php
declare(strict_types=1);
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../lib/ClientInfo.php';

$debugFile = __DIR__ . '/../waf_debug.log';

// CORS + headers
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    exit;
}
header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');

// Лог старту
@file_put_contents($debugFile, "[".date('Y-m-d H:i:s')."] waf_log START" . PHP_EOL, FILE_APPEND);

// Лог сирого input + POST + SERVER (основні поля)
$rawInput = file_get_contents('php://input');
@file_put_contents($debugFile, "[".date('Y-m-d H:i:s')."] RAW_INPUT: " . substr($rawInput, 0, 2000) . PHP_EOL, FILE_APPEND);
@file_put_contents($debugFile, "[".date('Y-m-d H:i:s')."] _POST: " . json_encode($_POST, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND);
@file_put_contents(
    $debugFile,
    "[".date('Y-m-d H:i:s')."] SERVER_KEYS: " .
    json_encode(array_intersect_key($_SERVER, array_flip([
        'REMOTE_ADDR','HTTP_X_FORWARDED_FOR','HTTP_CF_CONNECTING_IP','REQUEST_URI','HTTP_USER_AGENT'
    ])), JSON_UNESCAPED_UNICODE) .
    PHP_EOL,
    FILE_APPEND
);

// Пробуємо розпарсити як JSON (на випадок, якщо колись буде application/json)
$body = json_decode($rawInput, true);
if (!is_array($body)) {
    $body = [];
}

// Дістаємо site_token
$site_token = $_POST['site_token']
    ?? $body['site_token']
    ?? $body['siteToken']
    ?? null;

$params_raw = $_POST['params']
    ?? $body['params']
    ?? null;

if (!$site_token) {
    echo json_encode(['ok' => false, 'error' => 'missing site_token']);
    exit;
}

// Знаходимо сайт
$stmt = $pdo->prepare("SELECT id, title FROM sites WHERE protect_token = ?");
$stmt->execute([$site_token]);
$site = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$site) {
    @file_put_contents($debugFile, "[".date('Y-m-d H:i:s')."] INVALID_TOKEN: {$site_token}" . PHP_EOL, FILE_APPEND);
    echo json_encode(['ok' => false, 'error' => 'invalid token or site not found']);
    exit;
}
$site_id = (int)$site['id'];

// Розбір params
$params = [];
if (is_string($params_raw)) {
    $decoded = json_decode($params_raw, true);
    if (is_array($decoded)) {
        $params = $decoded;
    } else {
        parse_str($params_raw, $parsed);
        if (is_array($parsed) && count($parsed)) {
            $params = $parsed;
        } else {
            $params = ['raw' => $params_raw];
        }
    }
} elseif (is_array($params_raw)) {
    $params = $params_raw;
}

// Дістаємо реальний IP через ClientInfo
$ip = ClientInfo::clientIp();
if (!$ip || in_array($ip, ['::1','127.0.0.1'], true)) {
    $external = ClientInfo::fetchServerPublicIp();
    if ($external) $ip = $external;
}
@file_put_contents($debugFile, "[".date('Y-m-d H:i:s')."] RESOLVED_IP: {$ip}" . PHP_EOL, FILE_APPEND);

// Вставка в waf_events
try {
    $stmt = $pdo->prepare("
        INSERT INTO waf_events (site_id, label, score, url, ua, ip, ref, raw, created_at)
        VALUES (:site_id, :label, :score, :url, :ua, :ip, :ref, :raw, NOW())
        RETURNING id
    ");
    $stmt->execute([
        ':site_id' => $site_id,
        ':label'   => $params['label'] ?? $params['type'] ?? 'unknown',
        ':score'   => $params['score'] ?? 0,
        ':url'     => $params['url'] ?? '',
        ':ua'      => $params['ua'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? ''),
        ':ip'      => $ip,
        ':ref'     => $params['ref'] ?? '',
        ':raw'     => json_encode($params, JSON_UNESCAPED_UNICODE)
    ]);
    $event_id = $stmt->fetchColumn();
    @file_put_contents($debugFile, "[".date('Y-m-d H:i:s')."] INSERT_OK event_id={$event_id} site_id={$site_id}" . PHP_EOL, FILE_APPEND);
} catch (Throwable $e) {
    @file_put_contents($debugFile, "[".date('Y-m-d H:i:s')."] INSERT_ERROR: ".$e->getMessage() . PHP_EOL, FILE_APPEND);
    echo json_encode(['ok' => false, 'error' => 'db_insert_failed', 'msg' => $e->getMessage()]);
    exit;
}

try {
    ClientInfo::collectAndStore($pdo, $ip, (int)$event_id, (int)$site_id);
} catch (Throwable $e) {
    @file_put_contents($debugFile, "[".date('Y-m-d H:i:s')."] CLIENTINFO_ERROR: ".$e->getMessage() . PHP_EOL, FILE_APPEND);
}


echo json_encode([
    'ok'       => true,
    'event_id' => $event_id,
    'ip'       => $ip
]);
