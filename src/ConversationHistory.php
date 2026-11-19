<?php

declare(strict_types=1);

namespace App;

final class ConversationHistory
{
    private const MAX_MESSAGES = 40;

    private function __construct(private string $path)
    {
    }

    public static function forSession(array $session, string $directory): ?self
    {
        $identities = [
            'account' => $session['user']['user_id'] ?? null,
            'application' => $session['application']['application_id'] ?? $session['user_id'] ?? null,
            'session' => $session['session_id'] ?? null,
        ];

        foreach ($identities as $type => $id) {
            if (is_string($id) && $id !== '') {
                $key = hash('sha256', serialize([$session['skill_id'] ?? '', $type, $id]));
                return new self($directory . DIRECTORY_SEPARATOR . $key . '.json');
            }
        }

        return null;
    }

    public function load(): array
    {
        if (!is_file($this->path)) {
            return [];
        }

        $json = file_get_contents($this->path);
        if ($json === false) {
            throw new \RuntimeException('Cannot read conversation history.');
        }

        return array_slice(json_decode($json, true, 512, JSON_THROW_ON_ERROR), -self::MAX_MESSAGES);
    }

    public function append(string $message, string $reply): void
    {
        $directory = dirname($this->path);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Cannot create conversation history directory.');
        }

        $lock = fopen($this->path . '.lock', 'c');
        if ($lock === false) {
            throw new \RuntimeException('Cannot open conversation history lock.');
        }

        try {
            if (!flock($lock, LOCK_EX)) {
                throw new \RuntimeException('Cannot lock conversation history.');
            }

            clearstatcache(true, $this->path);
            $messages = $this->load();
            $messages[] = ['role' => 'user', 'content' => $message];
            $messages[] = ['role' => 'assistant', 'content' => $reply];
            $json = json_encode(array_slice($messages, -self::MAX_MESSAGES), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

            $temporaryPath = tempnam($directory, 'history-');
            if ($temporaryPath === false) {
                throw new \RuntimeException('Cannot create temporary history file.');
            }

            try {
                if (file_put_contents($temporaryPath, $json) !== strlen($json) || !rename($temporaryPath, $this->path)) {
                    throw new \RuntimeException('Cannot save conversation history.');
                }
            } finally {
                if (is_file($temporaryPath)) {
                    unlink($temporaryPath);
                }
            }
        } finally {
            fclose($lock);
        }
    }
}
