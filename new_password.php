<?php
session_start();
require_once __DIR__ . '/db.php';
include __DIR__ . '/header.php';

if (!($_SESSION['reset_verified'] ?? false) || empty($_SESSION['reset_email'])) {
    header('Location: forgot_password.php');
    exit;
}

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (strlen($password) < 8) {
        $message = '❌ Пароль має містити щонайменше 8 символів.';
    } elseif ($password !== $confirm) {
        $message = '❌ Паролі не співпадають.';
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE email = ?");
        $stmt->execute([$hash, $_SESSION['reset_email']]);

        // Очистити сесію
        unset($_SESSION['reset_verified'], $_SESSION['reset_code'], $_SESSION['reset_email']);

        $message = '✅ Пароль успішно змінено. <a href="login.php">Увійти</a>';
    }
}
?>

<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-4">
            <div class="card p-4">
                <h2 class="text-center mb-4">Новий пароль</h2>

                <?php if ($message): ?>
                    <div class="alert alert-info"><?= $message ?></div>
                <?php endif; ?>

                <form method="post">
                    <div class="mb-3">
                        <label class="form-label">Новий пароль</label>
                        <input type="password" name="password" class="form-control" required minlength="8">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Підтвердження пароля</label>
                        <input type="password" name="confirm_password" class="form-control" required>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Змінити пароль</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/footer.php'; ?>
