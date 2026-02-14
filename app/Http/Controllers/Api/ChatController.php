<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Assistant;
use App\Models\AssistantChannel;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Company;
use App\Models\CompanyClient;
use App\Models\CompanyCalendarEvent;
use App\Models\CompanyClientOrder;
use App\Models\CompanyClientQuestion;
use App\Models\CompanyClientTask;
use App\Models\User;
use App\Services\CompanySubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class ChatController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'channel' => ['nullable', 'string', 'max:32'],
            'assistant_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:160'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $user = $this->resolveUser($request);
        $company = $this->resolveCompany($user);
        $this->subscriptionService()->syncAssistantAccess($company);
        $this->ensureAssistantTestChats($company);

        $channelFilter = $this->normalizeFilterChannel(
            (string) ($validated['channel'] ?? 'all')
        );

        if ($channelFilter === null) {
            return response()->json([
                'message' => 'Unsupported channel filter.',
            ], 422);
        }

        $assistantId = isset($validated['assistant_id']) ? (int) $validated['assistant_id'] : null;

        if (
            $assistantId !== null
            && ! $company->assistants()->whereKey($assistantId)->exists()
        ) {
            return response()->json([
                'message' => 'Assistant filter is invalid for this company.',
            ], 422);
        }

        $search = trim((string) ($validated['search'] ?? ''));
        $limit = (int) ($validated['limit'] ?? 60);

        $query = $company->chats()
            ->with([
                'assistant:id,name,is_active',
                'assistantChannel:id,name,channel',
            ]);

        if ($channelFilter === 'assistant') {
            $query->whereNotNull('assistant_id');
        } elseif ($channelFilter !== 'all') {
            $query->where('channel', $channelFilter);
        }

        if ($assistantId !== null) {
            $query->where('assistant_id', $assistantId);
        }

        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('name', 'like', '%'.$search.'%')
                    ->orWhere('last_message_preview', 'like', '%'.$search.'%')
                    ->orWhere('channel_chat_id', 'like', '%'.$search.'%');
            });
        }

        $chats = $query
            ->orderByDesc('last_message_at')
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

        $assistants = $company->assistants()
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'is_active'])
            ->map(fn (Assistant $assistant): array => [
                'id' => $assistant->id,
                'name' => $assistant->name,
                'is_active' => (bool) $assistant->is_active,
            ])
            ->values();

        return response()->json([
            'chats' => $chats
                ->map(fn (Chat $chat): array => $this->chatPayload($chat))
                ->values(),
            'assistants' => $assistants,
            'channel_counts' => $this->channelCountsPayload($company),
            'subscription' => $this->subscriptionPayload($company),
        ]);
    }

    public function show(Request $request, int $chatId): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $user = $this->resolveUser($request);
        $company = $this->resolveCompany($user);
        $this->subscriptionService()->syncAssistantAccess($company);
        $chat = $this->resolveChat($company, $chatId);
        $limit = (int) ($validated['limit'] ?? 80);

        $messages = $chat->messages()
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->sortBy('id')
            ->values();

        $assistants = $company->assistants()
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'is_active'])
            ->map(fn (Assistant $assistant): array => [
                'id' => $assistant->id,
                'name' => $assistant->name,
                'is_active' => (bool) $assistant->is_active,
            ])
            ->values();

        return response()->json([
            'chat' => $this->chatPayload($chat->refresh()),
            'messages' => $messages
                ->map(fn (ChatMessage $message): array => $this->messagePayload($message))
                ->values(),
            'assistants' => $assistants,
            'subscription' => $this->subscriptionPayload($company),
        ]);
    }

    public function insights(Request $request, int $chatId): JsonResponse
    {
        $user = $this->resolveUser($request);
        $company = $this->resolveCompany($user);
        $chat = $this->resolveChat($company, $chatId);
        $client = $this->resolveLinkedClient($company, $chat);

        return response()->json([
            'chat' => $this->chatPayload($chat),
            'client' => $client
                ? [
                    'id' => $client->id,
                    'name' => $client->name,
                    'status' => $client->status,
                ]
                : null,
            'contacts' => $this->chatContactsPayload($chat, $client),
            'history' => $this->chatHistoryPayload($company, $chat, $client),
        ]);
    }

    public function createTask(Request $request, int $chatId): JsonResponse
    {
        $validated = $request->validate([
            'description' => ['nullable', 'string', 'max:2000'],
            'chat_message_id' => ['nullable', 'integer'],
            'priority' => ['nullable', 'string', 'in:low,normal,high,urgent'],
        ]);

        $user = $this->resolveUser($request);
        $company = $this->resolveCompany($user);
        $chat = $this->resolveChat($company, $chatId);
        $chatMessageId = isset($validated['chat_message_id'])
            ? (int) $validated['chat_message_id']
            : null;

        if (
            $chatMessageId !== null
            && ! $chat->messages()->whereKey($chatMessageId)->exists()
        ) {
            return response()->json([
                'message' => 'chat_message_id is invalid for this chat.',
            ], 422);
        }

        $client = $this->resolveOrCreateClientForChat($company, $chat);

        $description = trim((string) ($validated['description'] ?? ''));
        if ($description === '') {
            $preview = trim((string) ($chat->last_message_preview ?? ''));
            $base = 'Lead from chat #'.$chat->id;
            $description = $preview !== '' ? $base.': '.$preview : $base;
        }

        $task = CompanyClientTask::query()->create([
            'user_id' => $company->user_id,
            'company_id' => $company->id,
            'company_client_id' => $client->id,
            'assistant_id' => $chat->assistant_id,
            'description' => Str::limit($description, 2000, ''),
            'status' => CompanyClientTask::STATUS_TODO,
            'board_column' => 'todo',
            'position' => 0,
            'priority' => (string) ($validated['priority'] ?? CompanyClientTask::PRIORITY_NORMAL),
            'sync_with_calendar' => true,
            'metadata' => array_filter([
                'source' => 'chat_info_panel',
                'chat_id' => $chat->id,
                'chat_message_id' => $chatMessageId,
            ], static fn ($value): bool => $value !== null),
        ]);

        return response()->json([
            'message' => 'Task created successfully.',
            'task' => $this->taskHistoryItemPayload($task),
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
                'status' => $client->status,
            ],
        ], 201);
    }

    public function createOrder(Request $request, int $chatId): JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:32'],
            'service_name' => ['required', 'string', 'max:160'],
            'address' => ['required', 'string', 'max:255'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:2000'],
            'chat_message_id' => ['nullable', 'integer'],
            'book_appointment' => ['nullable', 'boolean'],
            'appointment_date' => ['nullable', 'date_format:Y-m-d'],
            'appointment_time' => ['nullable', 'date_format:H:i'],
            'appointment_duration_minutes' => ['nullable', 'integer', 'min:15', 'max:720'],
        ]);

        $user = $this->resolveUser($request);
        $company = $this->resolveCompany($user);
        $chat = $this->resolveChat($company, $chatId);
        $chatMessageId = isset($validated['chat_message_id'])
            ? (int) $validated['chat_message_id']
            : null;

        if (
            $chatMessageId !== null
            && ! $chat->messages()->whereKey($chatMessageId)->exists()
        ) {
            return response()->json([
                'message' => 'chat_message_id is invalid for this chat.',
            ], 422);
        }

        $phoneInput = trim((string) ($validated['phone'] ?? ''));
        $serviceNameInput = trim((string) ($validated['service_name'] ?? ''));
        $addressInput = trim((string) ($validated['address'] ?? ''));

        if ($phoneInput === '' || $serviceNameInput === '' || $addressInput === '') {
            return response()->json([
                'message' => 'Phone, service_name and address are required.',
            ], 422);
        }

        $client = $this->resolveOrCreateClientForChat($company, $chat);
        $normalizedPhone = $this->normalizeClientPhone($phoneInput, $chat);

        if ((string) $client->phone !== $normalizedPhone) {
            $phoneBusy = $company->clients()
                ->where('phone', $normalizedPhone)
                ->whereKeyNot($client->id)
                ->exists();

            if ($phoneBusy) {
                return response()->json([
                    'message' => 'Phone is already linked to another client.',
                ], 422);
            }
        }

        $clientMetadata = is_array($client->metadata) ? $client->metadata : [];
        $clientMetadata['address'] = $addressInput;

        $client->forceFill([
            'phone' => $normalizedPhone,
            'metadata' => $clientMetadata,
        ])->save();

        $amount = isset($validated['amount'])
            ? round((float) $validated['amount'], 2)
            : 0.0;
        $note = trim((string) ($validated['note'] ?? ''));

        $bookAppointment = (bool) ($validated['book_appointment'] ?? false);
        $appointmentDate = trim((string) ($validated['appointment_date'] ?? ''));
        $appointmentTime = trim((string) ($validated['appointment_time'] ?? ''));
        $appointmentDurationMinutes = isset($validated['appointment_duration_minutes'])
            ? (int) $validated['appointment_duration_minutes']
            : null;

        if ($bookAppointment) {
            if (! $this->appointmentsEnabledForCompany($company)) {
                return response()->json([
                    'message' => 'Appointments are disabled for this company.',
                ], 422);
            }

            if ($appointmentDate === '' || $appointmentTime === '' || $appointmentDurationMinutes === null) {
                return response()->json([
                    'message' => 'appointment_date, appointment_time and appointment_duration_minutes are required when book_appointment is enabled.',
                ], 422);
            }
        }

        $calendarEvent = null;
        if ($bookAppointment) {
            $timezone = $this->companyTimezone($company);
            $startsAtLocal = Carbon::createFromFormat(
                'Y-m-d H:i',
                "{$appointmentDate} {$appointmentTime}",
                $timezone
            );
            $endsAtLocal = (clone $startsAtLocal)->addMinutes($appointmentDurationMinutes ?? 0);

            $startsAt = $startsAtLocal->copy()->utc();
            $endsAt = $endsAtLocal->copy()->utc();

            $calendarEvent = CompanyCalendarEvent::query()->create([
                'user_id' => $company->user_id,
                'company_id' => $company->id,
                'company_client_id' => $client->id,
                'assistant_id' => $chat->assistant_id,
                'title' => 'Appointment: '.Str::limit($serviceNameInput, 120, ''),
                'description' => $note !== '' ? Str::limit($note, 2000, '') : null,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'timezone' => $timezone,
                'status' => CompanyCalendarEvent::STATUS_SCHEDULED,
                'location' => Str::limit($addressInput, 255, ''),
                'metadata' => array_filter([
                    'source' => 'chat_order_booking',
                    'chat_id' => $chat->id,
                    'chat_message_id' => $chatMessageId,
                    'phone' => $normalizedPhone,
                ], static fn ($value): bool => $value !== null),
            ]);
        }

        $orderMetadata = array_filter([
            'source' => 'chat_info_panel',
            'chat_id' => $chat->id,
            'chat_message_id' => $chatMessageId,
            'address' => $addressInput,
            'phone' => $normalizedPhone,
        ], static fn ($value): bool => $value !== null);

        if ($calendarEvent) {
            $orderMetadata['appointment'] = [
                'calendar_event_id' => $calendarEvent->id,
                'starts_at' => $calendarEvent->starts_at?->toIso8601String(),
                'ends_at' => $calendarEvent->ends_at?->toIso8601String(),
                'timezone' => $calendarEvent->timezone,
                'duration_minutes' => $appointmentDurationMinutes,
            ];
        }

        $order = CompanyClientOrder::query()->create([
            'user_id' => $company->user_id,
            'company_id' => $company->id,
            'company_client_id' => $client->id,
            'assistant_id' => $chat->assistant_id,
            'service_name' => Str::limit($serviceNameInput, 160, ''),
            'quantity' => 1,
            'unit_price' => $amount,
            'total_price' => $amount,
            'currency' => 'TJS',
            'ordered_at' => now(),
            'notes' => $note !== '' ? Str::limit($note, 2000, '') : null,
            'metadata' => $orderMetadata,
        ]);

        if ($calendarEvent) {
            $calendarMetadata = is_array($calendarEvent->metadata) ? $calendarEvent->metadata : [];
            $calendarMetadata['order_id'] = $order->id;

            $calendarEvent->forceFill([
                'metadata' => $calendarMetadata,
            ])->save();
        }

        return response()->json([
            'message' => 'Order created successfully.',
            'order' => $this->orderHistoryItemPayload($order),
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
                'status' => $client->status,
            ],
            'contacts' => $this->chatContactsPayload($chat, $client),
        ], 201);
    }

    public function updateAiEnabled(Request $request, int $chatId): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $user = $this->resolveUser($request);
        $company = $this->resolveCompany($user);
        $chat = $this->resolveChat($company, $chatId);

        $metadata = is_array($chat->metadata) ? $chat->metadata : [];
        $metadata['is_active'] = (bool) $validated['enabled'];
        $chat->forceFill([
            'metadata' => $metadata,
        ])->save();

        return response()->json([
            'message' => 'Chat AI state updated successfully.',
            'chat' => $this->chatPayload($chat->refresh()),
        ]);
    }

    public function markAsRead(Request $request, int $chatId): JsonResponse
    {
        $user = $this->resolveUser($request);
        $company = $this->resolveCompany($user);
        $chat = $this->resolveChat($company, $chatId);

        $now = now();

        $chat->messages()
            ->where('direction', ChatMessage::DIRECTION_INBOUND)
            ->whereNull('read_at')
            ->update([
                'read_at' => $now,
                'status' => 'read',
            ]);

        $chat->forceFill([
            'unread_count' => 0,
        ])->save();

        return response()->json([
            'message' => 'Chat marked as read.',
            'chat' => $this->chatPayload($chat->refresh()),
        ]);
    }

    public function webhook(Request $request, string $channel): JsonResponse
    {
        if (! $this->isWebhookAuthorized($request)) {
            return response()->json([
                'message' => 'Invalid webhook token.',
            ], 401);
        }

        $normalizedChannel = $this->normalizeWebhookChannel($channel);

        if ($normalizedChannel === null) {
            return response()->json([
                'message' => 'Unsupported webhook channel.',
            ], 422);
        }

        $request->merge($this->extractWebhookPayload($request));

        $validated = $request->validate([
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'assistant_id' => ['nullable', 'integer', 'exists:assistants,id'],
            'assistant_channel_id' => ['nullable', 'integer', 'exists:assistant_channels,id'],
            'channel_chat_id' => ['nullable', 'string', 'max:191'],
            'channel_user_id' => ['nullable', 'string', 'max:191'],
            'name' => ['nullable', 'string', 'max:160'],
            'avatar' => ['nullable', 'string', 'max:2048'],
            'status' => ['nullable', 'string', 'in:open,pending,closed,archived'],
            'sender_type' => [
                'nullable',
                'string',
                'in:customer,assistant,agent,system',
            ],
            'direction' => ['nullable', 'string', 'in:inbound,outbound'],
            'channel_message_id' => ['nullable', 'string', 'max:191'],
            'message_type' => ['nullable', 'string', 'in:text,image,video,voice,audio,link,file'],
            'text' => ['nullable', 'string', 'max:20000'],
            'media_url' => ['nullable', 'string', 'max:2048'],
            'media_mime_type' => ['nullable', 'string', 'max:191'],
            'media_size' => ['nullable', 'integer', 'min:0'],
            'link_url' => ['nullable', 'string', 'max:2048'],
            'attachments' => ['nullable', 'array'],
            'metadata' => ['nullable', 'array'],
            'payload' => ['nullable', 'array'],
            'status_message' => ['nullable', 'string', 'max:32'],
            'sent_at' => ['nullable', 'date'],
        ]);

        [$company, $assistant, $companyError] = $this->resolveCompanyFromWebhook(
            $validated['company_id'] ?? null,
            $validated['assistant_id'] ?? null
        );

        if ($companyError !== null) {
            return response()->json([
                'message' => $companyError,
            ], 422);
        }

        $assistantChannel = null;
        $assistantChannelId = isset($validated['assistant_channel_id'])
            ? (int) $validated['assistant_channel_id']
            : null;

        if ($assistantChannelId !== null) {
            $assistantChannel = AssistantChannel::query()->find($assistantChannelId);

            if (! $assistantChannel || $assistantChannel->company_id !== $company->id) {
                return response()->json([
                    'message' => 'Assistant channel does not belong to this company.',
                ], 422);
            }

            if ($assistant === null && $assistantChannel->assistant_id) {
                $assistant = Assistant::query()->find($assistantChannel->assistant_id);
            }

            if (
                $assistant !== null
                && $assistantChannel->assistant_id
                && (int) $assistantChannel->assistant_id !== (int) $assistant->id
            ) {
                return response()->json([
                    'message' => 'Assistant channel and assistant mismatch.',
                ], 422);
            }
        }

        $channelChatId = $this->nullableTrimmedString($validated['channel_chat_id'] ?? null)
            ?? $this->nullableTrimmedString($validated['channel_user_id'] ?? null);

        if ($channelChatId === null) {
            $channelChatId = 'auto-'.substr(sha1(implode('|', [
                (string) $company->id,
                $normalizedChannel,
                (string) ($validated['channel_message_id'] ?? ''),
                (string) ($validated['name'] ?? ''),
                (string) ($validated['text'] ?? ''),
            ])), 0, 40);
        }

        $chat = Chat::query()->firstOrNew([
            'company_id' => $company->id,
            'channel' => $normalizedChannel,
            'channel_chat_id' => $channelChatId,
        ]);

        $chat->user_id = $company->user_id;
        $chat->company_id = $company->id;
        $chat->channel = $normalizedChannel;
        $chat->channel_chat_id = $channelChatId;
        $chat->channel_user_id = $this->nullableTrimmedString($validated['channel_user_id'] ?? null);
        $chat->assistant_id = $assistant?->id ?? $chat->assistant_id;
        $chat->assistant_channel_id = $assistantChannel?->id ?? $chat->assistant_channel_id;

        $name = $this->nullableTrimmedString($validated['name'] ?? null);
        if ($name !== null) {
            $chat->name = $name;
        }

        $avatar = $this->nullableTrimmedString($validated['avatar'] ?? null);
        if ($avatar !== null) {
            $chat->avatar = $avatar;
        }

        if (isset($validated['status'])) {
            $chat->status = (string) $validated['status'];
        } elseif (! $chat->exists || ! $chat->status) {
            $chat->status = Chat::STATUS_OPEN;
        }

        $incomingMetadata = is_array($validated['metadata'] ?? null)
            ? $validated['metadata']
            : [];
        if ($incomingMetadata !== []) {
            $chat->metadata = array_merge(
                is_array($chat->metadata) ? $chat->metadata : [],
                $incomingMetadata
            );
        }

        $chat->save();

        $channelMessageId = $this->nullableTrimmedString($validated['channel_message_id'] ?? null);
        $existingMessage = null;

        if ($channelMessageId !== null) {
            $existingMessage = ChatMessage::query()
                ->where('chat_id', $chat->id)
                ->where('channel_message_id', $channelMessageId)
                ->first();
        }

        if ($existingMessage) {
            return response()->json([
                'message' => 'Webhook accepted. Message already exists.',
                'chat' => $this->chatPayload($chat->refresh()),
                'chat_message' => $this->messagePayload($existingMessage),
                'duplicate' => true,
            ]);
        }

        $message = ChatMessage::query()->create([
            'user_id' => $company->user_id,
            'company_id' => $company->id,
            'chat_id' => $chat->id,
            'assistant_id' => $assistant?->id ?? $chat->assistant_id,
            'sender_type' => (string) ($validated['sender_type'] ?? ChatMessage::SENDER_CUSTOMER),
            'direction' => (string) ($validated['direction'] ?? ChatMessage::DIRECTION_INBOUND),
            'status' => (string) ($validated['status_message'] ?? 'received'),
            'channel_message_id' => $channelMessageId,
            'message_type' => (string) ($validated['message_type'] ?? ChatMessage::TYPE_TEXT),
            'text' => $this->nullableTrimmedString($validated['text'] ?? null),
            'media_url' => $this->nullableTrimmedString($validated['media_url'] ?? null),
            'media_mime_type' => $this->nullableTrimmedString($validated['media_mime_type'] ?? null),
            'media_size' => isset($validated['media_size']) ? (int) $validated['media_size'] : null,
            'link_url' => $this->nullableTrimmedString($validated['link_url'] ?? null),
            'attachments' => is_array($validated['attachments'] ?? null) ? $validated['attachments'] : null,
            'payload' => is_array($validated['payload'] ?? null) ? $validated['payload'] : null,
            'sent_at' => isset($validated['sent_at']) ? Carbon::parse((string) $validated['sent_at']) : now(),
        ]);

        $isCustomerInbound = $message->direction === ChatMessage::DIRECTION_INBOUND
            && $message->sender_type === ChatMessage::SENDER_CUSTOMER;

        $this->updateChatSnapshotFromMessage($chat, $message, $isCustomerInbound);

        if ($isCustomerInbound) {
            $this->subscriptionService()->incrementChatUsage($company, 1);
        }

        return response()->json([
            'message' => 'Webhook accepted.',
            'chat' => $this->chatPayload($chat->refresh()),
            'chat_message' => $this->messagePayload($message),
            'duplicate' => false,
        ], 201);
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

    private function subscriptionPayload(Company $company): array
    {
        $subscription = $company->subscription()->with('plan')->first();

        if (! $subscription) {
            return [
                'has_active_subscription' => false,
                'status' => null,
            ];
        }

        $this->subscriptionService()->synchronizeBillingPeriods($subscription);
        $subscription->refresh()->load('plan');

        return [
            'has_active_subscription' => $subscription->isActiveAt(),
            'status' => $subscription->status,
        ];
    }

    private function ensureAssistantTestChats(Company $company): void
    {
        $company->chats()
            ->where('channel', 'assistant')
            ->whereNull('assistant_id')
            ->delete();

        $assistants = $company->assistants()
            ->orderBy('id')
            ->get(['id', 'name']);

        if ($assistants->isEmpty()) {
            return;
        }

        foreach ($assistants as $assistant) {
            $hasAnyChatsForAssistant = $company->chats()
                ->where('assistant_id', $assistant->id)
                ->exists();

            if ($hasAnyChatsForAssistant) {
                continue;
            }

            $chat = Chat::query()->firstOrNew([
                'company_id' => $company->id,
                'channel' => 'assistant',
                'channel_chat_id' => 'assistant-test-'.$assistant->id,
            ]);

            $metadata = is_array($chat->metadata) ? $chat->metadata : [];
            $metadata['is_test_chat'] = true;
            $metadata['assistant_test_chat'] = true;
            $metadata['assistant_id'] = (int) $assistant->id;

            $chat->user_id = $company->user_id;
            $chat->assistant_id = (int) $assistant->id;
            $chat->assistant_channel_id = null;
            $chat->channel_user_id = 'assistant-'.$assistant->id;
            $chat->name = trim((string) $assistant->name) !== ''
                ? trim((string) $assistant->name)
                : 'Assistant #'.$assistant->id;
            $chat->status = $chat->status ?: Chat::STATUS_OPEN;
            $chat->metadata = $metadata;

            if (! $chat->exists) {
                $chat->last_message_preview = 'Assistant test chat is ready.';
                $chat->last_message_at = now();
            }

            $chat->save();
        }
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

    private function normalizeFilterChannel(string $channel): ?string
    {
        $normalized = Str::lower(trim($channel));

        return match ($normalized) {
            '', 'all' => 'all',
            'instagram' => 'instagram',
            'telegram' => 'telegram',
            'widget', 'web-widget', 'web_widget', 'webchat' => 'widget',
            'api' => 'api',
            'assistant' => 'assistant',
            default => null,
        };
    }

    private function normalizeWebhookChannel(string $channel): ?string
    {
        $normalized = Str::lower(trim($channel));
        $allowedChannels = (array) config('chats.allowed_webhook_channels', []);

        $mapped = match ($normalized) {
            'instagram' => 'instagram',
            'telegram' => 'telegram',
            'widget', 'web-widget', 'web_widget', 'webchat' => 'widget',
            'api' => 'api',
            default => null,
        };

        if ($mapped === null) {
            return null;
        }

        return in_array($mapped, $allowedChannels, true) ? $mapped : null;
    }

    private function channelCountsPayload(Company $company): array
    {
        $baseQuery = $company->chats();

        return [
            'all' => (clone $baseQuery)->count(),
            'instagram' => (clone $baseQuery)->where('channel', 'instagram')->count(),
            'telegram' => (clone $baseQuery)->where('channel', 'telegram')->count(),
            'widget' => (clone $baseQuery)->where('channel', 'widget')->count(),
            'api' => (clone $baseQuery)->where('channel', 'api')->count(),
            'assistant' => (clone $baseQuery)->whereNotNull('assistant_id')->count(),
        ];
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

    private function resolveLinkedClient(Company $company, Chat $chat): ?CompanyClient
    {
        $metadata = is_array($chat->metadata) ? $chat->metadata : [];

        $clientId = (int) ($metadata['company_client_id'] ?? 0);
        if ($clientId > 0) {
            $client = $company->clients()->whereKey($clientId)->first();

            if ($client) {
                return $client;
            }
        }

        $channelUserId = trim((string) ($chat->channel_user_id ?? ''));
        if ($channelUserId !== '') {
            $client = $company->clients()
                ->where(function ($builder) use ($channelUserId): void {
                    $builder
                        ->where('phone', $channelUserId)
                        ->orWhere('email', $channelUserId);
                })
                ->first();

            if ($client) {
                return $client;
            }
        }

        $metadataEmail = $this->extractChatMetadataEmail($metadata);
        if ($metadataEmail !== null) {
            $client = $company->clients()
                ->where('email', $metadataEmail)
                ->first();

            if ($client) {
                return $client;
            }
        }

        return null;
    }

    private function resolveOrCreateClientForChat(Company $company, Chat $chat): CompanyClient
    {
        $existing = $this->resolveLinkedClient($company, $chat);

        if ($existing) {
            $this->bindChatToClient($chat, $existing);

            return $existing;
        }

        $metadata = is_array($chat->metadata) ? $chat->metadata : [];
        $email = $this->extractChatMetadataEmail($metadata);
        $phoneCandidate = $this->extractChatMetadataPhone($metadata)
            ?? $this->nullableTrimmedString($chat->channel_user_id);
        $phone = $this->normalizeClientPhone($phoneCandidate, $chat);
        $name = trim((string) ($chat->name ?? ''));

        if ($name === '') {
            $name = 'Client #'.$chat->id;
        }

        $client = CompanyClient::query()->create([
            'user_id' => $company->user_id,
            'company_id' => $company->id,
            'name' => Str::limit($name, 160, ''),
            'phone' => $phone,
            'email' => $email,
            'status' => CompanyClient::STATUS_ACTIVE,
            'metadata' => [
                'source_chat_id' => $chat->id,
                'source_channel' => $chat->channel,
                'source_channel_user_id' => $chat->channel_user_id,
            ],
        ]);

        $this->bindChatToClient($chat, $client);

        return $client;
    }

    private function bindChatToClient(Chat $chat, CompanyClient $client): void
    {
        $metadata = is_array($chat->metadata) ? $chat->metadata : [];
        $metadata['company_client_id'] = (int) $client->id;

        $chat->forceFill([
            'metadata' => $metadata,
        ])->save();
    }

    private function normalizeClientPhone(?string $phoneCandidate, Chat $chat): string
    {
        $phone = trim((string) ($phoneCandidate ?? ''));

        if ($phone === '') {
            return 'chat-'.$chat->id;
        }

        return Str::limit($phone, 32, '');
    }

    private function extractChatMetadataPhone(array $metadata): ?string
    {
        $candidates = [
            $metadata['phone'] ?? null,
            $metadata['client_phone'] ?? null,
            data_get($metadata, 'contact.phone'),
            data_get($metadata, 'contacts.phone'),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    private function extractChatMetadataEmail(array $metadata): ?string
    {
        $candidates = [
            $metadata['email'] ?? null,
            $metadata['client_email'] ?? null,
            data_get($metadata, 'contact.email'),
            data_get($metadata, 'contacts.email'),
        ];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            $normalized = trim($candidate);
            if ($normalized === '') {
                continue;
            }

            if (filter_var($normalized, FILTER_VALIDATE_EMAIL)) {
                return Str::limit($normalized, 191, '');
            }
        }

        return null;
    }

    private function chatContactsPayload(Chat $chat, ?CompanyClient $client): array
    {
        $metadata = is_array($chat->metadata) ? $chat->metadata : [];
        $items = [];

        $phone = $client?->phone ?? $this->extractChatMetadataPhone($metadata);
        if (is_string($phone) && trim($phone) !== '') {
            $items[] = [
                'key' => 'phone',
                'label' => 'Phone',
                'value' => trim($phone),
            ];
        }

        $email = $client?->email ?? $this->extractChatMetadataEmail($metadata);
        if (is_string($email) && trim($email) !== '') {
            $items[] = [
                'key' => 'email',
                'label' => 'Email',
                'value' => trim($email),
            ];
        }

        $address = $this->firstNonEmptyString([
            $client && is_array($client->metadata) ? ($client->metadata['address'] ?? null) : null,
            $metadata['address'] ?? null,
            data_get($metadata, 'contact.address'),
            data_get($metadata, 'contacts.address'),
        ]);
        if ($address !== null) {
            $items[] = [
                'key' => 'address',
                'label' => 'Address',
                'value' => $address,
            ];
        }

        return $items;
    }

    private function chatHistoryPayload(Company $company, Chat $chat, ?CompanyClient $client): array
    {
        $chatId = (int) $chat->id;
        $items = collect();

        $tasks = $company->clientTasks()
            ->when($client !== null, fn ($query) => $query->where('company_client_id', $client->id))
            ->orderByDesc('created_at')
            ->limit(120)
            ->get();

        $tasks
            ->filter(fn (CompanyClientTask $task): bool => $this->metadataHasChatLink($task->metadata, $chatId))
            ->each(function (CompanyClientTask $task) use ($items): void {
                $items->push($this->taskHistoryItemPayload($task));
            });

        $questions = $company->clientQuestions()
            ->when($client !== null, fn ($query) => $query->where('company_client_id', $client->id))
            ->orderByDesc('created_at')
            ->limit(120)
            ->get();

        $questions
            ->filter(fn (CompanyClientQuestion $question): bool => $this->metadataHasChatLink($question->metadata, $chatId))
            ->each(function (CompanyClientQuestion $question) use ($items): void {
                $items->push($this->questionHistoryItemPayload($question));
            });

        $orders = $company->clientOrders()
            ->when($client !== null, fn ($query) => $query->where('company_client_id', $client->id))
            ->orderByDesc('ordered_at')
            ->orderByDesc('created_at')
            ->limit(120)
            ->get();

        $orders
            ->filter(fn (CompanyClientOrder $order): bool => $this->metadataHasChatLink($order->metadata, $chatId))
            ->each(function (CompanyClientOrder $order) use ($items): void {
                $items->push($this->orderHistoryItemPayload($order));
            });

        return $items
            ->sortByDesc(fn (array $item) => (string) ($item['created_at'] ?? ''))
            ->values()
            ->all();
    }

    private function taskHistoryItemPayload(CompanyClientTask $task): array
    {
        return [
            'id' => 'task-'.$task->id,
            'record_id' => $task->id,
            'type' => 'task',
            'title' => Str::limit((string) $task->description, 220, '...'),
            'status' => $task->status,
            'created_at' => $task->created_at?->toIso8601String(),
            'chat_message_id' => $this->extractChatMessageIdFromMetadata($task->metadata),
        ];
    }

    private function questionHistoryItemPayload(CompanyClientQuestion $question): array
    {
        return [
            'id' => 'question-'.$question->id,
            'record_id' => $question->id,
            'type' => 'question',
            'title' => Str::limit((string) $question->description, 220, '...'),
            'status' => $question->status,
            'created_at' => $question->created_at?->toIso8601String(),
            'chat_message_id' => $this->extractChatMessageIdFromMetadata($question->metadata),
        ];
    }

    private function orderHistoryItemPayload(CompanyClientOrder $order): array
    {
        $title = trim((string) ($order->service_name ?? ''));
        if ($title === '') {
            $title = 'Order #'.$order->id;
        }

        return [
            'id' => 'order-'.$order->id,
            'record_id' => $order->id,
            'type' => 'order',
            'title' => Str::limit($title, 220, '...'),
            'status' => 'created',
            'created_at' => ($order->ordered_at ?? $order->created_at)?->toIso8601String(),
            'chat_message_id' => $this->extractChatMessageIdFromMetadata($order->metadata),
        ];
    }

    private function metadataHasChatLink(mixed $metadata, int $chatId): bool
    {
        if (! is_array($metadata)) {
            return false;
        }

        $candidates = [
            data_get($metadata, 'chat_id'),
            data_get($metadata, 'source_chat_id'),
            data_get($metadata, 'chat.id'),
            data_get($metadata, 'source.chat_id'),
        ];

        foreach ($candidates as $value) {
            if (is_numeric($value) && (int) $value === $chatId) {
                return true;
            }
        }

        return false;
    }

    private function extractChatMessageIdFromMetadata(mixed $metadata): ?int
    {
        if (! is_array($metadata)) {
            return null;
        }

        $candidates = [
            data_get($metadata, 'chat_message_id'),
            data_get($metadata, 'message_id'),
            data_get($metadata, 'chat.message_id'),
            data_get($metadata, 'source.chat_message_id'),
        ];

        foreach ($candidates as $value) {
            if (is_numeric($value)) {
                $normalized = (int) $value;

                if ($normalized > 0) {
                    return $normalized;
                }
            }
        }

        return null;
    }

    private function firstNonEmptyString(array $candidates): ?string
    {
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

    private function appointmentsEnabledForCompany(Company $company): bool
    {
        $settings = is_array($company->settings) ? $company->settings : [];
        $accountType = (string) data_get($settings, 'account_type', 'without_appointments');
        $appointmentEnabled = (bool) data_get($settings, 'appointment.enabled', false);

        return $accountType === 'with_appointments' && $appointmentEnabled;
    }

    private function companyTimezone(Company $company): string
    {
        $settings = is_array($company->settings) ? $company->settings : [];
        $timezone = data_get($settings, 'business.timezone');

        if (! is_string($timezone) || ! in_array($timezone, timezone_identifiers_list(), true)) {
            return (string) config('app.timezone', 'UTC');
        }

        return $timezone;
    }

    private function extractWebhookPayload(Request $request): array
    {
        $raw = $request->all();
        $chat = is_array($raw['chat'] ?? null) ? $raw['chat'] : [];
        $message = is_array($raw['message'] ?? null) ? $raw['message'] : [];
        $sender = is_array($raw['sender'] ?? null) ? $raw['sender'] : [];

        $attachments = null;
        if (is_array($raw['attachments'] ?? null)) {
            $attachments = $raw['attachments'];
        } elseif (is_array($message['attachments'] ?? null)) {
            $attachments = $message['attachments'];
        }

        $metadata = null;
        if (is_array($raw['metadata'] ?? null)) {
            $metadata = $raw['metadata'];
        } elseif (is_array($chat['metadata'] ?? null)) {
            $metadata = $chat['metadata'];
        }

        $payload = null;
        if (is_array($raw['payload'] ?? null)) {
            $payload = $raw['payload'];
        } elseif ($message !== []) {
            $payload = $message;
        }

        return [
            'company_id' => $raw['company_id'] ?? $chat['company_id'] ?? null,
            'assistant_id' => $raw['assistant_id'] ?? $chat['assistant_id'] ?? null,
            'assistant_channel_id' => $raw['assistant_channel_id'] ?? $chat['assistant_channel_id'] ?? null,
            'channel_chat_id' => $raw['channel_chat_id'] ?? $chat['id'] ?? $chat['chat_id'] ?? null,
            'channel_user_id' => $raw['channel_user_id'] ?? $chat['user_id'] ?? $sender['id'] ?? null,
            'name' => $raw['name'] ?? $chat['name'] ?? $sender['name'] ?? null,
            'avatar' => $raw['avatar'] ?? $chat['avatar'] ?? $sender['avatar'] ?? null,
            'status' => $raw['status'] ?? $chat['status'] ?? null,
            'sender_type' => $raw['sender_type'] ?? $message['sender_type'] ?? null,
            'direction' => $raw['direction'] ?? $message['direction'] ?? null,
            'channel_message_id' => $raw['channel_message_id'] ?? $message['id'] ?? null,
            'message_type' => $raw['message_type'] ?? $message['type'] ?? null,
            'text' => $raw['text'] ?? $message['text'] ?? null,
            'media_url' => $raw['media_url'] ?? $message['media_url'] ?? null,
            'media_mime_type' => $raw['media_mime_type'] ?? $message['media_mime_type'] ?? null,
            'media_size' => $raw['media_size'] ?? $message['media_size'] ?? null,
            'link_url' => $raw['link_url'] ?? $message['link_url'] ?? null,
            'attachments' => $attachments,
            'metadata' => $metadata,
            'payload' => $payload,
            'status_message' => $raw['status_message'] ?? $message['status'] ?? null,
            'sent_at' => $raw['sent_at'] ?? $message['sent_at'] ?? null,
        ];
    }

    private function resolveCompanyFromWebhook(
        mixed $companyId,
        mixed $assistantId
    ): array {
        $company = null;
        $assistant = null;

        if ($companyId !== null) {
            $company = Company::query()->find((int) $companyId);
        }

        if ($assistantId !== null) {
            $assistant = Assistant::query()->find((int) $assistantId);

            if (! $assistant) {
                return [null, null, 'Assistant was not found.'];
            }

            if ($company !== null && (int) $assistant->company_id !== (int) $company->id) {
                return [null, null, 'Assistant does not belong to this company.'];
            }

            if ($company === null) {
                $company = Company::query()->find((int) $assistant->company_id);
            }
        }

        if (! $company) {
            return [null, null, 'company_id or assistant_id is required.'];
        }

        return [$company, $assistant, null];
    }

    private function isWebhookAuthorized(Request $request): bool
    {
        $expectedToken = trim((string) config('chats.webhook_token', ''));

        if ($expectedToken === '') {
            return true;
        }

        $providedToken = trim((string) (
            $request->header('X-Webhook-Token')
            ?? $request->input('token')
            ?? ''
        ));

        return $providedToken !== '' && hash_equals($expectedToken, $providedToken);
    }

    private function nullableTrimmedString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }

    private function subscriptionService(): CompanySubscriptionService
    {
        return app(CompanySubscriptionService::class);
    }
}
