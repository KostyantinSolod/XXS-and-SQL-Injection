<?php
declare(strict_types=1);
require_once __DIR__ . '/../db.php';

header('Content-Type: application/javascript; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

// =========================
// 1. Витягаємо токен із URL
//    /waf/<token>/protect.php
// =========================
$uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$parts   = explode('/', trim($uriPath, '/'));
$token   = '';

for ($i = 0; $i < count($parts) - 1; $i++) {
    if ($parts[$i] === 'waf' && isset($parts[$i+1])) {
        $token = $parts[$i+1];
        break;
    }
}

if ($token === '' || !preg_match('~^[A-Za-z0-9\-_]{10,}$~', $token)) {
    echo "/* protect: invalid token */";
    exit;
}

// =========================
// 2. Шукаємо сайт по protect_token
// =========================
$stmt = $pdo->prepare("SELECT id FROM sites WHERE protect_token = ?");
$stmt->execute([$token]);
$site = $stmt->fetch(PDO::FETCH_ASSOC);
$siteId = $site ? (int)$site['id'] : 0;

if ($siteId <= 0) {
    echo "/* protect: token not recognized */";
    exit;
}

// =========================
// 3. Формуємо URL до waf_log.php
// =========================
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
$origin = rtrim($scheme . '://' . $host, '/');

// SCRIPT_NAME у тебе, як правило: /TestFixed/waf/protect.php
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '/waf/protect.php';
$wafDir     = rtrim(dirname($scriptName), '/\\');     // /TestFixed/waf

// Фізично файл лежить у /waf/api/waf_log.php
$sendUrl = $origin . $wafDir . '/api/waf_log.php';

// @file_put_contents(__DIR__.'/waf_debug.log', "[".date('Y-m-d H:i:s')."] SEND_URL {$sendUrl}\n", FILE_APPEND);
?>
(function(){
"use strict";

const siteId    = <?= json_encode($siteId) ?>;
const siteToken = <?= json_encode($token) ?>;
const sendUrl   = <?= json_encode($sendUrl) ?>;
console.log('[WAF] sendUrl =', sendUrl);

function clip(s, n){
try { return String(s).slice(0, n || 2048); }
catch(e){ return ""; }
}

// ==========================================================
// 1. Патерни XSS / SQLi + аналіз тексту
// ==========================================================
const patterns = {
xss: [
/<script[\s\S]*?>/i,
/<iframe[\s\S]*?>/i,
/<object[\s\S]*?>/i,
/<embed[\s\S]*?>/i,
/javascript:/i,
/vbscript:/i,
/on\w+\s*=/i,
/alert\s*\(/i,
/confirm\s*\(/i,
/prompt\s*\(/i,
/document\.cookie/i,
/document\.write/i,
/eval\s*\(/i,
/expression\s*\(/i,
/<svg[\s\S]*?on\w+/i,
/<img[\s\S]*?on\w+/i
],
sqli: [
/\bunion\b[\s\S]*?\bselect\b/i,
/\bselect\b[\s\S]*?\bfrom\b/i,
/\bdrop\b[\s\S]*?\btable\b/i,
/\binsert\b[\s\S]*?\binto\b/i,
/\bupdate\b[\s\S]*?\bset\b/i,
/\bdelete\b[\s\S]*?\bfrom\b/i,
/--[\s\S]*$/i,
/\/\*[\s\S]*?\*\//i,
/\bor\b\s+\d+\s*=\s*\d+/i,
/\band\b\s+\d+\s*=\s*\d+/i,
/'\s*(or|and)\s*'/i,
/'\s*;\s*(drop|insert|update|delete)/i,
/\bexec\s*\(/i,
/\bxp_\w+/i
]
};

function analyzeText(text){
if (!text) return { score: 0, types: [] };

let score = 0;
let detected = [];

let decoded = text;
try {
decoded = decodeURIComponent(text);
decoded = decodeURIComponent(decoded);
} catch(e) {
decoded = text;
}

let base64Decoded = '';
try {
if (/^[A-Za-z0-9+\/]+=*$/.test(text)) {
base64Decoded = atob(text);
}
} catch(e){}

const checkText = [text, decoded, base64Decoded].join(' ');

for (const [type, pats] of Object.entries(patterns)) {
for (const p of pats) {
try {
if (p.test(checkText)) {
score += 0.3;
if (!detected.includes(type)) detected.push(type);
break;
}
} catch(e) {}
}
}
return { score: Math.min(score, 1), types: detected };
}

function isSuspicious(text) {
const res = analyzeText(String(text || ''));
return {
suspicious: (res.score > 0.2 || res.types.length > 0),
res
};
}

// ==========================================================
// 2. Відправка на сервер
// ==========================================================
function sendToServer(payload){
let sent = false;

const params = new URLSearchParams();
params.set('site_token', String(siteToken || ''));
params.set('params', JSON.stringify(payload));

try {
fetch(sendUrl, {
method: 'POST',
body: params,
keepalive: true,
credentials: 'omit'
})
.then(async r => {
let text = '';
try { text = await r.text(); } catch(e){ text = '{no-text}'; }
console.log('[WAF] fetch status', r.status, 'resp:', text);
sent = true;
})
.catch(e => {
console.warn('[WAF] fetch failed', e);
if (!sent && navigator.sendBeacon) {
try {
navigator.sendBeacon(sendUrl, params);
console.log('[WAF] sent via beacon');
sent = true;
} catch(e2){
console.warn('[WAF] beacon failed', e2);
}
}
});
} catch(e) {
console.warn('[WAF] send error', e);
try {
if (navigator.sendBeacon) {
navigator.sendBeacon(sendUrl, params);
sent = true;
}
} catch(e2){}
}
}

// ==========================================================
// 3. Універсальний логер
//    force = true → логати навіть без збігів regex
// ==========================================================
function checkAndLog(source, content, force){
if (!content || String(content).length < 1) return;

const text = String(content);
const res  = analyzeText(text);

if (force || res.score > 0.2 || res.types.length > 0) {
const payload = {
site_id: siteId,
url:  clip(location.href, 2048),
ref:  clip(document.referrer, 2048),
ua:   clip(navigator.userAgent, 1024),
lang: clip(navigator.language || '', 32),
label: res.types.length ? res.types.join('_') : 'suspicious',
score: Math.round(res.score * 100) / 100,
source: source,
content: clip(text, 2048),
t: Date.now()
};
console.log('[WAF] log:', payload);
sendToServer(payload);
}
}

// ==========================================================
// 4. URL / форми / input / SPA
// ==========================================================
try {
checkAndLog('query', location.search);
checkAndLog('hash', location.hash);
checkAndLog('pathname', location.pathname);
} catch(e){}

// ----- БЛОКУВАННЯ ФОРМ -----
document.addEventListener('submit', function(e){
try {
const form = e.target;
if (!form || form.tagName !== 'FORM') return;

const fd = new FormData(form);
for (const [k, v] of fd.entries()) {
const text = String(v);
const { suspicious } = isSuspicious(text);
if (suspicious) {
e.preventDefault();
e.stopPropagation();
checkAndLog('blocked_form_'+k, text, true);
try {
alert('🚫 Запит заблоковано WAF (підозрілий вміст у полі "'+k+'").');
} catch(_){}
return;
}
}
} catch(err){
console.warn('[WAF] submit handler error', err);
}
});

// ----- input: можна чистити підозріле -----
document.addEventListener('input', function(e){
try {
const el = e.target;
if (!el || !('value' in el)) return;
const v = String(el.value || '');
if (v.length < 3) return;

const { suspicious } = isSuspicious(v);
if (suspicious) {
checkAndLog('blocked_input_'+(el.name || el.id || 'unknown'), v, true);
// можна просто очистити поле
el.value = '';
el.setAttribute('data-waf-blocked', '1');
}
} catch(e){}
});

// ----- SPA-навігація -----
let lastHref = location.href;
setInterval(function(){
try {
if (location.href !== lastHref) {
lastHref = location.href;
checkAndLog('navigation_query', location.search);
checkAndLog('navigation_hash', location.hash);
}
} catch(e){}
}, 1000);

// ==========================================================
// 5. Скан усіх <script> після DOMContentLoaded
    //    (ловить <script>alert("Test")</script> і логить це)
// ==========================================================
function scanAllScripts() {
try {
const scripts = document.getElementsByTagName('script');
for (let i = 0; i < scripts.length; i++) {
const s = scripts[i];
const src = s.getAttribute('src') || '';
const txt = s.textContent || s.innerHTML || '';

// пропускаємо наш власний protect.php
if (src && src.indexOf('protect.php') !== -1) continue;

const combined = (src + ' ' + txt).trim();
if (!combined) continue;

checkAndLog('script_tag', combined);
}
} catch(e){
console.warn('[WAF] scanAllScripts error', e);
}
}

if (document.readyState === 'loading') {
document.addEventListener('DOMContentLoaded', scanAllScripts);
} else {
scanAllScripts();
}

// ==========================================================
// 6. Хуки на alert / confirm / prompt
//    ТУТ МИ ВЖЕ БЛОКУЄМО, а не викликаємо оригінал
// ==========================================================
(function(){
const origAlert   = window.alert;
const origConfirm = window.confirm;
const origPrompt  = window.prompt;

window.alert = function(message){
try {
const text = String(message);
checkAndLog('sink_alert_blocked', 'alert(' + text + ')', true);
console.warn('[WAF] blocked alert:', text);
} catch(e){}
// НЕ викликаємо origAlert → будь-який alert "XSS" не спливе
// якщо хочеш частково дозволити, можеш перевіряти isSuspicious(text)
// і тільки тоді блокувати
// return origAlert.apply(this, arguments);
};

window.confirm = function(message){
try {
const text = String(message);
checkAndLog('sink_confirm_blocked', 'confirm(' + text + ')', true);
console.warn('[WAF] blocked confirm:', text);
} catch(e){}
// повертаємо false, ніби користувач натиснув "Скасувати"
return false;
};

window.prompt = function(message, def){
try {
const text = String(message);
checkAndLog('sink_prompt_blocked', 'prompt(' + text + ')', true);
console.warn('[WAF] blocked prompt:', text);
} catch(e){}
// повертаємо null, ніби користувач нічого не ввів
return null;
};
})();

console.log('[WAF] Protection initialized for site', siteId);
})();
