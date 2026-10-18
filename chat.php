<?php
/**
 * chat.php - Backend chatbot (revisi: selalu mengembalikan JSON)
 *
 * Key API ditulis di file apikey.php (satu folder). Contoh isi apikey.php:
 *
 *   <?php
 *   $GEMINI_KEY    = 'AIza...';
 *   $OPENAI_KEY    = '';
 *   $ANTHROPIC_KEY = '';
 *   // $SSL_VERIFY = false;   // hanya jika error "SSL certificate problem" di localhost
 *
 * Urutan prioritas: Gemini -> ChatGPT -> Claude -> tanpa key (kata kunci).
 * Jika provider gagal, otomatis lanjut ke berikutnya.
 */

// Tampung semua output liar (warning, spasi, BOM) agar tidak merusak JSON
ob_start();
ini_set('display_errors', '0');
error_reporting(E_ALL);

$GLOBALS['__responded'] = false;

/** Kirim JSON bersih lalu berhenti. */
function respond(array $data, int $status = 200): void
{
    $GLOBALS['__responded'] = true;
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

// Tangkap fatal error (mis. fungsi tidak ada, memori habis) dan ubah jadi JSON
register_shutdown_function(function () {
    if ($GLOBALS['__responded']) {
        return;
    }
    $e = error_get_last();
    $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if ($e && in_array($e['type'], $fatal, true)) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'reply'  => 'Terjadi kesalahan server: ' . $e['message'] . ' (baris ' . $e['line'] . ')',
            'source' => 'error',
            'model'  => '-',
            'errors' => [$e['message']],
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
});

// Fungsi cadangan jika ekstensi mbstring belum aktif
if (!function_exists('mb_strlen')) {
    function mb_strlen($s) { return strlen(utf8_decode($s)); }
}
if (!function_exists('mb_strtolower')) {
    function mb_strtolower($s) { return strtolower($s); }
}

// Session (jangan sampai gagal merusak output)
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}
if (!isset($_SESSION) || !is_array($_SESSION)) {
    $_SESSION = [];   // tetap jalan walau session tidak bisa ditulis
}

// ================== KEY API ==================
$GEMINI_KEY = $OPENAI_KEY = $ANTHROPIC_KEY = '';
$SSL_VERIFY = true;

$keyFile = __DIR__ . '/apikey.php';
if (is_file($keyFile)) {
    try {
        require $keyFile;
    } catch (Throwable $e) {
        respond([
            'reply'  => 'apikey.php bermasalah: ' . $e->getMessage() . ' (baris ' . $e->getLine() . ')',
            'source' => 'error', 'model' => '-', 'errors' => [$e->getMessage()],
        ], 500);
    }
}
$GEMINI_KEY    = trim((string)$GEMINI_KEY);
$OPENAI_KEY    = trim((string)$OPENAI_KEY);
$ANTHROPIC_KEY = trim((string)$ANTHROPIC_KEY);

// Model yang dipakai (boleh diganti)
$GEMINI_MODEL    = 'gemini-3.5-flash-lite';   // pastikan nama model ada di halaman pricing/models Google
$OPENAI_MODEL    = 'gpt-4o-mini';
$ANTHROPIC_MODEL = 'claude-sonnet-5-5';
// =============================================

$SYSTEM_PROMPT = 'Kamu adalah asisten chatbot yang ramah. Jawab singkat dan jelas dalam bahasa yang dipakai pengguna.';

// ---------- Ambil input ----------
$input   = json_decode((string)file_get_contents('php://input'), true);
$input   = is_array($input) ? $input : [];
$message = trim((string)($input['message'] ?? ''));

if (!empty($input['reset'])) {
    $_SESSION['history'] = [];
    respond(['reply' => 'Percakapan direset.']);
}
if ($message === '') {
    respond(['reply' => 'Pesan tidak boleh kosong.'], 400);
}
if (mb_strlen($message) > 1000) {
    respond(['reply' => 'Pesan terlalu panjang (maks 1000 karakter).'], 400);
}

