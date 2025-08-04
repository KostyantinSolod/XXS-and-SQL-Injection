<?php
require_once __DIR__ . '/db.php';
include __DIR__ . '/header.php';

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare('SELECT password FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
    $_SESSION['user'] = $username;

    // Load saved settings
    $stmtSettings = $pdo->prepare('SELECT theme, timezone, fontsize FROM user_settings WHERE user_id = (SELECT id FROM users WHERE username = ?)');
    $stmtSettings->execute([$username]);
    if ($row = $stmtSettings->fetch()) {
        $_SESSION['theme'] = $row['theme'];
        $_SESSION['timezone'] = $row['timezone'];
        $_SESSION['fontsize'] = $row['fontsize'];
    }

    header('Location: dashboard.php');

        exit;
    }
    $message = 'Невірні облікові дані.';
}
?>
<div class="card shadow-lg" style="max-width:420px;width:100%">
    <div class="card-body p-4">
        <h2 class="h4 mb-4 text-center">Вхід</h2>
        <?php if (isset($_GET['registered'])): ?>
            <div class="alert alert-success">Реєстрація успішна! Тепер увійдіть.</div>
        <?php endif; ?>
        <?php if ($message): ?>
            <div class="alert alert-danger"><?=$message?></div>
        <?php endif; ?>
        <form method="post" novalidate>
            <div class="mb-3">
                <label class="form-label">Імʼя користувача</label>
                <input class="form-control" name="username" required value="<?=htmlspecialchars($_POST['username'] ?? '')?>">
            </div>
            <div class="mb-3">
                <label class="form-label">Пароль</label>
                <input class="form-control" type="password" name="password" required>
            </div>
            <button class="btn btn-primary w-100">Увійти</button>
        </form>
        <p class="mt-3 text-center mb-0">Немає акаунту? <a href="register.php">Зареєструйся</a></p>
        <div class="text-center mt-3">
            <a href="forgot_password.php">Забули пароль?</a>
        </div>
    </div>
</div>
<?php include __DIR__ . '/footer.php'; ?>
