<?php
session_start();
if (!isset($_SESSION['user'])) { header('Location: login.php'); exit; }
require_once __DIR__ . '/db.php';
include __DIR__ . '/header.php';

// дістаємо користувача і його розширені поля
$stmtUser = $pdo->prepare('SELECT id, username, telegram_id, tg_user_id, tg_username, last_login_at, last_login_ip FROM users WHERE username = ?');
$stmtUser->execute([$_SESSION['user']]);
$user = $stmtUser->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    echo '<div class="container"><div class="alert alert-danger mt-4">❌ Користувача не знайдено</div></div>';
    include __DIR__ . '/footer.php'; exit;
}

// налаштування UI
$stmtSet = $pdo->prepare('SELECT theme, timezone, fontsize FROM user_settings WHERE user_id = ?');
$stmtSet->execute([$user['id']]);
$saved = $stmtSet->fetch(PDO::FETCH_ASSOC) ?: [];
$_SESSION['theme']    = $saved['theme']    ?? ($_SESSION['theme'] ?? 'light');
$_SESSION['timezone'] = $saved['timezone'] ?? ($_SESSION['timezone'] ?? 'UTC');
$_SESSION['fontsize'] = $saved['fontsize'] ?? ($_SESSION['fontsize'] ?? 'medium');

// назва бота з .env
$botName = getenv('TELEGRAM_BOT_NAME');
?>
<div class="container">
    <h2 class="my-4 text-center">Розширені налаштування</h2>

    <!-- Останній вхід -->
    <div class="card card-body mb-4">
        <h5 class="mb-2">Останній вхід</h5>
        <div>Час: <b><?= !empty($user['last_login_at']) ? date('d.m.Y H:i:s', strtotime($user['last_login_at'])) : '—' ?></b></div>
        <div>IP: <b><?= htmlspecialchars($user['last_login_ip'] ?? '—') ?></b></div>
    </div>

    <form action="save_settings.php" method="post">
        <div class="mb-3">
            <label class="form-label">Тема інтерфейсу:</label>
            <input type="hidden" name="theme" value="light">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" id="themeSwitch" name="theme" value="dark"
                    <?= (($_SESSION['theme'] ?? 'light') === 'dark') ? 'checked' : '' ?>>
                <label class="form-check-label" for="themeSwitch">Темна тема</label>
            </div>
        </div>

        <div class="mb-3">
            <label for="timezone" class="form-label">Часовий пояс:</label>
            <select name="timezone" id="timezone" class="form-select" required>
                <?php
                $timezones = DateTimeZone::listIdentifiers();
                $excluded = [];
                $excludedJsonPath = __DIR__ . '/data/excluded_timezones.json';
                if (file_exists($excludedJsonPath)) { $excluded = json_decode(file_get_contents($excludedJsonPath), true); }
                $currentTz = $saved['timezone'] ?? ($_SESSION['timezone'] ?? 'UTC');
                foreach ($timezones as $tz) {
                    if (in_array($tz, $excluded)) continue;
                    $sel = ($currentTz === $tz) ? 'selected' : '';
                    echo "<option value=\"".htmlspecialchars($tz)."\" $sel>".htmlspecialchars($tz)."</option>";
                }
                ?>
            </select>
        </div>

        <div class="mb-3">
            <label for="fontsize" class="form-label">Розмір шрифта:</label>
            <?php $currentFs = $saved['fontsize'] ?? ($_SESSION['fontsize'] ?? 'medium'); ?>
            <select name="fontsize" id="fontsize" class="form-select">
                <option value="small"  <?= $currentFs==='small'  ? 'selected':'' ?>>Малий</option>
                <option value="medium" <?= $currentFs==='medium' ? 'selected':'' ?>>Середній</option>
                <option value="large"  <?= $currentFs==='large'  ? 'selected':'' ?>>Великий</option>
            </select>
        </div>

        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h3 class="card-title mb-0">Прив'язки</h3>
            </div>
            <div class="card-body">
                <!-- Telegram -->
                <div class="mb-3">
                    <label class="form-label">Telegram:</label>
                    <?php
                    $isLinked = !empty($user['telegram_id']) || !empty($user['tg_user_id']);
                    if (!$botName) {
                        echo "<div class='alert alert-danger'>❌ TELEGRAM_BOT_NAME не знайдений у .env</div>";
                    } elseif (!$isLinked) { ?>
                        <a href="https://t.me/<?= htmlspecialchars($botName) ?>?start=<?= (int)$user['id'] ?>"
                           class="btn btn-outline-primary" target="_blank">Прив'язати Telegram</a>
                    <?php } else { ?>
                        <div class="d-flex align-items-center gap-2">
                <span class="text-success">
                  <i class="bi bi-check-circle-fill me-1"></i> Прив'язано
                  <?php if (!empty($user['tg_username'])): ?>
                      — @<?= htmlspecialchars($user['tg_username']) ?>
                  <?php elseif (!empty($user['telegram_id'])): ?>
                      — ID: <?= htmlspecialchars($user['telegram_id']) ?>
                  <?php elseif (!empty($user['tg_user_id'])): ?>
                      — ID: <?= (int)$user['tg_user_id'] ?>
                  <?php endif; ?>
                </span>
                            <button type="submit"
                                    class="btn btn-sm btn-outline-danger"
                                    formaction="api/telegram_unbind.php"
                                    formmethod="post">
                                Відв'язати
                            </button>

                        </div>
                    <?php } ?>
                </div>
            </div>
        </div>

        <button type="submit" class="btn btn-success">Зберегти налаштування</button>
    </form>
</div>

<?php include __DIR__ . '/footer.php'; ?>
