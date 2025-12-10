<?php
session_start();
if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/db.php';

// Таймзона користувача для відображення дат
$uiTimezone = $_SESSION['timezone'] ?? 'UTC';

if (!function_exists('gen_protect_token')) {
    function gen_protect_token(): string {
        return rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    }
}

$site_id = (int)($_GET['id'] ?? 0);

// Завантажуємо сайт + власника
$stmt = $pdo->prepare("
    SELECT
        s.*,
        u.id          AS uid,
        u.username,
        u.password    AS hash,
        u.telegram_id  AS user_tg_user_id,
        u.tg_username AS user_tg_username
    FROM sites s
    JOIN users u ON u.id = s.user_id
    WHERE s.id = ?
");

$stmt->execute([$site_id]);
$site = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$site || $site['username'] !== $_SESSION['user']) {
    header('Location: dashboard.php');
    exit;
}

$user_id = (int)$site['uid'];

// Генеруємо токен, якщо порожній
if (empty($site['protect_token'])) {
    $tok = gen_protect_token();
    $stmt = $pdo->prepare("UPDATE sites SET protect_token=? WHERE id=? AND user_id=?");
    $stmt->execute([$tok, $site_id, $user_id]);
    $site['protect_token'] = $tok;
}

// Гарантуємо наявність колонки для шаблону бота
$pdo->exec("ALTER TABLE sites ADD COLUMN IF NOT EXISTS tg_alert_template TEXT");
$user_id = (int)$site['uid'];

// Часовий пояс користувача (для відображення дат)
$stmtTz = $pdo->prepare("SELECT timezone FROM user_settings WHERE user_id = ?");
$stmtTz->execute([$user_id]);
$uiTzRow   = $stmtTz->fetch(PDO::FETCH_ASSOC);
$uiTimezone = $uiTzRow['timezone'] ?? ($_SESSION['timezone'] ?? 'UTC');

$tab = $_GET['tab'] ?? 'stats';
$success = $error = '';

// ---------------- POST HANDLERS ---------------- //
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Видалення сайту
    if (($_POST['action'] ?? '') === 'delete') {
        $stmtDel = $pdo->prepare("DELETE FROM sites WHERE id=? AND user_id=?");
        $stmtDel->execute([$site_id, $user_id]);
        header('Location: dashboard.php');
        exit;
    }

    // Збереження шаблону повідомлення Telegram
    if (($_POST['action'] ?? '') === 'save_bot_template') {
        $tpl = trim($_POST['bot_template'] ?? '');

        $stmtUpd = $pdo->prepare(
            "UPDATE sites SET tg_alert_template=? WHERE id=? AND user_id=?"
        );
        $stmtUpd->execute([$tpl, $site_id, $user_id]);

        // 🔧 ВАЖЛИВО: оновлюємо локальний масив, з якого рендериться форма
        $site['tg_alert_template'] = $tpl;

        $success = "✅ Текст бота збережено.";
        $tab = 'settings';
    }


    // Ротація токена
    if (($_POST['action'] ?? '') === 'rotate_token') {
        $new = gen_protect_token();
        $stmt = $pdo->prepare("UPDATE sites SET protect_token=? WHERE id=? AND user_id=?");
        $stmt->execute([$new, $site_id, $user_id]);
        $site['protect_token'] = $new;
        $success = "🔐 Токен оновлено. Не забудьте оновити скрипт на клієнтському сайті.";
        $tab = 'code';
    }

    // Прив'язка Telegram (приват/група)
    if (in_array($_POST['action'] ?? '', ['tg_private','tg_group'], true)) {
        $action  = $_POST['action'];
        $chat_id = trim($_POST['chat_id'] ?? '');

        // ✅ Приватний чат -> шлемо в tg_user_id власника сайту
        if ($action === 'tg_private') {
            $chat_id = $site['user_tg_user_id'] ?? null;   // беремо з users
            if (empty($chat_id)) {
                $error = "❌ Немає прив'язаного Telegram-акаунта. "
                    . "Спершу натисніть «Прив’язати Telegram» на сайті і запустіть бота.";
            }
        }


        // ✅ Для групи Chat ID обов'язковий
        if ($action === 'tg_group' && $chat_id === '') {
            $error = "❌ Для групового чату потрібно ввести Chat ID або обрати зі списку.";
        }

        if (empty($error)) {
            $pdo->exec("ALTER TABLE sites ADD COLUMN IF NOT EXISTS telegram_chat_id VARCHAR(50)");
            $pdo->exec("ALTER TABLE sites ADD COLUMN IF NOT EXISTS telegram_type VARCHAR(20)");

            $stmtUpd = $pdo->prepare("
            UPDATE sites
               SET telegram_chat_id = ?,
                   telegram_type    = ?
             WHERE id      = ?
               AND user_id = ?
        ");
            $stmtUpd->execute([$chat_id, $action, $site_id, $user_id]);

            // оновимо локальний масив, щоб відразу показувало актуальне
            $site['telegram_chat_id'] = $chat_id;
            $site['telegram_type']    = $action;

            $success = "✅ Сайт приєднано до Telegram (" .
                ($action === 'tg_private' ? 'Приватний чат' : 'Груповий чат') . ").";
        }

        $tab = 'settings';
    }

}

