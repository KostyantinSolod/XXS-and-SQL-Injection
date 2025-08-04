<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
<!doctype html>
<html lang="uk" data-bs-theme="<?= $_SESSION['theme'] ?? 'light' ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>SecureAuth Demo</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="/assets/css/style.css" rel="stylesheet">
</head>
<body class="d-flex flex-column min-vh-100">

<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <div class="container">
        <a class="navbar-brand fw-bold" href="/">SecureAuth</a>
        <div>
            <?php if (isset($_SESSION['user'])): ?>
                <span class="navbar-text me-3">Привіт, <?=htmlspecialchars($_SESSION['user'])?>!</span>
                <a class="btn btn-outline-light btn-sm me-2" href="dashboard.php">Дашборд</a>
                <a class="btn btn-outline-light btn-sm" href="logout.php">Вийти</a>
            <?php else: ?>
                <a class="btn btn-outline-light btn-sm me-2" href="login.php">Увійти</a>
                <a class="btn btn-light btn-sm" href="register.php">Реєстрація</a>
            <?php endif; ?>
        </div>
    </div>
</nav>

<main class="flex-grow-1 d-flex align-items-center justify-content-center p-3">
