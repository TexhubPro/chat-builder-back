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
use TexHub\Meta\Facades\Instagram as InstagramFacade;
use TexHub\Meta\Models\InstagramIntegration;
use TexHub\OpenAi\Assistant as OpenAiAssistantClient;
use Throwable;

class InstagramMainWebhookService
{
    public function __construct(
        private CompanySubscriptionService $subscriptionService,
        private OpenAiAssistantService $openAiAssistantService,
        private OpenAiAssistantClient $openAiClient,
        private InstagramTokenService $instagramTokenService,
        private AssistantCrmAutomationService $assistantCrmAutomationService,
    ) {}

    public function processEvent(array $event): void
    {
        if ($this->isEchoEvent($event)) {
            return;
        }

        $senderId = $this->extractSenderId($event);
        $recipientId = $this->extractRecipientId($event);

        if ($senderId === null || $recipientId === null) {
            return;
        }

        $integration = $this->resolveIntegration($recipientId);

        if (! $integration) {
            return;
        }

        $integration = $this->synchronizeRecipientBinding($integration, $recipientId);

        $user = User::query()->find($integration->user_id);
        if (! $user) {
            return;
        }

        $isUserActive = (bool) $user->status;
        $company = $this->subscriptionService->provisionDefaultWorkspaceForUser($user->id, $user->name);
        $assistantChannel = $this->resolveAssistantChannel($company, $integration, $recipientId);
        $assistant = $this->resolveAssistant($assistantChannel);

        $chat = $this->resolveChat(
            $company,
            $assistant,
            $assistantChannel,
            $integration,
            $senderId,
            $recipientId,
            $event,
        );

        $parts = $this->normalizeInboundParts($event);
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
            $integration,
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

        [$sentMessageId, $outboundType, $outboundMediaUrl] = $this->sendAssistantResponseToInstagram(
            $integration,
            $recipientId,
            $senderId,
            $assistantResponse,
            $parts
        );

        if ($sentMessageId === null) {
            return;
        }

        $outboundMessage = ChatMessage::query()->create([
            'user_id' => $company->user_id,
            'company_id' => $company->id,
            'chat_id' => $chat->id,
            'assistant_id' => $assistant->id,
            'sender_type' => ChatMessage::SENDER_ASSISTANT,
            'direction' => ChatMessage::DIRECTION_OUTBOUND,
            'status' => 'sent',
            'channel_message_id' => $sentMessageId,
            'message_type' => $outboundType,
            'text' => $assistantResponse,
            'media_url' => $outboundMediaUrl,
            'sent_at' => now(),
        ]);

        $this->touchChatSnapshot($chat, $outboundMessage, incrementUnreadBy: 0);
    }