include __DIR__ . '/header.php';
?>
<div class="container">
    <h1 class="mb-4"><?= htmlspecialchars($site['title'] ?: $site['url']) ?></h1>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <ul class="nav nav-tabs mb-4">
        <li class="nav-item">
            <a class="nav-link <?= $tab==='stats'?'active':'' ?>" href="?id=<?= $site_id ?>&tab=stats">Статистика</a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $tab==='code'?'active':'' ?>" href="?id=<?= $site_id ?>&tab=code">Код</a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $tab==='settings'?'active':'' ?>" href="?id=<?= $site_id ?>&tab=settings">Налаштування</a>
        </li>
    </ul>

    <?php if ($tab === 'stats'): ?>

        <!-- ФІЛЬТРИ -->
        <div class="d-flex mb-3 gap-2 flex-wrap">
            <input type="date" id="from" class="form-control" style="max-width:180px">
            <input type="date" id="to"   class="form-control" style="max-width:180px">

            <select id="label" class="form-control" style="max-width:150px">
                <option value="all">Всі типи</option>
                <option value="xss">XSS</option>
                <option value="sqli">SQL Injection</option>
            </select>

            <button class="btn btn-primary" onclick="loadAll()">Застосувати</button>
        </div>

        <!-- ГРАФІК -->
        <div class="card mb-4">
            <div class="card-body" style="height:280px">
                <canvas id="chart"></canvas>
            </div>
        </div>

        <!-- ТАБЛИЦЯ -->
        <div class="card">
            <div class="card-body">
                <table id="eventsTable"
                       class="table table-striped table-bordered mb-0 waf-table"
                       style="width:100%"></table>

            </div>
        </div>

        <!-- МОДАЛЬНЕ ВІКНО (Bootstrap) -->
        <div class="modal fade waf-modal" id="eventModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Деталі події</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body" id="modalBody">
                        Завантаження...
                    </div>
                </div>
            </div>
        </div>

        <!-- jQuery + DataTables + Chart.js -->
        <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
        <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
        <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

        <script>
            // Таймзона, збережена для користувача (з PHP)
            const USER_TIMEZONE = <?= json_encode($uiTimezone) ?> || 'UTC';
            const tz = USER_TIMEZONE;

            // Форматування created_at для ТАБЛИЦІ (як було)
            function formatDateToUserTz(ts) {
                if (!ts) return '';
                ts = String(ts).trim();

                // очікуємо "YYYY-MM-DD HH:MM[:SS]"
                const m = /^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2})(?::(\d{2}))?$/.exec(ts);
                if (!m) return ts;

                const [, y, mo, d, h, mi, s] = m;
                return `${d}.${mo}.${y}, ${h}:${mi}:${s || '00'}`;
            }


            let chartInstance = null;

            // Парсимо ts, який ПРИХОДИТЬ ВЖЕ В ЛОКАЛЬНОМУ ЧАСІ з get_stats.php
            function parseStatTs(ts) {
                ts = String(ts || '').trim();

                // "YYYY-MM-DD HH:MM" або "YYYY-MM-DD HH:MM:SS"
                let m = ts.match(/^(\d{4}-\d{2}-\d{2})\s+(\d{2}:\d{2})(?::\d{2})?$/);
                if (m) {
                    return {
                        date:  m[1],
                        label: m[2]   // HH:MM для осі X
                    };
                }

                // "YYYY-MM-DD" (агрегація по днях)
                m = ts.match(/^(\d{4}-\d{2}-\d{2})$/);
                if (m) {
                    return {
                        date:  m[1],
                        label: m[1]   // показуємо сам день
                    };
                }

                // "HH:MM" (один день, погодинно)
                m = ts.match(/^(\d{2}:\d{2})$/);
                if (m) {
                    return {
                        date:  null,
                        label: m[1]
                    };
                }

                return { date: null, label: ts };
            }

            // Завантажити і графік, і таблицю
            async function loadAll() {
                const f   = document.getElementById('from').value;
                const t   = document.getElementById('to').value;
                const lbl = document.getElementById('label').value;

                loadChart(f, t);
                loadTable(f, t, lbl);
            }

            // ---------- ТАБЛИЦЯ ----------
            async function loadTable(from, to, lbl) {
                const url = 'api/get_events.php?site_id=<?= $site_id ?>'
                    + '&from=' + encodeURIComponent(from || '')
                    + '&to='   + encodeURIComponent(to   || '')
                    + '&tz='   + encodeURIComponent(tz || '')
                    + '&label='+ encodeURIComponent(lbl  || '');

                const res  = await fetch(url);
                const json = await res.json();

                $('#eventsTable').DataTable({
                    destroy: true,
                    data: json.events || [],
                    pageLength: 10,
                    order: [[0,'desc']],
                    columns: [
                        {
                            data: 'created_at',
                            title: 'Дата',
                            render: function (data, type, row) {
                                // Для відображення/фільтрації — конвертована дата,
                                // для сортування — сире значення з БД
                                if (type === 'display' || type === 'filter') {
                                    return formatDateToUserTz(data);
                                }
                                return data;
                            }
                        },
                        { data: 'ip',      title: 'IP' },
                        { data: 'country', title: 'Країна' },
                        { data: 'label',   title: 'Тип атаки' },
                        { data: 'score',   title: 'Score' },
                        { data: 'url',     title: 'URL' },
                        { data: 'ref',     title: 'Referrer' }
                    ]
                });fetch

                $('#eventsTable tbody').off('click').on('click', 'tr', function () {
                    const row = $('#eventsTable').DataTable().row(this).data();
                    if (row && row.id) showEvent(row.id);
                });
            }

            // --- Плагін: пунктирна вертикальна лінія на переході між днями ---
            const daySeparatorPlugin = {
                id: 'daySeparator',
                afterDraw(chart, args, pluginOptions) {
                    const xScale = chart.scales.x;
                    if (!xScale) return;

                    const indexes = pluginOptions.indexes || [];
                    if (!indexes.length) return;

                    const ctx = chart.ctx;
                    const { top, bottom } = chart.chartArea;

                    ctx.save();
                    ctx.strokeStyle = pluginOptions.color || 'rgba(255,255,255,0.35)';
                    ctx.setLineDash(pluginOptions.dash || [6, 4]);
                    ctx.lineWidth = pluginOptions.lineWidth || 1;

                    indexes.forEach(idx => {
                        if (idx <= 0 || idx >= xScale.ticks.length) return;
                        const x = xScale.getPixelForTick(idx);
                        ctx.beginPath();
                        ctx.moveTo(x, top);
                        ctx.lineTo(x, bottom);
                        ctx.stroke();
                    });

                    ctx.restore();
                }
            };

            // ---------- ГРАФІК ----------
            async function loadChart(from, to) {
                const url = 'api/get_stats.php?site_id=<?= $site_id ?>'
                    + '&from=' + encodeURIComponent(from || '')
                    + '&to='   + encodeURIComponent(to   || '')
                    + '&tz='   + encodeURIComponent(tz || '');

                const res  = await fetch(url);
                const json = await res.json();

                const rows = json.stats || [];

                // ts уже в «локальному» форматі з БД, просто парсимо рядком
                const localRows = rows.map(r => {
                    const parsed = parseStatTs(r.ts);
                    return {
                        ...r,
                        _localDate:  parsed.date,   // YYYY-MM-DD або null
                        _localLabel: parsed.label   // те, що показуємо на осі X
                    };
                });

                const labels = localRows.map(r => r._localLabel);
                const values = localRows.map(r => Number(r.cnt) || 0);

                // Індекси, де починається новий день (тільки коли є дата)
                const dayBreakIndexes = [];
                for (let i = 1; i < localRows.length; i++) {
                    const prevDay = localRows[i - 1]._localDate;
                    const currDay = localRows[i]._localDate;
                    if (prevDay && currDay && prevDay !== currDay) {
                        dayBreakIndexes.push(i);
                    }
                }

                if (chartInstance) {
                    chartInstance.destroy();
                }

                let type = 'line';
                if (labels.length >= 50) type = 'bar';

                const ctx = document.getElementById('chart').getContext('2d');
                chartInstance = new Chart(ctx, {
                    type,
                    data: {
                        labels,
                        datasets: [{
                            label: 'Кількість атак',
                            data: values,
                            borderColor: '#ff5252',
                            backgroundColor: 'rgba(255,82,82,0.4)',
                            fill: type === 'line',
                            tension: 0.3,
                            pointRadius: type === 'line' ? 3 : 0,
                            pointHoverRadius: 6,
                            borderWidth: 2
                        }]
                    },
                    options: {
                        maintainAspectRatio: false,
                        scales: {
                            x: {
                                ticks: { color: '#ccc' },
                                grid:  { color: 'rgba(255,255,255,0.05)' }
                            },
                            y: {
                                beginAtZero: true,
                                ticks: { color: '#ccc' },
                                grid:  { color: 'rgba(255,255,255,0.08)' }
                            }
                        },
                        plugins: {
                            legend: {
                                labels: { color: '#fff' }
                            },
                            daySeparator: {
                                indexes: dayBreakIndexes,
                                color: 'rgba(207,207,207,0.58)',
                                dash: [20, 10]
                            }
                        }
                    },
                    plugins: [daySeparatorPlugin]
                });
            }


            // ---------- МОДАЛКА ПОДІЇ ----------
            async function showEvent(id) {
                const res = await fetch('api/get_event_info.php?id=' + encodeURIComponent(id));
                const d   = await res.json();
                const c   = d.data || {};

                document.getElementById('modalBody').innerHTML = `
            <b>IP:</b> ${c.ip ?? ''}<br>
            <b>Країна:</b> ${c.country ?? ''}<br>
            <b>Місто:</b> ${c.city ?? ''}<br>
            <b>ISP:</b> ${c.isp ?? ''}<br>
            <b>ОС:</b> ${c.os_name ?? ''} ${c.os_version ?? ''}<br>
            <b>Браузер:</b> ${c.browser ?? ''} ${c.browser_version ?? ''}<br>
            <b>Timezone:</b> ${c.timezone ?? ''}<br>
            <b>Proxy:</b> ${c.proxy ? 'так' : 'ні'}<br>
            <b>Hosting:</b> ${c.hosting ? 'так' : 'ні'}<br>
            <hr>
            <b>URL:</b> ${c.url ?? ''}<br>
            <b>Referrer:</b> ${c.ref ?? ''}<br><br>
            <b>User-Agent:</b>
            <pre>${c.ua ?? ''}</pre>
        `;

                const m = new bootstrap.Modal(document.getElementById('eventModal'));
                m.show();
            }

            document.addEventListener('DOMContentLoaded', loadAll);
        </script>


    <?php elseif ($tab === 'code'): ?>
    <?php
    // Чи щойно ввели правильний пароль у цьому запиті?
    $passwordOk = false;

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'verify')) {
        $password = $_POST['password'] ?? '';

        if (!empty($site['hash']) && password_verify($password, $site['hash'])) {
            $passwordOk = true;
        } else {
            $error = 'Невірний пароль.';
        }
    }
    ?>

    <?php if (!$passwordOk): ?>
        <form method="post" class="card card-body" style="max-width:380px">
            <h5 class="mb-3">Підтвердіть пароль</h5>
            <input type="hidden" name="action" value="verify">
            <div class="mb-3">
                <input type="password" name="password" class="form-control" placeholder="Пароль" required>
            </div>
            <?php if (!empty($error)): ?>
                <div class="alert alert-danger mt-2"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <button class="btn btn-primary w-100">Підтвердити</button>
        </form>
    <?php else: ?>
        <?php
        $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\'); // напр. /TestFixed
        $publicUrl = "http://localhost" . $base . "/waf/" . urlencode($site['protect_token']) . "/protect.php";
        ?>
        <div class="card card-body">
            <h5 class="mb-3">Скрипт для вставки на сайт</h5>
            <pre class="code-snippet p-3 rounded"><?= htmlspecialchars(
                    '<script src="' . $publicUrl . '"></script>',
                    ENT_QUOTES
                ) ?></pre>

            <p class="text-muted">Скопіюйте тег перед закриваючим &lt;/body&gt;.</p>
        </div>
    <?php endif; ?>

    <?php elseif ($tab === 'settings'): ?>

        <!-- Кнопка для відкриття модалок налаштувань -->
        <div class="mb-3">
            <button class="btn btn-primary" onclick="openTfModal('tgModal')">
                Прив'язати сайт до Telegram
            </button>

            <button class="btn btn-secondary ms-2" onclick="openTfModal('botTextModal')">
                Текст бота
            </button>

            <button class="btn btn-outline-warning ms-2" onclick="openTfModal('rotateModal')">
                🔐 Згенерувати новий токен
            </button>
        </div>

        <!-- Модальне вікно для Telegram (кастомне, не Bootstrap) -->
        <div id="tgModal" class="tf-modal">
            <div class="modal-content">
                <h5>Прив'язати сайт до Telegram</h5>

                <?php if (!empty($site['telegram_type'])): ?>
                    <div class="alert alert-info text-start">
                        <b>Поточні налаштування:</b><br>
                        Посилання на бота:
                        <a href="https://t.me/InfoXssAndSQLBot" target="_blank" rel="noopener">
                            Перейти в бота
                        </a><br>
                        Тип чату: <?= $site['telegram_type'] === 'tg_private' ? 'Приватний' : 'Груповий' ?><br>
                        <?php if (!empty($site['telegram_chat_id'])): ?>
                            Chat ID: <?= htmlspecialchars($site['telegram_chat_id']) ?>
                        <?php else: ?>
                            Chat ID: <i>не вимагається</i>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <form method="post">
                    <input type="hidden" name="site_id" value="<?= $site_id ?>">

                    <div class="mb-3 text-start">
                        <label class="form-label">Оберіть тип чату</label>
                        <select id="chat_type" name="action" class="form-select" required>
                            <option value="">-- Виберіть --</option>
                            <option value="tg_private" <?= $site['telegram_type']==='tg_private'?'selected':'' ?>>Приватний чат</option>
                            <option value="tg_group"   <?= $site['telegram_type']==='tg_group'?'selected':'' ?>>Груповий чат</option>
                        </select>
                    </div>

                    <div class="mb-3 text-start" id="chat_id_field" style="display: <?= $site['telegram_type']==='tg_group'?'block':'none' ?>;">
                        <label class="form-label">Оберіть групу</label>

                        <select id="chat_select" class="form-select" style="display:none"></select>

                        <div id="manualToggleWrap" class="form-check my-2" style="display:none">
                            <input type="checkbox" class="form-check-input" id="manualToggle">
                            <label for="manualToggle" class="form-check-label">Ввести Chat ID вручну</label>
                        </div>

                        <input type="text" id="chat_id" name="chat_id"
                               class="form-control"
                               placeholder="-1001234567890"
                               value="<?= htmlspecialchars($site['telegram_chat_id'] ?? '') ?>"
                            <?= $site['telegram_type']==='tg_group'?'required':'' ?>>

                        <div class="form-text">
                            Список показує лише групи, де <b>і ви</b>, і <b>бот</b> — адміністратори.
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn-cancel" onclick="closeTfModal('tgModal')">Скасувати</button>
                        <button type="submit" class="btn-confirm">Прив'язати</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Модальне вікно “Текст бота” -->
        <div id="botTextModal" class="tf-modal">
            <div class="modal-content" style="max-width:720px;text-align:left">
                <h5 class="mb-2">Шаблон повідомлення для Telegram</h5>
                <p class="text-muted small mb-3">
                    Доступні плейсхолдери:
                    <code>{{site_id}}</code>, <code>{{label}}</code>, <code>{{score}}</code>,
                    <code>{{ip}}</code>, <code>{{url}}</code>, <code>{{user_agent}}</code>,
                    <code>{{date}}</code>, <code>{{time}}</code>, <code>{{chat_id}}</code>,
                    <code>{{tg_username}}</code>.
                </p>
                <form method="post">
                    <input type="hidden" name="action" value="save_bot_template">
                    <div class="mb-3">
                        <label class="form-label">Шаблон</label>
                        <textarea name="bot_template" class="form-control" rows="12" placeholder="Введіть шаблон..."><?= htmlspecialchars(
                                $site['tg_alert_template']
                                ?? "⚠️ Новий інцидент WAF:\n\n• Сайт ID: {{site_id}}\n• Тип: {{label}}\n• Рейтинг: {{score}}\n• IP: {{ip}}\n• URL: {{url}}\n• User-Agent: {{user_agent}}\n• Час: {{date}} {{time}}"
                            ) ?></textarea>
                    </div>
                    <div class="modal-footer" style="text-align:right">
                        <button type="button" class="btn-cancel" onclick="closeTfModal('botTextModal')">Скасувати</button>
                        <button type="submit" class="btn-confirm">Зберегти</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Модальне вікно “Ротація токена” -->
        <div id="rotateModal" class="tf-modal">
            <div class="modal-content" style="max-width:480px;text-align:left">
                <h5 class="mb-2">Згенерувати новий токен</h5>
                <p class="text-danger" style="margin-top:10px">
                    Увага: після ротації <b>усі старі теги перестануть працювати</b>.
                    Потрібно буде оновити вставлений на сайтах тег зі скриптом.
                </p>
                <form method="post" style="margin-top:12px">
                    <input type="hidden" name="action" value="rotate_token">
                    <div class="modal-footer">
                        <button type="button" class="btn-cancel" onclick="closeTfModal('rotateModal')">Скасувати</button>
                        <button type="submit" class="btn-confirm">Підтвердити ротацію</button>
                    </div>
                </form>
            </div>
        </div>

    <hr>

        <!-- Кнопка для відкриття модалки видалення -->
        <button class="btn btn-danger" onclick="openTfModal('deleteModal')">
            Видалити сайт
        </button>

        <!-- Модальне вікно видалення -->
        <div id="deleteModal" class="tf-modal">
            <div class="modal-content">
                <h5>Підтвердження видалення</h5>
                <p>Ви впевнені, що хочете видалити сайт
                    <b><?= htmlspecialchars($site['title'] ?: $site['url']) ?></b>?</p>
                <div class="modal-footer">
                    <button type="button" class="btn-cancel" onclick="closeTfModal('deleteModal')">Скасувати</button>
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="action" value="delete">
                        <button type="submit" class="btn-confirm">Так, видалити</button>
                    </form>
                </div>
            </div>
        </div>

    <?php endif; // кінець табів ?>
