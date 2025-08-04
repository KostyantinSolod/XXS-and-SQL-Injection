<?php
session_start();
if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/db.php';
include __DIR__ . '/header.php';

// Тепер $pдо буде доступна
$stmt = $pdo->prepare('SELECT id, telegram_id FROM users WHERE username = ?');
$stmt->execute([$_SESSION['user']]);
$user = $stmt->fetch();

// Завантажуємо збережені налаштування
$stmtSet = $pdo->prepare('SELECT theme, timezone, fontsize FROM user_settings WHERE user_id = ?');
$stmtSet->execute([$user['id']]);
$saved = $stmtSet->fetch(PDO::FETCH_ASSOC) ?: [];
$_SESSION['theme'] = $saved['theme'] ?? ($_SESSION['theme'] ?? 'light');
$_SESSION['timezone'] = $saved['timezone'] ?? ($_SESSION['timezone'] ?? 'UTC');
$_SESSION['fontsize'] = $saved['fontsize'] ?? ($_SESSION['fontsize'] ?? 16);

?>

<div class="container">
    <h2 class="my-4 text-center">Розширені налаштування</h2>
        <form action="save_settings.php" method="post">
                <!-- Додано перемикач теми -->
                <div class="mb-3">
                    <label class="form-label">Тема інтерфейсу:</label>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="themeSwitch" name="theme" 
                            <?= ($_SESSION['theme'] ?? 'light') === 'dark' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="themeSwitch">
                            Темна тема
                        </label>
                    </div>
                </div>

            <div class="mb-3">
                <label for="timezone" class="form-label">Часовий пояс:</label>
                <select name="timezone" id="timezone" class="form-select">
                    <?php
                    $timezones = DateTimeZone::listIdentifiers();


                    $excluded = [];
                    $excludedJsonPath = __DIR__ . './data/excluded_timezones.json';
                    if (file_exists($excludedJsonPath)) {
                        $excluded = json_decode(file_get_contents($excludedJsonPath), true);
                    }

                    // Виводимо тільки ті таймзони, яких немає у списку
                    foreach ($timezones as $tz) {
                        if (!in_array($tz, $excluded)) {
                            echo "<option value=\"$tz\">$tz</option>";
                        }
                    }
                    ?>
                </select>
            </div>


            <div class="mb-3">
            <label for="fontsize" class="form-label">Розмір шрифта:</label>
            <select name="fontsize" id="fontsize" class="form-select">
                <option value="small">Малий</option>
                <option value="medium">Середній</option>
                <option value="large">Великий</option>
            </select>
        </div>


            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <h3 class="card-title mb-0">Прив'язки</h3>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Telegram:</label>
                        <?php
                        // отримаємо дані користувача
                        $stmt = $pdo->prepare('SELECT id, telegram_id FROM users WHERE username = ?');
                        $stmt->execute([$_SESSION['user']]);
                        $user = $stmt->fetch(PDO::FETCH_ASSOC);

                        $botName = getenv('TELEGRAM_BOT_NAME');

                        if (!$user) {
                            echo "<div class='alert alert-danger'>❌ Користувача не знайдено!</div>";
                        } elseif (!$botName) {
                            echo "<div class='alert alert-danger'>❌ TELEGRAM_BOT_NAME не знайдений у .env</div>";
                        } elseif (empty($user['telegram_id'])) {
                            ?>
                            <a href="https://t.me/<?= htmlspecialchars($botName) ?>?start=<?= urlencode($user['id']) ?>"
                               class="btn btn-outline-primary"
                               target="_blank">
                                Прив'язати Telegram
                            </a>
                            <?php
                        } else {
                            ?>
                            <div class="d-flex align-items-center">
                    <span class="text-success me-2">
                        <i class="bi bi-check-circle-fill"></i> Прив'язано
                    </span>
                                <form action="unlink_telegram.php" method="post" class="d-inline">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                        Відв'язати
                                    </button>
                                </form>
                            </div>
                            <?php
                        }
                        ?>
                    </div>
                </div>
            </div>

        <button type="submit" class="btn btn-success">Зберегти налаштування</button>
    </form>
</div>

<?php include __DIR__ . '/footer.php'; ?>
