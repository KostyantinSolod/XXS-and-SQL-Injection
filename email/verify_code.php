<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');

$code  = trim((string)($_POST['code']  ?? ''));
$email = trim((string)($_POST['email'] ?? ''));

if ($code === '' || $email === '') {
    http_response_code(400);
    echo json_encode(['success'=>false,'message'=>'Код та email обовʼязкові']);
    exit;
}

if (empty($_SESSION['email_code']) || empty($_SESSION['email_for_code']) || empty($_SESSION['email_code_expires'])) {
    http_response_code(400);
    echo json_encode(['success'=>false,'message'=>'Код не ініціалізовано. Відправ код ще раз.']);
    exit;
}

if (time() > (int)$_SESSION['email_code_expires']) {
    http_response_code(400);
    echo json_encode(['success'=>false,'message'=>'Код прострочено. Відправ новий.']);
    exit;
}

$_SESSION['email_code_attempts'] = (int)($_SESSION['email_code_attempts'] ?? 0) + 1;
if ($_SESSION['email_code_attempts'] > 5) {
    http_response_code(429);
    echo json_encode(['success'=>false,'message'=>'Забагато спроб. Відправ код знову.']);
    exit;
}

if ($email !== $_SESSION['email_for_code'] || $code !== $_SESSION['email_code']) {
    http_response_code(400);
    echo json_encode(['success'=>false,'message'=>'Невірний код']);
    exit;
}

// Успіх: помітимо email як підтверджений
$_SESSION['email_verified'] = true;
// (опційно) можна очистити код, або залишити до завершення реєстрації
echo json_encode(['success'=>true,'message'=>'✅ Email підтверджено']);
