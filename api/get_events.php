<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../db.php';

$site_id = (int)($_GET['site_id'] ?? 0);
$from    = $_GET['from']  ?? null; // "YYYY-MM-DD" або порожньо
$to      = $_GET['to']    ?? null;
$label   = $_GET['label'] ?? 'all';
$tz      = $_GET['tz']    ?? 'UTC';

if ($site_id <= 0) {
    echo json_encode(['events' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

// валідуємо таймзону
if ($tz === '' || !in_array($tz, timezone_identifiers_list(), true)) {
    $tz = 'UTC';
}

try {
    $sql = "
        SELECT 
            w.id,
            to_char(
                w.created_at AT TIME ZONE :tz,
                'YYYY-MM-DD HH24:MI:SS'
            ) AS created_at,
            w.ip,
            w.label,
            w.score,
            w.url,
            w.ref,
            c.country
        FROM waf_events w
        LEFT JOIN clients c
               ON c.ip = w.ip
        WHERE w.site_id = :site_id
    ";

    $params = [
        ':site_id' => $site_id,
        ':tz'      => $tz,
    ];

    if ($from) {
        $sql .= " AND w.created_at >= :from_ts";
        $params[':from_ts'] = $from . ' 00:00:00';
    }

    if ($to) {
        $sql .= " AND w.created_at <= :to_ts";
        $params[':to_ts'] = $to . ' 23:59:59';
    }

    if ($label !== 'all') {
        $sql .= " AND w.label = :label";
        $params[':label'] = $label;
    }

    $sql .= " ORDER BY w.created_at DESC LIMIT 5000";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id'         => $r['id'],
            'created_at' => $r['created_at'],
            'ip'         => $r['ip'],
            'country'    => $r['country'] ?? '',
            'label'      => $r['label'],
            'score'      => $r['score'],
            'url'        => $r['url'],
            'ref'        => $r['ref'],
        ];
    }

    echo json_encode(['events' => $out], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log('GET_EVENTS_ERROR: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error'], JSON_UNESCAPED_UNICODE);
}
