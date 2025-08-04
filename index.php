<?php
session_start();
if (isset($_SESSION['user'])) {
    header('Location: dashboard.php');
    exit;
}
include __DIR__ . '/header.php';
?>
<div class="container text-center">
    <h1 class="display-4 mb-4">SecureAuth</h1>
    <p class="lead mb-5">Платформа для захисту вашого веб‑сайту від XSS та SQL‑інʼєкцій</p>
    <div class="d-flex justify-content-center gap-3">
        <a class="btn btn-primary btn-lg" href="register.php">Реєстрація</a>
        <a class="btn btn-outline-light btn-lg" href="login.php">Увійти</a>
    </div>
</div>
<?php include __DIR__ . '/footer.php'; ?>
