<?php
session_start();
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SESSION['user'])) {
    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ?');
    $stmt->execute([$_SESSION['user']]);
    $user = $stmt->fetch();
    if ($user) {
        $stmt = $pdo->prepare('INSERT INTO user_settings (user_id, theme, timezone, fontsize)
                               VALUES (:uid, :theme, :tz, :fs)
                               ON CONFLICT (user_id) DO UPDATE
                                   SET theme = EXCLUDED.theme,
                                       timezone = EXCLUDED.timezone,
                                       fontsize = EXCLUDED.fontsize');
        $stmt->execute([
            ':uid' => $user['id'],
            ':theme' => $_POST['theme'],
            ':tz' => $_POST['timezone'],
            ':fs' => $_POST['fontsize']
        ]);

        $_SESSION['theme'] = $_POST['theme'];
        $_SESSION['timezone'] = $_POST['timezone'];
        $_SESSION['fontsize'] = $_POST['fontsize'];
    }
}

header('Location: dashboard.php');
exit;
