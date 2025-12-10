<?php
// tg/webapp/event_details.php
declare(strict_types=1);


require_once __DIR__ . '/../../db.php';

// ====== helpers ======
function h(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ====== input ======
$eventId = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;
if ($eventId <= 0) {
    http_response_code(400);
    echo "Invalid event_id";
    exit;
}

// ====== main event + client ======
$sql = "
    SELECT
        e.id           AS event_id,
        e.site_id,
        e.label,
        e.score,
        e.url,
        e.ip,
        e.ua,
        e.created_at,

        s.url          AS site_url,
        s.title        AS site_title,

        c.id           AS client_id,
        c.country,
        c.country_code,
        c.region,
        c.region_name,
        c.city,
        c.zip_code,
        c.latitude,
        c.longitude,
        c.timezone,
        c.currency,
        c.isp,
        c.organization,
        c.is_proxy,
        c.request_time AS client_request_time
    FROM waf_events e
    LEFT JOIN sites   s ON s.id = e.site_id
    LEFT JOIN clients c
           ON c.site_id = e.site_id
          AND c.ip      = e.ip
    WHERE e.id = :event_id
    LIMIT 1
";
$stmt = $pdo->prepare($sql);
$stmt->execute([':event_id' => $eventId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

// Підготуємо місце під додаткові таблиці
$browser = $headers = $conn = null;

if ($row && !empty($row['client_id'])) {
    $clientId = (int)$row['client_id'];

    // ====== browsers (останній запис по client_id) ======
    $stmt = $pdo->prepare("
        SELECT
            name,
            version,
            os,
            device,
            user_agent
        FROM browsers
        WHERE client_id = :cid
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([':cid' => $clientId]);
    $browser = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    // ====== headers (останній запис по client_id) ======
    $stmt = $pdo->prepare("
        SELECT
            accept_language,
            referer,
            accept_encoding,
            cache_control,
            connection
        FROM headers
        WHERE client_id = :cid
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([':cid' => $clientId]);
    $headers = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    // ====== connection_info (останній запис по client_id) ======
    $stmt = $pdo->prepare("
        SELECT
            remote_port,
            server_port,
            request_scheme,
            server_protocol
        FROM connection_info
        WHERE client_id = :cid
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([':cid' => $clientId]);
    $conn = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$title = $row ? ("Інцидент #" . $row['event_id']) : "Інцидент не знайдено";

?><!doctype html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <title><?= h($title) ?> – TestFixed</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- Telegram WebApp JS -->
    <script src="https://telegram.org/js/telegram-web-app.js"></script>
    <style>
        :root {
            color-scheme: light dark;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }
        body {
            margin: 0;
            padding: 12px;
            background: var(--tg-theme-bg-color, #111);
            color: var(--tg-theme-text-color, #fff);
        }
        .card {
            background: rgba(0,0,0,0.16);
            border-radius: 12px;
            padding: 12px 14px;
            margin-bottom: 10px;
            border: 1px solid rgba(255,255,255,0.06);
        }
        h1, h2, h3 {
            margin: 0 0 8px 0;
            font-size: 18px;
        }
        .label {
            font-size: 12px;
            opacity: .7;
        }
        .value {
            font-size: 14px;
            margin-bottom: 4px;
            word-break: break-all;
        }
        .grid {
            display: grid;
            grid-template-columns: minmax(0,1fr) minmax(0,1fr);
            gap: 4px 12px;
        }
        .chip {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 12px;
            background: rgba(255,255,255,0.09);
            margin-right: 4px;
            margin-bottom: 4px;
        }
        code {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
            font-size: 12px;
        }
    </style>
</head>
<body>
<script>
    const tg = window.Telegram?.WebApp;
    if (tg) {
        tg.ready();
        tg.expand();
    }
</script>

<?php if (!$row): ?>
    <div class="card">
        <h1>Інцидент не знайдено</h1>
        <div class="value">event_id = <?= (int)$eventId ?></div>
    </div>
<?php else: ?>
    <!-- Інцидент -->
    <div class="card">
        <h1>Інцидент #<?= (int)$row['event_id'] ?></h1>
        <div class="value">
            <span class="chip"><?= h($row['label'] ?? 'невідомо') ?></span>
            <?php if ($row['score'] !== null): ?>
                <span class="chip">Score: <?= h((string)$row['score']) ?></span>
            <?php endif; ?>
        </div>
        <div class="value">
            <span class="label">Сайт:</span>
            <?= h($row['site_title'] ?? '') ?>
            <?php if (!empty($row['site_url'])): ?>
                (<?= h($row['site_url']) ?>)
            <?php endif; ?>
        </div>
        <div class="value">
            <span class="label">URL:</span> <?= h($row['url'] ?? '') ?>
        </div>
        <div class="value">
            <span class="label">Час події:</span> <?= h((string)$row['created_at']) ?>
        </div>
    </div>

    <!-- Клієнт (clients) -->
    <div class="card">
        <h2>Клієнт</h2>
        <div class="grid">
            <div>
                <div class="label">IP</div>
                <div class="value"><?= h($row['ip'] ?? '-') ?></div>
            </div>
            <div>
                <div class="label">Провайдер</div>
                <div class="value"><?= h($row['isp'] ?? '-') ?></div>
            </div>
            <div>
                <div class="label">Організація</div>
                <div class="value"><?= h($row['organization'] ?? '-') ?></div>
            </div>
            <div>
                <div class="label">Проксі/VPN</div>
                <div class="value">
                    <?php
                    if ($row['is_proxy'] === null) {
                        echo '-';
                    } else {
                        echo $row['is_proxy'] ? 'так' : 'ні';
                    }
                    ?>
                </div>
            </div>
            <div>
                <div class="label">Країна / регіон / місто</div>
                <div class="value">
                    <?= h($row['country'] ?? '-') ?>,
                    <?= h($row['region_name'] ?? $row['region'] ?? '-') ?>,
                    <?= h($row['city'] ?? '-') ?>
                </div>
            </div>
            <div>
                <div class="label">Поштовий індекс</div>
                <div class="value"><?= h($row['zip_code'] ?? '-') ?></div>
            </div>
            <div>
                <div class="label">Часовий пояс</div>
                <div class="value"><?= h($row['timezone'] ?? '-') ?></div>
            </div>
            <div>
                <div class="label">Валюта</div>
                <div class="value"><?= h($row['currency'] ?? '-') ?></div>
            </div>
            <div>
                <div class="label">Координати</div>
                <div class="value">
                    <?php if ($row['latitude'] !== null && $row['longitude'] !== null): ?>
                        <?= h((string)$row['latitude']) ?>, <?= h((string)$row['longitude']) ?>
                    <?php else: ?>
                        -
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="value">
            <span class="label">Останній візит IP:</span>
            <?= h($row['client_request_time'] ?? '-') ?>
        </div>
    </div>

    <!-- Браузер (browsers) -->
    <div class="card">
        <h2>Браузер</h2>
        <?php if (!$browser): ?>
            <div class="value">Немає даних по браузеру.</div>
        <?php else: ?>
            <div class="grid">
                <div>
                    <div class="label">Назва</div>
                    <div class="value"><?= h($browser['name'] ?? '-') ?></div>
                </div>
                <div>
                    <div class="label">Версія</div>
                    <div class="value"><?= h($browser['version'] ?? '-') ?></div>
                </div>
                <div>
                    <div class="label">ОС</div>
                    <div class="value"><?= h($browser['os'] ?? '-') ?></div>
                </div>
                <div>
                    <div class="label">Пристрій</div>
                    <div class="value"><?= h($browser['device'] ?? '-') ?></div>
                </div>
            </div>
            <div class="value">
                <div class="label">User-Agent</div>
                <div class="value"><code><?= h($browser['user_agent'] ?? '-') ?></code></div>
            </div>
        <?php endif; ?>
    </div>

    <!-- HTTP-заголовки (headers) -->
    <div class="card">
        <h2>HTTP-заголовки</h2>
        <?php if (!$headers): ?>
            <div class="value">Немає даних по заголовках.</div>
        <?php else: ?>
            <div class="grid">
                <div>
                    <div class="label">Accept-Language</div>
                    <div class="value"><code><?= h($headers['accept_language'] ?? '-') ?></code></div>
                </div>
                <div>
                    <div class="label">Referer</div>
                    <div class="value"><code><?= h($headers['referer'] ?? '-') ?></code></div>
                </div>
                <div>
                    <div class="label">Accept-Encoding</div>
                    <div class="value"><code><?= h($headers['accept_encoding'] ?? '-') ?></code></div>
                </div>
                <div>
                    <div class="label">Cache-Control</div>
                    <div class="value"><code><?= h($headers['cache_control'] ?? '-') ?></code></div>
                </div>
                <div>
                    <div class="label">Connection</div>
                    <div class="value"><code><?= h($headers['connection'] ?? '-') ?></code></div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Параметри з'єднання (connection_info) -->
    <div class="card">
        <h2>Параметри з'єднання</h2>
        <?php if (!$conn): ?>
            <div class="value">Немає даних по з'єднанню.</div>
        <?php else: ?>
            <div class="grid">
                <div>
                    <div class="label">Remote port</div>
                    <div class="value"><?= h($conn['remote_port'] ?? '-') ?></div>
                </div>
                <div>
                    <div class="label">Server port</div>
                    <div class="value"><?= h($conn['server_port'] ?? '-') ?></div>
                </div>
                <div>
                    <div class="label">Scheme</div>
                    <div class="value"><?= h($conn['request_scheme'] ?? '-') ?></div>
                </div>
                <div>
                    <div class="label">Protocol</div>
                    <div class="value"><?= h($conn['server_protocol'] ?? '-') ?></div>
                </div>
            </div>
        <?php endif; ?>
    </div>

<?php endif; ?>

</body>
</html>
