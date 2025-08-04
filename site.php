<?php
session_start();
if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/db.php';
$site_id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT s.*, u.id AS uid, u.username, u.password AS hash 
                       FROM sites s 
                       JOIN users u ON u.id = s.user_id 
                       WHERE s.id = ?');
$stmt->execute([$site_id]);
$site = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$site || $site['username'] !== $_SESSION['user']) {
    header('Location: dashboard.php');
    exit;
}
$user_id = $site['uid'];

$tab = $_GET['tab'] ?? 'stats';
$success = $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'verify') {
        $password = $_POST['password'] ?? '';
        if (password_verify($password, $site['hash'])) {
            $_SESSION['code_verified'][$site_id] = true;
            $tab = 'code';
        } else {
            $error = 'Невірний пароль.';
            $tab = 'code';
        }
    }

    if (isset($_POST['action']) && $_POST['action'] === 'delete') {
        $stmtDel = $pdo->prepare('DELETE FROM sites WHERE id = ? AND user_id = ?');
        $stmtDel->execute([$site_id, $user_id]);
        header('Location: dashboard.php');
        exit;
    }

    if (!empty($_POST['action']) && in_array($_POST['action'], ['tg_private', 'tg_group'])) {
        $chat_id = trim($_POST['chat_id'] ?? '');

        if ($_POST['action'] === 'tg_private') {
            $chat_id = null; // для приватного чату не потрібен
        }

        if ($_POST['action'] === 'tg_group' && empty($chat_id)) {
            $error = "❌ Для групового чату потрібно ввести Chat ID.";
        } else {
            $pdo->exec("ALTER TABLE sites ADD COLUMN IF NOT EXISTS telegram_chat_id VARCHAR(50)");
            $pdo->exec("ALTER TABLE sites ADD COLUMN IF NOT EXISTS telegram_type VARCHAR(20)");

            $stmtUpd = $pdo->prepare("UPDATE sites SET telegram_chat_id = ?, telegram_type = ? WHERE id = ? AND user_id = ?");
            $stmtUpd->execute([$chat_id, $_POST['action'], $site_id, $user_id]);

            $success = "✅ Сайт приєднано до Telegram (" . ($_POST['action']=='tg_private' ? 'Приватний чат' : 'Група') . ").";
        }
        $tab = 'settings';
    }
}

