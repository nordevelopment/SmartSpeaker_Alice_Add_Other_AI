<?php

declare(strict_types=1);

namespace App;

use JsonException;
use RuntimeException;

/**
 * Исключение при ошибках cURL или сетевого транспорта.
 */
class HttpException extends RuntimeException
{
}

/**
 * Объект ответа HTTP.
 */
class HttpResponse
{
    /**
     * @param int $statusCode HTTP-код ответа
     * @param array<string, string> $headers Заголовки (ключи в нижнем регистре)
     * @param string $body Тело ответа
     */
    public function __construct(
        private readonly int $statusCode,
        private readonly array $headers,
        private readonly string $body
    ) {}

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getHeader(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * Декодирует JSON-тело ответа.
     *
     * @param bool $assoc Возвращать ассоциативный массив
     * @throws JsonException При ошибке парсинга JSON
     */
    public function json(bool $assoc = true): mixed
    {
        return json_decode($this->body, $assoc, 512, JSON_THROW_ON_ERROR);
    }

    public function isSuccessful(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    public function isClientError(): bool
    {
        return $this->statusCode >= 400 && $this->statusCode < 500;
    }

    public function isServerError(): bool
    {
        return $this->statusCode >= 500 && $this->statusCode < 600;
    }

    public function __toString(): string
    {
        return $this->body;
    }
}

/**
 * HTTP-клиент на чистом PHP (ext-curl) без сторонних библиотек.
 */
class HttpClient
{
    private string $baseUrl;
    private int $timeout;
    private int $connectTimeout;
    private array $defaultHeaders;
    private ?string $proxy;
    private bool $verifySsl;

    /**
     * @param array{
     *     base_uri?: string,
     *     base_url?: string,
     *     timeout?: int,
     *     connect_timeout?: int,
     *     headers?: array<string, string>,
     *     proxy?: ?string,
     *     verify_ssl?: bool
     * } $config
     */
    public function __construct(array $config = [])
    {
        $rawBaseUrl = (string)($config['base_uri'] ?? $config['base_url'] ?? '');
        $this->baseUrl = rtrim($rawBaseUrl, '/');
        $this->timeout = (int)($config['timeout'] ?? 20);
        $this->connectTimeout = (int)($config['connect_timeout'] ?? 5);
        $this->defaultHeaders = (array)($config['headers'] ?? []);
        $this->proxy = !empty($config['proxy']) ? (string)$config['proxy'] : null;
        $this->verifySsl = (bool)($config['verify_ssl'] ?? true);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function get(string $url, array $options = []): HttpResponse
    {
        return $this->request('GET', $url, $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function post(string $url, array $options = []): HttpResponse
    {
        return $this->request('POST', $url, $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function put(string $url, array $options = []): HttpResponse
    {
        return $this->request('PUT', $url, $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function patch(string $url, array $options = []): HttpResponse
    {
        return $this->request('PATCH', $url, $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function delete(string $url, array $options = []): HttpResponse
    {
        return $this->request('DELETE', $url, $options);
    }

    /**
     * Выполняет HTTP-запрос через cURL.
     *
     * Поддерживаемые опции:
     * - 'headers' (array<string, string>): Дополнительные заголовки
     * - 'json' (mixed): Данные для сериализации в JSON
     * - 'body' (string): Сырое тело запроса
     * - 'query' (array<string, mixed>): Query-параметры в URL
     * - 'timeout' (int): Таймаут ожидания запроса (в сек)
     * - 'connect_timeout' (int): Таймаут соединения (в сек)
     *
     * @param array<string, mixed> $options
     * @throws HttpException При ошибке cURL или сетевого сбоя
     * @throws JsonException При ошибке кодирования JSON
     */
    public function request(string $method, string $url, array $options = []): HttpResponse
    {
        $normalizedMethod = strtoupper($method);
        $fullUrl = $this->buildFullUrl($url, $options['query'] ?? null);

        $ch = curl_init();
        if ($ch === false) {
            throw new HttpException('Не удалось инициализировать дескриптор cURL.');
        }

        try {
            $headers = array_merge($this->defaultHeaders, $options['headers'] ?? []);

            $curlOptions = [
                CURLOPT_URL            => $fullUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER         => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_CONNECTTIMEOUT => (int)($options['connect_timeout'] ?? $this->connectTimeout),
                CURLOPT_TIMEOUT        => (int)($options['timeout'] ?? $this->timeout),
                CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
                CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
                CURLOPT_CUSTOMREQUEST  => $normalizedMethod,
            ];

            if ($this->proxy !== null) {
                $curlOptions[CURLOPT_PROXY] = $this->proxy;
            }

            if (array_key_exists('json', $options)) {
                $jsonPayload = json_encode(
                    $options['json'],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                );
                $headers['Content-Type'] = 'application/json';
                $curlOptions[CURLOPT_POSTFIELDS] = $jsonPayload;
            } elseif (array_key_exists('body', $options)) {
                $curlOptions[CURLOPT_POSTFIELDS] = (string)$options['body'];
            }

            // Формируем список заголовков для cURL
            $formattedHeaders = [];
            foreach ($headers as $headerName => $headerValue) {
                if (is_int($headerName)) {
                    $formattedHeaders[] = (string)$headerValue;
                } else {
                    $formattedHeaders[] = "{$headerName}: {$headerValue}";
                }
            }
            $curlOptions[CURLOPT_HTTPHEADER] = $formattedHeaders;

            curl_setopt_array($ch, $curlOptions);

            $rawResult = curl_exec($ch);

            if ($rawResult === false) {
                $errno = curl_errno($ch);
                $error = curl_error($ch);
                throw new HttpException(
                    sprintf('Ошибка cURL [%d] при запросе %s %s: %s', $errno, $normalizedMethod, $fullUrl, $error),
                    $errno
                );
            }

            $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);

            $rawHeaderStr = substr((string)$rawResult, 0, $headerSize);
            $body = substr((string)$rawResult, $headerSize);

            $parsedHeaders = $this->parseHeaders($rawHeaderStr);

            return new HttpResponse($statusCode, $parsedHeaders, $body);
        } finally {
            curl_close($ch);
        }
    }

    private function buildFullUrl(string $url, ?array $query): string
    {
        if (preg_match('#^https?://#i', $url)) {
            $fullUrl = $url;
        } else {
            $path = ltrim($url, '/');
            $fullUrl = $this->baseUrl !== '' ? "{$this->baseUrl}/{$path}" : $url;
        }

        if (!empty($query)) {
            $separator = str_contains($fullUrl, '?') ? '&' : '?';
            $fullUrl .= $separator . http_build_query($query);
        }

        return $fullUrl;
    }

    /**
     * Разбирает заголовки ответа. Корректно обрабатывает редиректы,
     * возвращая заголовки последнего (целевого) HTTP-ответа.
     *
     * @return array<string, string>
     */
    private function parseHeaders(string $rawHeaders): array
    {
        $headers = [];
        $blocks = preg_split('/(\r?\n){2}/', trim($rawHeaders));
        if ($blocks === false || empty($blocks)) {
            return $headers;
        }

        $lastBlock = end($blocks);
        $lines = preg_split('/\r?\n/', $lastBlock);
        if ($lines === false) {
            return $headers;
        }

        foreach ($lines as $line) {
            if (str_contains($line, ':')) {
                [$key, $val] = explode(':', $line, 2);
                $headers[strtolower(trim($key))] = trim($val);
            }
        }

        return $headers;
    }
}
