<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../db.php';

$site_id = (int)($_GET['site_id'] ?? 0);
$from    = $_GET['from']  ?? null;
$to      = $_GET['to']    ?? null;
$label   = $_GET['label'] ?? 'all';
$tz      = $_GET['tz']    ?? 'UTC';

try {
    $sql = "
        SELECT 
            w.id,
            to_char(w.created_at AT TIME ZONE 'UTC' AT TIME ZONE :tz, 'YYYY-MM-DD HH24:MI:SS') AS created_at,
            w.ip,
            w.label,
            w.score,
            w.url,
            w.ref,
            w.ua,
            c.country
        FROM waf_events w
        LEFT JOIN clients c ON c.ip = w.ip
        WHERE w.site_id = :site_id
    ";

    $prm = [':site_id' => $site_id, ':tz' => $tz];

    if ($from) {
        $sql .= " AND w.created_at >= :from";
        $prm[':from'] = $from . " 00:00:00";
    }

    if ($to) {
        $sql .= " AND w.created_at <= :to";
        $prm[':to'] = $to . " 23:59:59";
    }

    if ($label !== "all") {
        $sql .= " AND w.label = :label";
        $prm[':label'] = $label;
    }

    $sql .= " ORDER BY w.created_at DESC LIMIT 5000";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($prm);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['events' => $rows, 'timezone' => $tz], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log('GET_EVENTS_ERROR: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error'], JSON_UNESCAPED_UNICODE);
}