</div>
<style>
    /* Оверлей для КАСТОМНИХ модалок налаштувань (щоб не ламати Bootstrap .modal) */
    .tf-modal {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,0.75);
        align-items: center;
        justify-content: center;
        z-index: 1050;
    }

    .tf-modal .modal-content {
        background: #ffffff;
        color: #212529;
        border: 1px solid #dee2e6;
        padding: 20px;
        border-radius: 10px;
        width: 350px;
        text-align: center;
        box-shadow: 0 1rem 3rem rgba(0,0,0,.175);
        animation: fadeIn .3s ease;
    }

    [data-bs-theme="dark"] .tf-modal .modal-content {
        background: #2b2b2b;
        color: #f8f9fa;
        border-color: #3a3a3a;
    }

    .tf-modal .form-text { color: #6c757d; }
    [data-bs-theme="dark"] .tf-modal .form-text { color: #adb5bd; }

    .modal-footer {
        margin-top: 15px;
        display: flex;
        gap: .5rem;
        justify-content: center;
    }

    .btn-cancel, .btn-confirm {
        padding: 8px 16px;
        border-radius: .5rem;
        border: 1px solid #dee2e6;
        cursor: pointer;
    }
    .btn-cancel {
        background: #f8f9fa;
        color: #212529;
    }
    .btn-confirm {
        background: #dc3545;
        color: #fff;
        border-color: #dc3545;
    }
    [data-bs-theme="dark"] .btn-cancel {
        background: #343a40;
        color: #f8f9fa;
        border-color: #3a3a3a;
    }
    .btn-cancel:hover { filter: brightness(0.98); }
    .btn-confirm:hover { filter: brightness(1.05); }

    @keyframes fadeIn {
        from { opacity:0; transform:scale(0.98); }
        to   { opacity:1; transform:scale(1); }
    }
</style>

<?php include __DIR__ . '/footer.php'; ?>

<script>
    // === КАСТОМНІ tf-modal (Telegram, шаблон, видалення) ===
    function openTfModal(id) {
        const el = document.getElementById(id);
        if (el) el.style.display = 'flex';
    }
    function closeTfModal(id) {
        const el = document.getElementById(id);
        if (el) el.style.display = 'none';
    }

    // Закриття tf-modal по кліку поза вікном
    window.addEventListener('click', function (e) {
        document.querySelectorAll('.tf-modal').forEach(m => {
            if (e.target === m) {
                m.style.display = 'none';
            }
        });
    });

    // Логіка завантаження списку чатів та перемикач введення Chat ID
    (function() {
        const chatTypeEl = document.getElementById('chat_type');
        const fieldWrap  = document.getElementById('chat_id_field');
        const chatInput  = document.getElementById('chat_id');
        const chatSelect = document.getElementById('chat_select');
        const manualWrap = document.getElementById('manualToggleWrap');
        const manualCb   = document.getElementById('manualToggle');

        if (!chatTypeEl || !fieldWrap || !chatInput) return;

        function toggleGroupField() {
            const isGroup = chatTypeEl.value === 'tg_group';
            fieldWrap.style.display = isGroup ? 'block' : 'none';
        }

        async function loadChats() {
            try {
                const res = await fetch('/api/get_available_chats.php', {credentials:'same-origin'});
                if (!res.ok) throw new Error('HTTP '+res.status);
                const data = await res.json();

                if (!Array.isArray(data) || data.length === 0) {
                    if (chatSelect) chatSelect.style.display = 'none';
                    if (manualWrap) manualWrap.style.display = 'none';
                    chatInput.style.display  = 'block';
                    chatInput.required = true;
                    return;
                }

                chatSelect.innerHTML = '';
                for (const c of data) {
                    const opt = document.createElement('option');
                    opt.value = c.chat_id;
                    opt.textContent = `${c.title || '(без назви)'} — ${c.chat_type}`;
                    chatSelect.appendChild(opt);
                }

                // Підставляємо поточний chat_id, якщо є
                if (chatInput.value) {
                    const found = Array.from(chatSelect.options).find(o => o.value === chatInput.value);
                    if (found) chatSelect.value = chatInput.value;
                } else if (chatSelect.options.length > 0) {
                    chatSelect.value = chatSelect.options[0].value;
                    chatInput.value  = chatSelect.value;
                }

                chatSelect.style.display = 'block';
                manualWrap.style.display = 'block';
                chatInput.style.display  = 'none';
                chatInput.required = true;

                chatSelect.addEventListener('change', () => {
                    if (!manualCb.checked) {
                        chatInput.value = chatSelect.value;
                    }
                });

                manualCb.addEventListener('change', () => {
                    if (manualCb.checked) {
                        chatInput.style.display = 'block';
                    } else {
                        chatInput.style.display = 'none';
                        chatInput.value = chatSelect.value || '';
                    }
                });

            } catch (e) {
                if (chatSelect) chatSelect.style.display = 'none';
                if (manualWrap) manualWrap.style.display = 'none';
                chatInput.style.display  = 'block';
                chatInput.required = true;
            }
        }

        chatTypeEl.addEventListener('change', () => {
            toggleGroupField();
            if (chatTypeEl.value === 'tg_group') {
                loadChats();
            }
        });

        // Якщо вже обраний tg_group — підвантажити відразу
        if (chatTypeEl.value === 'tg_group') {
            toggleGroupField();
            loadChats();
        } else {
            toggleGroupField();
        }
    })();
</script>