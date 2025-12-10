<?php
// defender/remote_defender.php  (або analyze.php)
// Початок файлу — логування та нормалізація

// Встановити шлях до логів
$logDir = __DIR__ . '/../logs';
if (!is_dir($logDir)) @mkdir($logDir, 0755, true);

// Отримати вхідні дані
$site_id = isset($_POST['site_id']) ? trim($_POST['site_id']) : (isset($_GET['site_id']) ? trim($_GET['site_id']) : '');
$rawParams = isset($_POST['params']) ? $_POST['params'] : (isset($_GET['params']) ? $_GET['params'] : '');

// Логування сирого вхідного (короткий фрагмент)
$rawPreview = substr($rawParams, 0, 4000);
file_put_contents($logDir . '/debug_incoming_raw.log', date("c") . " | site_id=".$site_id." | raw_len=".strlen($rawParams)." | preview: ".preg_replace("/\s+/", " ", $rawPreview) . PHP_EOL, FILE_APPEND);

// Нормалізація: декодуємо URL-encoding, HTML entities, і, якщо виглядає як base64, декодуємо
function try_normalize($s) {
    if (!is_string($s)) return $s;
    $t = $s;
    // видалити зайві control-символи
    $t = preg_replace("/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F]+/", " ", $t);
    // url decode
    $t2 = urldecode($t);
    if (strlen($t2) > strlen($t)) $t = $t2;
    // html entity decode
    $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // спроба base64 декодування якщо рядок довгий і має тільки base64-символи
    if (preg_match('/^[A-Za-z0-9\\/\\+\\=\\s]+$/', trim($t)) && strlen(trim($t)) > 64) {
        $maybe = @base64_decode($t, true);
        if ($maybe !== false && strlen($maybe) > 0) {
            // якщо після декодування є тег <script> або javascript:, використаємо декодований
            if (preg_match('/<script|javascript:|onerror|onload/i', $maybe)) {
                $t = $maybe;
            }
        }
    }
    // нормалізувати пробіли
    $t = preg_replace('/\s+/', ' ', $t);
    return trim($t);
}

$paramsJson = try_normalize($rawParams);

// Якщо params виглядає як JSON — декодуємо
$data = array();
if ($paramsJson) {
    $maybe = json_decode($paramsJson, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($maybe)) {
        $data = $maybe;
    } else {
        // Якщо не JSON — кладемо сирий текст у поле 'raw'
        $data['raw'] = $paramsJson;
    }
}

// Логування розпакованих даних (коротко)
file_put_contents($logDir . '/debug_parsed.log', date("c") . " | site_id=".$site_id." | keys: ".implode(',', array_keys($data)).PHP_EOL, FILE_APPEND);

// --- ПРОСТА СИГНАТУРНА ПРОВІРКА (приклад) ---
$attacks_found = [];
$hay = '';
if (!empty($data)) {
    // зберемо кілька полів у великий рядок для пошуку сигнатур
    $parts = [];
    foreach ($data as $k=>$v) {
        if (is_string($v)) $parts[] = $v;
        elseif (is_array($v)) $parts[] = json_encode($v);
    }
    $hay = implode(' ', $parts);
} else {
    $hay = isset($data['raw']) ? $data['raw'] : '';
}

// Нормалізований рядок для пошуку
$hay_norm = strtolower($hay);

// прості сигнатури
$sigs = [
    '<script' => 'script tag',
    'javascript:' => 'javascript: uri',
    'onerror=' => 'inline onerror',
    'onload=' => 'inline onload',
    "document.cookie" => 'cookie access',
    "' or '" => 'sql tautology (simple)',
    "\" or \"" => 'sql tautology (simple)',
    "union select" => 'sql union select',
    "-- " => 'sql comment',
    "/*" => 'sql comment start'
];

foreach ($sigs as $pat => $name) {
    if (strpos($hay_norm, $pat) !== false) {
        $attacks_found[] = $name . " (". $pat .")";
    }
}

// Якщо знайшли — логувати окремо
if (!empty($attacks_found)) {
    $logEntry = [
        'ts' => date("c"),
        'site_id' => $site_id,
        'found' => $attacks_found,
        'snippet' => substr($hay, 0, 2000)
    ];
    file_put_contents($logDir . '/attacks.log', json_encode($logEntry, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND);
    // Можна також відправляти нотифікацію в Telegram тут
}

// Відповідь (мінімальна)
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['status'=>'ok','found'=>(empty($attacks_found)?[]:$attacks_found)]);
exit;
