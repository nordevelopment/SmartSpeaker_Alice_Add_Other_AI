#Умная колонка Алису подключаем другои ИИ (AI) /ChatGPT, Gemini, Grok, Deepseek, Qwen и другие

Вебхук для кастомного навыка Яндекс.Алисы, подключающий её к OpenAI API, OpenRouter или любому совместимому с Chat Completions API провайдеру на **чистом PHP (ext-curl)** без тяжелых внешних библиотек.

---

## ⚡ Особенности реализации

* **Чистый PHP 8.1+**: Весь HTTP-транспорт реализован на нативном расширении `curl` в едином файле `src/HttpClient.php` (`HttpClient`, `HttpResponse`, `HttpException`).
* **Zero-dependency**: Проект может запускаться как с Composer (PSR-4), так и полностью автономно благодаря встроенному fallback-автозагрузчику и нативному парсеру `.env`.
* **Поддержка любых провайдеров**: Легко переключается между OpenAI (`https://api.openai.com/v1`), OpenRouter (`https://openrouter.ai/api/v1`), локальными инференсами (Ollama, vLLM, LM Studio) через переменные окружения.
* **Корректная сессионность для Алисы**: Яндекс.Диалоги **не сохраняют cookie** (`PHPSESSID`). Сессия в проекте автоматически связывается с `session_id` из полезной нагрузки запроса Алисы.
* **Защита от лимитов Яндекс.Диалогов**:
  * Автоматическая обрезка ответов до 1000 символов (лимит Алисы — 1024 символа).
  * Очистка Markdown-разметки (`*`, `#`, `` ` ``) для голосового движка TTS, чтобы синтезатор речи не зачитывал спецсимволы вслух.
  * Ограничение глубины контекста (последние 10 реплик) для предотвращения таймаутов (Яндекс ждет ответ до 2.8–3 сек).
* **Поддержка прокси**: Нативная поддержка `HTTP_PROXY` / `HTTPS_PROXY` для работы в сетевых окружениях с ограничениями.

---

## 📁 Структура проекта

```text
├── src/
│   ├── HttpClient.php    # HTTP-клиент, HttpResponse и HttpException в одном файле (cURL)
│   └── Env.php           # Легковесный парсер .env без сторонних зависимостей
├── .env                  # Локальные переменные окружения (в gitignore)
├── .env.example          # Шаблон конфигурации
├── .gitignore
├── composer.json         # PSR-4 автозагрузка (Guzzle удален)
├── index.php             # Точка входа вебхука Яндекс.Диалогов
└── README.md
```

---

## 📋 Требования

* **PHP 8.1+**
* Расширения PHP: `curl`, `json`, `mbstring`, `session`
* Публичный HTTPS-URL (требование платформы Яндекс.Диалоги)

---

## 🚀 Быстрый старт

### 1. Клонирование и настройка окружения

Создайте файл `.env` на основе примера:

```bash
cp .env.example .env
```

Отредактируйте `.env`:

```ini
# Ключ авторизации (OpenAI или OpenRouter)
AI_API_KEY=sk-or-v1-...

# Эндпоинт API
# Для OpenRouter:
API_BASE_URL=https://openrouter.ai/api/v1
# Для OpenAI:
# API_BASE_URL=https://api.openai.com/v1

# Модель нейросети
AI_MODEL=openai/gpt-6-luna

# Таймаут запроса в секундах
AI_TIMEOUT=20

# Прокси (опционально, при необходимости)
# HTTP_PROXY=http://127.0.0.1:10809
```

### 2. Запуск

Проект работает «из коробки» без обязательного вызова `composer install`:

```bash
php -S 0.0.0.0:8000 index.php
```

*(Опционально) Если вы предпочитаете автозагрузчик Composer:*
```bash
composer dump-autoload
```

### 3. Проброс туннеля для тестирования

Яндекс.Диалогам требуется публичный HTTPS-эндпоинт. Для локального тестирования используйте любой туннель:

```bash
# Через ngrok:
ngrok http 8000

# Либо через cloudflared:
cloudflared tunnel --url http://localhost:8000
```

Скопируйте выданный адрес (например: `https://xxxx.ngrok-free.app/index.php`).

---

## 🛠️ Настройка в консоли Яндекс.Диалогов

1. Откройте [Консоль разработчика Яндекс.Диалогов](https://dialogs.yandex.ru/developer).
2. Нажмите **«Создать диалог»** → **«Навык в Алисе»**.
3. В поле **Backend** выберите **Webhook URL** и укажите ваш HTTPS-адрес:
   `https://your-domain.com/index.php` (или URL туннеля).
4. Задайте имя навыка и активационные фразы.
5. Во вкладке **«Тестирование»** проверьте работу навыка:
   * На стартовую реплику бот отправит приветствие.
   * На любой вопрос бот вернет ответ от настроенной языковой модели с сохранением истории диалога.
6. Опубликуйте навык или используйте его в режиме приватного тестирования под вашим Яндекс ID на своих устройствах.

---

## 🔌 Архитектура класса HttpClient

Класс `App\HttpClient` инкапсулирует работу с нативным `ext-curl`:

```php
use App\HttpClient;

$client = new HttpClient([
    'base_uri' => 'https://openrouter.ai/api/v1',
    'timeout'  => 15,
    'headers'  => [
        'Authorization' => 'Bearer ' . $apiKey,
    ],
]);

// POST-запрос с автоматической сериализацией JSON
$response = $client->post('/chat/completions', [
    'json' => [
        'model'    => 'openai/gpt-6-luna',
        'messages' => [
            ['role' => 'user', 'content' => 'Привет!']
        ],
    ],
]);

if ($response->isSuccessful()) {
    $data = $response->json(); // декодированный JSON
    $reply = $data['choices'][0]['message']['content'];
}
```

### Возможности `HttpClient`:
* Методы: `get()`, `post()`, `put()`, `patch()`, `delete()`, `request()`.
* Опция `'json'` автоматически проставляет заголовок `Content-Type: application/json` и кодирует payload.
* Автоматическая сборка Query-параметров (`'query' => [...]`).
* Корректный разбор заголовков ответа даже при цепочке редиректов (`CURLOPT_FOLLOWLOCATION`).
* Объект ответа `HttpResponse` предоставляет методы: `getStatusCode()`, `getBody()`, `getHeaders()`, `getHeader()`, `json()`, `isSuccessful()`, `isClientError()`, `isServerError()`.
* Все транспортные сбои выбрасывают типизированное исключение `HttpException` с сохранением кода ошибки cURL (`curl_errno`).

---

## 📄 Лицензия

MIT License. Свободно для модификации и коммерческого использования.