$_SESSION['history'] = $_SESSION['history'] ?? [];

// ---------- Helper HTTP ----------
$LAST_ERROR = '';

function postJson(string $url, array $headers, array $payload): ?array
{
    global $LAST_ERROR, $SSL_VERIFY;
    $LAST_ERROR = '';
    $body    = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    $headers = array_merge(['content-type: application/json'], $headers);
    $err     = '';
    $res     = false;
    $code    = 0;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_SSL_VERIFYPEER => (bool)$SSL_VERIFY,
            CURLOPT_SSL_VERIFYHOST => $SSL_VERIFY ? 2 : 0,
        ];
        // Jika ada cacert.pem di folder ini, pakai (umum dibutuhkan di XAMPP/Windows)
        $ca = __DIR__ . '/cacert.pem';
        if ($SSL_VERIFY && is_file($ca)) {
            $opts[CURLOPT_CAINFO] = $ca;
        }
        curl_setopt_array($ch, $opts);
        $res  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => implode("\r\n", $headers),
            'content'       => $body,
            'timeout'       => 30,
            'ignore_errors' => true,
        ]]);
        $res = @file_get_contents($url, false, $ctx);
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $code = (int)$m[1];
        }
        if ($res === false) {
            $err = 'curl tidak aktif dan file_get_contents gagal (cek allow_url_fopen dan openssl di php.ini)';
        }
    }

    if ($res === false || $code !== 200) {
        $detail = $err;
        if ($detail === '' && is_string($res)) {
            $j = json_decode($res, true);
            $detail = $j['error']['message'] ?? substr($res, 0, 300);
        }
        $LAST_ERROR = "HTTP {$code} {$detail}";
        return null;
    }
    $data = json_decode($res, true);
    if (!is_array($data)) {
        $LAST_ERROR = 'Respons API bukan JSON';
        return null;
    }
    return $data;
}

// ---------- Provider: Gemini ----------
function geminiReply(string $key, string $model, string $system, array $history): ?string
{
    global $LAST_ERROR;
    $contents = [];
    foreach ($history as $m) {
        $contents[] = [
            'role'  => $m['role'] === 'assistant' ? 'model' : 'user',
            'parts' => [['text' => $m['content']]],
        ];
    }
    $data = postJson(
        "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent",
        ['x-goog-api-key: ' . $key],
        [
            'system_instruction' => ['parts' => [['text' => $system]]],
            'contents'           => $contents,
        ]
    );
    if ($data === null) {
        return null;
    }
    $text = '';
    foreach ($data['candidates'][0]['content']['parts'] ?? [] as $part) {
        $text .= $part['text'] ?? '';
    }
    if ($text === '') {
        $LAST_ERROR = 'Respons kosong' . (isset($data['promptFeedback']['blockReason'])
            ? ' (diblokir: ' . $data['promptFeedback']['blockReason'] . ')' : '');
        return null;
    }
    return $text;
}

// ---------- Provider: ChatGPT (OpenAI) ----------
function openaiReply(string $key, string $model, string $system, array $history): ?string
{
    $messages = array_merge([['role' => 'system', 'content' => $system]], $history);
    $data = postJson(
        'https://api.openai.com/v1/chat/completions',
        ['Authorization: Bearer ' . $key],
        ['model' => $model, 'messages' => $messages]
    );
    return $data['choices'][0]['message']['content'] ?? null;
}

// ---------- Provider: Claude (Anthropic) ----------
function claudeReply(string $key, string $model, string $system, array $history): ?string
{
    $data = postJson(
        'https://api.anthropic.com/v1/messages',
        ['x-api-key: ' . $key, 'anthropic-version: 2023-06-01'],
        [
            'model'      => $model,
            'max_tokens' => 1024,
            'system'     => $system,
            'messages'   => $history,
        ]
    );
    return $data['content'][0]['text'] ?? null;
}

