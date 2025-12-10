<?php
declare(strict_types=1);
session_start();
if (!isset($_SESSION['user'])) { header('Location: login.php'); exit; }
require_once __DIR__ . '/db.php';

$stmt = $pdo->prepare("UPDATE users SET tg_user_id = NULL, tg_username = NULL, tg_linked_at = NULL WHERE username = ?");
$stmt->execute([$_SESSION['user']]);

header('Location: settings.php?unlinked=1');
exit;

