<?php

declare(strict_types=1);

// Автозагрузка через Composer либо встроенный PSR-4 автозагрузчик
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    spl_autoload_register(function (string $class): void {
        $prefix = 'App\\';
        $baseDir = __DIR__ . '/src/';
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            return;
        }
        $relativeClass = substr($class, $len);
        $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    });
}

use App\Env;
use App\HttpClient;
use App\HttpException;

// Загрузка переменных окружения из .env
Env::load(__DIR__ . '/.env');

// Конфигурация API
$apiKey     = (string)(Env::get('AI_API_KEY') ?: Env::get('OPENAI_API_KEY') ?: '');
$baseUrl    = (string)(Env::get('API_BASE_URL', 'https://api.openai.com/v1'));
$model      = (string)(Env::get('AI_MODEL', 'gpt-6-luna'));
$proxy      = Env::get('HTTP_PROXY') ?: Env::get('HTTPS_PROXY');
$timeout    = (int)(Env::get('AI_TIMEOUT', 20));

// Инициализация HTTP-клиента
$httpClient = new HttpClient([
    'base_uri' => $baseUrl,
    'timeout'  => $timeout,
    'proxy'    => $proxy ? (string)$proxy : null,
    'headers'  => [
        'Authorization' => 'Bearer ' . $apiKey,
    ],
]);

/**
 * Очищает сообщение от активационных слов навыка.
 */
function cleanRequest(string $request): string
{
    $cutWords = ['Алиса', 'алиса'];
    foreach ($cutWords as $word) {
        if (mb_stripos($request, $word) === 0) {
            $request = mb_substr($request, mb_strlen($word));
        }
    }
    return trim($request);
}

/**
 * Очищает текст от markdown-символов для голосового движка TTS.
 */
function sanitizeForTts(string $text): string
{
    $clean = preg_replace('/[*#`_~>\[\]]/u', '', $text) ?? $text;
    $clean = preg_replace('/\s+/u', ' ', $clean) ?? $clean;
    $clean = trim($clean);

    return mb_strlen($clean) > 1000 ? mb_substr($clean, 0, 997) . '...' : $clean;
}

/**
 * Отправляет диалог в OpenAI / OpenRouter Chat Completion API.
 *
 * @param array<int, array{role: string, content: string}> $history
 */
function askAI(string $userMessage, array &$history, HttpClient $client, string $model): string
{
    $history[] = [
        'role'    => 'user',
        'content' => $userMessage,
    ];

    // Ограничиваем глубину контекста последними 10 сообщениями для контроля лимитов и скорости
    if (count($history) > 10) {
        $history = array_slice($history, -10);
    }

    try {
        $response = $client->post('/chat/completions', [
            'json' => [
                'model'    => $model,
                'messages' => $history,
            ],
        ]);

        if (!$response->isSuccessful()) {
            error_log(sprintf('AI API Error [%d]: %s', $response->getStatusCode(), $response->getBody()));
            return 'Сервер AI вернул ошибку. Пожалуйста, попробуйте позже.';
        }

        $body = $response->json();
        $reply = trim((string)($body['choices'][0]['message']['content'] ?? ''));

        if ($reply === '') {
            error_log('AI API returned empty reply: ' . $response->getBody());
            return 'Не удалось получить ответ от сервиса.';
        }

        $history[] = [
            'role'    => 'assistant',
            'content' => $reply,
        ];

        return $reply;
    } catch (HttpException $e) {
        error_log('HTTP/cURL exception in askAI: ' . $e->getMessage());
        return 'Ошибка сетевого соединения с AI-провайдером.';
    } catch (Throwable $e) {
        error_log('Unexpected error in askAI: ' . $e->getMessage());
        return 'Произошла непредвиденная ошибка при запросе к AI.';
    }
}

// Проверка HTTP-метода
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Method Not Allowed';
    exit;
}

$rawInput = file_get_contents('php://input');
$input = json_decode((string)$rawInput, true);

if (!is_array($input) || !isset($input['session'], $input['version'])) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Bad Request: invalid Yandex Dialogs payload';
    exit;
}

// Привязка сессии PHP к session_id Алисы (Яндекс не передает куки браузера)
$rawSessionId = (string)($input['session']['session_id'] ?? '');
if ($rawSessionId !== '') {
    $cleanSessionId = preg_replace('/[^a-zA-Z0-9,-]/', '', $rawSessionId);
    if (!empty($cleanSessionId)) {
        session_id($cleanSessionId);
    }
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// При старте новой сессии сбрасываем историю
if (!empty($input['session']['new']) || !isset($_SESSION['messages']) || !is_array($_SESSION['messages'])) {
    $_SESSION['messages'] = [];
}

$responsePayload = [
    'session'  => $input['session'],
    'version'  => $input['version'],
    'response' => [
        'end_session' => false,
    ],
];

$originalUtterance = (string)($input['request']['original_utterance'] ?? '');
$cleanedMessage = cleanRequest($originalUtterance);

if ($cleanedMessage !== '') {
    $botReply = askAI($cleanedMessage, $_SESSION['messages'], $httpClient, $model);

    // Ограничение длины текста для Алисы (максимум 1024 символа)
    $displayText = mb_strlen($botReply) > 1000 ? mb_substr($botReply, 0, 997) . '...' : $botReply;
    $ttsText = sanitizeForTts($botReply);

    $responsePayload['response']['text'] = $displayText;
    $responsePayload['response']['tts']  = $ttsText . ' <speaker audio="alice-sounds-things-door-2.opus">';
} else {
    // Приветствие при старте навыка или пустом запросе
    $welcomeText = 'Привет! Я умный ассистент на базе искусственного интеллекта. Задайте мне любой вопрос.';
    $responsePayload['response']['text'] = $welcomeText;
    $responsePayload['response']['tts']  = $welcomeText;
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($responsePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