// ---------- Tanpa key: berbasis kata kunci ----------
function ruleBasedReply(string $text): string
{
    $t = mb_strtolower($text);
    $rules = [
        ['/\b(halo|hai|hi|hello|selamat (pagi|siang|sore|malam))\b/u',
            ['Halo! Ada yang bisa saya bantu?', 'Hai! Senang bertemu denganmu.']],
        ['/\b(siapa kamu|namamu|nama kamu)\b/u',
            ['Saya chatbot sederhana yang dibuat dengan PHP.']],
        ['/\b(jam|pukul|waktu)\b/u',
            ['Sekarang pukul ' . date('H:i') . ' WIB.']],
        ['/\b(tanggal|hari ini)\b/u',
            ['Hari ini tanggal ' . date('d-m-Y') . '.']],
        ['/\b(terima kasih|makasih|thanks)\b/u',
            ['Sama-sama!', 'Dengan senang hati.']],
        ['/\b(bantuan|help|bisa apa)\b/u',
            ['Coba tanya: "jam berapa?", "tanggal berapa?", atau sapa saya dengan "halo".']],
        ['/\b(bye|dadah|sampai jumpa)\b/u',
            ['Sampai jumpa lagi!']],
    ];
    foreach ($rules as $rule) {
        if (preg_match($rule[0], $t)) {
            return $rule[1][array_rand($rule[1])];
        }
    }
    return 'Maaf, saya belum mengerti. Ketik "bantuan" untuk melihat apa yang bisa saya jawab.';
}

// ---------- Proses ----------
$_SESSION['history'][] = ['role' => 'user', 'content' => $message];
$_SESSION['history']   = array_values(array_slice($_SESSION['history'], -20));
while (!empty($_SESSION['history']) && $_SESSION['history'][0]['role'] !== 'user') {
    array_shift($_SESSION['history']);
}
$history = $_SESSION['history'];

$reply     = null;
$source    = 'rule-based';
$modelName = 'kata kunci (tanpa AI)';
$errors    = [];

$providers = [
    ['gemini',  $GEMINI_KEY,    $GEMINI_MODEL,    function () use ($GEMINI_KEY, $GEMINI_MODEL, $SYSTEM_PROMPT, $history) {
        return geminiReply($GEMINI_KEY, $GEMINI_MODEL, $SYSTEM_PROMPT, $history);
    }],
    ['chatgpt', $OPENAI_KEY,    $OPENAI_MODEL,    function () use ($OPENAI_KEY, $OPENAI_MODEL, $SYSTEM_PROMPT, $history) {
        return openaiReply($OPENAI_KEY, $OPENAI_MODEL, $SYSTEM_PROMPT, $history);
    }],
    ['claude',  $ANTHROPIC_KEY, $ANTHROPIC_MODEL, function () use ($ANTHROPIC_KEY, $ANTHROPIC_MODEL, $SYSTEM_PROMPT, $history) {
        return claudeReply($ANTHROPIC_KEY, $ANTHROPIC_MODEL, $SYSTEM_PROMPT, $history);
    }],
];

foreach ($providers as $p) {
    [$name, $key, $mdl, $call] = $p;
    if ($reply !== null || $key === '') {
        continue;
    }
    try {
        $reply = $call();
    } catch (Throwable $e) {
        $reply = null;
        $LAST_ERROR = $e->getMessage();
    }
    if ($reply !== null) {
        $source    = $name;
        $modelName = $mdl;
    } else {
        $errors[] = "{$name} gagal: " . ($LAST_ERROR ?: 'respons kosong');
    }
}
if ($reply === null) {
    $reply = ruleBasedReply($message);
}

$_SESSION['history'][] = ['role' => 'assistant', 'content' => $reply];

respond([
    'reply'  => $reply,
    'source' => $source,
    'model'  => $modelName,
    'errors' => $errors,
]);