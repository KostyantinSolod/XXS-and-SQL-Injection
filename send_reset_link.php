<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['email'])) {
    header("Location: forgot_password.php?status=error&message=Некоректний+запит");
    exit;
}

$email = trim($_POST['email']);
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header("Location: forgot_password.php?status=error&message=Некоректна+email+адреса");
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user) {
    header("Location: forgot_password.php?status=error&message=Email+не+знайдено");
    exit;
}

// Генеруємо код
$code = strtoupper(bin2hex(random_bytes(3)));

// Зберігаємо у сесію
$_SESSION['reset_code'] = $code;
$_SESSION['reset_email'] = $email;

// Надсилаємо лист
$mail = new PHPMailer(true);

try {
    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com';
    $mail->SMTPAuth = true;
    $mail->Username = 'solodkostya12345@gmail.com';
    $mail->Password = 'dytohitaoljuefds'; // App Password Gmail
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = 587;

    $mail->setFrom('solodkostya12345@gmail.com', 'Відновлення пароля');
    $mail->addAddress($email);
    $mail->isHTML(true);
    $mail->Subject = 'Код відновлення пароля';
    $mail->Body = "Ваш код для відновлення пароля: <b>$code</b>";

    $mail->send();

    header("Location: enter_reset_code.php");
    exit;
} catch (Exception $e) {
    header("Location: forgot_password.php?status=error&message=Помилка+відправки+листа");
    exit;
}
?>