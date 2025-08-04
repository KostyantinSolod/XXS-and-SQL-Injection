<?php
session_start();
header('Content-Type: application/json');

require __DIR__ . '/../vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['email'])) {
    echo json_encode(['success' => false, 'message' => 'Невірний запит']);
    exit;
}

$email = trim($_POST['email']);
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Некоректний email']);
    exit;
}

// Генеруємо код
$code = strtoupper(bin2hex(random_bytes(3)));
$_SESSION['reset_code'] = $code;
$_SESSION['reset_email'] = $email;

$mail = new PHPMailer(true);

try {
    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com';
    $mail->SMTPAuth = true;
    $mail->Username = 'solodkostya12345@gmail.com';     // Gmail
    $mail->Password = 'dytohitaoljuefds';               // App Password
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = 587;

    $mail->setFrom('solodkostya12345@gmail.com', 'Підтвердження');
    $mail->addAddress($email);
    $mail->isHTML(true);
    $mail->Subject = 'Код для відновлення пароля';
    $mail->Body    = "Ваш код: <b>$code</b>";

    $mail->send();
    echo json_encode(['success' => true, 'message' => '✅ Код для відновлення пароля надіслано']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => '❌ Не вдалося надіслати: ' . $mail->ErrorInfo]);
}
