<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class TelegramBotApiService
{
    public function getMe(string $botToken): array
    {
        $payload = $this->request($botToken, 'getMe');
        $result = $payload['result'] ?? null;

        if (! is_array($result)) {
            throw new \RuntimeException('Telegram getMe response is invalid.');
        }

        return $result;
    }

    public function setWebhook(
        string $botToken,
        string $webhookUrl,
        string $secretToken,
        array $allowedUpdates = ['message', 'edited_message'],
    ): array {
        if (trim($webhookUrl) === '') {
            throw new \RuntimeException('Telegram webhook URL is missing.');
        }

        $payload = $this->request($botToken, 'setWebhook', [
            'url' => $webhookUrl,
            'secret_token' => $secretToken,
            'allowed_updates' => array_values(array_filter(
                $allowedUpdates,
                static fn (mixed $value): bool => is_string($value) && trim($value) !== ''
            )),
            'drop_pending_updates' => false,
        ]);

        return $payload;
    }

    public function getFile(string $botToken, string $fileId): array
    {
        $normalizedFileId = trim($fileId);

        if ($normalizedFileId === '') {
            throw new \RuntimeException('Telegram file id is missing.');
        }

        $payload = $this->request($botToken, 'getFile', [
            'file_id' => $normalizedFileId,
        ]);
        $result = $payload['result'] ?? null;

        if (! is_array($result)) {
            throw new \RuntimeException('Telegram getFile response is invalid.');
        }

        return $result;
    }

    public function deleteWebhook(
        string $botToken,
        bool $dropPendingUpdates = false,
    ): array {
        return $this->request($botToken, 'deleteWebhook', [
            'drop_pending_updates' => $dropPendingUpdates,
        ]);
    }

    public function sendTextMessage(
        string $botToken,
        string|int $chatId,
        string $text,
    ): ?string {
        $normalizedText = trim($text);

        if ($normalizedText === '') {
            return null;
        }

        $payload = $this->request($botToken, 'sendMessage', [
            'chat_id' => (string) $chatId,
            'text' => $normalizedText,
            'disable_web_page_preview' => false,
        ]);

        return $this->extractMessageId($payload['result'] ?? null);
    }

    public function sendMediaMessage(
        string $botToken,
        string|int $chatId,
        string $method,
        string $mediaUrl,
        ?string $caption = null,
    ): ?string {
        $normalizedMediaUrl = trim($mediaUrl);

        if ($normalizedMediaUrl === '') {
            return null;
        }

        $normalizedMethod = trim($method);
        $mediaField = match ($normalizedMethod) {
            'sendPhoto' => 'photo',
            'sendVideo' => 'video',
            'sendVoice' => 'voice',
            'sendAudio' => 'audio',
            'sendDocument' => 'document',
            default => null,
        };

        if ($mediaField === null) {
            throw new \RuntimeException('Unsupported Telegram media method: '.$normalizedMethod);
        }

        $requestPayload = [
            'chat_id' => (string) $chatId,
            $mediaField => $normalizedMediaUrl,
        ];

        $normalizedCaption = trim((string) ($caption ?? ''));
        if ($normalizedCaption !== '') {
            $requestPayload['caption'] = $normalizedCaption;
        }

        $payload = $this->request($botToken, $normalizedMethod, $requestPayload);

        return $this->extractMessageId($payload['result'] ?? null);
    }

    public function resolveDownloadUrl(string $botToken, string $filePath): string
    {
        $base = rtrim((string) config('services.telegram.bot_api_base', 'https://api.telegram.org'), '/');

        return $base.'/file/bot'.$botToken.'/'.ltrim($filePath, '/');
    }

    private function request(string $botToken, string $method, ?array $payload = null): array
    {
        $token = trim($botToken);
        if ($token === '') {
            throw new \RuntimeException('Telegram bot token is missing.');
        }

        $url = $this->endpoint($token, $method);

        $response = $payload === null
            ? Http::timeout(20)->get($url)
            : Http::timeout(20)->asJson()->post($url, $payload);

        return $this->decodeResponse($response, $method);
    }

    private function decodeResponse(Response $response, string $method): array
    {
        $payload = $response->json();

        if (! is_array($payload)) {
            throw new \RuntimeException("Telegram {$method} returned invalid response.");
        }

        if (! $response->successful()) {
            $description = trim((string) ($payload['description'] ?? ''));
            throw new \RuntimeException($description !== ''
                ? $description
                : "Telegram {$method} request failed.");
        }

        if (($payload['ok'] ?? null) !== true) {
            $description = trim((string) ($payload['description'] ?? ''));
            throw new \RuntimeException($description !== ''
                ? $description
                : "Telegram {$method} request was rejected.");
        }

        return $payload;
    }

    private function endpoint(string $botToken, string $method): string
    {
        $base = rtrim((string) config('services.telegram.bot_api_base', 'https://api.telegram.org'), '/');
        $normalizedMethod = trim($method);

        return $base.'/bot'.$botToken.'/'.$normalizedMethod;
    }

    private function extractMessageId(mixed $result): ?string
    {
        if (! is_array($result)) {
            return null;
        }

        $messageId = $result['message_id'] ?? null;

        if (! is_scalar($messageId)) {
            return null;
        }

        $normalized = trim((string) $messageId);

        return $normalized !== '' ? $normalized : null;
    }
}
