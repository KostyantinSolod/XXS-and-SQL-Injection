<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/ClientInfo.php';

header('Content-Type: application/json; charset=UTF-8');

$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM waf_events WHERE id = ?");
$stmt->execute([$id]);
$e = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$e) {
    echo json_encode(['ok' => false]);
    exit;
}

$ip = $e['ip'];

// === Гео + браузер + заголовки ===
$geo = ClientInfo::geoByIp($ip) ?? [];
$ua  = ClientInfo::parseUserAgent($e['ua']);

echo json_encode([
    'ok' => true,
    'data' => [
        'id'       => $e['id'],
        'created_at'=> $e['created_at'],
        'ip'       => $ip,
        'url'      => $e['url'],
        'ref'      => $e['ref'],
        'ua'       => $e['ua'],
        'label'    => $e['label'],
        'score'    => $e['score'],

        // ClientInfo
        'country' => $geo['country'] ?? null,
        'city'    => $geo['city'] ?? null,
        'timezone'=> $geo['timezone'] ?? null,
        'isp'     => $geo['isp'] ?? null,
        'proxy'   => $geo['proxy'] ?? false,
        'hosting' => $geo['hosting'] ?? false,

        // UA
        'browser'        => $ua['name'] ?? null,
        'browser_version'=> $ua['version'] ?? null,
        'os_name'        => $ua['os'] ?? null,
        'device_type'    => $ua['device'] ?? null,
    ]
]);
