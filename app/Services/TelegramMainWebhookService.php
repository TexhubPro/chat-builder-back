<?php

namespace App\Services;

use App\Models\Assistant;
use App\Models\AssistantChannel;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use TexHub\OpenAi\Assistant as OpenAiAssistantClient;
use Throwable;

class TelegramMainWebhookService
{
    public function __construct(
        private CompanySubscriptionService $subscriptionService,
        private OpenAiAssistantService $openAiAssistantService,
        private OpenAiAssistantClient $openAiClient,
        private TelegramBotApiService $telegramBotApiService,
        private AssistantCrmAutomationService $assistantCrmAutomationService,
    ) {}

    public function processUpdate(AssistantChannel $assistantChannel, array $update): void
    {
        if ($assistantChannel->channel !== AssistantChannel::CHANNEL_TELEGRAM) {
            return;
        }

        $event = $this->extractMessageEvent($update);
        if ($event === null) {
            return;
        }

        $sender = is_array($event['from'] ?? null) ? $event['from'] : [];
        if (($sender['is_bot'] ?? false) === true) {
            return;
        }

        $chatInfo = is_array($event['chat'] ?? null) ? $event['chat'] : [];
        $chatId = trim((string) ($chatInfo['id'] ?? ''));
        $senderId = trim((string) ($sender['id'] ?? $chatId));

        if ($chatId === '' || $senderId === '') {
            return;
        }

        $company = Company::query()->find($assistantChannel->company_id);
        if (! $company) {
            return;
        }

        $user = User::query()->find($company->user_id);
        if (! $user) {
            return;
        }

        $assistant = $this->resolveAssistant($assistantChannel);
        $isUserActive = (bool) $user->status;
        $chat = $this->resolveChat($company, $assistant, $assistantChannel, $event, $senderId, $chatId);

        $parts = $this->normalizeInboundParts($assistantChannel, $event, $update);
        $hasReplyableContent = $this->hasReplyableInboundContent($parts);

        if ($parts === []) {
            return;
        }

        $createdInboundMessages = [];
        foreach ($parts as $part) {
            $baseChannelMessageId = (string) $part['channel_message_id'];
            $channelMessageId = $this->resolveInboundChannelMessageId(
                $chat->id,
                $baseChannelMessageId,
                $part,
            );

            if ($channelMessageId === null) {
                continue;
            }

            $createdInboundMessages[] = ChatMessage::query()->create([
                'user_id' => $company->user_id,
                'company_id' => $company->id,
                'chat_id' => $chat->id,
                'assistant_id' => $assistant?->id ?? $chat->assistant_id,
                'sender_type' => ChatMessage::SENDER_CUSTOMER,
                'direction' => ChatMessage::DIRECTION_INBOUND,
                'status' => 'received',
                'channel_message_id' => $channelMessageId,
                'message_type' => $part['message_type'],
                'text' => $part['text'],
                'media_url' => $part['media_url'],
                'media_mime_type' => $part['media_mime_type'],
                'link_url' => $part['link_url'],
                'payload' => $part['payload'],
                'sent_at' => $part['sent_at'],
            ]);
        }

        if ($createdInboundMessages === []) {
            return;
        }

        $lastInbound = $createdInboundMessages[array_key_last($createdInboundMessages)];
        $this->touchChatSnapshot($chat, $lastInbound, incrementUnreadBy: count($createdInboundMessages));

        [$hasActiveSubscription, $hasRemainingIncludedChats] = $this->subscriptionStateForAutoReply($company);
        $this->subscriptionService->incrementChatUsage($company, 1);

        if (! $this->shouldAutoReply(
            $chat,
            $assistantChannel,
            $assistant,
            $isUserActive,
            $hasActiveSubscription,
            $hasRemainingIncludedChats,
            $hasReplyableContent
        )) {
            return;
        }

        $prompt = $this->buildPromptFromInboundParts($parts);
        $assistantResponse = $this->generateAssistantResponse($company, $assistant, $chat, $prompt, $parts);

        if ($assistantResponse === null || trim($assistantResponse) === '') {
            return;
        }

        $sentMessageId = $this->sendAssistantResponseToTelegram(
            $assistantChannel,
            $chatId,
            $assistantResponse,
        );

        if ($sentMessageId === null) {
            return;
        }

        $outboundMessage = ChatMessage::query()->create([
            'user_id' => $company->user_id,
            'company_id' => $company->id,
            'chat_id' => $chat->id,
            'assistant_id' => $assistant?->id ?? $chat->assistant_id,
            'sender_type' => ChatMessage::SENDER_ASSISTANT,
            'direction' => ChatMessage::DIRECTION_OUTBOUND,
            'status' => 'sent',
            'channel_message_id' => $sentMessageId,
            'message_type' => ChatMessage::TYPE_TEXT,
            'text' => $assistantResponse,
            'sent_at' => now(),
        ]);

        $this->touchChatSnapshot($chat, $outboundMessage, incrementUnreadBy: 0);
    }

