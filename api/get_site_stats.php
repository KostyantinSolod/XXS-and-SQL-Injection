<?php
declare(strict_types=1);

session_start();
if (!isset($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

require_once __DIR__ . '/../db.php';

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

$siteId = (int)($_GET['site_id'] ?? 0);
$range  = $_GET['range'] ?? '7d'; // 1d,2d,7d,30d,60d,6m,12m

if ($siteId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'invalid site_id']);
    exit;
}

// витягаємо таймзону користувача
$stmt = $pdo->prepare("
    SELECT COALESCE(us.timezone, 'UTC') AS tz
    FROM sites s
    JOIN users u ON u.id = s.user_id
    LEFT JOIN user_settings us ON us.user_id = u.id
    WHERE s.id = ? AND u.username = ?
");
$stmt->execute([$siteId, $_SESSION['user']]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    echo json_encode(['ok' => false, 'error' => 'site not found or access denied']);
    exit;
}

$tz = $row['tz'] ?: 'UTC';

// обчислюємо період у локальній таймзоні
$nowLocal = new DateTimeImmutable('now', new DateTimeZone($tz));

switch ($range) {
    case '1d':  $fromLocal = $nowLocal->sub(new DateInterval('P1D'));  break;
    case '2d':  $fromLocal = $nowLocal->sub(new DateInterval('P2D'));  break;
    case '7d':  $fromLocal = $nowLocal->sub(new DateInterval('P7D'));  break;
    case '30d': $fromLocal = $nowLocal->sub(new DateInterval('P30D')); break;
    case '60d': $fromLocal = $nowLocal->sub(new DateInterval('P60D')); break;
    case '6m':  $fromLocal = $nowLocal->sub(new DateInterval('P6M'));  break;
    case '12m': $fromLocal = $nowLocal->sub(new DateInterval('P12M')); break;
    default:    $fromLocal = $nowLocal->sub(new DateInterval('P7D'));  $range = '7d';
}

$diffDays = ($nowLocal->getTimestamp() - $fromLocal->getTimestamp()) / 86400.0;

// правило бінів:
// <= 2 днів – по годинах
// <= 60 днів – по днях
// > 60 днів – по місяцях
if ($diffDays <= 2.1) {
    $bucket = 'hour';
} elseif ($diffDays <= 60.5) {
    $bucket = 'day';
} else {
    $bucket = 'month';
}

// конвертуємо в UTC для БД
$fromUtc = $fromLocal->setTimezone(new DateTimeZone('UTC'));
$toUtc   = $nowLocal->setTimezone(new DateTimeZone('UTC'));

$sql = "
    SELECT
        CASE
            WHEN :bucket = 'hour' THEN date_trunc('hour', created_at AT TIME ZONE :tz)
            WHEN :bucket = 'day'  THEN date_trunc('day',  created_at AT TIME ZONE :tz)
            ELSE                        date_trunc('month',created_at AT TIME ZONE :tz)
        END AS bucket_start,
        COUNT(*)::int AS total,
        SUM(CASE WHEN label ILIKE 'xss%'  THEN 1 ELSE 0 END)::int AS xss,
        SUM(CASE WHEN label ILIKE 'sqli%' THEN 1 ELSE 0 END)::int AS sqli
    FROM waf_events
    WHERE site_id = :site_id
      AND created_at >= :from_utc
      AND created_at <  :to_utc
    GROUP BY bucket_start
    ORDER BY bucket_start
";
$stmt = $pdo->prepare($sql);
$stmt->execute([
    ':bucket'   => $bucket,
    ':tz'       => $tz,
    ':site_id'  => $siteId,
    ':from_utc' => $fromUtc->format('Y-m-d H:i:sP'),
    ':to_utc'   => $toUtc->format('Y-m-d H:i:sP'),
]);

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$points = [];
foreach ($rows as $r) {
    $points[] = [
        't'     => $r['bucket_start'],   // локальний час (без TZ)
        'total' => (int)$r['total'],
        'xss'   => (int)$r['xss'],
        'sqli'  => (int)$r['sqli'],
    ];
}

echo json_encode([
    'ok'       => true,
    'bucket'   => $bucket,     // hour|day|month
    'timezone' => $tz,
    'range'    => $range,
    'from'     => $fromLocal->format(DATE_ATOM),
    'to'       => $nowLocal->format(DATE_ATOM),
    'points'   => $points,
]);
