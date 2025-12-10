<?php
declare(strict_types=1);
require_once __DIR__.'/../bootstrap.php';
require_once __DIR__.'/../db.php';
header('Content-Type: application/json; charset=utf-8');

// Виклик іде ВІД бота: перевіряємо спільний секрет
$secret = $_POST['secret'] ?? '';
if (!hash_equals($_ENV['TG_BIND_SECRET'] ?? '', $secret)) {
    http_response_code(403);
    echo json_encode(['success'=>false,'message'=>'Forbidden']); exit;
}

$siteUserId = (int)($_POST['site_user_id'] ?? 0);
$tgUserId   = (int)($_POST['tg_user_id'] ?? 0);
$tgUsername = trim($_POST['tg_username'] ?? '');

if ($siteUserId <= 0 || $tgUserId <= 0) {
    http_response_code(400);
    echo json_encode(['success'=>false,'message'=>'Bad params']); exit;
}

$stmt = $pdo->prepare("UPDATE users
  SET tg_user_id = ?, tg_username = ?, tg_linked_at = NOW()
  WHERE id = ?");
$stmt->execute([$tgUserId, $tgUsername ?: null, $siteUserId]);

echo json_encode(['success'=>true,'message'=>'Linked']);
