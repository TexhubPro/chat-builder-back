<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Assistant as AssistantModel;
use App\Models\AssistantChannel;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Company;
use App\Models\User;
use App\Services\CompanySubscriptionService;
use App\Services\InstagramTokenService;
use App\Services\OpenAiAssistantService;
use App\Services\TelegramBotApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use TexHub\Meta\Facades\Instagram as InstagramFacade;
use TexHub\Meta\Models\InstagramIntegration;
use TexHub\OpenAi\Assistant as OpenAiAssistantClient;
use Throwable;

class ChatMessageController extends Controller
{
    public function store(Request $request, int $chatId): JsonResponse
    {
        $validated = $request->validate([
            'assistant_id' => ['nullable', 'integer'],
            'sender_type' => ['nullable', 'string', 'in:agent,assistant,system,customer'],
            'direction' => ['nullable', 'string', 'in:inbound,outbound'],
            'message_type' => ['nullable', 'string', 'in:text,image,video,voice,audio,link,file'],
            'text' => ['nullable', 'string', 'min:1', 'max:20000'],
            'media_url' => ['nullable', 'string', 'max:2048'],
            'link_url' => ['nullable', 'string', 'max:2048'],
            'attachments' => ['nullable', 'array'],
            'payload' => ['nullable', 'array'],
            'file' => ['nullable', 'file', 'max:4096'],
        ]);

        $user = $this->resolveUser($request);
        $company = $this->resolveCompany($user);
        $chat = $this->resolveChat($company, $chatId);
        $assistant = null;

        if (isset($validated['assistant_id'])) {
            $assistant = $company->assistants()
                ->whereKey((int) $validated['assistant_id'])
                ->first();

            if (! $assistant) {
                return response()->json([
                    'message' => 'Assistant not found for this company.',
                ], 422);
            }
        }

        $direction = (string) ($validated['direction'] ?? ChatMessage::DIRECTION_OUTBOUND);
        $senderType = (string) ($validated['sender_type'] ?? ChatMessage::SENDER_AGENT);

        $normalizedText = $this->nullableTrimmedString($validated['text'] ?? null);
        $uploadedFile = $request->file('file');

        if ($normalizedText === null && ! ($uploadedFile instanceof UploadedFile)) {
            return response()->json([
                'message' => 'Message text or file is required.',
            ], 422);
        }

        $filePayload = $uploadedFile instanceof UploadedFile
            ? $this->storeChatFile($uploadedFile)
            : null;

        $attachments = is_array($validated['attachments'] ?? null) ? $validated['attachments'] : [];

        if ($filePayload !== null) {
            $attachments[] = [
                'type' => 'uploaded_file',
                'name' => $filePayload['name'],
                'url' => $filePayload['url'],
                'mime_type' => $filePayload['mime_type'],
                'size' => $filePayload['size'],
            ];
        }

        $messageType = $filePayload !== null
            ? $this->resolveMessageTypeForFile($filePayload['mime_type'])
            : (string) ($validated['message_type'] ?? ChatMessage::TYPE_TEXT);

        $messageText = $normalizedText ?? ($filePayload['name'] ?? null);
        $mediaUrl = $filePayload['url'] ?? $this->nullableTrimmedString($validated['media_url'] ?? null);
        $linkUrl = $this->nullableTrimmedString($validated['link_url'] ?? null);
        $channelMessageId = null;

        if ($this->shouldDispatchInstagramOutbound($chat, $direction)) {
            [$channelMessageId, $dispatchError] = $this->dispatchOutboundInstagramMessage(
                $company,
                $chat,
                $messageType,
                $normalizedText,
                $mediaUrl,
                $linkUrl,
            );

            if ($channelMessageId === null) {
                return response()->json([
                    'message' => $dispatchError ?? 'Failed to deliver message to Instagram.',
                ], 422);
            }
        }

        if ($this->shouldDispatchTelegramOutbound($chat, $direction)) {
            [$channelMessageId, $dispatchError] = $this->dispatchOutboundTelegramMessage(
                $company,
                $chat,
                $messageType,
                $normalizedText,
                $mediaUrl,
                $linkUrl,
            );

            if ($channelMessageId === null) {
                return response()->json([
                    'message' => $dispatchError ?? 'Failed to deliver message to Telegram.',
                ], 422);
            }
        }

        $message = ChatMessage::query()->create([
            'user_id' => $company->user_id,
            'company_id' => $company->id,
            'chat_id' => $chat->id,
            'assistant_id' => $assistant?->id ?? $chat->assistant_id,
            'sender_type' => $senderType,
            'direction' => $direction,
            'status' => $direction === ChatMessage::DIRECTION_OUTBOUND ? 'sent' : 'received',
            'channel_message_id' => $channelMessageId,
            'message_type' => $messageType,
            'text' => $messageText,
            'media_url' => $mediaUrl,
            'media_mime_type' => $filePayload['mime_type'] ?? null,
            'media_size' => $filePayload['size'] ?? null,
            'link_url' => $linkUrl,
            'attachments' => $attachments !== [] ? $attachments : null,
            'payload' => is_array($validated['payload'] ?? null) ? $validated['payload'] : null,
            'sent_at' => now(),
        ]);

        $isCustomerInbound = $direction === ChatMessage::DIRECTION_INBOUND
            && $senderType === ChatMessage::SENDER_CUSTOMER;

        $this->updateChatSnapshotFromMessage($chat, $message, $isCustomerInbound);

        $hasActiveSubscription = true;
        $hasRemainingIncludedChats = true;

        if ($isCustomerInbound) {
            [$hasActiveSubscription, $hasRemainingIncludedChats] = $this->subscriptionStateForAutoReply($company);
            $this->subscriptionService()->incrementChatUsage($company, 1);
        }

        $assistantMessage = null;
        $assistantForAutoReply = $isCustomerInbound
            ? $this->resolveAssistantForReply(
                $company,
                $chat,
                isset($validated['assistant_id']) ? (int) $validated['assistant_id'] : null
            )
            : null;

        if ($this->shouldAutoReplyToAssistantChat(
            $chat,
            $assistantForAutoReply,
            $hasActiveSubscription,
            $hasRemainingIncludedChats
        )) {
            if ($assistantForAutoReply && (int) $chat->assistant_id !== (int) $assistantForAutoReply->id) {
                $chat->forceFill([
                    'assistant_id' => $assistantForAutoReply->id,
                ])->save();
            }

            $assistantPrompt = $this->buildPromptFromChatMessage($message);
            $assistantResponse = $this->generateAssistantResponse(
                $assistantForAutoReply,
                $chat,
                $assistantPrompt,
                $message,
                $filePayload['absolute_path'] ?? null,
            );

            if (trim($assistantResponse) !== '') {
                $assistantMessage = ChatMessage::query()->create([
                    'user_id' => $company->user_id,
                    'company_id' => $company->id,
                    'chat_id' => $chat->id,
                    'assistant_id' => $assistantForAutoReply?->id,
                    'sender_type' => ChatMessage::SENDER_ASSISTANT,
                    'direction' => ChatMessage::DIRECTION_OUTBOUND,
                    'status' => 'sent',
                    'message_type' => ChatMessage::TYPE_TEXT,
                    'text' => $assistantResponse,
                    'sent_at' => now(),
                ]);

                $chat->forceFill([
                    'unread_count' => 0,
                ])->save();

                $this->updateChatSnapshotFromMessage($chat, $assistantMessage, false);
            }
        }

        return response()->json([
            'message' => 'Message sent successfully.',
            'chat' => $this->chatPayload($chat->refresh()),
            'chat_message' => $this->messagePayload($message),
            'assistant_message' => $assistantMessage ? $this->messagePayload($assistantMessage) : null,
        ], 201);
    }