    private function extractMessageEvent(array $update): ?array
    {
        foreach ([
            'message',
            'edited_message',
            'channel_post',
            'edited_channel_post',
        ] as $key) {
            $event = $update[$key] ?? null;

            if (is_array($event)) {
                return $event;
            }
        }

        return null;
    }

    private function resolveAssistant(AssistantChannel $assistantChannel): ?Assistant
    {
        if (! $assistantChannel->assistant_id) {
            return null;
        }

        return Assistant::query()->whereKey((int) $assistantChannel->assistant_id)->first();
    }

    private function resolveChat(
        Company $company,
        ?Assistant $assistant,
        AssistantChannel $assistantChannel,
        array $event,
        string $senderId,
        string $chatId,
    ): Chat {
        $chat = Chat::query()->firstOrNew([
            'company_id' => $company->id,
            'channel' => AssistantChannel::CHANNEL_TELEGRAM,
            'channel_chat_id' => $chatId,
        ]);

        $chatInfo = is_array($event['chat'] ?? null) ? $event['chat'] : [];
        $existingMetadata = is_array($chat->metadata) ? $chat->metadata : [];
        $channelCredentials = is_array($assistantChannel->credentials) ? $assistantChannel->credentials : [];
        $displayName = $this->extractChatDisplayName($event);
        $currentName = trim((string) ($chat->name ?? ''));
        $avatarUrl = is_string($chat->avatar) ? trim((string) $chat->avatar) : '';
        $profileSnapshot = null;

        if ($this->shouldResolveCustomerProfileForChat($chat, $existingMetadata, $senderId)) {
            $profile = $this->fetchTelegramCustomerProfile($assistantChannel, $event, $senderId);
            $resolvedProfileName = trim((string) ($profile['name'] ?? ''));

            if (
                $displayName === null
                && $resolvedProfileName !== ''
                && ($chat->exists === false || $this->shouldReplaceChatNameWithProfileName($currentName, $senderId))
            ) {
                $displayName = Str::limit($resolvedProfileName, 160, '');
            }

            $candidateAvatar = trim((string) ($profile['avatar'] ?? ''));
            if ($candidateAvatar !== '') {
                $avatarUrl = $candidateAvatar;
            }

            if (
                ($profile['name'] ?? null) !== null
                || ($profile['username'] ?? null) !== null
                || ($profile['avatar'] ?? null) !== null
            ) {
                $profileSnapshot = array_filter([
                    'name' => $profile['name'] ?? null,
                    'username' => $profile['username'] ?? null,
                    'avatar' => $profile['avatar'] ?? null,
                    'resolved_at' => now()->toIso8601String(),
                ], static fn (mixed $value): bool => $value !== null && $value !== '');
            }
        }

        $incomingMetadata = [
            'source' => 'telegram_webhook',
            'telegram' => array_filter([
                'assistant_channel_id' => $assistantChannel->id,
                'chat_id' => $chatId,
                'chat_type' => (string) ($chatInfo['type'] ?? ''),
                'bot_id' => trim((string) ($channelCredentials['bot_id'] ?? '')),
                'bot_username' => trim((string) ($channelCredentials['bot_username'] ?? '')),
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
        ];

        if (is_array($profileSnapshot) && $profileSnapshot !== []) {
            $incomingMetadata['telegram']['customer_profile'] = $profileSnapshot;
        }

        $chat->fill([
            'user_id' => $company->user_id,
            'assistant_id' => $assistant?->id ?? $chat->assistant_id,
            'assistant_channel_id' => $assistantChannel->id,
            'channel_user_id' => $senderId,
            'name' => $displayName ?? $chat->name ?? ('Telegram '.$senderId),
            'avatar' => $avatarUrl !== '' ? Str::limit($avatarUrl, 2048, '') : null,
            'status' => $chat->status ?: Chat::STATUS_OPEN,
            'metadata' => array_replace_recursive($existingMetadata, $incomingMetadata),
        ]);

        $chat->save();

        return $chat;
    }

    private function extractChatDisplayName(array $event): ?string
    {
        $from = is_array($event['from'] ?? null) ? $event['from'] : [];
        $chat = is_array($event['chat'] ?? null) ? $event['chat'] : [];

        $firstName = trim((string) ($from['first_name'] ?? $chat['first_name'] ?? ''));
        $lastName = trim((string) ($from['last_name'] ?? $chat['last_name'] ?? ''));
        $username = trim((string) ($from['username'] ?? $chat['username'] ?? ''));
        $title = trim((string) ($chat['title'] ?? ''));

        $name = trim($firstName.' '.$lastName);

        if ($name === '' && $title !== '') {
            $name = $title;
        }

        if ($name === '' && $username !== '') {
            $name = '@'.$username;
        }

        if ($name === '') {
            return null;
        }

        return Str::limit($name, 160, '');
    }

    private function shouldResolveCustomerProfileForChat(Chat $chat, array $existingMetadata, string $senderId): bool
    {
        if (! (bool) config('services.telegram.resolve_customer_profile', true)) {
            return false;
        }

        if (! $chat->exists) {
            return true;
        }

        $currentName = trim((string) ($chat->name ?? ''));
        if ($this->shouldReplaceChatNameWithProfileName($currentName, $senderId)) {
            return true;
        }

        $currentAvatar = trim((string) ($chat->avatar ?? ''));
        if ($currentAvatar === '') {
            return true;
        }

        return $this->isCustomerProfileSnapshotStale($existingMetadata);
    }

    private function shouldReplaceChatNameWithProfileName(string $currentName, string $senderId): bool
    {
        $normalizedCurrentName = trim($currentName);
        if ($normalizedCurrentName === '') {
            return true;
        }

        $normalizedSenderId = trim($senderId);
        if ($normalizedSenderId === '') {
            return false;
        }

        if ($normalizedCurrentName === $normalizedSenderId) {
            return true;
        }

        return Str::lower($normalizedCurrentName) === Str::lower('Telegram '.$normalizedSenderId);
    }

    private function isCustomerProfileSnapshotStale(array $existingMetadata): bool
    {
        $resolvedAt = trim((string) data_get($existingMetadata, 'telegram.customer_profile.resolved_at', ''));
        if ($resolvedAt === '') {
            return true;
        }

        try {
            $resolvedAtCarbon = Carbon::parse($resolvedAt);
        } catch (Throwable) {
            return true;
        }

        $refreshMinutes = max((int) config('services.telegram.customer_profile_refresh_minutes', 1440), 1);

        return $resolvedAtCarbon->lte(now()->subMinutes($refreshMinutes));
    }

    private function fetchTelegramCustomerProfile(
        AssistantChannel $assistantChannel,
        array $event,
        string $senderId,
    ): array {
        $from = is_array($event['from'] ?? null) ? $event['from'] : [];
        $chat = is_array($event['chat'] ?? null) ? $event['chat'] : [];

        $firstName = trim((string) ($from['first_name'] ?? $chat['first_name'] ?? ''));
        $lastName = trim((string) ($from['last_name'] ?? $chat['last_name'] ?? ''));
        $username = trim((string) ($from['username'] ?? $chat['username'] ?? ''));

        $name = trim($firstName.' '.$lastName);
        if ($name === '' && $username !== '') {
            $name = '@'.$username;
        }

        $profile = array_filter([
            'name' => $name !== '' ? Str::limit($name, 160, '') : null,
            'username' => $username !== '' ? Str::limit($username, 160, '') : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        $credentials = is_array($assistantChannel->credentials) ? $assistantChannel->credentials : [];
        $botToken = trim((string) ($credentials['bot_token'] ?? ''));
        $normalizedSenderId = trim($senderId);

        if ($botToken === '' || $normalizedSenderId === '') {
            return $profile;
        }

        try {
            $photos = $this->telegramBotApiService->getUserProfilePhotos(
                $botToken,
                $normalizedSenderId,
                0,
                1,
            );

            $photoSets = is_array($photos['photos'] ?? null) ? $photos['photos'] : [];
            $firstSet = is_array($photoSets[0] ?? null) ? $photoSets[0] : [];
            $bestPhoto = is_array($firstSet[array_key_last($firstSet)] ?? null)
                ? $firstSet[array_key_last($firstSet)]
                : [];
            $fileId = trim((string) ($bestPhoto['file_id'] ?? ''));

            if ($fileId !== '') {
                $file = $this->telegramBotApiService->getFile($botToken, $fileId);
                $filePath = trim((string) ($file['file_path'] ?? ''));

                if ($filePath !== '') {
                    $profile['avatar'] = Str::limit(
                        $this->telegramBotApiService->resolveDownloadUrl($botToken, $filePath),
                        2048,
                        '',
                    );
                }
            }
        } catch (Throwable) {
            // Ignore profile photo fetch errors to avoid blocking webhook processing.
        }

        return $profile;
    }

    private function normalizeInboundParts(
        AssistantChannel $assistantChannel,
        array $event,
        array $update,
    ): array {
        $payload = $update;
        $sentAt = $this->resolveEventTimestamp($event['date'] ?? null);

        $parts = [];
        $baseMessageId = trim((string) ($event['message_id'] ?? $update['update_id'] ?? ''));

        if ($baseMessageId === '') {
            $baseMessageId = 'tg-'.substr(sha1(json_encode([
                'chat' => $event['chat'] ?? null,
                'from' => $event['from'] ?? null,
                'date' => $event['date'] ?? null,
                'message' => $event,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''), 0, 40);
        }

        $text = trim((string) ($event['text'] ?? $event['caption'] ?? ''));
        if ($text !== '') {
            $linkUrl = $this->extractFirstUrl($text);
            $messageType = $linkUrl !== null && $this->isOnlyUrl($text)
                ? ChatMessage::TYPE_LINK
                : ChatMessage::TYPE_TEXT;

            $parts[] = [
                'channel_message_id' => $this->partMessageId($baseMessageId, 'text'),
                'message_type' => $messageType,
                'text' => $text,
                'media_url' => null,
                'media_mime_type' => null,
                'link_url' => $linkUrl,
                'payload' => $payload,
                'sent_at' => $sentAt,
                'kind' => 'text',
            ];
        }

        $credentials = is_array($assistantChannel->credentials) ? $assistantChannel->credentials : [];
        $botToken = trim((string) ($credentials['bot_token'] ?? ''));

        $photo = is_array($event['photo'] ?? null) ? $event['photo'] : [];
        if ($photo !== []) {
            $lastPhoto = $photo[array_key_last($photo)];
            $fileId = trim((string) ((is_array($lastPhoto) ? ($lastPhoto['file_id'] ?? null) : null) ?? ''));

            $parts[] = [
                'channel_message_id' => $this->partMessageId($baseMessageId, 'photo'),
                'message_type' => ChatMessage::TYPE_IMAGE,
                'text' => null,
                'media_url' => $this->resolveTelegramFileUrl($botToken, $fileId),
                'media_mime_type' => null,
                'link_url' => null,
                'payload' => $payload,
                'sent_at' => $sentAt,
                'kind' => 'photo',
            ];
        }

        if (is_array($event['video'] ?? null)) {
            $video = $event['video'];
            $fileId = trim((string) ($video['file_id'] ?? ''));

            $parts[] = [
                'channel_message_id' => $this->partMessageId($baseMessageId, 'video'),
                'message_type' => ChatMessage::TYPE_VIDEO,
                'text' => null,
                'media_url' => $this->resolveTelegramFileUrl($botToken, $fileId),
                'media_mime_type' => trim((string) ($video['mime_type'] ?? '')) ?: null,
                'link_url' => null,
                'payload' => $payload,
                'sent_at' => $sentAt,
                'kind' => 'video',
            ];
        }

        if (is_array($event['voice'] ?? null)) {
            $voice = $event['voice'];
            $fileId = trim((string) ($voice['file_id'] ?? ''));

            $parts[] = [
                'channel_message_id' => $this->partMessageId($baseMessageId, 'voice'),
                'message_type' => ChatMessage::TYPE_VOICE,
                'text' => null,
                'media_url' => $this->resolveTelegramFileUrl($botToken, $fileId),
                'media_mime_type' => trim((string) ($voice['mime_type'] ?? '')) ?: null,
                'link_url' => null,
                'payload' => $payload,
                'sent_at' => $sentAt,
                'kind' => 'voice',
            ];
        }

        if (is_array($event['audio'] ?? null)) {
            $audio = $event['audio'];
            $fileId = trim((string) ($audio['file_id'] ?? ''));

            $parts[] = [
                'channel_message_id' => $this->partMessageId($baseMessageId, 'audio'),
                'message_type' => ChatMessage::TYPE_AUDIO,
                'text' => null,
                'media_url' => $this->resolveTelegramFileUrl($botToken, $fileId),
                'media_mime_type' => trim((string) ($audio['mime_type'] ?? '')) ?: null,
                'link_url' => null,
                'payload' => $payload,
                'sent_at' => $sentAt,
                'kind' => 'audio',
            ];
        }

        if (is_array($event['document'] ?? null)) {
            $document = $event['document'];
            $fileId = trim((string) ($document['file_id'] ?? ''));

            $parts[] = [
                'channel_message_id' => $this->partMessageId($baseMessageId, 'document'),
                'message_type' => ChatMessage::TYPE_FILE,
                'text' => null,
                'media_url' => $this->resolveTelegramFileUrl($botToken, $fileId),
                'media_mime_type' => trim((string) ($document['mime_type'] ?? '')) ?: null,
                'link_url' => null,
                'payload' => $payload,
                'sent_at' => $sentAt,
                'kind' => 'document',
            ];
        }

        if ($parts === []) {
            $parts[] = [
                'channel_message_id' => $this->partMessageId($baseMessageId, 'event'),
                'message_type' => ChatMessage::TYPE_TEXT,
                'text' => 'Telegram event received',
                'media_url' => null,
                'media_mime_type' => null,
                'link_url' => null,
                'payload' => $payload,
                'sent_at' => $sentAt,
                'kind' => 'event',
            ];
        }

        return $parts;
    }

    private function resolveTelegramFileUrl(string $botToken, string $fileId): ?string
    {
        if ($botToken === '' || $fileId === '') {
            return null;
        }

        try {
            $file = $this->telegramBotApiService->getFile($botToken, $fileId);
            $filePath = trim((string) ($file['file_path'] ?? ''));

            if ($filePath === '') {
                return null;
            }

            return $this->telegramBotApiService->resolveDownloadUrl($botToken, $filePath);
        } catch (Throwable) {
            return null;
        }
    }

    private function resolveEventTimestamp(mixed $timestamp): Carbon
    {
        if (! is_numeric($timestamp)) {
            return now();
        }

        $normalized = (string) $timestamp;
        $digitsOnly = preg_replace('/\D+/', '', $normalized) ?? '';

        if ($digitsOnly !== '' && strlen($digitsOnly) <= 10) {
            return Carbon::createFromTimestamp((int) $timestamp);
        }

        return Carbon::createFromTimestampMs((int) $timestamp);
    }

    private function resolveInboundChannelMessageId(
        int $chatId,
        string $baseChannelMessageId,
        array $part,
    ): ?string {
        $existing = ChatMessage::query()
            ->where('chat_id', $chatId)
            ->where('channel_message_id', $baseChannelMessageId)
            ->first();

        if (! $existing) {
            return $baseChannelMessageId;
        }

        $timestampSuffix = $this->inboundTimestampSuffix($part['sent_at'] ?? null);
        $candidate = $baseChannelMessageId.'-'.$timestampSuffix;
        $attempt = 1;

        while (ChatMessage::query()
            ->where('chat_id', $chatId)
            ->where('channel_message_id', $candidate)
            ->exists()) {
            $attempt += 1;

            if ($attempt > 50) {
                return $baseChannelMessageId.'-'.Str::uuid();
            }

            $candidate = $baseChannelMessageId.'-'.$timestampSuffix.'-'.$attempt;
        }

        return $candidate;
    }

    private function inboundTimestampSuffix(mixed $sentAt): string
    {
        if ($sentAt instanceof \DateTimeInterface) {
            return $sentAt->format('Uv');
        }

        return now()->format('Uv');
    }

    private function partMessageId(string $baseMessageId, string $suffix): string
    {
        if ($baseMessageId !== '') {
            return $baseMessageId.':'.$suffix;
        }

        return 'tg-'.Str::uuid().':'.$suffix;
    }

    private function extractFirstUrl(string $text): ?string
    {
        if (preg_match('/https?:\/\/[^\s]+/iu', $text, $matches) !== 1) {
            return null;
        }

        $url = trim((string) ($matches[0] ?? ''));

        return $url !== '' ? $url : null;
    }

    private function isOnlyUrl(string $text): bool
    {
        $normalized = trim($text);

        if ($normalized === '') {
            return false;
        }

        return filter_var($normalized, FILTER_VALIDATE_URL) !== false;
    }

    private function hasReplyableInboundContent(array $parts): bool
    {
        foreach ($parts as $part) {
            $kind = (string) ($part['kind'] ?? '');

            if ($kind !== 'event') {
                return true;
            }
        }

        return false;
    }

    private function shouldAutoReply(
        Chat $chat,
        AssistantChannel $assistantChannel,
        ?Assistant $assistant,
        bool $isUserActive,
        bool $hasActiveSubscription,
        bool $hasRemainingIncludedChats,
        bool $hasReplyableContent,
    ): bool {
        if (! (bool) config('services.telegram.auto_reply_enabled', true)) {
            return false;
        }

        if (! $hasReplyableContent) {
            return false;
        }

        if (! $isUserActive) {
            return false;
        }

        if (! $assistantChannel->is_active) {
            return false;
        }

        if (! $hasActiveSubscription || ! $hasRemainingIncludedChats) {
            return false;
        }

        if (! $assistant || ! $assistant->is_active) {
            return false;
        }

        if (! $this->isChatActiveForAutoReply($chat)) {
            return false;
        }

        return $this->openAiAssistantService->isConfigured();
    }

    private function isChatActiveForAutoReply(Chat $chat): bool
    {
        $metadata = is_array($chat->metadata) ? $chat->metadata : [];

        if (array_key_exists('is_active', $metadata) && $metadata['is_active'] === false) {
            return false;
        }

        return in_array((string) $chat->status, [
            Chat::STATUS_OPEN,
            Chat::STATUS_PENDING,
        ], true);
    }

    private function subscriptionStateForAutoReply(Company $company): array
    {
        $subscription = $company->subscription()->with('plan')->first();

        if (! $subscription) {
            return [false, false];
        }

        $this->subscriptionService->synchronizeBillingPeriods($subscription);
        $subscription->refresh()->load('plan');

        if (! $subscription->isActiveAt()) {
            return [false, false];
        }

        $includedChats = max($subscription->resolvedIncludedChats(), 0);
        $usedChats = max((int) $subscription->chat_count_current_period, 0);

        return [true, $usedChats < $includedChats];
    }

    private function buildPromptFromInboundParts(array $parts): string
    {
        $lines = [
            'Incoming customer message from Telegram:',
        ];

        foreach ($parts as $part) {
            $type = (string) ($part['message_type'] ?? ChatMessage::TYPE_TEXT);
            $text = trim((string) ($part['text'] ?? ''));
            $mediaUrl = trim((string) ($part['media_url'] ?? ''));
            $linkUrl = trim((string) ($part['link_url'] ?? ''));

            if ($type === ChatMessage::TYPE_TEXT && $text !== '') {
                $lines[] = '- Text: '.$text;
                continue;
            }

            if ($type === ChatMessage::TYPE_LINK) {
                $lines[] = '- Link: '.($linkUrl !== '' ? $linkUrl : $text);
                continue;
            }

            if ($type === ChatMessage::TYPE_IMAGE) {
                $lines[] = '- Image URL: '.$mediaUrl;
                continue;
            }

            if ($type === ChatMessage::TYPE_VIDEO) {
                $lines[] = '- Video URL: '.$mediaUrl;
                continue;
            }

            if ($type === ChatMessage::TYPE_VOICE || $type === ChatMessage::TYPE_AUDIO) {
                $lines[] = '- Voice/audio URL: '.$mediaUrl;
                continue;
            }

            if ($type === ChatMessage::TYPE_FILE) {
                $lines[] = '- File URL: '.$mediaUrl;
                continue;
            }

            if ($text !== '') {
                $lines[] = '- Message: '.$text;
            }
        }

        $lines[] = 'Reply in the same language as customer, concise and helpful.';

        return implode("\n", $lines);
    }

    private function generateAssistantResponse(
        Company $company,
        Assistant $assistant,
        Chat $chat,
        string $prompt,
        array $parts,
    ): ?string {
        if (! $assistant->openai_assistant_id) {
            try {
                $this->openAiAssistantService->syncAssistant($assistant);
                $assistant->refresh();
            } catch (Throwable) {
                return $this->fallbackAssistantText($assistant, $prompt);
            }
        }

        $openAiAssistantId = trim((string) ($assistant->openai_assistant_id ?? ''));

        if ($openAiAssistantId === '') {
            return $this->fallbackAssistantText($assistant, $prompt);
        }

        $runtimePrompt = $this->assistantCrmAutomationService->augmentPromptWithRuntimeContext(
            $company,
            $chat,
            $assistant,
            $prompt,
        );

        $chatMetadata = is_array($chat->metadata) ? $chat->metadata : [];
        $threadMap = is_array($chatMetadata['openai_threads'] ?? null)
            ? $chatMetadata['openai_threads']
            : [];

        $threadKey = (string) $assistant->id;
        $threadId = trim((string) ($threadMap[$threadKey] ?? ''));

        if ($threadId === '') {
            $threadId = (string) ($this->openAiClient->createThread([], [
                'chat_id' => (string) $chat->id,
                'company_id' => (string) $chat->company_id,
                'assistant_id' => (string) $assistant->id,
            ]) ?? '');

            if ($threadId === '') {
                return $this->fallbackAssistantText($assistant, $prompt);
            }

            $threadMap[$threadKey] = $threadId;
            $chatMetadata['openai_threads'] = $threadMap;

            $chat->forceFill([
                'metadata' => $chatMetadata,
            ])->save();
        }

        $messageId = null;
        $imageFileIds = $this->uploadWebhookImagesToOpenAi($parts, 'telegram');

        if ($imageFileIds !== []) {
            $messageId = $this->openAiClient->sendImageFileMessage(
                $threadId,
                $imageFileIds,
                $runtimePrompt,
                'auto',
                [
                    'chat_id' => (string) $chat->id,
                    'assistant_id' => (string) $assistant->id,
                ],
                'user',
            );
        }

        if (! is_string($messageId) || trim($messageId) === '') {
            $imageUrls = [];
            foreach ($parts as $part) {
                if (($part['message_type'] ?? '') === ChatMessage::TYPE_IMAGE) {
                    $url = trim((string) ($part['media_url'] ?? ''));

                    if ($url !== '' && Str::startsWith($url, ['http://', 'https://'])) {
                        $imageUrls[] = $url;
                    }
                }
            }

            if ($imageUrls !== []) {
                $messageId = $this->openAiClient->sendImageUrlMessage(
                    $threadId,
                    $imageUrls,
                    $runtimePrompt,
                    'auto',
                    [
                        'chat_id' => (string) $chat->id,
                        'assistant_id' => (string) $assistant->id,
                    ],
                    'user',
                );
            }
        }

        if (! is_string($messageId) || trim($messageId) === '') {
            $messageId = $this->openAiClient->sendTextMessage(
                $threadId,
                $runtimePrompt,
                [
                    'chat_id' => (string) $chat->id,
                    'assistant_id' => (string) $assistant->id,
                ],
                'user',
            );
        }

        if (! is_string($messageId) || trim($messageId) === '') {
            return $this->fallbackAssistantText($assistant, $prompt);
        }

        $responseText = $this->openAiClient->runThreadAndGetResponse(
            $threadId,
            $openAiAssistantId,
            [],
            20,
            900,
        );

        $normalized = trim((string) ($responseText ?? ''));
        if ($normalized === '') {
            return $this->fallbackAssistantText($assistant, $prompt);
        }

        $normalized = $this->assistantCrmAutomationService->applyActionsFromAssistantResponse(
            $company,
            $chat,
            $assistant,
            $normalized,
        );

        return trim($normalized) === '' ? $this->fallbackAssistantText($assistant, $prompt) : $normalized;
    }

    private function uploadWebhookImagesToOpenAi(array $parts, string $source): array
    {
        $fileIds = [];
        $index = 0;

        foreach ($parts as $part) {
            if (($part['message_type'] ?? '') !== ChatMessage::TYPE_IMAGE) {
                continue;
            }

            $url = trim((string) ($part['media_url'] ?? ''));
            if ($url === '' || ! Str::startsWith($url, ['http://', 'https://'])) {
                continue;
            }

            $localPath = $this->downloadWebhookImageToLocal($url, $source, $index);
            $index += 1;

            if ($localPath === null) {
                continue;
            }

            $fileId = $this->openAiClient->uploadFile($localPath, 'vision');
            if (is_string($fileId) && trim($fileId) !== '') {
                $fileIds[] = trim($fileId);
            }
        }

        return array_values(array_unique($fileIds));
    }

    private function downloadWebhookImageToLocal(string $url, string $source, int $index): ?string
    {
        try {
            $response = Http::timeout(20)
                ->accept('image/*')
                ->get($url);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $body = $response->body();
        if (! is_string($body) || $body === '') {
            return null;
        }

        $extension = $this->detectImageExtensionFromResponse($response->header('Content-Type'), $url);
        $relativePath = 'chat-files/webhook-media/'.$source.'/'
            .now()->format('Y/m/d').'/'
            .now()->format('His').'-'.$index.'-'.Str::random(24).'.'.$extension;

        Storage::disk('public')->put($relativePath, $body);

        return Storage::disk('public')->path($relativePath);
    }

    private function detectImageExtensionFromResponse(?string $contentType, string $url): string
    {
        $normalizedType = Str::lower(trim((string) ($contentType ?? '')));

        $known = [
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'image/bmp' => 'bmp',
            'image/tiff' => 'tiff',
            'image/avif' => 'avif',
            'image/heic' => 'heic',
            'image/heif' => 'heif',
        ];

        foreach ($known as $mime => $extension) {
            if (Str::startsWith($normalizedType, $mime)) {
                return $extension;
            }
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        $candidate = Str::lower(pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($candidate, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'tif', 'tiff', 'avif', 'heic', 'heif'], true)) {
            return $candidate === 'jpeg' ? 'jpg' : ($candidate === 'tif' ? 'tiff' : $candidate);
        }

        return 'jpg';
    }

    private function fallbackAssistantText(Assistant $assistant, string $prompt): string
    {
        return 'Извините, сейчас не удалось сформировать ответ. Пожалуйста, попробуйте еще раз.';
    }

    private function sendAssistantResponseToTelegram(
        AssistantChannel $assistantChannel,
        string $chatId,
        string $assistantResponse,
    ): ?string {
        $credentials = is_array($assistantChannel->credentials) ? $assistantChannel->credentials : [];
        $botToken = trim((string) ($credentials['bot_token'] ?? ''));

        if ($botToken === '' || $chatId === '') {
            return null;
        }

        try {
            return $this->telegramBotApiService->sendTextMessage(
                $botToken,
                $chatId,
                $assistantResponse,
            );
        } catch (Throwable) {
            return null;
        }
    }

    private function touchChatSnapshot(Chat $chat, ChatMessage $message, int $incrementUnreadBy): void
    {
        $chat->last_message_preview = $this->messagePreview($message);
        $chat->last_message_at = $message->sent_at ?? now();

        if ($incrementUnreadBy > 0) {
            $chat->unread_count = max((int) $chat->unread_count, 0) + $incrementUnreadBy;
        }

        $chat->save();
    }

    private function messagePreview(ChatMessage $message): string
    {
        $text = trim((string) ($message->text ?? ''));

        if ($text !== '') {
            return Str::limit($text, 160, '...');
        }

        return match ($message->message_type) {
            ChatMessage::TYPE_IMAGE => '[Image]',
            ChatMessage::TYPE_VIDEO => '[Video]',
            ChatMessage::TYPE_VOICE => '[Voice]',
            ChatMessage::TYPE_AUDIO => '[Audio]',
            ChatMessage::TYPE_LINK => '[Link]',
            ChatMessage::TYPE_FILE => '[File]',
            default => '[Message]',
        };
    }
}
