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

$stmt = $pdo->prepare('SELECT id, url, title FROM sites WHERE user_id = ? ORDER BY created_at DESC');
$stmt->execute([$user_id]);
$sites = $stmt->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/header.php';
?>
<div class="container">
    <h1 class="mb-4">Ваші сайти</h1>

    <a href="protect_site.php" class="btn btn-success mb-4">Захистити сайт</a>

    <?php if (!$sites): ?>
        <p class="text-muted">Сайти ще не додані.</p>
    <?php else: ?>
        <div class="list-group">
            <?php foreach ($sites as $site): ?>
                <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center"
                   href="site.php?id=<?= $site['id'] ?>">
                    <span><?= htmlspecialchars($site['title'] ?: $site['url']) ?></span>
                    <i class="bi bi-graph-up"></i>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/footer.php'; ?>