    public function assistantReply(Request $request, int $chatId): JsonResponse
    {
        $validated = $request->validate([
            'assistant_id' => ['nullable', 'integer'],
            'prompt' => ['required', 'string', 'min:1', 'max:20000'],
        ]);

        $user = $this->resolveUser($request);
        $company = $this->resolveCompany($user);
        $chat = $this->resolveChat($company, $chatId);

        if (! $this->isChatActiveForAutoReply($chat)) {
            return response()->json([
                'message' => 'AI replies are disabled for this chat.',
            ], 422);
        }

        $assistant = $this->resolveAssistantForReply(
            $company,
            $chat,
            isset($validated['assistant_id']) ? (int) $validated['assistant_id'] : null
        );

        if (! $assistant) {
            return response()->json([
                'message' => 'No running assistant is available for this company.',
            ], 422);
        }

        $chat->forceFill([
            'assistant_id' => $assistant->id,
        ])->save();

        $prompt = trim((string) $validated['prompt']);

        $incomingMessage = ChatMessage::query()->create([
            'user_id' => $company->user_id,
            'company_id' => $company->id,
            'chat_id' => $chat->id,
            'assistant_id' => $assistant->id,
            'sender_type' => ChatMessage::SENDER_CUSTOMER,
            'direction' => ChatMessage::DIRECTION_INBOUND,
            'status' => 'read',
            'message_type' => ChatMessage::TYPE_TEXT,
            'text' => $prompt,
            'sent_at' => now(),
            'read_at' => now(),
        ]);

        $this->subscriptionService()->incrementChatUsage($company, 1);

        $responseText = $this->generateAssistantResponse($assistant, $chat, $prompt);

        $assistantMessage = ChatMessage::query()->create([
            'user_id' => $company->user_id,
            'company_id' => $company->id,
            'chat_id' => $chat->id,
            'assistant_id' => $assistant->id,
            'sender_type' => ChatMessage::SENDER_ASSISTANT,
            'direction' => ChatMessage::DIRECTION_OUTBOUND,
            'status' => 'sent',
            'message_type' => ChatMessage::TYPE_TEXT,
            'text' => $responseText,
            'sent_at' => now(),
        ]);

        $chat->forceFill([
            'unread_count' => 0,
        ])->save();

        $this->updateChatSnapshotFromMessage($chat, $assistantMessage, false);

        return response()->json([
            'message' => 'Assistant reply generated successfully.',
            'assistant' => [
                'id' => $assistant->id,
                'name' => $assistant->name,
            ],
            'chat' => $this->chatPayload($chat->refresh()),
            'incoming_message' => $this->messagePayload($incomingMessage),
            'assistant_message' => $this->messagePayload($assistantMessage),
        ], 201);
    }

