<?php
require_once __DIR__ . '/db.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: forgot_password.php'); exit; }

$token = $_POST['token'] ?? '';
$password = $_POST['password'] ?? '';
$confirmPassword = $_POST['confirm_password'] ?? '';

if (empty($token) || $password !== $confirmPassword || strlen($password) < 8) {
    $error = $password !== $confirmPassword ? "Паролі не співпадають" : "Пароль повинен містити щонайменше 8 символів";
    header("Location: reset_password.php?token=$token&error=" . urlencode($error));
    exit;
}
try {
    $stmt = $pdo->prepare("SELECT user_id FROM password_resets WHERE token = ? AND expires_at > NOW()");
    $stmt->execute([$token]);
    $resetRequest = $stmt->fetch();
    if (!$resetRequest) { header('Location: forgot_password.php?status=error&message=Невірний+запит'); exit; }

    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
    $stmt->execute([$hashedPassword, $resetRequest['user_id']]);

    $stmt = $pdo->prepare("DELETE FROM password_resets WHERE user_id = ?");
    $stmt->execute([$resetRequest['user_id']]);

    header('Location: login.php?status=password_changed');
} catch (PDOException $e) {
    error_log("Password reset error: " . $e->getMessage());
    header('Location: forgot_password.php?status=error&message=Помилка+сервера');
}
