<?php
declare(strict_types=1);
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json; charset=UTF-8');

$site_id = (int)($_GET['site_id'] ?? 0);
$fromRaw = $_GET['from'] ?? '';
$toRaw   = $_GET['to']   ?? '';
$tz      = $_GET['tz']   ?? 'UTC';

if ($site_id <= 0) {
    echo json_encode(['stats' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($tz === '' || !in_array($tz, timezone_identifiers_list(), true)) {
    $tz = 'UTC';
}

/**
 * Нормалізація дати:
 *  - приймає "2025-11-22" АБО "22.11.2025"
 *  - повертає "2025-11-22" або null
 */
function normalizeDate(?string $s): ?string
{
    $s = trim((string)$s);
    if ($s === '') return null;

    $dt = DateTime::createFromFormat('Y-m-d', $s)
        ?: DateTime::createFromFormat('d.m.Y', $s);

    return $dt ? $dt->format('Y-m-d') : null;
}

$fromDay = normalizeDate($fromRaw);
$toDay   = normalizeDate($toRaw);

/**
 * 1) ОДИН ДЕНЬ → 24 точки (0..23), час у TZ користувача
 */
if ($fromDay && $toDay && $fromDay === $toDay) {

    $dayStart = $fromDay . ' 00:00:00';

    $sql = "
        WITH hours AS (
            SELECT generate_series(
                :day_start::timestamp,
                :day_start::timestamp + interval '23 hour',
                interval '1 hour'
            ) AS ts
        ),
        events AS (
            SELECT
                date_trunc('hour', created_at AT TIME ZONE :tz) AS ts,
                COUNT(*) AS cnt
            FROM waf_events
            WHERE site_id = :site_id
              AND created_at >= :day_start::timestamp
              AND created_at <  (:day_start::timestamp + interval '1 day')
            GROUP BY 1
        )
        SELECT
            to_char(h.ts, 'HH24:00') AS ts,
            COALESCE(e.cnt, 0)       AS cnt
        FROM hours h
        LEFT JOIN events e ON e.ts = h.ts
        ORDER BY h.ts ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':site_id'   => $site_id,
        ':day_start' => $dayStart,
        ':tz'        => $tz,
    ]);

    echo json_encode(['stats' => $stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * 2) Діапазон до 2 днів → погодинна статистика в TZ
 */
if ($fromDay && $toDay) {
    $fromDt = new DateTime($fromDay);
    $toDt   = new DateTime($toDay);
    $diff   = $fromDt->diff($toDt)->days; // 0,1,...

    if ($diff > 0 && $diff <= 1) {

        $fromTs = $fromDay . ' 00:00:00';
        $toTs   = $toDay   . ' 23:00:00';

        $sql = "
            WITH hours AS (
                SELECT generate_series(
                    :from_ts::timestamp,
                    :to_ts::timestamp,
                    interval '1 hour'
                ) AS ts
            ),
            events AS (
                SELECT
                    date_trunc('hour', created_at AT TIME ZONE :tz) AS ts,
                    COUNT(*) AS cnt
                FROM waf_events
                WHERE site_id = :site_id
                  AND created_at >= :from_ts::timestamp
                  AND created_at <  (:to_ts::timestamp + interval '1 hour')
                GROUP BY 1
            )
            SELECT
                to_char(h.ts, 'YYYY-MM-DD HH24:00') AS ts,
                COALESCE(e.cnt, 0)                  AS cnt
            FROM hours h
            LEFT JOIN events e ON e.ts = h.ts
            ORDER BY h.ts ASC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':site_id' => $site_id,
            ':from_ts' => $fromTs,
            ':to_ts'   => $toTs,
            ':tz'      => $tz,
        ]);

        echo json_encode(['stats' => $stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

/**
 * 3) 3+ дні або незаданий діапазон → агрегація по днях у TZ
 */
$where  = ['site_id = :site_id'];
$params = [
    ':site_id' => $site_id,
    ':tz'      => $tz,
];

if ($fromDay) {
    $where[]           = 'created_at >= :from_ts';
    $params[':from_ts'] = $fromDay . ' 00:00:00';
}
if ($toDay) {
    $where[]           = 'created_at <= :to_ts';
    $params[':to_ts']   = $toDay . ' 23:59:59';
}

$sql = "
    SELECT
        to_char(
            date_trunc('day', created_at AT TIME ZONE :tz),
            'YYYY-MM-DD'
        ) AS ts,
        COUNT(*) AS cnt
    FROM waf_events
    WHERE " . implode(' AND ', $where) . "
    GROUP BY 1
    ORDER BY 1 ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

echo json_encode(['stats' => $stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
