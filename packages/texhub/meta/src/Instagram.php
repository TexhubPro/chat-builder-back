<?php

namespace TexHub\Meta;

use Illuminate\Support\Facades\Http;
use TexHub\Meta\Models\InstagramChat;
use TexHub\Meta\Models\InstagramMessage;
use TexHub\Meta\Models\MetaLog;
use Throwable;

class Instagram
{
    public function __construct(private array $config = [])
    {
    }

    public function config(): array
    {
        return $this->config;
    }

    /**
     * Send a raw message payload.
     */
    public function sendMessage(
        string $igUserId,
        string $recipientId,
        array $message,
        ?string $accessToken = null,
        bool $store = true
    ): ?string {
        if ($igUserId === '' || $recipientId === '') {
            $this->logError('Instagram send message failed', [
                'error' => 'Missing ig user id or recipient id.',
                'ig_user_id' => $igUserId,
                'recipient_id' => $recipientId,
            ]);

            return null;
        }

        $token = $accessToken ?: ($this->config['access_token'] ?? null);
        if (! is_string($token) || $token === '') {
            $this->logError('Instagram send message failed', [
                'error' => 'Missing access token.',
            ]);

            return null;
        }

        $payload = [
            'recipient' => [
                'id' => $recipientId,
            ],
            'message' => $message,
        ];

        try {
            $response = Http::withToken($token)
                ->post($this->endpoint($igUserId), $payload);

            if (! $response->successful()) {
                $this->logError('Instagram send message failed', [
                    'status' => $response->status(),
                    'body' => $response->json(),
                    'payload' => $payload,
                ]);

                return null;
            }

            $messageId = $response->json('message_id');

            if ($store) {
                $this->storeOutgoingMessage($igUserId, $recipientId, $message, $payload);
            }

            return is_string($messageId) ? $messageId : null;
        } catch (Throwable $exception) {
            $this->logError('Instagram send message failed', [
                'error' => $exception->getMessage(),
                'payload' => $payload,
            ]);

            return null;
        }
    }

    /**
     * Send text message.
     */
    public function sendTextMessage(
        string $igUserId,
        string $recipientId,
        string $text,
        ?string $accessToken = null,
        bool $store = true
    ): ?string {
        return $this->sendMessage($igUserId, $recipientId, [
            'text' => $text,
        ], $accessToken, $store);
    }

    /**
     * Send media message (image, video, audio, file) by URL.
     */
    public function sendMediaMessage(
        string $igUserId,
        string $recipientId,
        string $type,
        string $url,
        bool $isReusable = false,
        ?string $accessToken = null,
        bool $store = true
    ): ?string {
        return $this->sendMessage($igUserId, $recipientId, [
            'attachment' => [
                'type' => $type,
                'payload' => [
                    'url' => $url,
                    'is_reusable' => $isReusable,
                ],
            ],
        ], $accessToken, $store);
    }

    /**
     * Send quick replies.
     */
    public function sendQuickRepliesMessage(
        string $igUserId,
        string $recipientId,
        string $text,
        array $quickReplies,
        ?string $accessToken = null,
        bool $store = true
    ): ?string {
        return $this->sendMessage($igUserId, $recipientId, [
            'text' => $text,
            'quick_replies' => $quickReplies,
        ], $accessToken, $store);
    }

    /**
     * Alias for quick replies (buttons).
     */
    public function sendButtonsMessage(
        string $igUserId,
        string $recipientId,
        string $text,
        array $buttons,
        ?string $accessToken = null,
        bool $store = true
    ): ?string {
        return $this->sendQuickRepliesMessage($igUserId, $recipientId, $text, $buttons, $accessToken, $store);
    }

    /**
     * Send a generic template message (supports buttons).
     */
    public function sendTemplateMessage(
        string $igUserId,
        string $recipientId,
        array $elements,
        ?string $accessToken = null,
        bool $store = true
    ): ?string {
        return $this->sendMessage($igUserId, $recipientId, [
            'attachment' => [
                'type' => 'template',
                'payload' => [
                    'template_type' => 'generic',
                    'elements' => $elements,
                ],
            ],
        ], $accessToken, $store);
    }

    private function endpoint(string $igUserId): string
    {
        $base = rtrim((string) ($this->config['graph_base'] ?? 'https://graph.instagram.com'), '/');
        $version = trim((string) ($this->config['api_version'] ?? 'v23.0'));
        $version = $version !== '' ? $version : 'v23.0';

        return $base . '/' . $version . '/' . $igUserId . '/messages';
    }

    private function storeOutgoingMessage(string $igUserId, string $recipientId, array $message, array $payload): void
    {
        try {
            $chat = InstagramChat::firstOrCreate(
                [
                    'user_id' => 0,
                    'instagram_user_id' => $recipientId,
                    'receiver_id' => $igUserId,
                ],
                [
                    'last_message_at' => now(),
                ]
            );

            $chat->update([
                'last_message_at' => now(),
            ]);

            $text = $message['text'] ?? null;
            $attachment = $message['attachment'] ?? null;
            $attachmentType = is_array($attachment) ? ($attachment['type'] ?? null) : null;
            $attachmentUrl = is_array($attachment) && isset($attachment['payload']['url']) ? $attachment['payload']['url'] : null;

            InstagramMessage::create([
                'chat_id' => $chat->id,
                'direction' => 'out',
                'sender_id' => $igUserId,
                'recipient_id' => $recipientId,
                'message_type' => $text ? 'text' : (is_string($attachmentType) ? $attachmentType : 'message'),
                'text' => is_string($text) ? $text : null,
                'media_url' => is_string($attachmentUrl) ? $attachmentUrl : null,
                'media_type' => is_string($attachmentType) ? $attachmentType : null,
                'payload' => $payload,
                'sent_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $this->logError('Instagram store outgoing message failed', [
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function logError(string $title, array $data): void
    {
        try {
            MetaLog::create([
                'title' => $title,
                'content' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        } catch (Throwable $exception) {
            // ignore log errors
        }
    }
}
