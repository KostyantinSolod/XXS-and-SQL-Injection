<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../db.php'; // $pdo

if (empty($_SESSION['user'])) {
    echo json_encode([]);
    exit;
}

// Дістаємо поточного юзера
$stmt = $pdo->prepare("SELECT id, tg_user_id FROM users WHERE username = ?");
$stmt->execute([$_SESSION['user']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user || empty($user['tg_user_id'])) {
    echo json_encode([]); // не прив'язаний TG — нема що показувати
    exit;
}

$botId = getenv('BOT_TG_ID'); // числовий ID твого бота
if (!$botId) {
    echo json_encode([]); // без ID бота не визначимо перетин
    exit;
}

$sql = "
    SELECT c.chat_id, c.title, c.chat_type
      FROM chats c
     WHERE c.chat_type IN ('group','supergroup')
       AND EXISTS (SELECT 1 FROM chat_admins a WHERE a.chat_id = c.chat_id AND a.admin_tg_user_id = :u)
       AND EXISTS (SELECT 1 FROM chat_admins b WHERE b.chat_id = c.chat_id AND b.admin_tg_user_id = :b)
     ORDER BY c.updated_at DESC NULLS LAST, c.title ASC
    LIMIT 200
";
$stmt = $pdo->prepare($sql);
$stmt->execute([':u'=>(int)$user['tg_user_id'], ':b'=>(int)$botId]);

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
echo json_encode($rows, JSON_UNESCAPED_UNICODE);
