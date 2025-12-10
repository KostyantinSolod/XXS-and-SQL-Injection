<?php
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
define('DEFENDER_ANALYZE_URL', $scheme . '://' . $host . '/defender/api/analyze.php');

function send_to_analyzer($data) {
    $ch = curl_init(DEFENDER_ANALYZE_URL);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    curl_exec($ch);
    curl_close($ch);
}

$params = array_merge($_GET, $_POST, $_COOKIE);
$site_id = (int)($_GET['site_id'] ?? $_POST['site_id'] ?? 0);

send_to_analyzer([
    'site_id'    => $site_id,
    'params'     => json_encode($params, JSON_UNESCAPED_UNICODE),
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
    'ip'         => $_SERVER['REMOTE_ADDR'] ?? '',
    'url'        => $_SERVER['REQUEST_URI'] ?? ''
]);
