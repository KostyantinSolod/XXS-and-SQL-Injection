<?php
session_start();
if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/db.php';
$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT s.*, u.username FROM sites s JOIN users u ON u.id = s.user_id WHERE s.id = ?');
$stmt->execute([$id]);
$site = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$site || $site['username'] !== $_SESSION['user']) {
    header('Location: dashboard.php');
    exit;
}

include __DIR__ . '/header.php';
?>
<div class="container">
    <h1 class="mb-4">Трафік для <?= htmlspecialchars($site['title'] ?: $site['url']) ?></h1>
    <canvas id="trafficChart" height="140"></canvas>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
const ctx = document.getElementById('trafficChart');
new Chart(ctx, {
    type: 'line',
    data: {
        labels: [...Array(30).keys()].map(d => `День ${d + 1}`),
        datasets: [{
            label: 'Відвідувань',
            data: [...Array(30).keys()].map(() => Math.floor(Math.random() * 100) + 20),
            tension: 0.2,
        }]
    },
    options: {
        scales: {
            y: { beginAtZero: true }
        }
    }
});
</script>
<?php include __DIR__ . '/footer.php'; ?>
