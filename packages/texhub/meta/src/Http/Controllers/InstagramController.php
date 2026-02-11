<?php

namespace TexHub\Meta\Http\Controllers;

use App\Services\InstagramMainWebhookService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use TexHub\Meta\Models\InstagramIntegration;
use TexHub\Meta\Models\InstagramChat;
use TexHub\Meta\Models\InstagramMessage;
use TexHub\Meta\Models\MetaLog;
use Throwable;

class InstagramController extends Controller
{
    public function verifyPage()
    {
        return view('texhub-meta::instagram.verify', [
            'verify_token' => config('meta.instagram.verify_token'),
        ]);
    }

    public function webhook(Request $request)
    {
        if ($request->isMethod('get')) {
            $mode = $request->query('hub_mode', $request->query('hub.mode'));
            $token = $request->query('hub_verify_token', $request->query('hub.verify_token'));
            $challenge = $request->query('hub_challenge', $request->query('hub.challenge'));

            if ($mode === 'subscribe' && $token && hash_equals((string) config('meta.instagram.verify_token'), (string) $token)) {
                return response((string) $challenge, 200);
            }

            return response('Invalid verify token', 403);
        }

        $this->storeWebhookPayload($request->all());

        try {
            $this->handleWebhook($request->all());
        } catch (Throwable $exception) {
            $this->logError('Instagram webhook handle failed', [
                'error' => $exception->getMessage(),
            ]);
        }

        return response()->json(['ok' => true]);
    }

    public function callback(Request $request)
    {
        $user = Auth::user();

        if (! $user) {
            return redirect()->route('login');
        }

        $code = $request->query('code');

        if (! is_string($code) || $code === '') {
            $this->logError('Instagram callback failed', [
                'error' => 'Missing code.',
                'query' => $request->query(),
            ]);

            return redirect()->route('dashboard');
        }

        $tokenResponse = $this->exchangeCodeForToken($code);

        if ($tokenResponse === null) {
            return redirect()->route('dashboard');
        }

        $accessToken = $tokenResponse['access_token'] ?? null;
        $expiresIn = $tokenResponse['expires_in'] ?? null;
        $instagramUserId = $tokenResponse['user_id'] ?? null;

        if (! is_string($accessToken) || $accessToken === '') {
            $this->logError('Instagram callback failed', [
                'error' => 'Missing access token.',
                'body' => $tokenResponse,
            ]);

            return redirect()->route('dashboard');
        }

        $longLived = $this->exchangeForLongLivedToken($accessToken);

        if ($longLived !== null) {
            $accessToken = $longLived['access_token'] ?? $accessToken;
            $expiresIn = $longLived['expires_in'] ?? $expiresIn;
        }

        $profile = $this->fetchProfile($accessToken);

        $username = is_array($profile) ? ($profile['username'] ?? null) : null;
        $profilePicture = is_array($profile) ? ($profile['profile_picture_url'] ?? null) : null;
        $receiverId = is_array($profile) ? ($profile['id'] ?? null) : null;

        $expiresAt = null;
        if (is_numeric($expiresIn)) {
            $expiresAt = now()->addSeconds((int) $expiresIn);
        }

        InstagramIntegration::updateOrCreate(
            [
                'user_id' => $user->id,
                'instagram_user_id' => (string) ($instagramUserId ?? $receiverId ?? ''),
            ],
            [
                'username' => $username,
                'receiver_id' => $receiverId ? (string) $receiverId : null,
                'access_token' => $accessToken,
                'token_expires_at' => $expiresAt,
                'profile_picture_url' => $profilePicture,
                'avatar_path' => null,
                'is_active' => true,
            ]
        );

        return redirect()->route('dashboard');
    }

