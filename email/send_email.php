<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');

// Завантажуємо Composer + Dotenv
$autoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload)) {
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'❌ Немає vendor/autoload.php. Виконай composer require vlucas/phpdotenv phpmailer/phpmailer']);
    exit;
}
require_once $autoload;

if (file_exists(__DIR__.'/../.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__.'/..');
    $dotenv->load();
}

$email = trim((string)($_POST['email'] ?? ''));
if ($email === '') {
    http_response_code(400);
    echo json_encode(['success'=>false,'message'=>'Email обовʼязковий']);
    exit;
}
// Читаємо змінні
$host   = $_ENV['SMTP_HOST']   ?? 'smtp.gmail.com';
$user   = $_ENV['SMTP_USER']   ?? '';
$pass   = $_ENV['SMTP_PASS']   ?? '';
$from   = $_ENV['SMTP_FROM']   ?? $user;
$port   = (int)($_ENV['SMTP_PORT'] ?? 587);
$secure = strtolower($_ENV['SMTP_SECURE'] ?? 'tls'); // tls або ssl

if (!$user || !$pass || !$from) {
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'❌ SMTP не налаштовано (дивись .env)']);
    exit;
}

try {
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    $mail->CharSet = 'UTF-8';
    $mail->isSMTP();
    $mail->Host       = $host;
    $mail->SMTPAuth   = true;
    $mail->Username   = $user;
    $mail->Password   = $pass;
    $mail->SMTPSecure = $secure;
    $mail->Port       = $port;

    $code = random_int(100000, 999999);
    $mail->setFrom($from, 'Реєстрація');
    $mail->addAddress($email);
    $mail->isHTML(true);
    $mail->Subject = 'Код підтвердження';
    $mail->Body    = "Ваш код: <b>$code</b>";

    $mail->send();
    // збережемо код у сесію на 10 хв і прив'яжемо до email
    $_SESSION['email_code'] = (string)$code;
    $_SESSION['email_for_code'] = $email;   // той самий email, куди відправляли
    $_SESSION['email_code_expires'] = time() + 600; // 10 хв
    $_SESSION['email_code_attempts'] = 0;

    echo json_encode(['success'=>true,'message'=>'✅ Код надіслано', 'ttl'=>600]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'❌ SMTP помилка: '.$e->getMessage()]);
}
