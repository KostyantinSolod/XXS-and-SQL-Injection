<?php
require_once 'db.php';

$token = $_GET['token'] ?? '';
if (!$token) {
    echo "Невірний токен.";
    exit();
}

$stmt = $pdo->prepare("SELECT * FROM email_verification WHERE token = ? AND expires_at >= NOW()");
$stmt->execute([$token]);
$data = $stmt->fetch();

if ($data) {
    $pdo->prepare("UPDATE users SET email_verified = 1 WHERE id = ?")->execute([$data['user_id']]);
    $pdo->prepare("DELETE FROM email_verification WHERE user_id = ?")->execute([$data['user_id']]);
    echo "✅ Email підтверджено. Тепер ви можете увійти.";
} else {
    echo "⛔ Токен недійсний або застарілий.";
}
?>