include __DIR__ . '/header.php';
?>
<div class="container">
    <h1 class="mb-4"><?= htmlspecialchars($site['title'] ?: $site['url']) ?></h1>

    <?php if (!empty($success)): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <ul class="nav nav-tabs mb-4">
        <li class="nav-item">
            <a class="nav-link <?= $tab === 'stats' ? 'active' : '' ?>" href="?id=<?= $site_id ?>&tab=stats">Статистика</a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $tab === 'code' ? 'active' : '' ?>" href="?id=<?= $site_id ?>&tab=code">Код</a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $tab === 'settings' ? 'active' : '' ?>" href="?id=<?= $site_id ?>&tab=settings">Налаштування</a>
        </li>
    </ul>

    <?php if ($tab === 'stats'): ?>
        <canvas id="trafficChart" height="140"></canvas>
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
        <script>
            const ctx = document.getElementById('trafficChart');
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: [...Array(30).keys()].map(i => `День ${i + 1}`),
                    datasets: [{label: 'Відвідувань', data: [...Array(30).keys()].map(()=>Math.floor(Math.random()*120)+10), tension: .2}]
                },
                options: {scales: {y: {beginAtZero: true}}}
            });
        </script>

    <?php elseif ($tab === 'code'): ?>
    <?php $verified = $_SESSION['code_verified'][$site_id] ?? false; ?>
    <?php if (!$verified): ?>
        <form method="post" class="card card-body" style="max-width:380px">
            <h5 class="mb-3">Підтвердіть пароль</h5>
            <input type="hidden" name="action" value="verify">
            <div class="mb-3">
                <input type="password" name="password" class="form-control" placeholder="Пароль" required>
            </div>
            <button class="btn btn-primary w-100">Підтвердити</button>
        </form>
    <?php else: ?>
        <div class="card card-body">
            <h5 class="mb-3">Скрипт для вставки на сайт</h5>
            <pre class="bg-light p-3 rounded">&lt;script src="https://yourdomain.local/protect.js?site_id=<?= $site_id ?>"&gt;&lt;/script&gt;</pre>
            <p class="text-muted">Скопіюйте тег перед закриваючим &lt;/body&gt;.</p>
        </div>
    <?php endif; ?>

    <?php elseif ($tab === 'settings'): ?>
    <!-- Кнопка для відкриття модалки Telegram -->
    <button class="btn btn-primary" onclick="document.getElementById('tgModal').style.display='flex'">
        Прив'язати сайт до Telegram
    </button>

    <!-- Модальне вікно для Telegram -->
    <div id="tgModal" class="modal">
        <div class="modal-content">
            <h5>Прив'язати сайт до Telegram</h5>

            <?php if (!empty($site['telegram_type'])): ?>
                <div class="alert alert-info">
                    <b>Поточні налаштування:</b><br>
                    Посилання на бота: <a href="https://t.me/InfoXssAndSQLBot">Перейти в бота</a>
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

                <div class="mb-3">
                    <label class="form-label">Оберіть тип чату</label>
                    <select id="chat_type" name="action" class="form-select" required>
                        <option value="">-- Виберіть --</option>
                        <option value="tg_private" <?= $site['telegram_type']==='tg_private'?'selected':'' ?>>Приватний чат</option>
                        <option value="tg_group" <?= $site['telegram_type']==='tg_group'?'selected':'' ?>>Груповий чат</option>
                    </select>
                </div>

                <div class="mb-3" id="chat_id_field" style="display: <?= $site['telegram_type']==='tg_group'?'block':'none' ?>;">
                    <label for="chat_id" class="form-label">Введіть Chat ID групи</label>
                    <input type="text" id="chat_id" name="chat_id"
                           class="form-control"
                           placeholder="-1001234567890"
                           value="<?= htmlspecialchars($site['telegram_chat_id'] ?? '') ?>"
                           <?= $site['telegram_type']==='tg_group'?'required':'' ?>>
                    <div class="form-text">Щоб отримати chat_id групи, додайте бота до своєї групи і напишіть /start.</div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn-cancel" onclick="document.getElementById('tgModal').style.display='none'">Скасувати</button>
                    <button type="submit" class="btn-confirm">Прив'язати</button>
                </div>
            </form>
        </div>
    </div>

    <hr>

    <!-- Кнопка для відкриття модалки видалення -->
    <button class="btn btn-danger" onclick="document.getElementById('deleteModal').style.display='flex'">
        Видалити сайт
    </button>

    <!-- Модальне вікно видалення -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <h5>Підтвердження видалення</h5>
            <p>Ви впевнені, що хочете видалити сайт <b><?= htmlspecialchars($site['title'] ?: $site['url']) ?></b>?</p>
            <div class="modal-footer">
                <button class="btn-cancel" onclick="document.getElementById('deleteModal').style.display='none'">Скасувати</button>
                <form method="post" style="display:inline;">
                    <input type="hidden" name="action" value="delete">
                    <button type="submit" class="btn-confirm">Так, видалити</button>
                </form>
            </div>
        </div>
    </div>

    <script>
        // показ/приховування поля Chat ID
        document.getElementById('chat_type').addEventListener('change', function() {
            const chatField = document.getElementById('chat_id_field');
            if (this.value === 'tg_group') {
                chatField.style.display = 'block';
                document.getElementById('chat_id').setAttribute('required', 'required');
            } else {
                chatField.style.display = 'none';
                document.getElementById('chat_id').removeAttribute('required');
            }
        });

        // закриття модалок кліком поза вікном
        window.onclick = function(event) {
            const tgModal = document.getElementById('tgModal');
            const delModal = document.getElementById('deleteModal');
            if (event.target === tgModal) tgModal.style.display = "none";
            if (event.target === delModal) delModal.style.display = "none";
        }
    </script>
<?php endif; ?>


</div>

<style>
    .modal {
        display: none;
        position: fixed;
        top:0; left:0; width:100%; height:100%;
        background: rgba(0,0,0,0.6);
        align-items: center; justify-content: center;
        z-index: 1050;
    }
    .modal-content {
        background:#fff; padding:20px;
        border-radius:10px; width:350px;
        text-align:center;
        animation: fadeIn .3s ease;
    }
    .modal-footer { margin-top:15px; }
    .btn-cancel { background:#6c757d; color:#fff; padding:8px 16px; border-radius:5px; border:none; cursor:pointer; }
    .btn-confirm { background:#dc3545; color:#fff; padding:8px 16px; border:none; border-radius:5px; cursor:pointer; }
    @keyframes fadeIn {
        from {opacity:0; transform:scale(0.8);}
        to {opacity:1; transform:scale(1);}
    }
</style>

<?php include __DIR__ . '/footer.php'; ?>