    private function shouldAutoReply(
        Chat $chat,
        InstagramIntegration $integration,
        ?AssistantChannel $assistantChannel,
        ?Assistant $assistant,
        bool $isUserActive,
        bool $hasActiveSubscription,
        bool $hasRemainingIncludedChats,
        bool $hasReplyableContent,
    ): bool {
        if (! (bool) config('meta.instagram.auto_reply_enabled', true)) {
            return false;
        }

        if (! $hasReplyableContent) {
            return false;
        }

        if (! $isUserActive) {
            return false;
        }

        if (! (bool) $integration->is_active) {
            return false;
        }

        if (! $assistantChannel || ! $assistantChannel->is_active) {
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

    private function resolveIntegration(string $recipientId): ?InstagramIntegration
    {
        return InstagramIntegration::query()
            ->where(function ($builder) use ($recipientId): void {
                $builder
                    ->where('receiver_id', $recipientId)
                    ->orWhere('instagram_user_id', $recipientId);
            })
            ->orderByDesc('id')
            ->first();
    }

    private function resolveAssistantChannel(
        Company $company,
        InstagramIntegration $integration,
        string $recipientId,
    ): ?AssistantChannel {
        return $company->assistantChannels()
            ->with('assistant')
            ->where('channel', AssistantChannel::CHANNEL_INSTAGRAM)
            ->where(function ($builder) use ($integration, $recipientId): void {
                $builder
                    ->where('external_account_id', $recipientId)
                    ->orWhere('external_account_id', (string) $integration->instagram_user_id)
                    ->orWhere('external_account_id', (string) ($integration->receiver_id ?? ''));
            })
            ->orderByDesc('is_active')
            ->orderBy('id')
            ->first();
    }

    private function synchronizeRecipientBinding(
        InstagramIntegration $integration,
        string $recipientId,
    ): InstagramIntegration {
        $normalizedRecipientId = trim($recipientId);
        if ($normalizedRecipientId === '') {
            return $integration;
        }

        $currentReceiverId = trim((string) ($integration->receiver_id ?? ''));
        if ($currentReceiverId === $normalizedRecipientId) {
            return $integration;
        }

        $integration->forceFill([
            'receiver_id' => $normalizedRecipientId,
        ])->save();

        $integration->refresh();

        $instagramUserId = trim((string) ($integration->instagram_user_id ?? ''));
        $candidateExternalIds = array_values(array_unique(array_filter([
            $currentReceiverId,
            $instagramUserId,
            $normalizedRecipientId,
        ], static fn (string $value): bool => $value !== '')));

        if ($candidateExternalIds === []) {
            return $integration;
        }

        AssistantChannel::query()
            ->where('user_id', $integration->user_id)
            ->where('channel', AssistantChannel::CHANNEL_INSTAGRAM)
            ->whereIn('external_account_id', $candidateExternalIds)
            ->get()
            ->each(function (AssistantChannel $assistantChannel) use ($integration, $normalizedRecipientId): void {
                $credentials = is_array($assistantChannel->credentials) ? $assistantChannel->credentials : [];
                $credentials['receiver_id'] = $normalizedRecipientId;

                if (trim((string) ($credentials['instagram_user_id'] ?? '')) === '') {
                    $credentials['instagram_user_id'] = (string) $integration->instagram_user_id;
                }

                $assistantChannel->forceFill([
                    'external_account_id' => $normalizedRecipientId,
                    'credentials' => $credentials,
                ])->save();
            });

        return $integration;
    }

    private function resolveAssistant(
        ?AssistantChannel $assistantChannel,
    ): ?Assistant {
        if ($assistantChannel?->assistant && $assistantChannel->assistant->is_active) {
            return $assistantChannel->assistant;
        }

        return null;
    }

    private function resolveChat(
        Company $company,
        ?Assistant $assistant,
        ?AssistantChannel $assistantChannel,
        InstagramIntegration $integration,
        string $senderId,
        string $recipientId,
        array $event,
    ): Chat {
        $channelChatId = $recipientId.':'.$senderId;
        $chat = Chat::query()->firstOrNew([
            'company_id' => $company->id,
            'channel' => 'instagram',
            'channel_chat_id' => $channelChatId,
        ]);
        $isNewChat = ! $chat->exists;

        $existingMetadata = is_array($chat->metadata) ? $chat->metadata : [];
        $displayName = $this->extractChatDisplayName($event);
        $avatarUrl = is_string($chat->avatar) ? trim((string) $chat->avatar) : '';
        $profileSnapshot = null;

        if ($isNewChat && $this->shouldResolveCustomerProfile()) {
            $profile = $this->fetchInstagramCustomerProfile($integration, $senderId);

            if ($displayName === null) {
                $displayName = $profile['name'] ?? null;
            }

            $candidateAvatar = $profile['avatar'] ?? null;
            if (is_string($candidateAvatar) && trim($candidateAvatar) !== '') {
                $avatarUrl = trim($candidateAvatar);
            }

            if (($profile['name'] ?? null) !== null || ($profile['avatar'] ?? null) !== null) {
                $profileSnapshot = array_filter([
                    'name' => $profile['name'] ?? null,
                    'avatar' => $profile['avatar'] ?? null,
                    'resolved_at' => now()->toIso8601String(),
                ], static fn (mixed $value): bool => $value !== null && $value !== '');
            }
        }

        $incomingMetadata = [
            'source' => 'meta_instagram_main_webhook',
            'instagram' => [
                'integration_id' => $integration->id,
                'instagram_user_id' => $integration->instagram_user_id,
                'receiver_id' => $recipientId,
            ],
        ];

        if (is_array($profileSnapshot) && $profileSnapshot !== []) {
            $incomingMetadata['instagram']['customer_profile'] = $profileSnapshot;
        }

        $chat->fill([
            'user_id' => $company->user_id,
            'assistant_id' => $assistant?->id ?? $chat->assistant_id,
            'assistant_channel_id' => $assistantChannel?->id ?? $chat->assistant_channel_id,
            'channel_user_id' => $senderId,
            'name' => $displayName ?? $chat->name ?? ('Instagram '.$senderId),
            'avatar' => $avatarUrl !== '' ? Str::limit($avatarUrl, 2048, '') : null,
            'status' => $chat->status ?: Chat::STATUS_OPEN,
            'metadata' => array_replace_recursive($existingMetadata, $incomingMetadata),
        ]);

        $chat->save();

        return $chat;
    }

    private function extractChatDisplayName(array $event): ?string
    {
        $sender = $event['sender'] ?? null;

        if (is_array($sender)) {
            $name = trim((string) ($sender['name'] ?? ''));

            if ($name !== '') {
                return Str::limit($name, 160, '');
            }
        }

        return null;
    }

    private function shouldResolveCustomerProfile(): bool
    {
        return (bool) config('meta.instagram.resolve_customer_profile', true);
    }

    private function fetchInstagramCustomerProfile(InstagramIntegration $integration, string $senderId): array
    {
        $normalizedSenderId = trim($senderId);
        $accessToken = trim((string) ($integration->access_token ?? ''));

        if ($normalizedSenderId === '' || $accessToken === '') {
            return [];
        }

        $apiVersion = trim((string) config('meta.instagram.api_version', 'v23.0'));
        if ($apiVersion === '') {
            $apiVersion = 'v23.0';
        }

        $configuredGraphBase = rtrim((string) config('meta.instagram.graph_base', 'https://graph.instagram.com'), '/');
        $graphBases = array_values(array_unique(array_filter([
            'https://graph.facebook.com',
            $configuredGraphBase,
        ], static fn (string $value): bool => trim($value) !== '')));

        foreach ($graphBases as $base) {
            try {
                $response = Http::timeout(8)->get($base.'/'.$apiVersion.'/'.$normalizedSenderId, [
                    'fields' => 'name,username,profile_pic,profile_picture_url',
                    'access_token' => $accessToken,
                ]);
            } catch (Throwable) {
                continue;
            }

            if (! $response->successful()) {
                continue;
            }

            $payload = $response->json();
            if (! is_array($payload)) {
                continue;
            }

            $name = trim((string) ($payload['name'] ?? $payload['username'] ?? ''));
            $avatar = trim((string) ($payload['profile_pic'] ?? $payload['profile_picture_url'] ?? ''));

            return array_filter([
                'name' => $name !== '' ? Str::limit($name, 160, '') : null,
                'avatar' => $avatar !== '' ? Str::limit($avatar, 2048, '') : null,
            ], static fn (mixed $value): bool => $value !== null && $value !== '');
        }

        return [];
    }

    private function normalizeInboundParts(array $event): array
    {
        $message = is_array($event['message'] ?? null) ? $event['message'] : [];
        $payload = is_array($event['payload'] ?? null) ? $event['payload'] : $event;
        $timestamp = $event['timestamp'] ?? null;
        $sentAt = $this->resolveEventTimestamp($timestamp);

        $parts = [];
        $baseMessageId = trim((string) ($message['mid'] ?? $event['mid'] ?? ''));
        if ($baseMessageId === '') {
            $baseMessageId = 'ig-'.substr(sha1(json_encode([
                'sender' => $event['sender'] ?? null,
                'recipient' => $event['recipient'] ?? null,
                'timestamp' => $event['timestamp'] ?? null,
                'message' => $message,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''), 0, 40);
        }

        $text = trim((string) ($message['text'] ?? ''));
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

        $attachments = is_array($message['attachments'] ?? null) ? $message['attachments'] : [];

        foreach ($attachments as $index => $attachment) {
            if (! is_array($attachment)) {
                continue;
            }

            $type = Str::lower(trim((string) ($attachment['type'] ?? '')));
            $attachmentPayload = is_array($attachment['payload'] ?? null) ? $attachment['payload'] : [];
            $url = $this->extractAttachmentUrl($attachmentPayload);

            $mappedType = match ($type) {
                'image', 'story_mention', 'story_reply' => ChatMessage::TYPE_IMAGE,
                'video' => ChatMessage::TYPE_VIDEO,
                'audio' => ChatMessage::TYPE_VOICE,
                'file' => ChatMessage::TYPE_FILE,
                'share' => ChatMessage::TYPE_LINK,
                default => ChatMessage::TYPE_FILE,
            };

            $parts[] = [
                'channel_message_id' => $this->partMessageId($baseMessageId, 'attachment-'.$index),
                'message_type' => $mappedType,
                'text' => null,
                'media_url' => $mappedType === ChatMessage::TYPE_LINK ? null : $url,
                'media_mime_type' => null,
                'link_url' => $mappedType === ChatMessage::TYPE_LINK ? $url : null,
                'payload' => $payload,
                'sent_at' => $sentAt,
                'kind' => $type !== '' ? $type : 'attachment',
            ];
        }

        if ($parts === []) {
            $summary = $this->summarizePayload($payload);

            if ($summary === null) {
                return [];
            }

            $parts[] = [
                'channel_message_id' => $this->partMessageId($baseMessageId, 'event'),
                'message_type' => ChatMessage::TYPE_TEXT,
                'text' => $summary,
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

    private function summarizePayload(array $payload): ?string
    {
        if (isset($payload['reaction'])) {
            $reaction = trim((string) ($payload['reaction']['reaction'] ?? $payload['reaction']['emoji'] ?? ''));

            return $reaction !== '' ? 'Reaction: '.$reaction : 'Reaction received';
        }

        if (isset($payload['read'])) {
            return 'Message seen';
        }

        if (isset($payload['postback'])) {
            $title = trim((string) ($payload['postback']['title'] ?? ''));

            return $title !== '' ? 'Postback: '.$title : 'Postback received';
        }

        return null;
    }

    private function buildPromptFromInboundParts(array $parts): string
    {
        $lines = [
            'Incoming customer message from Instagram:',
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
        $imageFileIds = $this->uploadWebhookImagesToOpenAi($parts, 'instagram');

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
        $safePrompt = Str::limit(trim($prompt), 220, '...');

        return '['.$assistant->name.'] Received: '.$safePrompt;
    }

    private function sendAssistantResponseToInstagram(
        InstagramIntegration $integration,
        string $recipientId,
        string $customerInstagramUserId,
        string $assistantResponse,
        array $parts,
    ): array {
        $integration = $this->instagramTokenService->ensureTokenIsFresh(
            $integration,
            max((int) config('meta.instagram.token_refresh_grace_seconds', 900), 0),
        );

        $accessToken = trim((string) $integration->access_token);
        $igUserId = trim((string) ($recipientId ?: $integration->receiver_id ?: $integration->instagram_user_id));

        if ($accessToken === '' || $igUserId === '') {
            return [null, ChatMessage::TYPE_TEXT, null];
        }

        $shouldReplyAsVoice = (bool) config('meta.instagram.voice_reply_for_audio', true)
            && $this->containsVoiceInbound($parts);

        if ($shouldReplyAsVoice) {
            $audioUrl = $this->synthesizeSpeechUrl($assistantResponse);

            if ($audioUrl !== null) {
                $audioMessageId = InstagramFacade::sendMediaMessage(
                    $igUserId,
                    $customerInstagramUserId,
                    'audio',
                    $audioUrl,
                    false,
                    $accessToken,
                    false,
                );

                if (is_string($audioMessageId) && trim($audioMessageId) !== '') {
                    return [$audioMessageId, ChatMessage::TYPE_VOICE, $audioUrl];
                }
            }
        }

        $textMessageId = InstagramFacade::sendTextMessage(
            $igUserId,
            $customerInstagramUserId,
            $assistantResponse,
            $accessToken,
            false,
        );

        if (! is_string($textMessageId) || trim($textMessageId) === '') {
            return [null, ChatMessage::TYPE_TEXT, null];
        }

        return [$textMessageId, ChatMessage::TYPE_TEXT, null];
    }

    private function synthesizeSpeechUrl(string $text): ?string
    {
        $responseFormat = trim((string) config('openai.tts.response_format', 'mp3'));
        $extension = $responseFormat !== '' ? $responseFormat : 'mp3';
        $relativePath = 'assistant-audio/'.now()->format('Y/m/d').'/'.Str::uuid().'.'.$extension;

        $disk = Storage::disk('public');
        $absolutePath = $disk->path($relativePath);
        $directory = dirname($absolutePath);

        if (! is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        $savedPath = $this->openAiClient->createSpeech($text, [], $absolutePath);

        if (! is_string($savedPath) || $savedPath === '') {
            return null;
        }

        $url = $disk->url($relativePath);

        if (Str::startsWith($url, ['http://', 'https://'])) {
            return $url;
        }

        $base = rtrim((string) config('app.url', ''), '/');

        if ($base === '') {
            return null;
        }

        return $base.'/'.ltrim($url, '/');
    }

    private function containsVoiceInbound(array $parts): bool
    {
        foreach ($parts as $part) {
            $type = (string) ($part['message_type'] ?? '');

            if ($type === ChatMessage::TYPE_VOICE || $type === ChatMessage::TYPE_AUDIO) {
                return true;
            }
        }

        return false;
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

    private function extractAttachmentUrl(array $payload): ?string
    {
        $candidates = [
            $payload['url'] ?? null,
            $payload['attachment_url'] ?? null,
            $payload['link'] ?? null,
            $payload['href'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            $normalized = trim($candidate);

            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
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

    private function partMessageId(string $baseMessageId, string $suffix): string
    {
        if ($baseMessageId !== '') {
            return $baseMessageId.':'.$suffix;
        }

        return 'ig-'.Str::uuid().':'.$suffix;
    }

    private function extractSenderId(array $event): ?string
    {
        $senderId = trim((string) ($event['sender']['id'] ?? ''));

        return $senderId === '' ? null : $senderId;
    }

    private function extractRecipientId(array $event): ?string
    {
        $recipientId = trim((string) ($event['recipient']['id'] ?? ''));

        return $recipientId === '' ? null : $recipientId;
    }

    private function isEchoEvent(array $event): bool
    {
        return (bool) ($event['message']['is_echo'] ?? false)
            || (bool) ($event['is_echo'] ?? false);
    }
}