    private function exchangeCodeForToken(string $code): ?array
    {
        $appId = config('meta.instagram.app_id');
        $appSecret = config('meta.instagram.app_secret');

        if (! $appId || ! $appSecret) {
            $this->logError('Instagram token exchange failed', [
                'error' => 'Missing app credentials.',
            ]);

            return null;
        }

        $redirectUri = $this->redirectUri();

        try {
            $response = Http::asForm()->post('https://api.instagram.com/oauth/access_token', [
                'client_id' => $appId,
                'client_secret' => $appSecret,
                'grant_type' => 'authorization_code',
                'redirect_uri' => $redirectUri,
                'code' => $code,
            ]);

            if (! $response->successful()) {
                $this->logError('Instagram token exchange failed', [
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);

                return null;
            }

            $data = $response->json();

            return is_array($data) ? $data : null;
        } catch (Throwable $exception) {
            $this->logError('Instagram token exchange failed', [
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function fetchProfile(string $accessToken): ?array
    {
        $graphBase = rtrim((string) config('meta.instagram.graph_base', 'https://graph.instagram.com'), '/');

        try {
            $response = Http::get($graphBase . '/me', [
                'fields' => 'id,username,profile_picture_url',
                'access_token' => $accessToken,
            ]);

            if (! $response->successful()) {
                $this->logError('Instagram profile fetch failed', [
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);

                return null;
            }

            $data = $response->json();

            return is_array($data) ? $data : null;
        } catch (Throwable $exception) {
            $this->logError('Instagram profile fetch failed', [
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function exchangeForLongLivedToken(string $accessToken): ?array
    {
        $graphBase = rtrim((string) config('meta.instagram.graph_base', 'https://graph.instagram.com'), '/');

        try {
            $response = Http::get($graphBase . '/access_token', [
                'grant_type' => 'ig_exchange_token',
                'access_token' => $accessToken,
            ]);

            if (! $response->successful()) {
                $this->logError('Instagram long-lived token exchange failed', [
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);

                return null;
            }

            $data = $response->json();

            return is_array($data) ? $data : null;
        } catch (Throwable $exception) {
            $this->logError('Instagram long-lived token exchange failed', [
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function redirectUri(): string
    {
        $path = (string) config('meta.instagram.redirect_path', '/callback');
        $path = '/' . ltrim($path, '/');

        return request()->getSchemeAndHttpHost() . $path;
    }

    private function handleWebhook(array $payload): void
    {
        if (empty($payload['entry']) || ! is_array($payload['entry'])) {
            return;
        }

        $mainWebhookService = app(InstagramMainWebhookService::class);

        foreach ($payload['entry'] as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $messagingEvents = $this->extractMessagingEvents($entry);
            if (! is_array($messagingEvents)) {
                continue;
            }

            foreach ($messagingEvents as $event) {
                if (! is_array($event)) {
                    continue;
                }

                $senderId = $event['sender']['id'] ?? null;
                $recipientId = $event['recipient']['id'] ?? null;

                if (! is_string($senderId) || $senderId === '' || ! is_string($recipientId) || $recipientId === '') {
                    continue;
                }

                $chat = InstagramChat::firstOrCreate(
                    [
                        'user_id' => 0,
                        'instagram_integration_id' => null,
                        'instagram_user_id' => $senderId,
                        'receiver_id' => $recipientId,
                    ],
                    [
                        'last_message_at' => now(),
                    ]
                );

                $chat->update([
                    'last_message_at' => now(),
                ]);

                $timestamp = $event['timestamp'] ?? null;
                $sentAt = is_numeric($timestamp) ? now()->setTimestamp((int) ($timestamp / 1000)) : null;

                $this->storeEventMessage($chat->id, $senderId, $recipientId, $event, $sentAt);

                try {
                    $mainWebhookService->processEvent($event);
                } catch (Throwable $exception) {
                    $this->logError('Instagram main webhook bridge failed', [
                        'error' => $exception->getMessage(),
                    ]);
                }
            }
        }
    }

    private function extractMessagingEvents(array $entry): array
    {
        if (! empty($entry['messaging']) && is_array($entry['messaging'])) {
            return $entry['messaging'];
        }

        $events = [];
        $changes = $entry['changes'] ?? [];

        if (! is_array($changes)) {
            return $events;
        }

        foreach ($changes as $change) {
            if (! is_array($change)) {
                continue;
            }

            $value = $change['value'] ?? null;
            if (! is_array($value)) {
                continue;
            }

            if (! empty($value['messages']) && is_array($value['messages'])) {
                foreach ($value['messages'] as $messageEvent) {
                    if (is_array($messageEvent)) {
                        $events[] = $messageEvent;
                    }
                }
                continue;
            }

            if (! empty($value['message']) && is_array($value['message']) && ! empty($value['sender']) && ! empty($value['recipient'])) {
                $events[] = [
                    'sender' => $value['sender'],
                    'recipient' => $value['recipient'],
                    'message' => $value['message'],
                    'timestamp' => $value['timestamp'] ?? null,
                ];
                continue;
            }

            if (! empty($change['field']) && ! empty($value['sender']) && ! empty($value['recipient'])) {
                $events[] = [
                    'type' => $change['field'],
                    'sender' => $value['sender'],
                    'recipient' => $value['recipient'],
                    'message' => [
                        'text' => $value['message']['text'] ?? $value['message_edit']['text'] ?? null,
                    ],
                    'timestamp' => $value['timestamp'] ?? null,
                    'payload' => $value,
                ];
                continue;
            }

            if (! empty($change['field']) && ! empty($value['from']) && is_array($value['from'])) {
                $events[] = [
                    'type' => $change['field'],
                    'sender' => $value['from'],
                    'recipient' => [
                        'id' => $entry['id'] ?? null,
                    ],
                    'message' => [
                        'text' => $value['text'] ?? null,
                    ],
                    'timestamp' => $value['timestamp'] ?? null,
                    'payload' => $value,
                ];
            }
        }

        return $events;
    }

    private function storeEventMessage(
        int $chatId,
        string $senderId,
        string $recipientId,
        array $event,
        $sentAt
    ): void {
        $message = $event['message'] ?? $event['messages'] ?? [];
        $attachments = is_array($message) ? ($message['attachments'] ?? []) : [];
        $eventType = $event['type'] ?? null;
        $payload = $event['payload'] ?? $event;

        $text = is_array($message) ? ($message['text'] ?? null) : null;
        if (! is_string($text) || $text === '') {
            $text = $payload['message_edit']['text'] ?? null;
        }

        if (is_string($text) && $text !== '') {
            InstagramMessage::create([
                'chat_id' => $chatId,
                'direction' => 'in',
                'sender_id' => $senderId,
                'recipient_id' => $recipientId,
                'message_type' => $eventType ?: 'text',
                'text' => $text,
                'payload' => $payload,
                'sent_at' => $sentAt,
            ]);
        }

        if (is_array($attachments)) {
            foreach ($attachments as $attachment) {
                if (! is_array($attachment)) {
                    continue;
                }

                $type = $attachment['type'] ?? null;
                $payloadData = $attachment['payload'] ?? [];
                $url = is_array($payloadData) ? ($payloadData['url'] ?? null) : null;

                InstagramMessage::create([
                    'chat_id' => $chatId,
                    'direction' => 'in',
                    'sender_id' => $senderId,
                    'recipient_id' => $recipientId,
                    'message_type' => is_string($type) ? $type : ($eventType ?: 'attachment'),
                    'media_type' => is_string($type) ? $type : null,
                    'media_url' => is_string($url) ? $url : null,
                    'payload' => $payload,
                    'sent_at' => $sentAt,
                ]);
            }
        }

        if ((! is_string($text) || $text === '') && empty($attachments)) {
            InstagramMessage::create([
                'chat_id' => $chatId,
                'direction' => 'in',
                'sender_id' => $senderId,
                'recipient_id' => $recipientId,
                'message_type' => $eventType ?: 'event',
                'text' => $this->summarizePayloadText($payload),
                'payload' => $payload,
                'sent_at' => $sentAt,
            ]);
        }
    }

    private function summarizePayloadText(array $payload): ?string
    {
        if (isset($payload['reaction'])) {
            $reaction = $payload['reaction']['reaction'] ?? $payload['reaction']['emoji'] ?? null;
            return is_string($reaction) ? ('reaction: ' . $reaction) : 'reaction';
        }

        if (isset($payload['read'])) {
            $mid = $payload['read']['mid'] ?? null;
            return is_string($mid) ? ('seen: ' . $mid) : 'seen';
        }

        if (isset($payload['postback'])) {
            $title = $payload['postback']['title'] ?? null;
            return is_string($title) ? ('postback: ' . $title) : 'postback';
        }

        if (isset($payload['referral'])) {
            $ref = $payload['referral']['ref'] ?? null;
            return is_string($ref) ? ('referral: ' . $ref) : 'referral';
        }

        return null;
    }

    private function storeWebhookPayload(array $payload): void
    {
        try {
            $dir = public_path('logs/meta');
            if (! is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }

            $file = $dir . '/instagram-' . now()->format('Y-m-d') . '.log';
            $line = '[' . now()->toDateTimeString() . '] ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
            @file_put_contents($file, $line, FILE_APPEND);
        } catch (Throwable $exception) {
            // ignore file logging errors
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
