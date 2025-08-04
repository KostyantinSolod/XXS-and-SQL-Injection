<?php
require_once __DIR__ . '/db.php';
include __DIR__ . '/header.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


$message = '';
$email = trim($_POST['email'] ?? '');
$username = trim($_POST['username'] ?? '');

// Перевірка коду
if (isset($_POST['verify_code_submit'])) {
    $enteredCode = trim($_POST['verify_code']);
    if ($_SESSION['verification_code'] !== $enteredCode) {
        $message = '❌ Невірний код підтвердження.';
    } else {
        $_SESSION['email_verified'] = true;
        $message = '✅ Email підтверджено.';
    }
}

// Реєстрація
if (isset($_POST['register'])) {
    $password = $_POST['password'] ?? '';

    if (!$username || !$password || !$email) {
        $message = '❌ Заповніть усі поля.';
    } elseif (!($_SESSION['email_verified'] ?? false)) {
        $message = '❌ Спочатку підтвердіть email.';
    } else {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
        $stmt->execute([$username]);
        if ($stmt->fetchColumn() > 0) {
            $message = '❌ Користувач уже існує.';
        } else {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
            $stmt->execute([$email]);
            if ($stmt->fetchColumn() > 0) {
                $message = '❌ Email вже використовується.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare('INSERT INTO users (username, email, password) VALUES (?, ?, ?)');
                $stmt->execute([$username, $email, $hash]);
                unset($_SESSION['verification_code'], $_SESSION['email_verified']);
                $_SESSION['user'] = $username;
                header('Location: dashboard.php');
                exit;
            }
        }
    }
}
?>

<div class="card shadow-lg" style="max-width:420px;width:100%">
    <div class="card-body p-4">
        <h2 class="h4 mb-4 text-center">Реєстрація</h2>

        <?php if ($message): ?>
            <div class="alert alert-info"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <form method="post" id="registerForm">
            <div class="mb-3">
                <label class="form-label">Імʼя користувача</label>
                <input class="form-control" name="username" required value="<?= htmlspecialchars($username) ?>">
            </div>

            <div class="mb-3">
                <label class="form-label">Пароль</label>
                <input class="form-control" type="password" name="password" required>
            </div>

            <div class="mb-3 d-flex gap-2 align-items-end">
                <div style="flex: 1">
                    <label class="form-label">Email</label>
                    <input class="form-control" type="email" name="email" id="email" required value="<?= htmlspecialchars($email) ?>">
                </div>
                <button type="button" class="btn btn-secondary" id="sendCodeBtn">Відправити код</button>
            </div>

            <div id="status" class="mb-3"></div>

            <div class="mb-3">
                <label class="form-label">Код підтвердження</label>
                <input class="form-control" type="text" name="verify_code" placeholder="Введіть код з email">
                <button class="btn btn-info mt-2" type="submit" name="verify_code_submit">Підтвердити код</button>
            </div>

            <button class="btn btn-primary w-100 mt-3" type="submit" name="register">Зареєструватися</button>
        </form>

        <p class="mt-3 text-center mb-0">Вже маєш акаунт? <a href="login.php">Увійди</a></p>
    </div>
</div>

<script>
    const sendBtn = document.getElementById("sendCodeBtn");
    const emailInput = document.getElementById("email");
    const statusBox = document.getElementById("status");

    sendBtn.addEventListener("click", async () => {
        const email = emailInput.value.trim();
        if (!email) {
            alert("Введіть email");
            return;
        }

        sendBtn.disabled = true;
        sendBtn.textContent = "Надсилаємо...";

        const formData = new FormData();
        formData.append("email", email);

        try {
            const response = await fetch("email/send_email.php", {
                method: "POST",
                body: formData
            });

            const data = await response.json();
            statusBox.innerHTML = `<div class="alert alert-${data.success ? 'success' : 'danger'}">${data.message}</div>`;
        } catch (err) {
            statusBox.innerHTML = `<div class="alert alert-danger">❌ Помилка запиту до сервера</div>`;
        }

        sendBtn.disabled = false;
        sendBtn.textContent = "Відправити код";
    });
</script>

<?php include __DIR__ . '/footer.php'; ?>
