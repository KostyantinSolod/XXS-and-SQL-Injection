<?php
session_start();
include __DIR__ . '/header.php';

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $enteredCode = trim($_POST['reset_code'] ?? '');
    if (empty($_SESSION['reset_code']) || $enteredCode !== $_SESSION['reset_code']) {
        $message = '❌ Невірний код.';
    } else {
        $_SESSION['reset_verified'] = true;
        header('Location: new_password.php');
        exit;
    }
}
?>

<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-4">
            <div class="card p-4">
                <h2 class="text-center mb-4">Підтвердження коду</h2>

                <?php if ($message): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($message) ?></div>
                <?php endif; ?>

                <form method="post">
                    <div class="mb-3">
                        <label for="reset_code" class="form-label">Введіть код з пошти</label>
                        <input type="text" class="form-control" name="reset_code" id="reset_code" required>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Підтвердити</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/footer.php'; ?>
