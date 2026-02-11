<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Assistant as AssistantModel;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Company;
use App\Models\User;
use App\Services\CompanySubscriptionService;
use App\Services\OpenAiAssistantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
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

        $message = ChatMessage::query()->create([
            'user_id' => $company->user_id,
            'company_id' => $company->id,
            'chat_id' => $chat->id,
            'assistant_id' => $assistant?->id ?? $chat->assistant_id,
            'sender_type' => $senderType,
            'direction' => $direction,
            'status' => $direction === ChatMessage::DIRECTION_OUTBOUND ? 'sent' : 'received',
            'message_type' => $messageType,
            'text' => $normalizedText ?? ($filePayload['name'] ?? null),
            'media_url' => $filePayload['url'] ?? $this->nullableTrimmedString($validated['media_url'] ?? null),
            'media_mime_type' => $filePayload['mime_type'] ?? null,
            'media_size' => $filePayload['size'] ?? null,
            'link_url' => $this->nullableTrimmedString($validated['link_url'] ?? null),
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
            $threadId = (string) ($this->openAiClient()->createThread([], [
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

        $messageId = $this->sendIncomingMessageToOpenAiThread(
            $threadId,
            $assistant,
            $chat,
            $prompt,
            $incomingMessage,
            $uploadedAbsolutePath,
        );

        if (! is_string($messageId) || trim($messageId) === '') {
            return $this->fallbackAssistantText($assistant, $prompt);
        }

        $responseText = $this->openAiClient()->runThreadAndGetResponse(
            $threadId,
            $openAiAssistantId,
            [],
            20,
            900
        );

        $normalized = trim((string) ($responseText ?? ''));

        return $normalized !== ''
            ? $normalized
            : $this->fallbackAssistantText($assistant, $prompt);
    }

    private function fallbackAssistantText(AssistantModel $assistant, string $prompt): string
    {
        $safePrompt = Str::limit(trim($prompt), 220, '...');

        return "[$assistant->name] Received: {$safePrompt}";
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
}
