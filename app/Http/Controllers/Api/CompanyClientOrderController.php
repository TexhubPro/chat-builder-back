<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Chat;
use App\Models\Company;
use App\Models\CompanyCalendarEvent;
use App\Models\CompanyClientOrder;
use App\Models\User;
use App\Services\CompanySubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CompanyClientOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        $company = $this->resolveCompany($user);
        $appointmentsEnabled = $this->appointmentsEnabledForCompany($company);

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
            'requests' => $orders
                ->map(
                    fn (CompanyClientOrder $order): array => $this->orderPayload(
                        $order,
                        $appointmentsEnabled,
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
                Rule::in([
                    CompanyClientOrder::STATUS_NEW,
                    CompanyClientOrder::STATUS_IN_PROGRESS,
                    CompanyClientOrder::STATUS_APPOINTMENTS,
                    CompanyClientOrder::STATUS_COMPLETED,
                ]),
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
        $order = $this->resolveOrder($company, $orderId);
        $client = $order->client;

        if (! $client) {
            return response()->json([
                'message' => 'Order client was not found.',
            ], 422);
        }

        if (array_key_exists('status', $validated)) {
            $status = $this->normalizeStatus((string) $validated['status']);

            if ($status === CompanyClientOrder::STATUS_APPOINTMENTS && ! $appointmentsEnabled) {
                return response()->json([
                    'message' => 'Appointments are disabled for this company.',
                ], 422);
            }

            $order->status = $status;
            $order->completed_at = $status === CompanyClientOrder::STATUS_COMPLETED
                ? ($order->completed_at ?? now())
                : null;
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

        $appointmentsEnabled = $this->appointmentsEnabledForCompany($company->fresh());
        $payload = $this->orderPayload($order->fresh(['client:id,name,phone,email', 'assistant:id,name']), $appointmentsEnabled, collect());

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
        Collection $chatsById
    ): array {
        $metadata = is_array($order->metadata) ? $order->metadata : [];
        $status = $this->normalizeStatus((string) ($order->status ?? CompanyClientOrder::STATUS_NEW));
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
            'board' => $this->resolveBoard($status, $appointment !== null, $appointmentsEnabled),
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

    private function normalizeStatus(string $status): string
    {
        return match ($status) {
            CompanyClientOrder::STATUS_IN_PROGRESS => CompanyClientOrder::STATUS_IN_PROGRESS,
            CompanyClientOrder::STATUS_APPOINTMENTS => CompanyClientOrder::STATUS_APPOINTMENTS,
            CompanyClientOrder::STATUS_COMPLETED => CompanyClientOrder::STATUS_COMPLETED,
            default => CompanyClientOrder::STATUS_NEW,
        };
    }

    private function resolveBoard(
        string $status,
        bool $hasAppointment,
        bool $appointmentsEnabled
    ): string {
        if ($status === CompanyClientOrder::STATUS_COMPLETED) {
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

        if ($status === CompanyClientOrder::STATUS_IN_PROGRESS) {
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
}
