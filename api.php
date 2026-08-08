<?php
/**
 * Chat endpoint — Groq primary, OpenRouter fallback.
 * Run: php -S localhost:8000 api.php
 *
 * GET  /debug      → diagnostics
 * POST /           → chat completion
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(200); exit; }

$uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// ===================== helpers =====================
function jsonOut(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}
function fail(int $code, string $message, array $debug = []): void {
    jsonOut(array_filter(['message' => $message, 'debug' => $debug ?: null], fn($v) => $v !== null), $code);
}
function readEnvKey(string $name): ?string {
    $envFile = __DIR__ . '/.env';
    if (!file_exists($envFile)) return null;
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with($line, $name . '=')) {
            $k = trim(substr($line, strlen($name . '=')));
            return $k !== '' ? $k : null;
        }
    }
    return null;
}

/**
 * OpenAI-compatible POST. Used for Groq + OpenRouter (both are OpenAI-compatible).
 */
function openaiChat(string $url, string $apiKey, array $payload, string $providerLabel): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);

    // OpenRouter wants these extra headers; Groq ignores them
    if ($providerLabel === 'OpenRouter') {
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
            'HTTP-Referer: http://localhost:8000',
            'X-Title: Upwork Portfolio Chat Widget',
        ]);
    }

    $resp  = curl_exec($ch);
    $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err   = curl_error($ch);
    curl_close($ch);

    return [
        'ok'       => $resp !== false,
        'code'     => $code,
        'error'    => $err,
        'body'     => $resp,
        'provider' => $providerLabel,
    ];
}

function pickGroqModel(): string {
    return 'llama-3.3-70b-versatile';  // best free Groq model
}

function pickOpenRouterModel(string $apiKey): string {
    // Try to discover a free model; fallback to a small paid-but-cheap one
    $ch = curl_init('https://openrouter.ai/api/v1/models');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiKey],
        CURLOPT_TIMEOUT => 6,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    if (!$body) return 'meta-llama/llama-3.1-8b-instruct';

    $data = json_decode($body, true);
    if (!isset($data['data'])) return 'meta-llama/llama-3.1-8b-instruct';

    foreach ($data['data'] as $m) {
        $id = $m['id'] ?? '';
        if ($id === 'meta-llama/llama-3.1-8b-instruct:free') return $id;
    }
    return 'meta-llama/llama-3.1-8b-instruct';
}

// ===================== /debug =====================
if ($method === 'GET' && $uri === '/debug') {
    $groqKey   = readEnvKey('GROQ_API_KEY');
    $openKey   = readEnvKey('OPENROUTER_API_KEY');
    $groqStatus = null; $groqError = null;
    if ($groqKey) {
        $r = openaiChat(
            'https://api.groq.com/openai/v1/chat/completions',
            $groqKey,
            ['model' => pickGroqModel(), 'messages' => [['role' => 'user', 'content' => 'ping']], 'max_tokens' => 1],
            'Groq'
        );
        $groqStatus = $r['code'];
        $groqError  = $r['error'] ?: null;
    }
    jsonOut([
        'php_version'      => PHP_VERSION,
        'curl_loaded'      => extension_loaded('curl'),
        'groq_key_present' => (bool) $groqKey,
        'groq_key_preview' => $groqKey ? (substr($groqKey, 0, 8) . '…' . substr($groqKey, -6)) : null,
        'groq_ping_status' => $groqStatus,
        'groq_ping_error'  => $groqError,
        'openrouter_key_present' => (bool) $openKey,
        'active_provider'  => $groqKey ? 'Groq (primary)' : ($openKey ? 'OpenRouter (fallback)' : 'NONE'),
    ]);
}

// ===================== POST / =====================
if ($method !== 'POST') fail(405, 'Use POST.');

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) fail(400, 'Invalid JSON.');

$messages     = $input['messages'] ?? [];
$systemPrompt = $input['system_prompt'] ?? 'You are a helpful assistant.';
if (!is_array($messages) || count($messages) === 0) fail(400, 'messages array required.');

$payloadBase = [
    'messages' => array_merge([['role' => 'system', 'content' => $systemPrompt]], $messages),
    'temperature' => 0.7,
    'max_tokens'  => 500,
];

// ---- Try Groq first ----
$groqKey = readEnvKey('GROQ_API_KEY');
if ($groqKey) {
    $payload = array_merge($payloadBase, ['model' => pickGroqModel()]);
    $r = openaiChat('https://api.groq.com/openai/v1/chat/completions', $groqKey, $payload, 'Groq');
    if ($r['ok'] && $r['code'] < 400) {
        $data = json_decode((string) $r['body'], true);
        $reply = $data['choices'][0]['message']['content'] ?? null;
        if ($reply) jsonOut(['message' => $reply, 'provider' => 'Groq', 'model' => pickGroqModel()]);
    }
    // If failed, fall through to OpenRouter
    $groqError = ['code' => $r['code'], 'error' => $r['error'], 'body' => substr((string) $r['body'], 0, 300)];
}

// ---- Fallback: OpenRouter ----
$openKey = readEnvKey('OPENROUTER_API_KEY');
if ($openKey) {
    $model   = pickOpenRouterModel($openKey);
    $payload = array_merge($payloadBase, ['model' => $model]);
    $r = openaiChat('https://openrouter.ai/api/v1/chat/completions', $openKey, $payload, 'OpenRouter');
    if (!$r['ok']) {
        fail(502, 'Network error (OpenRouter).', ['curl_error' => $r['error']]);
    }
    if ($r['code'] >= 400) {
        fail(502, 'All providers failed.', [
            'groq_error'    => $groqError ?? null,
            'openrouter'    => ['status' => $r['code'], 'model' => $model, 'body' => substr((string) $r['body'], 0, 400)],
        ]);
    }
    $data = json_decode((string) $r['body'], true);
    $reply = $data['choices'][0]['message']['content'] ?? null;
    if ($reply) jsonOut(['message' => $reply, 'provider' => 'OpenRouter', 'model' => $model]);
}

fail(503, 'No working LLM provider. Add GROQ_API_KEY (or OPENROUTER_API_KEY with credits) to .env.', [
    'groq_error' => $groqError ?? null,
]);