    private function resolveAssistantForReply(
        Company $company,
        Chat $chat,
        ?int $assistantId
    ): ?AssistantModel {
        if ($assistantId !== null) {
            return $company->assistants()
                ->whereKey($assistantId)
                ->where('is_active', true)
                ->first();
        }

        if ($chat->assistant_id) {
            $assistant = $company->assistants()
                ->whereKey((int) $chat->assistant_id)
                ->where('is_active', true)
                ->first();

            if ($assistant) {
                return $assistant;
            }
        }

        $assistant = $company->assistants()
            ->where('is_active', true)
            ->orderBy('id')
            ->first();

        if ($assistant) {
            return $assistant;
        }

        return null;
    }

    private function generateAssistantResponse(
        AssistantModel $assistant,
        Chat $chat,
        string $prompt,
        ?ChatMessage $incomingMessage = null,
        ?string $uploadedAbsolutePath = null,
    ): string {
        $apiKey = trim((string) config('openai.assistant.api_key', ''));

        if ($apiKey === '') {
            return $this->fallbackAssistantText($assistant, $prompt);
        }

        if (! $assistant->openai_assistant_id) {
            try {
                $this->openAiAssistantService()->syncAssistant($assistant);
                $assistant->refresh();
            } catch (Throwable) {
                return $this->fallbackAssistantText($assistant, $prompt);
            }
        }

        $openAiAssistantId = trim((string) ($assistant->openai_assistant_id ?? ''));

        if ($openAiAssistantId === '') {
            return $this->fallbackAssistantText($assistant, $prompt);
        }

        $chatMetadata = is_array($chat->metadata) ? $chat->metadata : [];
        $threadMap = is_array($chatMetadata['openai_threads'] ?? null)
            ? $chatMetadata['openai_threads']
            : [];

        $threadKey = (string) $assistant->id;
        $threadId = trim((string) ($threadMap[$threadKey] ?? ''));

        if ($threadId === '') {
            $threadId = $this->createAndPersistOpenAiThread($chat, $assistant, $chatMetadata, $threadMap, $threadKey);

            if ($threadId === null) {
                return $this->fallbackAssistantText($assistant, $prompt);
            }
        }

        $messageId = $this->sendIncomingMessageToOpenAiThread(
            $threadId,
            $assistant,
            $chat,
            $prompt,
            $incomingMessage,
            $uploadedAbsolutePath,
        );

        if (! is_string($messageId) || trim($messageId) === '') {
            $recoveryThreadId = $this->createAndPersistOpenAiThread($chat, $assistant, $chatMetadata, $threadMap, $threadKey);

            if ($recoveryThreadId !== null) {
                $messageId = $this->sendIncomingMessageToOpenAiThread(
                    $recoveryThreadId,
                    $assistant,
                    $chat,
                    $prompt,
                    $incomingMessage,
                    $uploadedAbsolutePath,
                );

                if (is_string($messageId) && trim($messageId) !== '') {
                    $threadId = $recoveryThreadId;
                }
            }
        }

        if (! is_string($messageId) || trim($messageId) === '') {
            $this->logAssistantFallback('OpenAI message send failed', [
                'assistant_id' => $assistant->id,
                'chat_id' => $chat->id,
            ]);
            return $this->fallbackAssistantText($assistant, $prompt);
        }

        $normalized = $this->runAssistantThread($threadId, $openAiAssistantId);

        if ($normalized !== '') {
            return $normalized;
        }

        $recoveryThreadId = $this->createAndPersistOpenAiThread($chat, $assistant, $chatMetadata, $threadMap, $threadKey);

        if ($recoveryThreadId !== null) {
            $retryMessageId = $this->sendIncomingMessageToOpenAiThread(
                $recoveryThreadId,
                $assistant,
                $chat,
                $prompt,
                $incomingMessage,
                $uploadedAbsolutePath,
            );

            if (is_string($retryMessageId) && trim($retryMessageId) !== '') {
                $retryNormalized = $this->runAssistantThread($recoveryThreadId, $openAiAssistantId);

                if ($retryNormalized !== '') {
                    return $retryNormalized;
                }
            }
        }

        $this->logAssistantFallback('OpenAI run did not return a response after retry', [
            'assistant_id' => $assistant->id,
            'chat_id' => $chat->id,
            'thread_id' => $threadId,
        ]);

        return $this->fallbackAssistantText($assistant, $prompt);
    }

    private function fallbackAssistantText(AssistantModel $assistant, string $prompt): string
    {
        $safePrompt = Str::limit(trim($prompt), 220, '...');

        return "[$assistant->name] Received: {$safePrompt}";
    }

