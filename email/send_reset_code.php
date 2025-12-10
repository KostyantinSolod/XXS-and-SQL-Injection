<?php
session_start();
header('Content-Type: application/json');

require __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use Dotenv\Dotenv;

// ---- .env ----
try {
    $dotenv = Dotenv::createImmutable(dirname(__DIR__)); // корінь проекту
    $dotenv->safeLoad();
} catch (\Throwable $e) {
    // не фейлимо, але продовжимо — можливо, змінні задані на рівні середовища
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['email'])) {
    echo json_encode(['success'=>false,'message'=>'Невірний запит']); exit;
}

$email = trim($_POST['email']);
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success'=>false,'message'=>'Некоректний email']); exit;
}

$code = strtoupper(bin2hex(random_bytes(3)));
$_SESSION['reset_code']  = $code;
$_SESSION['reset_email'] = $email;

$mail = null;

try {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->CharSet  = 'UTF-8';
    $mail->Encoding = 'base64';

    // читаємо з .env (доступні обидві нотації ключів)
    $host   = $_ENV['SMTP_HOST']   ?? $_ENV['SMTPHost']   ?? 'smtp.gmail.com';
    $user   = $_ENV['SMTP_USER']   ?? $_ENV['SMTPUsername'] ?? '';
    $pass   = $_ENV['SMTP_PASS']   ?? $_ENV['SMTPPassword'] ?? '';
    $secure = $_ENV['SMTP_SECURE'] ?? $_ENV['SMTPSecure'] ?? 'tls';
    $port   = (int)($_ENV['SMTP_PORT'] ?? $_ENV['SMTPPort'] ?? 587);
    $from   = $_ENV['SMTP_FROM']   ?? $user;

    if (!$user || !$pass) {
        throw new Exception('SMTP_USER/SMTP_PASS порожні — вкажи доступи до SMTP у .env');
    }
    if (!$from || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('SMTP_FROM порожній/некоректний (або не заданий SMTP_USER).');
    }

    // базові налаштування SMTP
    $mail->Host       = $host;
    $mail->SMTPAuth   = true;
    $mail->Username   = $user;
    $mail->Password   = $pass;

    // шифрування + порт
    $s = strtolower(trim((string)$secure));
    if (in_array($s, ['ssl', 'smtps'], true)) {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        if (!$port) { $port = 465; }
    } elseif (in_array($s, ['tls', 'starttls'], true)) {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        if (!$port) { $port = 587; }
    } else {
        // дефолт — STARTTLS на 587
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        if (!$port) { $port = 587; }
    }
    $mail->Port = $port;

    // ВАЖЛИВО: коректний From
    $mail->setFrom($from, 'Підтвердження');

    $mail->addAddress($email);
    $mail->isHTML(true);
    $mail->Subject = 'Код для відновлення пароля';
    $mail->Body    = "Ваш код: <b>{$code}</b>";

    $mail->send();
    echo json_encode(['success'=>true,'message'=>'✅ Код для відновлення пароля надіслано']);
} catch (Exception $e) {
    $err = ($mail instanceof PHPMailer && $mail->ErrorInfo) ? $mail->ErrorInfo : $e->getMessage();
    echo json_encode(['success'=>false,'message'=>'❌ Помилка SMTP: ' . $err]);
}

