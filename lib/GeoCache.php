<?php
declare(strict_types=1);
require_once __DIR__ . '/../db.php';

final class GeoCache {
    public static function get(string $ip, int $ttlSec = 3600): ?array {
        global $pdo;
        if (!filter_var($ip, FILTER_VALIDATE_IP)) return null;
        $stmt = $pdo->prepare("SELECT payload, EXTRACT(EPOCH FROM (NOW()-updated_at)) AS age FROM geo_cache WHERE ip=?");
        $stmt->execute([$ip]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        if ((int)$row['age'] > $ttlSec) return null;
        $payload = json_decode($row['payload'] ?? 'null', true);
        return is_array($payload) ? $payload : null;
    }

    public static function put(string $ip, array $payload): void {
        global $pdo;
        if (!filter_var($ip, FILTER_VALIDATE_IP)) return;
        $stmt = $pdo->prepare("INSERT INTO geo_cache(ip,payload,updated_at)
                               VALUES(?,?,NOW())
                               ON CONFLICT (ip) DO UPDATE SET payload=EXCLUDED.payload, updated_at=NOW()");
        $stmt->execute([$ip, json_encode($payload, JSON_UNESCAPED_UNICODE)]);
    }
}