    private function createAndPersistOpenAiThread(
        Chat $chat,
        AssistantModel $assistant,
        array &$chatMetadata,
        array &$threadMap,
        string $threadKey,
    ): ?string {
        try {
            $threadId = (string) ($this->openAiClient()->createThread([], [
                'chat_id' => (string) $chat->id,
                'company_id' => (string) $chat->company_id,
                'assistant_id' => (string) $assistant->id,
            ]) ?? '');
        } catch (Throwable $exception) {
            $this->logAssistantFallback('OpenAI thread creation failed', [
                'assistant_id' => $assistant->id,
                'chat_id' => $chat->id,
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }

        $threadId = trim($threadId);

        if ($threadId === '') {
            return null;
        }

        $threadMap[$threadKey] = $threadId;
        $chatMetadata['openai_threads'] = $threadMap;

        $chat->forceFill([
            'metadata' => $chatMetadata,
        ])->save();

        return $threadId;
    }

    private function runAssistantThread(string $threadId, string $openAiAssistantId): string
    {
        try {
            $responseText = $this->openAiClient()->runThreadAndGetResponse(
                $threadId,
                $openAiAssistantId,
                [],
                20,
                900
            );
        } catch (Throwable $exception) {
            $this->logAssistantFallback('OpenAI run failed with exception', [
                'thread_id' => $threadId,
                'assistant_openai_id' => $openAiAssistantId,
                'exception' => $exception->getMessage(),
            ]);

            return '';
        }

        return trim((string) ($responseText ?? ''));
    }

    private function logAssistantFallback(string $message, array $context = []): void
    {
        Log::warning($message, $context);
    }

    private function resolveUser(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    private function resolveCompany(User $user): Company
    {
        return $this->subscriptionService()->provisionDefaultWorkspaceForUser(
            $user->id,
            $user->name
        );
    }

    private function resolveChat(Company $company, int $chatId): Chat
    {
        /** @var Chat|null $chat */
        $chat = $company->chats()
            ->with([
                'assistant:id,name,is_active',
                'assistantChannel:id,name,channel',
            ])
            ->whereKey($chatId)
            ->first();

        if (! $chat) {
            abort(404, 'Chat not found.');
        }

        return $chat;
    }

    private function chatPayload(Chat $chat): array
    {
        $chat->loadMissing([
            'assistant:id,name,is_active',
            'assistantChannel:id,name,channel',
        ]);

        return [
            'id' => $chat->id,
            'channel' => $chat->channel,
            'channel_chat_id' => $chat->channel_chat_id,
            'channel_user_id' => $chat->channel_user_id,
            'name' => $chat->name,
            'avatar' => $chat->avatar,
            'status' => $chat->status,
            'unread_count' => (int) $chat->unread_count,
            'last_message_preview' => $chat->last_message_preview,
            'last_message_at' => $chat->last_message_at?->toIso8601String(),
            'metadata' => is_array($chat->metadata) ? $chat->metadata : null,
            'assistant' => $chat->assistant
                ? [
                    'id' => $chat->assistant->id,
                    'name' => $chat->assistant->name,
                    'is_active' => (bool) $chat->assistant->is_active,
                ]
                : null,
            'assistant_channel' => $chat->assistantChannel
                ? [
                    'id' => $chat->assistantChannel->id,
                    'name' => $chat->assistantChannel->name,
                    'channel' => $chat->assistantChannel->channel,
                ]
                : null,
            'created_at' => $chat->created_at?->toIso8601String(),
            'updated_at' => $chat->updated_at?->toIso8601String(),
        ];
    }

    private function messagePayload(ChatMessage $message): array
    {
        return [
            'id' => $message->id,
            'chat_id' => $message->chat_id,
            'assistant_id' => $message->assistant_id,
            'sender_type' => $message->sender_type,
            'direction' => $message->direction,
            'status' => $message->status,
            'channel_message_id' => $message->channel_message_id,
            'message_type' => $message->message_type,
            'text' => $message->text,
            'media_url' => $message->media_url,
            'media_mime_type' => $message->media_mime_type,
            'media_size' => $message->media_size,
            'link_url' => $message->link_url,
            'attachments' => $message->attachments,
            'payload' => $message->payload,
            'sent_at' => $message->sent_at?->toIso8601String(),
            'delivered_at' => $message->delivered_at?->toIso8601String(),
            'read_at' => $message->read_at?->toIso8601String(),
            'failed_at' => $message->failed_at?->toIso8601String(),
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }

    private function buildMessagePreview(ChatMessage $message): string
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

    private function updateChatSnapshotFromMessage(
        Chat $chat,
        ChatMessage $message,
        bool $incrementUnread
    ): void {
        $chat->last_message_preview = $this->buildMessagePreview($message);
        $chat->last_message_at = $message->sent_at ?? now();

        if ($incrementUnread) {
            $chat->unread_count = max((int) $chat->unread_count, 0) + 1;
        }

        $chat->save();
    }

    private function nullableTrimmedString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }

    private function storeChatFile(UploadedFile $file): array
    {
        $path = $file->store('chat-files', 'public');
        $size = $file->getSize();

        return [
            'name' => trim($file->getClientOriginalName()) !== ''
                ? $file->getClientOriginalName()
                : $file->hashName(),
            'url' => Storage::disk('public')->url($path),
            'mime_type' => $file->getMimeType() ?: $file->getClientMimeType() ?: null,
            'size' => is_int($size) ? $size : null,
            'storage_path' => $path,
            'absolute_path' => Storage::disk('public')->path($path),
        ];
    }

    private function resolveMessageTypeForFile(?string $mimeType): string
    {
        $normalized = trim((string) ($mimeType ?? ''));

        if ($normalized === '') {
            return ChatMessage::TYPE_FILE;
        }

        if (str_starts_with($normalized, 'image/')) {
            return ChatMessage::TYPE_IMAGE;
        }

        if (str_starts_with($normalized, 'video/')) {
            return ChatMessage::TYPE_VIDEO;
        }

        if (str_starts_with($normalized, 'audio/')) {
            return ChatMessage::TYPE_AUDIO;
        }

        return ChatMessage::TYPE_FILE;
    }

    private function shouldDispatchInstagramOutbound(Chat $chat, string $direction): bool
    {
        if ($chat->channel !== AssistantChannel::CHANNEL_INSTAGRAM) {
            return false;
        }

        return $direction === ChatMessage::DIRECTION_OUTBOUND;
    }

    private function shouldDispatchTelegramOutbound(Chat $chat, string $direction): bool
    {
        if ($chat->channel !== AssistantChannel::CHANNEL_TELEGRAM) {
            return false;
        }

        return $direction === ChatMessage::DIRECTION_OUTBOUND;
    }

    private function dispatchOutboundInstagramMessage(
        Company $company,
        Chat $chat,
        string $messageType,
        ?string $text,
        ?string $mediaUrl,
        ?string $linkUrl,
    ): array {
        $context = $this->resolveInstagramDispatchContext($company, $chat);

        if (! is_array($context)) {
            return [null, 'Instagram integration is not configured for this chat.'];
        }

        /** @var InstagramIntegration $integration */
        $integration = $context['integration'];
        $igUserId = (string) $context['ig_user_id'];
        $recipientId = (string) $context['recipient_id'];

        $accessToken = trim((string) $integration->access_token);
        if ($accessToken === '') {
            return [null, 'Instagram access token is missing. Reconnect integration.'];
        }

        if (in_array($messageType, [
            ChatMessage::TYPE_IMAGE,
            ChatMessage::TYPE_VIDEO,
            ChatMessage::TYPE_VOICE,
            ChatMessage::TYPE_AUDIO,
            ChatMessage::TYPE_FILE,
        ], true)) {
            $externalMediaUrl = $this->resolveExternalMediaUrl($mediaUrl);

            if ($externalMediaUrl === null) {
                return [null, 'Instagram media URL is invalid or not publicly accessible.'];
            }

            $instagramType = $this->instagramAttachmentTypeForMessageType($messageType);
            $messageId = InstagramFacade::sendMediaMessage(
                $igUserId,
                $recipientId,
                $instagramType,
                $externalMediaUrl,
                false,
                $accessToken,
                false,
            );

            if (! is_string($messageId) || trim($messageId) === '') {
                return [null, 'Instagram media message delivery failed.'];
            }

            return [trim($messageId), null];
        }

        $textPayload = $text ?? $linkUrl;
        $textPayload = $this->nullableTrimmedString($textPayload);

        if ($textPayload === null) {
            return [null, 'Instagram text message is empty.'];
        }

        $messageId = InstagramFacade::sendTextMessage(
            $igUserId,
            $recipientId,
            $textPayload,
            $accessToken,
            false,
        );

        if (! is_string($messageId) || trim($messageId) === '') {
            return [null, 'Instagram text message delivery failed.'];
        }

        return [trim($messageId), null];
    }

    private function resolveInstagramDispatchContext(Company $company, Chat $chat): ?array
    {
        if ($chat->channel !== AssistantChannel::CHANNEL_INSTAGRAM) {
            return null;
        }

        $assistantChannel = $chat->assistant_channel_id
            ? AssistantChannel::query()->whereKey($chat->assistant_channel_id)->first()
            : null;

        if (
            $assistantChannel
            && (
                ! $assistantChannel->is_active
                || $assistantChannel->channel !== AssistantChannel::CHANNEL_INSTAGRAM
            )
        ) {
            return null;
        }

        $metadata = is_array($chat->metadata) ? $chat->metadata : [];
        $instagramMeta = is_array($metadata['instagram'] ?? null)
            ? $metadata['instagram']
            : [];

        $integrationId = (int) ($instagramMeta['integration_id'] ?? 0);
        $businessAccountId = $this->resolveInstagramBusinessAccountId($chat, $instagramMeta);
        $customerAccountId = $this->resolveInstagramCustomerAccountId($chat);

        if ($businessAccountId === '' || $customerAccountId === '') {
            return null;
        }

        $integration = null;

        if ($integrationId > 0) {
            $integration = InstagramIntegration::query()
                ->where('user_id', $company->user_id)
                ->whereKey($integrationId)
                ->first();
        }

        if (! $integration) {
            $integration = InstagramIntegration::query()
                ->where('user_id', $company->user_id)
                ->where(function ($builder) use ($businessAccountId): void {
                    $builder
                        ->where('receiver_id', $businessAccountId)
                        ->orWhere('instagram_user_id', $businessAccountId);
                })
                ->orderByDesc('id')
                ->first();
        }

        if (! $integration || ! (bool) $integration->is_active) {
            return null;
        }

        $integration = $this->instagramTokenService()->ensureTokenIsFresh(
            $integration,
            $this->instagramTokenRefreshGraceSeconds(),
        );

        $igUserId = trim((string) ($integration->receiver_id ?: $businessAccountId));

        if ($igUserId === '') {
            return null;
        }

        return [
            'integration' => $integration,
            'ig_user_id' => $igUserId,
            'recipient_id' => $customerAccountId,
        ];
    }

    private function dispatchOutboundTelegramMessage(
        Company $company,
        Chat $chat,
        string $messageType,
        ?string $text,
        ?string $mediaUrl,
        ?string $linkUrl,
    ): array {
        $context = $this->resolveTelegramDispatchContext($company, $chat);

        if (! is_array($context)) {
            return [null, 'Telegram integration is not configured for this chat.'];
        }

        $botToken = (string) $context['bot_token'];
        $chatId = (string) $context['chat_id'];

        if (in_array($messageType, [
            ChatMessage::TYPE_IMAGE,
            ChatMessage::TYPE_VIDEO,
            ChatMessage::TYPE_VOICE,
            ChatMessage::TYPE_AUDIO,
            ChatMessage::TYPE_FILE,
        ], true)) {
            $externalMediaUrl = $this->resolveExternalMediaUrl($mediaUrl);

            if ($externalMediaUrl === null) {
                return [null, 'Telegram media URL is invalid or not publicly accessible.'];
            }

            $telegramMethod = $this->telegramMethodForMessageType($messageType);
            $caption = $this->nullableTrimmedString($text);

            try {
                $messageId = $this->telegramBotApiService()->sendMediaMessage(
                    $botToken,
                    $chatId,
                    $telegramMethod,
                    $externalMediaUrl,
                    $caption,
                );
            } catch (Throwable $exception) {
                return [null, $exception->getMessage() !== ''
                    ? $exception->getMessage()
                    : 'Telegram media message delivery failed.'];
            }

            if (! is_string($messageId) || trim($messageId) === '') {
                return [null, 'Telegram media message delivery failed.'];
            }

            return [trim($messageId), null];
        }

        $textPayload = $text ?? $linkUrl;
        $textPayload = $this->nullableTrimmedString($textPayload);

        if ($textPayload === null) {
            return [null, 'Telegram text message is empty.'];
        }

        try {
            $messageId = $this->telegramBotApiService()->sendTextMessage(
                $botToken,
                $chatId,
                $textPayload,
            );
        } catch (Throwable $exception) {
            return [null, $exception->getMessage() !== ''
                ? $exception->getMessage()
                : 'Telegram text message delivery failed.'];
        }

        if (! is_string($messageId) || trim($messageId) === '') {
            return [null, 'Telegram text message delivery failed.'];
        }

        return [trim($messageId), null];
    }

    private function resolveTelegramDispatchContext(Company $company, Chat $chat): ?array
    {
        if ($chat->channel !== AssistantChannel::CHANNEL_TELEGRAM) {
            return null;
        }

        $assistantChannel = $chat->assistant_channel_id
            ? AssistantChannel::query()->whereKey($chat->assistant_channel_id)->first()
            : AssistantChannel::query()
                ->where('company_id', $company->id)
                ->where('assistant_id', $chat->assistant_id)
                ->where('channel', AssistantChannel::CHANNEL_TELEGRAM)
                ->orderByDesc('is_active')
                ->orderBy('id')
                ->first();

        if (
            ! $assistantChannel
            || ! $assistantChannel->is_active
            || $assistantChannel->channel !== AssistantChannel::CHANNEL_TELEGRAM
        ) {
            return null;
        }

        $credentials = is_array($assistantChannel->credentials) ? $assistantChannel->credentials : [];
        $botToken = trim((string) ($credentials['bot_token'] ?? ''));

        if ($botToken === '') {
            return null;
        }

        $chatId = trim((string) ($chat->channel_chat_id ?? ''));
        if ($chatId === '') {
            $chatId = trim((string) ($chat->channel_user_id ?? ''));
        }

        if ($chatId === '') {
            return null;
        }

        return [
            'assistant_channel' => $assistantChannel,
            'bot_token' => $botToken,
            'chat_id' => $chatId,
        ];
    }

    private function resolveInstagramBusinessAccountId(Chat $chat, array $instagramMeta): string
    {
        $receiverId = trim((string) ($instagramMeta['receiver_id'] ?? ''));
        if ($receiverId !== '') {
            return $receiverId;
        }

        $channelChatId = trim((string) ($chat->channel_chat_id ?? ''));
        if ($channelChatId !== '' && str_contains($channelChatId, ':')) {
            [$businessId] = explode(':', $channelChatId, 2);
            $businessId = trim($businessId);

            if ($businessId !== '') {
                return $businessId;
            }
        }

        return trim((string) ($instagramMeta['instagram_user_id'] ?? ''));
    }

    private function resolveInstagramCustomerAccountId(Chat $chat): string
    {
        $channelUserId = trim((string) ($chat->channel_user_id ?? ''));
        if ($channelUserId !== '') {
            return $channelUserId;
        }

        $channelChatId = trim((string) ($chat->channel_chat_id ?? ''));
        if ($channelChatId !== '' && str_contains($channelChatId, ':')) {
            [, $customerId] = explode(':', $channelChatId, 2);

            return trim((string) $customerId);
        }

        return '';
    }

    private function telegramMethodForMessageType(string $messageType): string
    {
        return match ($messageType) {
            ChatMessage::TYPE_IMAGE => 'sendPhoto',
            ChatMessage::TYPE_VIDEO => 'sendVideo',
            ChatMessage::TYPE_AUDIO => 'sendAudio',
            ChatMessage::TYPE_VOICE => 'sendVoice',
            default => 'sendDocument',
        };
    }

    private function instagramAttachmentTypeForMessageType(string $messageType): string
    {
        return match ($messageType) {
            ChatMessage::TYPE_IMAGE => 'image',
            ChatMessage::TYPE_VIDEO => 'video',
            ChatMessage::TYPE_AUDIO, ChatMessage::TYPE_VOICE => 'audio',
            default => 'file',
        };
    }

    private function resolveExternalMediaUrl(?string $mediaUrl): ?string
    {
        $normalized = trim((string) ($mediaUrl ?? ''));

        if ($normalized === '') {
            return null;
        }

        if (Str::startsWith($normalized, ['http://', 'https://'])) {
            return $normalized;
        }

        $path = Str::startsWith($normalized, '/') ? $normalized : '/'.ltrim($normalized, '/');

        return url($path);
    }

    private function instagramTokenRefreshGraceSeconds(): int
    {
        return max((int) config('meta.instagram.token_refresh_grace_seconds', 900), 0);
    }

    private function telegramBotApiService(): TelegramBotApiService
    {
        return app(TelegramBotApiService::class);
    }

    private function shouldAutoReplyToAssistantChat(
        Chat $chat,
        ?AssistantModel $assistant,
        bool $hasActiveSubscription,
        bool $hasRemainingIncludedChats,
    ): bool {
        if ($chat->channel !== 'assistant') {
            return false;
        }

        if (! $assistant || ! $assistant->is_active) {
            return false;
        }

        if (! $hasActiveSubscription || ! $hasRemainingIncludedChats) {
            return false;
        }

        if (! $this->isChatActiveForAutoReply($chat)) {
            return false;
        }

        return $this->openAiAssistantService()->isConfigured();
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

        $this->subscriptionService()->synchronizeBillingPeriods($subscription);
        $subscription->refresh()->load('plan');

        if (! $subscription->isActiveAt()) {
            return [false, false];
        }

        $includedChats = max($subscription->resolvedIncludedChats(), 0);
        $usedChats = max((int) $subscription->chat_count_current_period, 0);

        return [true, $usedChats < $includedChats];
    }

    private function sendIncomingMessageToOpenAiThread(
        string $threadId,
        AssistantModel $assistant,
        Chat $chat,
        string $prompt,
        ?ChatMessage $incomingMessage = null,
        ?string $uploadedAbsolutePath = null,
    ): ?string {
        $metadata = [
            'chat_id' => (string) $chat->id,
            'assistant_id' => (string) $assistant->id,
        ];

        if (! $incomingMessage) {
            return $this->openAiClient()->sendTextMessage(
                $threadId,
                $prompt,
                $metadata,
                'user',
            );
        }

        $type = (string) $incomingMessage->message_type;
        $fileId = $this->uploadIncomingMessageFileToOpenAi($incomingMessage, $uploadedAbsolutePath);

        if ($type === ChatMessage::TYPE_IMAGE) {
            if (is_string($fileId) && trim($fileId) !== '') {
                $messageId = $this->openAiClient()->sendImageFileMessage(
                    $threadId,
                    [$fileId],
                    $prompt,
                    'auto',
                    $metadata,
                    'user',
                );

                if (is_string($messageId) && trim($messageId) !== '') {
                    return $messageId;
                }
            }

            $mediaUrl = trim((string) ($incomingMessage->media_url ?? ''));

            if ($mediaUrl !== '') {
                $messageId = $this->openAiClient()->sendImageUrlMessage(
                    $threadId,
                    [$mediaUrl],
                    $prompt,
                    'auto',
                    $metadata,
                    'user',
                );

                if (is_string($messageId) && trim($messageId) !== '') {
                    return $messageId;
                }
            }
        }

        if (in_array($type, [
            ChatMessage::TYPE_FILE,
            ChatMessage::TYPE_VIDEO,
            ChatMessage::TYPE_AUDIO,
            ChatMessage::TYPE_VOICE,
        ], true) && is_string($fileId) && trim($fileId) !== '') {
            $toolTypes = $this->resolveToolTypesForUploadedMessage($assistant);

            if ($toolTypes !== []) {
                $messageId = $this->openAiClient()->sendFileMessage(
                    $threadId,
                    [$fileId],
                    $prompt,
                    $toolTypes,
                    $metadata,
                    'user',
                );

                if (is_string($messageId) && trim($messageId) !== '') {
                    return $messageId;
                }
            }
        }

        return $this->openAiClient()->sendTextMessage(
            $threadId,
            $prompt,
            $metadata,
            'user',
        );
    }

    private function resolveToolTypesForUploadedMessage(AssistantModel $assistant): array
    {
        $toolTypes = [];

        if ($assistant->enable_file_search) {
            $toolTypes[] = 'file_search';
        }

        if ($assistant->enable_file_analysis) {
            $toolTypes[] = 'code_interpreter';
        }

        return $toolTypes;
    }

    private function uploadIncomingMessageFileToOpenAi(
        ChatMessage $incomingMessage,
        ?string $uploadedAbsolutePath = null,
    ): ?string {
        $absolutePath = $this->resolveIncomingMessageAbsoluteFilePath(
            $incomingMessage,
            $uploadedAbsolutePath,
        );

        if ($absolutePath === null) {
            return null;
        }

        $fileId = $this->openAiClient()->uploadFile($absolutePath, 'assistants');

        return is_string($fileId) && trim($fileId) !== '' ? trim($fileId) : null;
    }

    private function resolveIncomingMessageAbsoluteFilePath(
        ChatMessage $incomingMessage,
        ?string $uploadedAbsolutePath = null,
    ): ?string {
        $candidate = $this->normalizeAbsoluteFilePath($uploadedAbsolutePath);

        if ($candidate !== null) {
            return $candidate;
        }

        $attachments = is_array($incomingMessage->attachments) ? $incomingMessage->attachments : [];

        foreach ($attachments as $attachment) {
            if (! is_array($attachment)) {
                continue;
            }

            $storagePath = trim((string) ($attachment['storage_path'] ?? ''));

            if ($storagePath !== '' && Storage::disk('public')->exists($storagePath)) {
                return $this->normalizeAbsoluteFilePath(Storage::disk('public')->path($storagePath));
            }
        }

        $mediaUrl = trim((string) ($incomingMessage->media_url ?? ''));

        if ($mediaUrl === '') {
            return null;
        }

        $storageMarker = '/storage/';
        $position = strpos($mediaUrl, $storageMarker);

        if ($position === false) {
            return null;
        }

        $relativePath = ltrim(substr($mediaUrl, $position + strlen($storageMarker)), '/');

        if ($relativePath === '' || ! Storage::disk('public')->exists($relativePath)) {
            return null;
        }

        return $this->normalizeAbsoluteFilePath(Storage::disk('public')->path($relativePath));
    }

    private function normalizeAbsoluteFilePath(?string $path): ?string
    {
        $normalized = trim((string) ($path ?? ''));

        if ($normalized === '' || ! is_file($normalized) || ! is_readable($normalized)) {
            return null;
        }

        return $normalized;
    }

    private function buildPromptFromChatMessage(ChatMessage $message): string
    {
        $lines = [
            'Incoming customer message from assistant test chat:',
        ];

        $type = (string) $message->message_type;
        $text = trim((string) ($message->text ?? ''));
        $mediaUrl = trim((string) ($message->media_url ?? ''));
        $linkUrl = trim((string) ($message->link_url ?? ''));

        if ($text !== '') {
            $lines[] = '- Text: '.$text;
        }

        if ($type === ChatMessage::TYPE_IMAGE) {
            $lines[] = '- Customer sent an image.';
            if ($mediaUrl !== '') {
                $lines[] = '- Image URL: '.$mediaUrl;
            }
        } elseif ($type === ChatMessage::TYPE_VIDEO) {
            $lines[] = '- Customer sent a video file.';
            if ($mediaUrl !== '') {
                $lines[] = '- Video URL: '.$mediaUrl;
            }
        } elseif ($type === ChatMessage::TYPE_AUDIO || $type === ChatMessage::TYPE_VOICE) {
            $lines[] = '- Customer sent an audio/voice file.';
            if ($mediaUrl !== '') {
                $lines[] = '- Audio URL: '.$mediaUrl;
            }
        } elseif ($type === ChatMessage::TYPE_FILE) {
            $lines[] = '- Customer sent a file/document.';
            if ($mediaUrl !== '') {
                $lines[] = '- File URL: '.$mediaUrl;
            }
        } elseif ($type === ChatMessage::TYPE_LINK) {
            $lines[] = '- Customer sent a link: '.($linkUrl !== '' ? $linkUrl : $text);
        }

        $lines[] = 'Analyze attached content when available and answer in customer language.';

        return implode("\n", $lines);
    }

    private function subscriptionService(): CompanySubscriptionService
    {
        return app(CompanySubscriptionService::class);
    }

    private function openAiAssistantService(): OpenAiAssistantService
    {
        return app(OpenAiAssistantService::class);
    }

    private function openAiClient(): OpenAiAssistantClient
    {
        return app(OpenAiAssistantClient::class);
    }

    private function instagramTokenService(): InstagramTokenService
    {
        return app(InstagramTokenService::class);
    }
}
