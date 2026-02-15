<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AssistantChannel;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Company;
use App\Models\CompanyCalendarEvent;
use App\Models\CompanyClient;
use App\Models\CompanyClientOrder;
use App\Models\User;
use App\Services\CompanySubscriptionService;
use App\Services\InstagramTokenService;
use App\Services\TelegramBotApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use TexHub\Meta\Facades\Instagram as InstagramFacade;
use TexHub\Meta\Models\InstagramIntegration;
use Throwable;

class CompanyClientOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        $company = $this->resolveCompany($user);
        $appointmentsEnabled = $this->appointmentsEnabledForCompany($company);
        $deliveryEnabled = $this->deliveryEnabledForCompany($company);

        $orders = $company->clientOrders()
            ->with(['client:id,name,phone,email', 'assistant:id,name'])
            ->orderByDesc('ordered_at')
            ->orderByDesc('created_at')
            ->limit(300)
            ->get()
            ->reject(fn (CompanyClientOrder $order): bool => $this->isArchived($order->metadata))
            ->values();

        $chatIds = $orders
            ->map(fn (CompanyClientOrder $order): ?int => $this->extractSourceChatId($order->metadata))
            ->filter(static fn (?int $chatId): bool => $chatId !== null)
            ->values()
            ->unique()
            ->all();

        $chatsById = Chat::query()
            ->whereIn('id', $chatIds)
            ->get(['id', 'channel'])
            ->keyBy('id');

        return response()->json([
            'appointments_enabled' => $appointmentsEnabled,
            'delivery_enabled' => $deliveryEnabled,
            'requests' => $orders
                ->map(
                    fn (CompanyClientOrder $order): array => $this->orderPayload(
                        $order,
                        $appointmentsEnabled,
                        $deliveryEnabled,
                        $chatsById
                    )
                )
                ->values(),
        ]);
    }

    public function update(Request $request, int $orderId): JsonResponse
    {
        $validated = $request->validate([
            'status' => [
                'nullable',
                'string',
                Rule::in($this->allSupportedStatuses()),
            ],
            'client_name' => ['nullable', 'string', 'max:160'],
            'phone' => ['nullable', 'string', 'max:32'],
            'service_name' => ['nullable', 'string', 'max:160'],
            'address' => ['nullable', 'string', 'max:255'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:2000'],
            'book_appointment' => ['nullable', 'boolean'],
            'appointment_date' => ['nullable', 'date_format:Y-m-d'],
            'appointment_time' => ['nullable', 'date_format:H:i'],
            'appointment_duration_minutes' => ['nullable', 'integer', 'min:15', 'max:720'],
            'clear_appointment' => ['nullable', 'boolean'],
            'archived' => ['nullable', 'boolean'],
        ]);

        $user = $this->resolveUser($request);
        $company = $this->resolveCompany($user);
        $appointmentsEnabled = $this->appointmentsEnabledForCompany($company);
        $deliveryEnabled = $this->deliveryEnabledForCompany($company);
        $order = $this->resolveOrder($company, $orderId);
        $client = $order->client;
        $previousStatus = $this->normalizeStatus(
            (string) ($order->status ?? CompanyClientOrder::STATUS_NEW),
            $appointmentsEnabled,
            $deliveryEnabled
        );
        $statusChanged = false;

        if (! $client) {
            return response()->json([
                'message' => 'Order client was not found.',
            ], 422);
        }

        if (array_key_exists('status', $validated)) {
            $requestedStatus = strtolower(trim((string) $validated['status']));
            if (
                ! $deliveryEnabled
                && in_array($requestedStatus, $this->deliveryStatuses(), true)
            ) {
                return response()->json([
                    'message' => 'Delivery statuses are available only when delivery is enabled.',
                ], 422);
            }

            $status = $this->normalizeStatus(
                (string) $validated['status'],
                $appointmentsEnabled,
                $deliveryEnabled
            );

            if ($status === CompanyClientOrder::STATUS_APPOINTMENTS && ! $appointmentsEnabled) {
                return response()->json([
                    'message' => 'Appointments are disabled for this company.',
                ], 422);
            }

            $order->status = $status;
            $order->completed_at = $this->isTerminalStatus($status)
                ? ($order->completed_at ?? now())
                : null;
            $statusChanged = $previousStatus !== $status;
        }

        if (array_key_exists('service_name', $validated)) {
            $serviceName = trim((string) ($validated['service_name'] ?? ''));
            if ($serviceName === '') {
                return response()->json([
                    'message' => 'service_name cannot be empty.',
                ], 422);
            }

            $order->service_name = Str::limit($serviceName, 160, '');
        }

        if (array_key_exists('amount', $validated)) {
            $amount = round((float) $validated['amount'], 2);
            $order->unit_price = $amount;
            $order->total_price = $amount;
        }

        if (array_key_exists('note', $validated)) {
            $note = trim((string) ($validated['note'] ?? ''));
            $order->notes = $note !== '' ? Str::limit($note, 2000, '') : null;
        }

        $metadata = is_array($order->metadata) ? $order->metadata : [];

        if (array_key_exists('address', $validated)) {
            $address = trim((string) ($validated['address'] ?? ''));
            if ($address === '') {
                return response()->json([
                    'message' => 'address cannot be empty.',
                ], 422);
            }

            $metadata['address'] = Str::limit($address, 255, '');
        }

        if (array_key_exists('client_name', $validated)) {
            $clientName = trim((string) ($validated['client_name'] ?? ''));

            if ($clientName === '') {
                return response()->json([
                    'message' => 'client_name cannot be empty.',
                ], 422);
            }

            $client->name = Str::limit($clientName, 160, '');
        }

        if (array_key_exists('phone', $validated)) {
            $phone = trim((string) ($validated['phone'] ?? ''));

            if ($phone === '') {
                return response()->json([
                    'message' => 'phone cannot be empty.',
                ], 422);
            }

            $phoneBusy = $company->clients()
                ->where('phone', $phone)
                ->whereKeyNot($client->id)
                ->exists();

            if ($phoneBusy) {
                return response()->json([
                    'message' => 'Phone is already linked to another client.',
                ], 422);
            }

            $client->phone = Str::limit($phone, 32, '');
            $metadata['phone'] = $client->phone;
        }

        $clearAppointment = (bool) ($validated['clear_appointment'] ?? false);
        $hasAppointmentDate = array_key_exists('appointment_date', $validated);
        $hasAppointmentTime = array_key_exists('appointment_time', $validated);
        $hasAppointmentDuration = array_key_exists('appointment_duration_minutes', $validated);
        $hasAppointmentPayload = $hasAppointmentDate || $hasAppointmentTime || $hasAppointmentDuration;
        $bookAppointment = (bool) ($validated['book_appointment'] ?? false);

        if ($hasAppointmentPayload || $bookAppointment || $clearAppointment) {
            if (! $appointmentsEnabled) {
                return response()->json([
                    'message' => 'Appointments are disabled for this company.',
                ], 422);
            }
        }

        $appointmentData = is_array($metadata['appointment'] ?? null)
            ? $metadata['appointment']
            : null;
        $calendarEventId = $appointmentData && is_numeric($appointmentData['calendar_event_id'] ?? null)
            ? (int) $appointmentData['calendar_event_id']
            : null;
        $calendarEvent = $calendarEventId
            ? CompanyCalendarEvent::query()
                ->where('company_id', $company->id)
                ->whereKey($calendarEventId)
                ->first()
            : null;

        if ($clearAppointment) {
            if ($calendarEvent) {
                $calendarEvent->delete();
            }

            unset($metadata['appointment']);

            if (
                ! array_key_exists('status', $validated)
                && $order->status === CompanyClientOrder::STATUS_APPOINTMENTS
            ) {
                $order->status = CompanyClientOrder::STATUS_IN_PROGRESS;
            }
        }

        if ($bookAppointment || $hasAppointmentPayload) {
            $appointmentDate = trim((string) ($validated['appointment_date'] ?? ''));
            $appointmentTime = trim((string) ($validated['appointment_time'] ?? ''));
            $appointmentDurationMinutes = isset($validated['appointment_duration_minutes'])
                ? (int) $validated['appointment_duration_minutes']
                : null;

            if ($appointmentDate === '' || $appointmentTime === '' || $appointmentDurationMinutes === null) {
                return response()->json([
                    'message' => 'appointment_date, appointment_time and appointment_duration_minutes are required when booking is enabled.',
                ], 422);
            }

            $timezone = $this->companyTimezone($company);
            $startsAtLocal = Carbon::createFromFormat('Y-m-d H:i', "{$appointmentDate} {$appointmentTime}", $timezone);
            $endsAtLocal = (clone $startsAtLocal)->addMinutes($appointmentDurationMinutes);
            $startsAt = $startsAtLocal->copy()->utc();
            $endsAt = $endsAtLocal->copy()->utc();
            $location = trim((string) ($metadata['address'] ?? ''));

            if ($calendarEvent) {
                $calendarMetadata = is_array($calendarEvent->metadata) ? $calendarEvent->metadata : [];
                $calendarMetadata['order_id'] = $order->id;
                $calendarMetadata['source'] = 'client_requests_board';

                $calendarEvent->forceFill([
                    'title' => 'Appointment: '.Str::limit((string) $order->service_name, 120, ''),
                    'description' => $order->notes,
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                    'timezone' => $timezone,
                    'location' => $location !== '' ? Str::limit($location, 255, '') : null,
                    'status' => CompanyCalendarEvent::STATUS_SCHEDULED,
                    'metadata' => $calendarMetadata,
                ])->save();
            } else {
                $calendarEvent = CompanyCalendarEvent::query()->create([
                    'user_id' => $company->user_id,
                    'company_id' => $company->id,
                    'company_client_id' => $client->id,
                    'assistant_id' => $order->assistant_id,
                    'assistant_service_id' => $order->assistant_service_id,
                    'title' => 'Appointment: '.Str::limit((string) $order->service_name, 120, ''),
                    'description' => $order->notes,
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                    'timezone' => $timezone,
                    'status' => CompanyCalendarEvent::STATUS_SCHEDULED,
                    'location' => $location !== '' ? Str::limit($location, 255, '') : null,
                    'metadata' => [
                        'source' => 'client_requests_board',
                        'order_id' => $order->id,
                    ],
                ]);
            }

            $metadata['appointment'] = [
                'calendar_event_id' => $calendarEvent->id,
                'starts_at' => $calendarEvent->starts_at?->toIso8601String(),
                'ends_at' => $calendarEvent->ends_at?->toIso8601String(),
                'timezone' => $calendarEvent->timezone,
                'duration_minutes' => $appointmentDurationMinutes,
            ];

            if (! array_key_exists('status', $validated)) {
                $order->status = CompanyClientOrder::STATUS_APPOINTMENTS;
                $order->completed_at = null;
            }
        }

        if (array_key_exists('archived', $validated) && (bool) $validated['archived'] === true) {
            $metadata['archived'] = true;
        }

        $order->metadata = $metadata;
        $client->save();
        $order->save();

        if ($deliveryEnabled && $statusChanged) {
            $this->notifyClientAboutDeliveryStatusChange(
                $company,
                $order->fresh(['assistant:id,name']),
                $client->fresh()
            );
        }

        $appointmentsEnabled = $this->appointmentsEnabledForCompany($company->fresh());
        $deliveryEnabled = $this->deliveryEnabledForCompany($company->fresh());
        $payload = $this->orderPayload(
            $order->fresh(['client:id,name,phone,email', 'assistant:id,name']),
            $appointmentsEnabled,
            $deliveryEnabled,
            collect()
        );

        return response()->json([
            'message' => 'Client request updated successfully.',
            'request' => $payload,
        ]);
    }

    public function destroy(Request $request, int $orderId): JsonResponse
    {
        $user = $this->resolveUser($request);
        $company = $this->resolveCompany($user);
        $order = $this->resolveOrder($company, $orderId);

        $metadata = is_array($order->metadata) ? $order->metadata : [];
        $calendarEventId = data_get($metadata, 'appointment.calendar_event_id');

        if (is_numeric($calendarEventId)) {
            CompanyCalendarEvent::query()
                ->where('company_id', $company->id)
                ->whereKey((int) $calendarEventId)
                ->delete();
        }

        $order->delete();

        return response()->json([
            'message' => 'Client request deleted successfully.',
        ]);
    }

    private function resolveUser(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    private function resolveCompany(User $user): Company
    {
        return $this->subscriptionService()->provisionDefaultWorkspaceForUser($user->id, $user->name);
    }

    private function resolveOrder(Company $company, int $orderId): CompanyClientOrder
    {
        return $company->clientOrders()
            ->with(['client:id,name,phone,email', 'assistant:id,name'])
            ->whereKey($orderId)
            ->firstOrFail();
    }

    private function orderPayload(
        CompanyClientOrder $order,
        bool $appointmentsEnabled,
        bool $deliveryEnabled,
        Collection $chatsById
    ): array {
        $metadata = is_array($order->metadata) ? $order->metadata : [];
        $status = $this->normalizeStatus(
            (string) ($order->status ?? CompanyClientOrder::STATUS_NEW),
            $appointmentsEnabled,
            $deliveryEnabled
        );
        $appointment = $this->appointmentPayload($metadata);
        $sourceChatId = $this->extractSourceChatId($metadata);
        $sourceChat = $sourceChatId !== null ? $chatsById->get($sourceChatId) : null;
        $sourceChannel = is_object($sourceChat) && isset($sourceChat->channel)
            ? (string) $sourceChat->channel
            : '';

        return [
            'id' => $order->id,
            'client' => [
                'id' => $order->client?->id,
                'name' => (string) ($order->client?->name ?? 'Client'),
                'phone' => (string) ($order->client?->phone ?? ($metadata['phone'] ?? '')),
                'email' => $order->client?->email,
            ],
            'assistant' => $order->assistant
                ? [
                    'id' => $order->assistant->id,
                    'name' => $order->assistant->name,
                ]
                : null,
            'service_name' => (string) $order->service_name,
            'address' => (string) ($metadata['address'] ?? ''),
            'amount' => (float) $order->total_price,
            'currency' => (string) $order->currency,
            'note' => (string) ($order->notes ?? ''),
            'status' => $status,
            'board' => $this->resolveBoard(
                $status,
                $appointment !== null,
                $appointmentsEnabled,
                $deliveryEnabled
            ),
            'appointment' => $appointment,
            'source_chat_id' => $sourceChatId,
            'source_channel' => $sourceChannel !== '' ? $sourceChannel : null,
            'chat_message_id' => $this->extractChatMessageId($metadata),
            'ordered_at' => ($order->ordered_at ?? $order->created_at)?->toIso8601String(),
            'completed_at' => $order->completed_at?->toIso8601String(),
            'updated_at' => $order->updated_at?->toIso8601String(),
            'created_at' => $order->created_at?->toIso8601String(),
        ];
    }

    private function appointmentPayload(array $metadata): ?array
    {
        $appointment = is_array($metadata['appointment'] ?? null) ? $metadata['appointment'] : null;

        if (! $appointment) {
            return null;
        }

        $startsAt = is_string($appointment['starts_at'] ?? null) ? $appointment['starts_at'] : null;
        $endsAt = is_string($appointment['ends_at'] ?? null) ? $appointment['ends_at'] : null;
        $timezone = is_string($appointment['timezone'] ?? null) ? $appointment['timezone'] : null;
        $duration = is_numeric($appointment['duration_minutes'] ?? null)
            ? (int) $appointment['duration_minutes']
            : null;
        $eventId = is_numeric($appointment['calendar_event_id'] ?? null)
            ? (int) $appointment['calendar_event_id']
            : null;

        if ($startsAt === null || $timezone === null || $eventId === null) {
            return null;
        }

        return [
            'calendar_event_id' => $eventId,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'timezone' => $timezone,
            'duration_minutes' => $duration,
        ];
    }

    private function normalizeStatus(
        string $status,
        bool $appointmentsEnabled,
        bool $deliveryEnabled
    ): string
    {
        $normalized = strtolower(trim($status));

        if ($deliveryEnabled) {
            return match ($normalized) {
                CompanyClientOrder::STATUS_NEW => CompanyClientOrder::STATUS_NEW,
                CompanyClientOrder::STATUS_CONFIRMED => CompanyClientOrder::STATUS_CONFIRMED,
                CompanyClientOrder::STATUS_CANCELED => CompanyClientOrder::STATUS_CANCELED,
                CompanyClientOrder::STATUS_HANDED_TO_COURIER => CompanyClientOrder::STATUS_HANDED_TO_COURIER,
                CompanyClientOrder::STATUS_DELIVERED => CompanyClientOrder::STATUS_DELIVERED,
                CompanyClientOrder::STATUS_APPOINTMENTS => $appointmentsEnabled
                    ? CompanyClientOrder::STATUS_APPOINTMENTS
                    : CompanyClientOrder::STATUS_CONFIRMED,
                CompanyClientOrder::STATUS_COMPLETED => CompanyClientOrder::STATUS_DELIVERED,
                CompanyClientOrder::STATUS_IN_PROGRESS => CompanyClientOrder::STATUS_CONFIRMED,
                default => CompanyClientOrder::STATUS_NEW,
            };
        }

        return match ($normalized) {
            CompanyClientOrder::STATUS_NEW => CompanyClientOrder::STATUS_NEW,
            CompanyClientOrder::STATUS_IN_PROGRESS, CompanyClientOrder::STATUS_CONFIRMED, CompanyClientOrder::STATUS_HANDED_TO_COURIER => CompanyClientOrder::STATUS_IN_PROGRESS,
            CompanyClientOrder::STATUS_APPOINTMENTS => $appointmentsEnabled
                ? CompanyClientOrder::STATUS_APPOINTMENTS
                : CompanyClientOrder::STATUS_IN_PROGRESS,
            CompanyClientOrder::STATUS_COMPLETED, CompanyClientOrder::STATUS_DELIVERED, CompanyClientOrder::STATUS_CANCELED => CompanyClientOrder::STATUS_COMPLETED,
            default => CompanyClientOrder::STATUS_NEW,
        };
    }

    private function resolveBoard(
        string $status,
        bool $hasAppointment,
        bool $appointmentsEnabled,
        bool $deliveryEnabled
    ): string {
        if (
            $status === CompanyClientOrder::STATUS_COMPLETED
            || ($deliveryEnabled && in_array($status, [
                CompanyClientOrder::STATUS_DELIVERED,
                CompanyClientOrder::STATUS_CANCELED,
            ], true))
        ) {
            return 'completed';
        }

        if (
            $appointmentsEnabled
            && (
                $status === CompanyClientOrder::STATUS_APPOINTMENTS
                || $hasAppointment
            )
        ) {
            return 'appointments';
        }

        if (
            $status === CompanyClientOrder::STATUS_IN_PROGRESS
            || ($deliveryEnabled && in_array($status, [
                CompanyClientOrder::STATUS_CONFIRMED,
                CompanyClientOrder::STATUS_HANDED_TO_COURIER,
            ], true))
        ) {
            return 'in_progress';
        }

        return 'new';
    }

    private function isArchived(mixed $metadata): bool
    {
        if (! is_array($metadata)) {
            return false;
        }

        $archived = data_get($metadata, 'archived');

        return $archived === true
            || $archived === 1
            || $archived === '1';
    }

    private function extractSourceChatId(mixed $metadata): ?int
    {
        if (! is_array($metadata)) {
            return null;
        }

        $value = data_get($metadata, 'chat_id');

        if (! is_numeric($value)) {
            return null;
        }

        $chatId = (int) $value;

        return $chatId > 0 ? $chatId : null;
    }

    private function extractChatMessageId(mixed $metadata): ?int
    {
        if (! is_array($metadata)) {
            return null;
        }

        $value = data_get($metadata, 'chat_message_id');

        if (! is_numeric($value)) {
            return null;
        }

        $messageId = (int) $value;

        return $messageId > 0 ? $messageId : null;
    }

    private function appointmentsEnabledForCompany(Company $company): bool
    {
        $settings = is_array($company->settings) ? $company->settings : [];
        $accountType = (string) data_get($settings, 'account_type', 'without_appointments');
        $enabled = (bool) data_get($settings, 'appointment.enabled', false);

        return $accountType === 'with_appointments' && $enabled;
    }

    private function deliveryEnabledForCompany(Company $company): bool
    {
        $settings = is_array($company->settings) ? $company->settings : [];

        return (bool) data_get($settings, 'delivery.enabled', false);
    }

    /**
     * @return array<int, string>
     */
    private function allSupportedStatuses(): array
    {
        return array_values(array_unique(array_merge(
            [
                CompanyClientOrder::STATUS_NEW,
                CompanyClientOrder::STATUS_IN_PROGRESS,
                CompanyClientOrder::STATUS_APPOINTMENTS,
                CompanyClientOrder::STATUS_COMPLETED,
            ],
            $this->deliveryStatuses(),
        )));
    }

    /**
     * @return array<int, string>
     */
    private function deliveryStatuses(): array
    {
        return [
            CompanyClientOrder::STATUS_CONFIRMED,
            CompanyClientOrder::STATUS_CANCELED,
            CompanyClientOrder::STATUS_HANDED_TO_COURIER,
            CompanyClientOrder::STATUS_DELIVERED,
        ];
    }

    private function isTerminalStatus(string $status): bool
    {
        return in_array($status, [
            CompanyClientOrder::STATUS_COMPLETED,
            CompanyClientOrder::STATUS_CANCELED,
            CompanyClientOrder::STATUS_DELIVERED,
        ], true);
    }

    private function notifyClientAboutDeliveryStatusChange(
        Company $company,
        CompanyClientOrder $order,
        CompanyClient $client
    ): void {
        $status = trim((string) $order->status);
        if (! in_array($status, [
            CompanyClientOrder::STATUS_NEW,
            CompanyClientOrder::STATUS_CONFIRMED,
            CompanyClientOrder::STATUS_CANCELED,
            CompanyClientOrder::STATUS_HANDED_TO_COURIER,
            CompanyClientOrder::STATUS_DELIVERED,
        ], true)) {
            return;
        }

        $sourceChatId = $this->extractSourceChatId($order->metadata);
        if ($sourceChatId === null) {
            return;
        }

        $chat = Chat::query()
            ->where('company_id', $company->id)
            ->whereKey($sourceChatId)
            ->first();

        if (! $chat) {
            return;
        }

        $language = $this->resolveChatLanguage($company, $chat);
        $serviceName = trim((string) $order->service_name);
        $clientName = trim((string) $client->name);
        $messageText = $this->deliveryStatusNotificationText($status, $language, $serviceName, $clientName);

        if ($messageText === '') {
            return;
        }

        [$channelMessageId, $statusFlag] = $this->dispatchDeliveryStatusNotification($company, $chat, $messageText);

        $sentAt = now();
        $message = ChatMessage::query()->create([
            'user_id' => $company->user_id,
            'company_id' => $company->id,
            'chat_id' => $chat->id,
            'assistant_id' => $order->assistant_id,
            'sender_type' => ChatMessage::SENDER_SYSTEM,
            'direction' => ChatMessage::DIRECTION_OUTBOUND,
            'status' => $statusFlag,
            'channel_message_id' => $channelMessageId,
            'message_type' => ChatMessage::TYPE_TEXT,
            'text' => $messageText,
            'sent_at' => $sentAt,
            'failed_at' => $statusFlag === 'failed' ? $sentAt : null,
        ]);

        $chat->forceFill([
            'last_message_preview' => $this->buildMessagePreview($messageText),
            'last_message_at' => $message->sent_at ?? $sentAt,
        ])->save();
    }

    private function deliveryStatusNotificationText(
        string $status,
        string $language,
        string $serviceName,
        string $clientName = '',
    ): string {
        $namePrefix = $clientName !== ''
            ? ($language === 'en' ? "{$clientName}, " : "{$clientName}, ")
            : '';
        $service = $serviceName !== ''
            ? $serviceName
            : ($language === 'en' ? 'your order' : 'ваш заказ');

        return match ($status) {
            CompanyClientOrder::STATUS_NEW => $language === 'en'
                ? $namePrefix."we received {$service}. Status: new."
                : $namePrefix."мы получили {$service}. Статус: новый.",
            CompanyClientOrder::STATUS_CONFIRMED => $language === 'en'
                ? $namePrefix."{$service} has been confirmed."
                : $namePrefix."{$service} подтверждён.",
            CompanyClientOrder::STATUS_CANCELED => $language === 'en'
                ? $namePrefix."{$service} has been canceled."
                : $namePrefix."{$service} отменён.",
            CompanyClientOrder::STATUS_HANDED_TO_COURIER => $language === 'en'
                ? $namePrefix."{$service} has been handed to courier."
                : $namePrefix."{$service} передан курьеру.",
            CompanyClientOrder::STATUS_DELIVERED => $language === 'en'
                ? $namePrefix."{$service} has been delivered. Thank you!"
                : $namePrefix."{$service} доставлен. Спасибо!",
            default => '',
        };
    }

    private function resolveChatLanguage(Company $company, Chat $chat): string
    {
        $chatMetadata = is_array($chat->metadata) ? $chat->metadata : [];
        $metadataLanguage = strtolower(trim((string) (
            data_get($chatMetadata, 'customer_language')
            ?? data_get($chatMetadata, 'language')
            ?? data_get($chatMetadata, 'preferred_language')
            ?? ''
        )));

        if (in_array($metadataLanguage, ['ru', 'en'], true)) {
            return $metadataLanguage;
        }

        $latestInboundText = trim((string) (ChatMessage::query()
            ->where('chat_id', $chat->id)
            ->where('direction', ChatMessage::DIRECTION_INBOUND)
            ->where('sender_type', ChatMessage::SENDER_CUSTOMER)
            ->whereNotNull('text')
            ->orderByDesc('id')
            ->value('text') ?? ''));

        if ($latestInboundText !== '') {
            if (preg_match('/\p{Cyrillic}/u', $latestInboundText) === 1) {
                return 'ru';
            }

            if (preg_match('/[A-Za-z]/', $latestInboundText) === 1) {
                return 'en';
            }
        }

        $settings = is_array($company->settings) ? $company->settings : [];
        $allowedLanguages = data_get($settings, 'ai.response_languages');

        if (is_array($allowedLanguages)) {
            foreach ($allowedLanguages as $language) {
                $normalized = strtolower(trim((string) $language));
                if (in_array($normalized, ['ru', 'en'], true)) {
                    return $normalized;
                }
            }
        }

        return 'ru';
    }

    /**
     * @return array{0: string|null, 1: string}
     */
    private function dispatchDeliveryStatusNotification(
        Company $company,
        Chat $chat,
        string $text,
    ): array {
        if ($chat->channel === AssistantChannel::CHANNEL_TELEGRAM) {
            $context = $this->resolveTelegramDispatchContext($company, $chat);

            if (! is_array($context)) {
                return [null, 'failed'];
            }

            try {
                $messageId = $this->telegramBotApiService()->sendTextMessage(
                    (string) $context['bot_token'],
                    (string) $context['chat_id'],
                    $text,
                );
            } catch (Throwable) {
                return [null, 'failed'];
            }

            return [is_string($messageId) && trim($messageId) !== '' ? trim($messageId) : null, 'sent'];
        }

        if ($chat->channel === AssistantChannel::CHANNEL_INSTAGRAM) {
            $context = $this->resolveInstagramDispatchContext($company, $chat);

            if (! is_array($context)) {
                return [null, 'failed'];
            }

            /** @var InstagramIntegration $integration */
            $integration = $context['integration'];
            $igUserId = (string) $context['ig_user_id'];
            $recipientId = (string) $context['recipient_id'];
            $accessToken = trim((string) $integration->access_token);

            if ($accessToken === '') {
                return [null, 'failed'];
            }

            try {
                $messageId = InstagramFacade::sendTextMessage(
                    $igUserId,
                    $recipientId,
                    $text,
                    $accessToken,
                    false,
                );
            } catch (Throwable) {
                return [null, 'failed'];
            }

            return [is_string($messageId) && trim($messageId) !== '' ? trim($messageId) : null, 'sent'];
        }

        return [null, 'sent'];
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

        $igUserId = trim((string) ($businessAccountId ?: $integration->receiver_id ?: $integration->instagram_user_id));

        if ($igUserId === '') {
            return null;
        }

        return [
            'integration' => $integration,
            'ig_user_id' => $igUserId,
            'recipient_id' => $customerAccountId,
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

    private function buildMessagePreview(string $text): string
    {
        $normalized = trim($text);

        if ($normalized === '') {
            return '[Message]';
        }

        return Str::limit($normalized, 160, '...');
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

    private function subscriptionService(): CompanySubscriptionService
    {
        return app(CompanySubscriptionService::class);
    }

    private function telegramBotApiService(): TelegramBotApiService
    {
        return app(TelegramBotApiService::class);
    }

    private function instagramTokenService(): InstagramTokenService
    {
        return app(InstagramTokenService::class);
    }

    private function instagramTokenRefreshGraceSeconds(): int
    {
        return max((int) config('meta.instagram.token_refresh_grace_seconds', 900), 0);
    }
}
