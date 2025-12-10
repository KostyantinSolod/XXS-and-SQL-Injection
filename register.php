<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/db.php';
include __DIR__ . '/header.php';



$message = '';
$email = trim($_POST['email'] ?? '');
$username = trim($_POST['username'] ?? '');

// Перевірка коду
/*if (isset($_POST['verify_code_submit'])) {
    $enteredCode = trim($_POST['verify_code']);
    if ($enteredCode !== ($_SESSION['email_code'] ?? '')) {
        $message = '❌ Невірний код підтвердження.';
    } else {
        $_SESSION['email_verified'] = true;
        $message = '✅ Email підтверджено.';
    }
}*/

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
                <input class="form-control" type="text" name="verify_code" id="verify_code" placeholder="Введіть код з email">
                <button class="btn btn-info mt-2" type="button" id="verifyCodeBtn">Підтвердити код</button>

            </div>

            <button class="btn btn-primary w-100 mt-3" type="submit" name="register">Зареєструватися</button>
        </form>

        <p class="mt-3 text-center mb-0">Вже маєш акаунт? <a href="login.php">Увійди</a></p>
    </div>
</div>

<script>
    (function () {
        const sendBtn     = document.getElementById("sendCodeBtn");
        const emailInput  = document.getElementById("email");
        const statusBox   = document.getElementById("status");
        const verifyBtn   = document.getElementById("verifyCodeBtn");
        const verifyInput = document.getElementById("verify_code");

        async function sendMail(email) {
            const url = new URL("email/send_email.php", window.location.href).toString();

            const formData = new FormData();
            formData.append("email", email);

            const res = await fetch(url, {
                method: "POST",
                body: formData,
                credentials: "same-origin"
            });

            const raw = await res.text();
            let data;
            try { data = JSON.parse(raw); }
            catch (e) {
                throw new Error(`Non-JSON (${res.status}): ${raw.slice(0, 200)}`);
            }

            if (!res.ok || !data.success) {
                throw new Error(data.message || `HTTP ${res.status}`);
            }
            return data.message || "OK";
        }

        // Надіслати код
        sendBtn.addEventListener("click", async () => {
            const email = emailInput.value.trim();
            if (!email) {
                alert("Введіть email");
                return;
            }

            sendBtn.disabled = true;
            sendBtn.textContent = "Надсилаємо…";
            statusBox.innerHTML = "";

            try {
                const msg = await sendMail(email);
                statusBox.innerHTML = `<div class="alert alert-success">✅ ${msg}</div>`;
            } catch (err) {
                statusBox.innerHTML = `<div class="alert alert-danger">❌ Помилка: ${err.message}</div>`;
            } finally {
                sendBtn.disabled = false;
                sendBtn.textContent = "Відправити код";
            }
        });

        // Підтвердити код
        verifyBtn.addEventListener("click", async () => {
            const email = emailInput.value.trim();
            const code  = verifyInput.value.trim();

            if (!email) {
                alert("Спочатку введіть email");
                return;
            }
            if (!code) {
                alert("Введіть код підтвердження");
                return;
            }

            verifyBtn.disabled = true;
            verifyBtn.textContent = "Перевіряємо…";

            try {
                const url = new URL("email/verify_code.php", window.location.href).toString();
                const formData = new FormData();
                formData.append("email", email);
                formData.append("code", code);

                const res = await fetch(url, {
                    method: "POST",
                    body: formData,
                    credentials: "same-origin"
                });

                const raw = await res.text();
                let data;
                try { data = JSON.parse(raw); }
                catch (e) {
                    throw new Error(`Non-JSON (${res.status}): ${raw.slice(0, 200)}`);
                }

                if (!res.ok || !data.success) {
                    throw new Error(data.message || `HTTP ${res.status}`);
                }

                statusBox.innerHTML = `<div class="alert alert-success">${data.message}</div>`;
            } catch (err) {
                statusBox.innerHTML = `<div class="alert alert-danger">❌ Помилка: ${err.message}</div>`;
            } finally {
                verifyBtn.disabled = false;
                verifyBtn.textContent = "Підтвердити код";
            }
        });
    })();
</script>


<?php include __DIR__ . '/footer.php'; ?>
