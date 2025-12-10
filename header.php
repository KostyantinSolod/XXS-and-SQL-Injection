<?php
if (session_status() === PHP_SESSION_NONE) session_start();
$username = $_SESSION['user'] ?? null;

// якщо проєкт у підпапці (http://localhost/TestFixed)
$BASE = '/TestFixed'; // якщо в корені: $BASE = '';
?>
<!doctype html>
<html lang="uk" data-bs-theme="<?= $_SESSION['theme'] ?? 'light' ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>SecureAuth Demo</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <!-- Власні стилі через $BASE -->
    <link href="<?= $BASE ?>/assets/css/style.css" rel="stylesheet">
    <style>
        /* підстраховка, якщо щось перекриває dropdown */
        .navbar .dropdown-menu { z-index: 2000; }
    </style>
</head>
<body class="d-flex flex-column min-vh-100">

<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <div class="container">
        <!-- Лого → головна/дашборд у межах проєкту -->
        <a class="navbar-brand fw-bold" href="<?= $BASE ?>/dashboard.php">SecureAuth</a>

        <div class="ms-auto">
            <?php if ($username): ?>
                <div class="dropdown">
                    <button class="btn btn-outline-light btn-sm dropdown-toggle" type="button" id="userMenu" data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false">Вітаємо, <?= htmlspecialchars($username) ?>!</button>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userMenu">
                        <li><a class="dropdown-item" href="<?= $BASE ?>/settings.php">
                                <i class="bi bi-gear me-2"></i>Налаштування</a></li>
                        <li><a class="dropdown-item" href="<?= $BASE ?>/logout.php">
                                <i class="bi bi-box-arrow-right me-2"></i>Вийти</a></li>
                    </ul>
                </div>
            <?php else: ?>
                <a class="btn btn-outline-light btn-sm me-2" href="<?= $BASE ?>/login.php">Увійти</a>
                <a class="btn btn-light btn-sm" href="<?= $BASE ?>/register.php">Реєстрація</a>
            <?php endif; ?>
        </div>
    </div>
</nav>

<main class="flex-grow-1 d-flex align-items-center justify-content-center p-3">
