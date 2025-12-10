<?php
declare(strict_types=1);
session_start();
if (!isset($_SESSION['user'])) { header('Location: login.php'); exit; }

require_once __DIR__ . '/db.php';

// Вхідні
$theme    = ($_POST['theme'] ?? 'light') === 'dark' ? 'dark' : 'light';
$timezone = trim($_POST['timezone'] ?? 'UTC');
$fontsize = in_array($_POST['fontsize'] ?? 'medium', ['small','medium','large'], true) ? $_POST['fontsize'] : 'medium';

// Завантажимо користувача
$stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
$stmt->execute([$_SESSION['user']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) { header('Location: settings.php?e=nouser'); exit; }

$uid = (int)$user['id'];
$pdo->beginTransaction();
try {
    // upsert у user_settings
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_settings (
        id BIGSERIAL PRIMARY KEY,
        user_id BIGINT UNIQUE NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        theme VARCHAR(10) DEFAULT 'light',
        timezone VARCHAR(100) DEFAULT 'UTC',
        fontsize VARCHAR(20) DEFAULT 'medium'
    )");

    $stmt = $pdo->prepare("INSERT INTO user_settings (user_id, theme, timezone, fontsize)
                            VALUES (:u,:t,:z,:f)
      ON CONFLICT (user_id) DO UPDATE SET theme=EXCLUDED.theme, timezone=EXCLUDED.timezone, fontsize=EXCLUDED.fontsize");
    $stmt->execute([':u'=>$uid, ':t'=>$theme, ':z'=>$timezone, ':f'=>$fontsize]);
    $pdo->commit();

    // Оновимо сесію
    $_SESSION['theme']    = $theme;
    $_SESSION['timezone'] = $timezone;
    $_SESSION['fontsize'] = $fontsize;

    header('Location: settings.php?saved=1');
} catch (Throwable $e) {
    $pdo->rollBack();
    header('Location: settings.php?e=db');
}
exit;
