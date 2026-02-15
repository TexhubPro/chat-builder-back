<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Assistant;
use App\Models\AssistantChannel;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Company;
use App\Models\User;
use App\Services\AssistantCrmAutomationService;
use App\Services\CompanySubscriptionService;
use App\Services\OpenAiAssistantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use TexHub\OpenAi\Assistant as OpenAiAssistantClient;
use Throwable;

class WidgetChatController extends Controller
{
    public function options(Request $request, string $widgetKey, ?string $any = null): Response
    {
        unset($request, $widgetKey, $any);

        return $this->withPublicCors(response()->noContent());
    }

    public function config(Request $request, string $widgetKey): JsonResponse
    {
        $context = $this->resolveContextByWidgetKey($widgetKey);

        if (! $context) {
            return $this->publicJson([
                'message' => 'Widget channel not found.',
            ], 404);
        }

        /** @var AssistantChannel $assistantChannel */
        $assistantChannel = $context['assistant_channel'];
        /** @var Company $company */
        $company = $context['company'];
        /** @var Assistant|null $assistant */
        $assistant = $context['assistant'];
        $settings = $this->normalizeWidgetSettings(
            is_array($assistantChannel->settings) ? $assistantChannel->settings : [],
            $assistant,
            $company
        );

        return $this->publicJson([
            'widget' => [
                'key' => $widgetKey,
                'assistant_channel_id' => $assistantChannel->id,
                'assistant_id' => $assistant?->id,
                'company_id' => $company->id,
                'is_active' => (bool) $assistantChannel->is_active,
                'assistant_name' => $assistant?->name,
                'settings' => $settings,
            ],
        ]);
    }

