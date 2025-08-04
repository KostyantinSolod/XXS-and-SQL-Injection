<?php
session_start();
require_once 'Database.php';

$token = $_GET['token'] ?? '';

if (empty($token)) {
    header('Location: forgot_password.php?status=error&message=Невірне+посилання');
    exit;
}

try {
    $db = new Database();
    $pdo = $db->getConnection();
    
    // Перевірка токену
    $stmt = $pdo->prepare("
        SELECT user_id FROM password_resets 
        WHERE token = ? AND expires_at > NOW()
    ");
    $stmt->execute([$token]);
    $resetRequest = $stmt->fetch();
    
    if (!$resetRequest) {
        header('Location: forgot_password.php?status=error&message=Невірне+або+протерміноване+посилання');
        exit;
    }
    
    include __DIR__ . '/header.php';
    ?>
    
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-4">
                <div class="card p-4">
                    <h2 class="text-center mb-4">Новий пароль</h2>
                    
                    <?php if (isset($_GET['error'])): ?>
                        <div class="alert alert-danger"><?= htmlspecialchars($_GET['error']) ?></div>
                    <?php endif; ?>
                    
                    <form action="process_reset.php" method="post">
                        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                        
                        <div class="mb-3">
                            <label for="password" class="form-label">Новий пароль</label>
                            <input type="password" class="form-control" id="password" name="password" required minlength="8">
                        </div>
                        
                        <div class="mb-3">
                            <label for="confirm_password" class="form-label">Підтвердіть пароль</label>
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                        </div>
                        
                        <button type="submit" class="btn btn-primary w-100">Змінити пароль</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <?php include __DIR__ . '/footer.php';
} catch (PDOException $e) {
    error_log("Reset password error: " . $e->getMessage());
    header('Location: forgot_password.php?status=error&message=Помилка+сервера');
}