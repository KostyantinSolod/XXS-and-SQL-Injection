<?php
session_start();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['email'])) {
    echo json_encode(['success' => false, 'message' => 'Невірний запит']);
    exit;
}

$email = trim($_POST['email']);
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Некоректний email']);
    exit;
}

$code = strtoupper(bin2hex(random_bytes(3)));
$_SESSION['verification_code'] = $code;
$_SESSION['email_to_verify'] = $email;

require '../vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$mail = new PHPMailer(true);

try {
    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com';
    $mail->SMTPAuth = true;
    $mail->Username = 'solodkostya12345@gmail.com';      // Gmail email
    $mail->Password = 'dytohitaoljuefds';                // Gmail App Password
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = 587;

    $mail->setFrom('solodkostya12345@gmail.com', 'Реєстрація');
    $mail->addAddress($email);
    $mail->isHTML(true);
    $mail->Subject = 'Код підтвердження реєстрації';
    $mail->Body = "Ваш код підтвердження: <b>$code</b>";

    $mail->send();

    echo json_encode(['success' => true, 'message' => '✅ Код надіслано на пошту']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => '❌ ' . $mail->ErrorInfo]);
}