    public function messages(Request $request, string $widgetKey): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'session_id' => ['required', 'string', 'max:191'],
            'after_id' => ['nullable', 'integer', 'min:0'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:120'],
        ]);

        if ($validator->fails()) {
            return $this->publicJson([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();
        $context = $this->resolveContextByWidgetKey($widgetKey);

        if (! $context) {
            return $this->publicJson([
                'message' => 'Widget channel not found.',
            ], 404);
        }

        /** @var AssistantChannel $assistantChannel */
        $assistantChannel = $context['assistant_channel'];
        /** @var Company $company */
        $company = $context['company'];
        /** @var Assistant|null $assistant */
        $assistant = $context['assistant'];
        $sessionId = trim((string) $validated['session_id']);
        $afterId = isset($validated['after_id']) ? (int) $validated['after_id'] : 0;
        $limit = isset($validated['limit']) ? (int) $validated['limit'] : 80;

        $chat = Chat::query()
            ->where('company_id', $company->id)
            ->where('channel', AssistantChannel::CHANNEL_WIDGET)
            ->where('channel_chat_id', $sessionId)
            ->first();

        if (! $chat) {
            return $this->publicJson([
                'chat' => null,
                'messages' => [],
                'widget' => [
                    'is_active' => (bool) $assistantChannel->is_active,
                    'assistant_id' => $assistant?->id,
                    'assistant_channel_id' => $assistantChannel->id,
                ],
            ]);
        }

        $messages = $chat->messages()
            ->when($afterId > 0, fn ($query) => $query->where('id', '>', $afterId))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        return $this->publicJson([
            'chat' => $this->chatPayload($chat),
            'messages' => $messages
                ->map(fn (ChatMessage $message): array => $this->messagePayload($message))
                ->values(),
            'widget' => [
                'is_active' => (bool) $assistantChannel->is_active,
                'assistant_id' => $assistant?->id,
                'assistant_channel_id' => $assistantChannel->id,
            ],
        ]);
    }

    public function storeMessage(Request $request, string $widgetKey): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'session_id' => ['required', 'string', 'max:191'],
            'text' => ['nullable', 'string', 'max:20000'],
            'file' => ['nullable', 'image', 'max:8192'],
            'visitor_name' => ['nullable', 'string', 'max:160'],
            'visitor_email' => ['nullable', 'email', 'max:191'],
            'visitor_phone' => ['nullable', 'string', 'max:64'],
            'page_url' => ['nullable', 'url', 'max:2048'],
            'client_message_id' => ['nullable', 'string', 'max:191'],
        ]);

        if ($validator->fails()) {
            return $this->publicJson([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();
        $context = $this->resolveContextByWidgetKey($widgetKey);

        if (! $context) {
            return $this->publicJson([
                'message' => 'Widget channel not found.',
            ], 404);
        }

        /** @var AssistantChannel $assistantChannel */
        $assistantChannel = $context['assistant_channel'];
        /** @var Company $company */
        $company = $context['company'];
        /** @var Assistant|null $assistant */
        $assistant = $context['assistant'];
        /** @var User $user */
        $user = $context['user'];

        if (! $assistantChannel->is_active) {
            return $this->publicJson([
                'message' => 'Widget channel is disabled.',
            ], 422);
        }

        $normalizedText = $this->nullableTrimmedString($validated['text'] ?? null);
        $uploadedFile = $request->file('file');

        if ($normalizedText === null && ! ($uploadedFile instanceof UploadedFile)) {
            return $this->publicJson([
                'message' => 'Message text or image is required.',
            ], 422);
        }

        $sessionId = trim((string) $validated['session_id']);
        $chat = $this->resolveOrCreateWidgetChat(
            $company,
            $assistant,
            $assistantChannel,
            $sessionId,
            $validated
        );

        $clientMessageId = $this->nullableTrimmedString($validated['client_message_id'] ?? null);
        if ($clientMessageId !== null) {
            $existing = ChatMessage::query()
                ->where('chat_id', $chat->id)
                ->where('channel_message_id', $clientMessageId)
                ->first();

            if ($existing) {
                return $this->publicJson([
                    'message' => 'Message already exists.',
                    'duplicate' => true,
                    'chat' => $this->chatPayload($chat->refresh()),
                    'chat_message' => $this->messagePayload($existing),
                    'assistant_message' => null,
                ]);
            }
        }

        $filePayload = $uploadedFile instanceof UploadedFile
            ? $this->storeWidgetImage($uploadedFile)
            : null;

        $linkUrl = $this->extractFirstUrl($normalizedText);
        $messageType = $filePayload !== null
            ? ChatMessage::TYPE_IMAGE
            : ($normalizedText !== null && $linkUrl !== null && $this->isOnlyUrl($normalizedText)
                ? ChatMessage::TYPE_LINK
                : ChatMessage::TYPE_TEXT);
        $channelMessageId = $clientMessageId ?? ('widget_'.Str::uuid()->toString());

        $attachments = [];
        if ($filePayload !== null) {
            $attachments[] = [
                'type' => 'uploaded_image',
                'name' => $filePayload['name'],
                'url' => $filePayload['url'],
                'mime_type' => $filePayload['mime_type'],
                'size' => $filePayload['size'],
                'storage_path' => $filePayload['storage_path'],
            ];
        }

        $message = ChatMessage::query()->create([
            'user_id' => $company->user_id,
            'company_id' => $company->id,
            'chat_id' => $chat->id,
            'assistant_id' => $assistant?->id ?? $chat->assistant_id,
            'sender_type' => ChatMessage::SENDER_CUSTOMER,
            'direction' => ChatMessage::DIRECTION_INBOUND,
            'status' => 'received',
            'channel_message_id' => $channelMessageId,
            'message_type' => $messageType,
            'text' => $normalizedText,
            'media_url' => $filePayload['url'] ?? null,
            'media_mime_type' => $filePayload['mime_type'] ?? null,
            'media_size' => $filePayload['size'] ?? null,
            'link_url' => $messageType === ChatMessage::TYPE_LINK ? $linkUrl : null,
            'attachments' => $attachments !== [] ? $attachments : null,
            'payload' => array_filter([
                'source' => 'widget_script',
                'page_url' => $this->nullableTrimmedString($validated['page_url'] ?? null),
                'client_message_id' => $clientMessageId,
            ], static fn (mixed $value): bool => $value !== null),
            'sent_at' => now(),
        ]);

        $this->updateChatSnapshotFromMessage($chat, $message, true);

        [$hasActiveSubscription, $hasRemainingIncludedChats] = $this->subscriptionStateForAutoReply($company);
        $this->subscriptionService()->incrementChatUsage($company, 1);

        $assistantMessage = null;
        $hasReplyableContent = $normalizedText !== null || $filePayload !== null;

        if ($this->shouldAutoReply(
            $chat,
            $assistantChannel,
            $assistant,
            $user,
            $hasActiveSubscription,
            $hasRemainingIncludedChats,
            $hasReplyableContent
        )) {
            if ($assistant && (int) ($chat->assistant_id ?? 0) !== (int) $assistant->id) {
                $chat->forceFill([
                    'assistant_id' => $assistant->id,
                ])->save();
            }

            $assistantPrompt = $this->buildPromptFromInboundMessage($message);
            $assistantResponse = $this->generateAssistantResponse(
                $company,
                $assistant,
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
                    'assistant_id' => $assistant?->id ?? $chat->assistant_id,
                    'sender_type' => ChatMessage::SENDER_ASSISTANT,
                    'direction' => ChatMessage::DIRECTION_OUTBOUND,
                    'status' => 'sent',
                    'message_type' => ChatMessage::TYPE_TEXT,
                    'text' => $assistantResponse,
                    'sent_at' => now(),
                ]);

                $this->updateChatSnapshotFromMessage($chat, $assistantMessage, false);
            }
        }

        return $this->publicJson([
            'message' => 'Message accepted.',
            'duplicate' => false,
            'chat' => $this->chatPayload($chat->refresh()),
            'chat_message' => $this->messagePayload($message),
            'assistant_message' => $assistantMessage ? $this->messagePayload($assistantMessage) : null,
        ], 201);
    }

    private function resolveContextByWidgetKey(string $widgetKey): ?array
    {
        $normalizedKey = trim($widgetKey);
        if ($normalizedKey === '') {
            return null;
        }

        $assistantChannel = $this->findWidgetChannelByKey($normalizedKey);
        if (! $assistantChannel) {
            return null;
        }

        $company = Company::query()->find((int) $assistantChannel->company_id);
        if (! $company) {
            return null;
        }

        $user = User::query()->find((int) $company->user_id);
        if (! $user) {
            return null;
        }

        $assistant = $assistantChannel->assistant_id
            ? Assistant::query()->find((int) $assistantChannel->assistant_id)
            : null;

        return [
            'assistant_channel' => $assistantChannel,
            'company' => $company,
            'assistant' => $assistant,
            'user' => $user,
        ];
    }

    private function findWidgetChannelByKey(string $widgetKey): ?AssistantChannel
    {
        $channel = AssistantChannel::query()
            ->where('channel', AssistantChannel::CHANNEL_WIDGET)
            ->where('credentials->widget_key', $widgetKey)
            ->first();

        if ($channel) {
            return $channel;
        }

        return AssistantChannel::query()
            ->where('channel', AssistantChannel::CHANNEL_WIDGET)
            ->get()
            ->first(function (AssistantChannel $candidate) use ($widgetKey): bool {
                $credentials = is_array($candidate->credentials) ? $candidate->credentials : [];
                return trim((string) ($credentials['widget_key'] ?? '')) === $widgetKey;
            });
    }

    private function normalizeWidgetSettings(
        array $settings,
        ?Assistant $assistant,
        Company $company,
    ): array {
        $assistantName = $this->nullableTrimmedString($assistant?->name) ?? 'Assistant';
        $companyName = $this->nullableTrimmedString($company->name) ?? 'Company';
        $position = trim((string) ($settings['position'] ?? ''));
        $theme = trim((string) ($settings['theme'] ?? ''));
        $color = $this->normalizeWidgetColor($settings['primary_color'] ?? null);

        return [
            'position' => in_array($position, ['bottom-right', 'bottom-left'], true)
                ? $position
                : 'bottom-right',
            'theme' => in_array($theme, ['light', 'dark'], true)
                ? $theme
                : 'light',
            'primary_color' => $color ?? '#1677FF',
            'title' => $this->nullableTrimmedString($settings['title'] ?? null)
                ?? ($companyName.' Chat'),
            'welcome_message' => $this->nullableTrimmedString($settings['welcome_message'] ?? null)
                ?? ('Здравствуйте! Я '.$assistantName.'. Напишите ваш вопрос.'),
            'placeholder' => $this->nullableTrimmedString($settings['placeholder'] ?? null)
                ?? 'Введите сообщение...',
            'launcher_label' => $this->nullableTrimmedString($settings['launcher_label'] ?? null)
                ?? 'Чат',
        ];
    }

    private function resolveOrCreateWidgetChat(
        Company $company,
        ?Assistant $assistant,
        AssistantChannel $assistantChannel,
        string $sessionId,
        array $validatedPayload,
    ): Chat {
        $chat = Chat::query()->firstOrNew([
            'company_id' => $company->id,
            'channel' => AssistantChannel::CHANNEL_WIDGET,
            'channel_chat_id' => $sessionId,
        ]);

        $existingMetadata = is_array($chat->metadata) ? $chat->metadata : [];
        $widgetMetadata = is_array($existingMetadata['widget'] ?? null)
            ? $existingMetadata['widget']
            : [];

        $visitorName = $this->nullableTrimmedString($validatedPayload['visitor_name'] ?? null);
        $visitorEmail = $this->nullableTrimmedString($validatedPayload['visitor_email'] ?? null);
        $visitorPhone = $this->nullableTrimmedString($validatedPayload['visitor_phone'] ?? null);
        $pageUrl = $this->nullableTrimmedString($validatedPayload['page_url'] ?? null);

        $widgetMetadata = array_replace($widgetMetadata, array_filter([
            'session_id' => $sessionId,
            'assistant_channel_id' => $assistantChannel->id,
            'visitor_name' => $visitorName,
            'visitor_email' => $visitorEmail,
            'visitor_phone' => $visitorPhone,
            'page_url' => $pageUrl,
            'last_seen_at' => now()->toIso8601String(),
        ], static fn (mixed $value): bool => $value !== null && $value !== ''));

        $displayName = $visitorName ?? $chat->name;
        if ($displayName === null || trim($displayName) === '') {
            $displayName = 'Website Visitor';
        }

        $chat->fill([
            'user_id' => $company->user_id,
            'assistant_id' => $assistant?->id ?? $chat->assistant_id,
            'assistant_channel_id' => $assistantChannel->id,
            'channel_user_id' => $sessionId,
            'name' => Str::limit($displayName, 160, ''),
            'status' => $chat->status ?: Chat::STATUS_OPEN,
            'metadata' => array_replace_recursive($existingMetadata, [
                'source' => 'widget_script',
                'widget' => $widgetMetadata,
            ]),
        ]);

        $chat->save();

        return $chat;
    }

    private function shouldAutoReply(
        Chat $chat,
        AssistantChannel $assistantChannel,
        ?Assistant $assistant,
        User $user,
        bool $hasActiveSubscription,
        bool $hasRemainingIncludedChats,
        bool $hasReplyableContent,
    ): bool {
        if (! (bool) config('chats.widget.auto_reply_enabled', true)) {
            return false;
        }

        if (! $hasReplyableContent) {
            return false;
        }

        if (! (bool) $user->status) {
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

    private function generateAssistantResponse(
        Company $company,
        ?Assistant $assistant,
        Chat $chat,
        string $prompt,
        ?ChatMessage $incomingMessage = null,
        ?string $uploadedAbsolutePath = null,
    ): string {
        if (! $assistant) {
            return '';
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

        $runtimePrompt = $this->assistantCrmAutomationService()->augmentPromptWithRuntimeContext(
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
            $threadId = $this->createAndPersistOpenAiThread($chat, $assistant, $chatMetadata, $threadMap, $threadKey);
            if ($threadId === null) {
                return $this->fallbackAssistantText($assistant, $prompt);
            }
        }

        $messageId = $this->sendIncomingMessageToOpenAiThread(
            $threadId,
            $assistant,
            $chat,
            $runtimePrompt,
            $incomingMessage,
            $uploadedAbsolutePath,
        );

        if (! is_string($messageId) || trim($messageId) === '') {
            return $this->fallbackAssistantText($assistant, $prompt);
        }

        try {
            $responseText = $this->openAiClient()->runThreadAndGetResponse(
                $threadId,
                $openAiAssistantId,
                [],
                20,
                900
            );
        } catch (Throwable) {
            return $this->fallbackAssistantText($assistant, $prompt);
        }

        $normalized = trim((string) ($responseText ?? ''));

        if ($normalized === '') {
            return $this->fallbackAssistantText($assistant, $prompt);
        }

        $normalized = $this->assistantCrmAutomationService()->applyActionsFromAssistantResponse(
            $company,
            $chat,
            $assistant,
            $normalized,
        );

        return trim($normalized) === ''
            ? $this->fallbackAssistantText($assistant, $prompt)
            : $normalized;
    }

    private function createAndPersistOpenAiThread(
        Chat $chat,
        Assistant $assistant,
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
        } catch (Throwable) {
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

    private function sendIncomingMessageToOpenAiThread(
        string $threadId,
        Assistant $assistant,
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

        $fileId = $this->uploadIncomingMessageFileToOpenAi($incomingMessage, $uploadedAbsolutePath);

        if ((string) $incomingMessage->message_type === ChatMessage::TYPE_IMAGE) {
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
            if ($mediaUrl !== '' && $this->canUseExternalImageUrlForOpenAi($mediaUrl)) {
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

        return $this->openAiClient()->sendTextMessage(
            $threadId,
            $prompt,
            $metadata,
            'user',
        );
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

        $fileId = $this->openAiClient()->uploadFile($absolutePath, 'vision');

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

        return null;
    }

    private function normalizeAbsoluteFilePath(?string $path): ?string
    {
        $normalized = trim((string) ($path ?? ''));

        if ($normalized === '' || ! is_file($normalized) || ! is_readable($normalized)) {
            return null;
        }

        return $normalized;
    }

    private function canUseExternalImageUrlForOpenAi(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($host === '') {
            return false;
        }

        if ($host === 'localhost' || $host === '::1') {
            return false;
        }

        if ($host === '0.0.0.0' || $host === '127.0.0.1' || str_starts_with($host, '127.')) {
            return false;
        }

        if (str_ends_with($host, '.local') || str_ends_with($host, '.test')) {
            return false;
        }

        return true;
    }

    private function buildPromptFromInboundMessage(ChatMessage $message): string
    {
        $lines = [
            'Incoming customer message from website widget chat:',
        ];

        $type = (string) $message->message_type;
        $text = trim((string) ($message->text ?? ''));

        if ($text !== '') {
            $lines[] = '- Text: '.$text;
        }

        if ($type === ChatMessage::TYPE_IMAGE) {
            $lines[] = '- Customer sent an image.';
        } elseif ($type === ChatMessage::TYPE_LINK) {
            $lines[] = '- Customer sent a link.';
        }

        $lines[] = 'Respond in the customer language.';

        return implode("\n", $lines);
    }

    private function fallbackAssistantText(Assistant $assistant, string $prompt): string
    {
        $safePrompt = Str::limit(trim($prompt), 220, '...');

        return '['.$assistant->name.'] Received: '.$safePrompt;
    }

    private function storeWidgetImage(UploadedFile $file): array
    {
        $path = $file->store('chat-files/widget', 'public');
        $size = $file->getSize();

        return [
            'name' => trim((string) $file->getClientOriginalName()) !== ''
                ? $file->getClientOriginalName()
                : $file->hashName(),
            'url' => Storage::disk('public')->url($path),
            'mime_type' => $file->getMimeType() ?: $file->getClientMimeType() ?: 'image/jpeg',
            'size' => is_int($size) ? $size : null,
            'storage_path' => $path,
            'absolute_path' => Storage::disk('public')->path($path),
        ];
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

    private function buildMessagePreview(ChatMessage $message): string
    {
        $text = trim((string) ($message->text ?? ''));

        if ($text !== '') {
            return Str::limit($text, 160, '...');
        }

        return match ($message->message_type) {
            ChatMessage::TYPE_IMAGE => '[Image]',
            ChatMessage::TYPE_LINK => '[Link]',
            default => '[Message]',
        };
    }

    private function chatPayload(Chat $chat): array
    {
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
            'assistant_id' => $chat->assistant_id,
            'assistant_channel_id' => $chat->assistant_channel_id,
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
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }

    private function extractFirstUrl(?string $text): ?string
    {
        $normalized = trim((string) ($text ?? ''));

        if ($normalized === '') {
            return null;
        }

        preg_match('/https?:\/\/[^\s]+/ui', $normalized, $matches);
        $url = $matches[0] ?? null;

        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        return trim($url);
    }

    private function isOnlyUrl(string $text): bool
    {
        $url = $this->extractFirstUrl($text);

        if ($url === null) {
            return false;
        }

        return trim($text) === $url;
    }

    private function normalizeWidgetColor(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = strtoupper(trim($value));

        if (preg_match('/^#[0-9A-F]{6}$/', $normalized) === 1) {
            return $normalized;
        }

        return null;
    }

    private function nullableTrimmedString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }

    private function publicJson(array $payload, int $status = 200): JsonResponse
    {
        return $this->withPublicCors(response()->json($payload, $status));
    }

    private function withPublicCors(Response|JsonResponse $response): Response|JsonResponse
    {
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Access-Control-Allow-Methods', 'GET,POST,OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type,Accept,Origin');
        $response->headers->set('Access-Control-Max-Age', '86400');

        return $response;
    }

    private function subscriptionService(): CompanySubscriptionService
    {
        return app(CompanySubscriptionService::class);
    }

    private function openAiAssistantService(): OpenAiAssistantService
    {
        return app(OpenAiAssistantService::class);
    }

    private function assistantCrmAutomationService(): AssistantCrmAutomationService
    {
        return app(AssistantCrmAutomationService::class);
    }

    private function openAiClient(): OpenAiAssistantClient
    {
        return app(OpenAiAssistantClient::class);
    }
}
