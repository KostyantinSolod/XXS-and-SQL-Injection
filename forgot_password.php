<?php
session_start();
if (isset($_SESSION['user'])) {
    header('Location: dashboard.php');
    exit;
}

include __DIR__ . '/header.php';
?>

<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-4">
            <div class="card p-4">
                <h2 class="text-center mb-4">Відновлення пароля</h2>
                
                <?php if (isset($_GET['status'])): ?>
                    <div class="alert alert-<?= $_GET['status'] === 'success' ? 'success' : 'danger' ?>">
                        <?= $_GET['status'] === 'success' 
                            ? 'Інструкції відправлено на email' 
                            : (htmlspecialchars($_GET['message'] ?? 'Помилка відправки')) ?>
                    </div>
                <?php endif; ?>

                <form id="resetForm">
                    <div class="mb-3">
                        <label for="email" class="form-label">Email</label>
                        <input type="email" class="form-control" id="email" name="email" required>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Надіслати посилання</button>
<div id="status" class="mt-3"></div>
                </form>
                
                <div class="text-center mt-3">
                    <a href="login.php">Повернутись до входу</a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/footer.php'; ?>
<script>
document.addEventListener("DOMContentLoaded", function () {
    const form = document.getElementById("resetForm");
    const emailInput = document.getElementById("email");
    const statusBox = document.getElementById("status");

    form.addEventListener("submit", async function (e) {
        e.preventDefault();

        const email = emailInput.value.trim();
        if (!email) return alert("Введіть email!");

        const formData = new FormData();
        formData.append("email", email);

        try {
            const res = await fetch("email/send_reset_code.php", {
                method: "POST",
                body: formData
            });

            const data = await res.json();
            if (data.success) {
                window.location.href = "enter_reset_code.php";
            } else {
                statusBox.innerHTML = '<div class="alert alert-danger">' + (data.message || 'Помилка') + '</div>';
            }
        } catch (err) {
            statusBox.innerHTML = '<div class="alert alert-danger">❌ Помилка запиту до сервера</div>';
        }
    });
});
</script>
