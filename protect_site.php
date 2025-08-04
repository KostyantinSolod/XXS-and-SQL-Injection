<?php
session_start();
if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/db.php';

$stmt = $pdo->prepare('SELECT id FROM users WHERE username = ?');
$stmt->execute([$_SESSION['user']]);
$user = $stmt->fetch();
$user_id = $user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $url = trim($_POST['url']);
    $title = trim($_POST['title']);
    if ($url) {
        $stmt = $pdo->prepare('INSERT INTO sites (user_id, url, title) VALUES (?,?,?)');
        $stmt->execute([$user_id, $url, $title]);
        header('Location: dashboard.php');
        exit;
    }
}

include __DIR__ . '/header.php';
?>
<div class="container">
    <h1 class="mb-4">Додати сайт під захист</h1>
    <form method="post" class="card p-4">
        <div class="mb-3">
            <label class="form-label">URL сайту</label>
            <input type="url" name="url" class="form-control" placeholder="https://example.com" required>
        </div>
        <div class="mb-3">
            <label class="form-label">Назва (необов'язково)</label>
            <input type="text" name="title" class="form-control" placeholder="Мій блог">
        </div>
        <button class="btn btn-success">Зберегти</button>
    </form>
</div>
<?php include __DIR__ . '/footer.php'; ?>
