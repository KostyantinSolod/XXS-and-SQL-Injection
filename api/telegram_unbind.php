<?php
declare(strict_types=1);

session_start();
if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../db.php';

// Дістаємо id по username
$stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
$stmt->execute([$_SESSION['user']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if ($user) {
    $stmt = $pdo->prepare("
        UPDATE users
        SET telegram_id  = NULL,
            tg_user_id   = NULL,
            tg_username  = NULL,
            tg_linked_at = NULL
        WHERE id = ?
    ");
    $stmt->execute([(int)$user['id']]);
}

// Після відв’язки — назад у налаштування
header('Location: ../settings.php');
exit;
