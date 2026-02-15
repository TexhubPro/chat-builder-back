<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Assistant;
use App\Models\AssistantService;
use App\Models\Company;
use App\Models\CompanyCalendarEvent;
use App\Models\CompanyClient;
use App\Models\CompanyClientOrder;
use App\Models\User;
use App\Services\CompanySubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CompanyCalendarEventController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'assistant_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', Rule::in($this->allEventStatuses())],
        ]);

        $user = $this->resolveUser($request);
        $company = $this->resolveCompany($user);

        $timezone = $this->companyTimezone($company);
        $fromLocal = isset($validated['from'])
            ? Carbon::createFromFormat('Y-m-d', (string) $validated['from'], $timezone)->startOfDay()
            : now($timezone)->startOfMonth();
        $toLocal = isset($validated['to'])
            ? Carbon::createFromFormat('Y-m-d', (string) $validated['to'], $timezone)->endOfDay()
            : $fromLocal->copy()->endOfMonth();

        if ($toLocal->lt($fromLocal)) {
            throw ValidationException::withMessages([
                'to' => ['The end date must be greater than or equal to from date.'],
            ]);
        }

        $assistantFilterId = isset($validated['assistant_id'])
            ? (int) $validated['assistant_id']
            : null;

        if (
            $assistantFilterId !== null
            && ! $company->assistants()->whereKey($assistantFilterId)->exists()
        ) {
            throw ValidationException::withMessages([
                'assistant_id' => ['assistant_id is invalid for this company.'],
            ]);
        }

        $events = $company->calendarEvents()
            ->with([
                'client:id,name,phone,email',
                'assistant:id,name',
                'assistantService:id,name,assistant_id',
            ])
            ->whereBetween('starts_at', [$fromLocal->copy()->utc(), $toLocal->copy()->utc()])
            ->when(
                $assistantFilterId !== null,
                fn ($query) => $query->where('assistant_id', $assistantFilterId)
            )
            ->when(
                isset($validated['status']),
                fn ($query) => $query->where('status', (string) $validated['status'])
            )
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();

        return response()->json([
            'appointments_enabled' => $this->appointmentsEnabledForCompany($company),
            'timezone' => $timezone,
            'slot_minutes' => $this->defaultSlotMinutes($company),
            'business_schedule' => $this->businessSchedule($company),
            'assistants' => $company->assistants()
                ->orderBy('name')
                ->orderBy('id')
                ->get(['id', 'name'])
                ->map(fn (Assistant $assistant): array => [
                    'id' => $assistant->id,
                    'name' => $assistant->name,
                ])
                ->values(),
            'services' => $company->assistantServices()
                ->orderBy('name')
                ->orderBy('id')
                ->get(['id', 'assistant_id', 'name'])
                ->map(fn (AssistantService $service): array => [
                    'id' => $service->id,
                    'assistant_id' => $service->assistant_id,
                    'name' => $service->name,
                ])
                ->values(),
            'events' => $events
                ->map(fn (CompanyCalendarEvent $event): array => $this->eventPayload($event))
                ->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:4000'],
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
            'duration_minutes' => ['required', 'integer', 'min:15', 'max:720'],
            'timezone' => ['nullable', 'string'],
            'status' => ['nullable', 'string', Rule::in($this->allEventStatuses())],
            'location' => ['nullable', 'string', 'max:255'],
            'meeting_link' => ['nullable', 'url:http,https', 'max:2048'],
            'assistant_id' => ['nullable', 'integer'],
            'assistant_service_id' => ['nullable', 'integer'],
            'company_client_id' => ['nullable', 'integer'],
            'client_name' => ['required_without:company_client_id', 'string', 'max:160'],
            'client_phone' => ['required_without:company_client_id', 'string', 'max:32', 'regex:/^[0-9+\-\s()]{5,32}$/'],
            'client_email' => ['nullable', 'email', 'max:255'],
            'client_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $user = $this->resolveUser($request);
        $company = $this->resolveCompany($user);

        [$assistant, $service] = $this->resolveAssistantAndService(
            $company,
            isset($validated['assistant_id']) ? (int) $validated['assistant_id'] : null,
            isset($validated['assistant_service_id']) ? (int) $validated['assistant_service_id'] : null,
        );

        $timezone = $this->normalizeTimezone($validated['timezone'] ?? null, $company);
        [$startsAtUtc, $endsAtUtc] = $this->resolveDateRangeFromPayload(
            (string) $validated['date'],
            (string) $validated['time'],
            (int) $validated['duration_minutes'],
            $timezone,
        );

        $status = isset($validated['status'])
            ? (string) $validated['status']
            : CompanyCalendarEvent::STATUS_SCHEDULED;

        if (
            $this->blocksCalendarSlot($status)
            && ! $this->isSlotAvailable($company, $startsAtUtc, $endsAtUtc)
        ) {
            throw ValidationException::withMessages([
                'time' => ['Selected date and time is already occupied.'],
            ]);
        }

        $client = $this->resolveOrCreateClient(
            $company,
            isset($validated['company_client_id']) ? (int) $validated['company_client_id'] : null,
            $validated,
        );

        $event = CompanyCalendarEvent::query()->create([
            'user_id' => $company->user_id,
            'company_id' => $company->id,
            'company_client_id' => $client->id,
            'assistant_id' => $assistant?->id,
            'assistant_service_id' => $service?->id,
            'title' => Str::limit(trim((string) $validated['title']), 160, ''),
            'description' => $this->nullableTrimmed($validated['description'] ?? null),
            'starts_at' => $startsAtUtc,
            'ends_at' => $endsAtUtc,
            'timezone' => $timezone,
            'status' => $status,
            'location' => $this->nullableTrimmed($validated['location'] ?? null),
            'meeting_link' => $this->nullableTrimmed($validated['meeting_link'] ?? null),
            'metadata' => [
                'source' => 'calendar_manual',
                'created_via' => 'calendar_page',
            ],
        ]);

        return response()->json([
            'message' => 'Calendar event created successfully.',
            'event' => $this->eventPayload($event->fresh(['client:id,name,phone,email', 'assistant:id,name', 'assistantService:id,name,assistant_id'])),
        ], 201);
    }

    public function update(Request $request, int $eventId): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:4000'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'time' => ['nullable', 'date_format:H:i'],
            'duration_minutes' => ['nullable', 'integer', 'min:15', 'max:720'],
            'timezone' => ['nullable', 'string'],
            'status' => ['nullable', 'string', Rule::in($this->allEventStatuses())],
            'location' => ['nullable', 'string', 'max:255'],
            'meeting_link' => ['nullable', 'url:http,https', 'max:2048'],
            'assistant_id' => ['nullable', 'integer'],
            'assistant_service_id' => ['nullable', 'integer'],
            'company_client_id' => ['nullable', 'integer'],
            'client_name' => ['nullable', 'string', 'max:160'],
            'client_phone' => ['nullable', 'string', 'max:32', 'regex:/^[0-9+\-\s()]{5,32}$/'],
            'client_email' => ['nullable', 'email', 'max:255'],
            'client_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $user = $this->resolveUser($request);
        $company = $this->resolveCompany($user);
        $event = $this->resolveEvent($company, $eventId);

        $assistantId = array_key_exists('assistant_id', $validated)
            ? (is_numeric($validated['assistant_id']) ? (int) $validated['assistant_id'] : null)
            : $event->assistant_id;
        $serviceId = array_key_exists('assistant_service_id', $validated)
            ? (is_numeric($validated['assistant_service_id']) ? (int) $validated['assistant_service_id'] : null)
            : $event->assistant_service_id;

        [$assistant, $service] = $this->resolveAssistantAndService($company, $assistantId, $serviceId);

        $timezone = $this->normalizeTimezone(
            $validated['timezone'] ?? $event->timezone,
            $company,
        );

        $existingStartLocal = $event->starts_at
            ? $event->starts_at->copy()->setTimezone($timezone)
            : now($timezone);

        $date = isset($validated['date'])
            ? (string) $validated['date']
            : $existingStartLocal->format('Y-m-d');
        $time = isset($validated['time'])
            ? (string) $validated['time']
            : $existingStartLocal->format('H:i');

        $existingDuration = $event->ends_at && $event->starts_at
            ? max($event->ends_at->diffInMinutes($event->starts_at), 15)
            : $this->defaultSlotMinutes($company);
        $durationMinutes = isset($validated['duration_minutes'])
            ? (int) $validated['duration_minutes']
            : $existingDuration;

        [$startsAtUtc, $endsAtUtc] = $this->resolveDateRangeFromPayload(
            $date,
            $time,
            $durationMinutes,
            $timezone,
        );

        $status = isset($validated['status'])
            ? (string) $validated['status']
            : (string) $event->status;

        if (
            $this->blocksCalendarSlot($status)
            && ! $this->isSlotAvailable($company, $startsAtUtc, $endsAtUtc, $event->id)
        ) {
            throw ValidationException::withMessages([
                'time' => ['Selected date and time is already occupied.'],
            ]);
        }

        $clientId = array_key_exists('company_client_id', $validated)
            ? (is_numeric($validated['company_client_id']) ? (int) $validated['company_client_id'] : null)
            : $event->company_client_id;

        $client = $this->resolveOrCreateClient($company, $clientId, $validated, $event->client);

        $event->forceFill([
            'company_client_id' => $client->id,
            'assistant_id' => $assistant?->id,
            'assistant_service_id' => $service?->id,
            'title' => array_key_exists('title', $validated)
                ? Str::limit(trim((string) $validated['title']), 160, '')
                : $event->title,
            'description' => array_key_exists('description', $validated)
                ? $this->nullableTrimmed($validated['description'])
                : $event->description,
            'starts_at' => $startsAtUtc,
            'ends_at' => $endsAtUtc,
            'timezone' => $timezone,
            'status' => $status,
            'location' => array_key_exists('location', $validated)
                ? $this->nullableTrimmed($validated['location'])
                : $event->location,
            'meeting_link' => array_key_exists('meeting_link', $validated)
                ? $this->nullableTrimmed($validated['meeting_link'])
                : $event->meeting_link,
        ])->save();

        $this->syncLinkedOrders($company, $event);

        return response()->json([
            'message' => 'Calendar event updated successfully.',
            'event' => $this->eventPayload($event->fresh(['client:id,name,phone,email', 'assistant:id,name', 'assistantService:id,name,assistant_id'])),
        ]);
    }

    public function destroy(Request $request, int $eventId): JsonResponse
    {
        $user = $this->resolveUser($request);
        $company = $this->resolveCompany($user);
        $event = $this->resolveEvent($company, $eventId);

        $this->syncLinkedOrders($company, $event, true);
        $event->delete();

        return response()->json([
            'message' => 'Calendar event deleted successfully.',
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

    private function resolveEvent(Company $company, int $eventId): CompanyCalendarEvent
    {
        return $company->calendarEvents()
            ->with(['client:id,name,phone,email'])
            ->whereKey($eventId)
            ->firstOrFail();
    }

    /**
     * @return array{0: Assistant|null, 1: AssistantService|null}
     */
    private function resolveAssistantAndService(
        Company $company,
        ?int $assistantId,
        ?int $serviceId,
    ): array {
        $assistant = null;

        if ($assistantId !== null) {
            $assistant = $company->assistants()->whereKey($assistantId)->first();
            if (! $assistant) {
                throw ValidationException::withMessages([
                    'assistant_id' => ['assistant_id is invalid for this company.'],
                ]);
            }
        }

        $service = null;

        if ($serviceId !== null) {
            $service = $company->assistantServices()->whereKey($serviceId)->first();
            if (! $service) {
                throw ValidationException::withMessages([
                    'assistant_service_id' => ['assistant_service_id is invalid for this company.'],
                ]);
            }

            if ($assistant !== null && (int) $service->assistant_id !== (int) $assistant->id) {
                throw ValidationException::withMessages([
                    'assistant_service_id' => ['assistant_service_id does not belong to selected assistant.'],
                ]);
            }

            if ($assistant === null && $service->assistant_id) {
                $assistant = $company->assistants()->whereKey((int) $service->assistant_id)->first();
            }
        }

        return [$assistant, $service];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveOrCreateClient(
        Company $company,
        ?int $companyClientId,
        array $payload,
        ?CompanyClient $fallbackClient = null,
    ): CompanyClient {
        if ($companyClientId !== null) {
            $client = $company->clients()->whereKey($companyClientId)->first();
            if (! $client) {
                throw ValidationException::withMessages([
                    'company_client_id' => ['company_client_id is invalid for this company.'],
                ]);
            }

            $this->applyClientUpdates($company, $client, $payload);

            return $client;
        }

        if ($fallbackClient) {
            $this->applyClientUpdates($company, $fallbackClient, $payload);

            return $fallbackClient;
        }

        $name = trim((string) ($payload['client_name'] ?? ''));
        $phoneRaw = trim((string) ($payload['client_phone'] ?? ''));

        if ($name === '' || $phoneRaw === '') {
            throw ValidationException::withMessages([
                'client_name' => ['client_name and client_phone are required.'],
            ]);
        }

        $phone = $this->normalizePhone($phoneRaw);

        $existing = $company->clients()->where('phone', $phone)->first();
        if ($existing) {
            $this->applyClientUpdates($company, $existing, $payload);

            return $existing;
        }

        return CompanyClient::query()->create([
            'user_id' => $company->user_id,
            'company_id' => $company->id,
            'name' => Str::limit($name, 160, ''),
            'phone' => Str::limit($phone, 32, ''),
            'email' => $this->nullableTrimmed($payload['client_email'] ?? null),
            'notes' => $this->nullableTrimmed($payload['client_notes'] ?? null),
            'status' => CompanyClient::STATUS_ACTIVE,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function applyClientUpdates(Company $company, CompanyClient $client, array $payload): void
    {
        $dirty = false;

        if (array_key_exists('client_name', $payload)) {
            $name = trim((string) ($payload['client_name'] ?? ''));
            if ($name !== '') {
                $client->name = Str::limit($name, 160, '');
                $dirty = true;
            }
        }

        if (array_key_exists('client_phone', $payload)) {
            $phoneRaw = trim((string) ($payload['client_phone'] ?? ''));
            if ($phoneRaw !== '') {
                $normalized = Str::limit($this->normalizePhone($phoneRaw), 32, '');

                $phoneBusy = $company->clients()
                    ->where('phone', $normalized)
                    ->whereKeyNot($client->id)
                    ->exists();

                if ($phoneBusy) {
                    throw ValidationException::withMessages([
                        'client_phone' => ['Phone is already linked to another client.'],
                    ]);
                }

                $client->phone = $normalized;
                $dirty = true;
            }
        }

        if (array_key_exists('client_email', $payload)) {
            $email = $this->nullableTrimmed($payload['client_email'] ?? null);

            if ($email !== null) {
                $emailBusy = $company->clients()
                    ->where('email', $email)
                    ->whereKeyNot($client->id)
                    ->exists();

                if ($emailBusy) {
                    throw ValidationException::withMessages([
                        'client_email' => ['Email is already linked to another client.'],
                    ]);
                }
            }

            $client->email = $email;
            $dirty = true;
        }

        if (array_key_exists('client_notes', $payload)) {
            $client->notes = $this->nullableTrimmed($payload['client_notes'] ?? null);
            $dirty = true;
        }

        if ($dirty) {
            $client->save();
        }
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolveDateRangeFromPayload(
        string $date,
        string $time,
        int $durationMinutes,
        string $timezone,
    ): array {
        try {
            $startsAtLocal = Carbon::createFromFormat('Y-m-d H:i', "{$date} {$time}", $timezone);
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'time' => ['Invalid date or time.'],
            ]);
        }

        $duration = max(min($durationMinutes, 720), 15);
        $endsAtLocal = $startsAtLocal->copy()->addMinutes($duration);

        return [$startsAtLocal->copy()->utc(), $endsAtLocal->copy()->utc()];
    }

    private function normalizeTimezone(mixed $value, Company $company): string
    {
        if (is_string($value) && in_array($value, timezone_identifiers_list(), true)) {
            return $value;
        }

        return $this->companyTimezone($company);
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

    private function appointmentsEnabledForCompany(Company $company): bool
    {
        $settings = is_array($company->settings) ? $company->settings : [];

        return (string) data_get($settings, 'account_type', 'without_appointments') === 'with_appointments'
            && (bool) data_get($settings, 'appointment.enabled', false);
    }

    private function defaultSlotMinutes(Company $company): int
    {
        $settings = is_array($company->settings) ? $company->settings : [];
        $raw = (int) data_get($settings, 'appointment.slot_minutes', 30);

        return max(min($raw, 120), 15);
    }

    /**
     * @return array<string, array{is_day_off: bool, start_time: string|null, end_time: string|null}>
     */
    private function businessSchedule(Company $company): array
    {
        $settings = is_array($company->settings) ? $company->settings : [];
        $schedule = is_array(data_get($settings, 'business.schedule'))
            ? data_get($settings, 'business.schedule')
            : [];

        $defaults = [
            'monday' => ['is_day_off' => false, 'start_time' => '09:00', 'end_time' => '18:00'],
            'tuesday' => ['is_day_off' => false, 'start_time' => '09:00', 'end_time' => '18:00'],
            'wednesday' => ['is_day_off' => false, 'start_time' => '09:00', 'end_time' => '18:00'],
            'thursday' => ['is_day_off' => false, 'start_time' => '09:00', 'end_time' => '18:00'],
            'friday' => ['is_day_off' => false, 'start_time' => '09:00', 'end_time' => '18:00'],
            'saturday' => ['is_day_off' => true, 'start_time' => null, 'end_time' => null],
            'sunday' => ['is_day_off' => true, 'start_time' => null, 'end_time' => null],
        ];

        foreach (array_keys($defaults) as $day) {
            $row = is_array($schedule[$day] ?? null) ? $schedule[$day] : [];
            $isDayOff = (bool) ($row['is_day_off'] ?? $defaults[$day]['is_day_off']);

            $defaults[$day] = [
                'is_day_off' => $isDayOff,
                'start_time' => $isDayOff
                    ? null
                    : $this->normalizeTime($row['start_time'] ?? $defaults[$day]['start_time']),
                'end_time' => $isDayOff
                    ? null
                    : $this->normalizeTime($row['end_time'] ?? $defaults[$day]['end_time']),
            ];

            if ($defaults[$day]['start_time'] === null || $defaults[$day]['end_time'] === null) {
                $defaults[$day]['is_day_off'] = true;
                $defaults[$day]['start_time'] = null;
                $defaults[$day]['end_time'] = null;
            }
        }

        return $defaults;
    }

    private function normalizeTime(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $normalized) === 1
            ? $normalized
            : null;
    }

    private function blocksCalendarSlot(string $status): bool
    {
        return in_array($status, [
            CompanyCalendarEvent::STATUS_SCHEDULED,
            CompanyCalendarEvent::STATUS_CONFIRMED,
        ], true);
    }

    private function isSlotAvailable(
        Company $company,
        Carbon $startsAtUtc,
        Carbon $endsAtUtc,
        ?int $ignoreEventId = null,
    ): bool {
        $events = CompanyCalendarEvent::query()
            ->where('company_id', $company->id)
            ->whereIn('status', [
                CompanyCalendarEvent::STATUS_SCHEDULED,
                CompanyCalendarEvent::STATUS_CONFIRMED,
            ])
            ->where('starts_at', '<', $endsAtUtc)
            ->when(
                $ignoreEventId !== null,
                fn ($query) => $query->whereKeyNot($ignoreEventId)
            )
            ->get(['starts_at', 'ends_at']);

        foreach ($events as $event) {
            $eventStart = $event->starts_at;
            $eventEnd = $event->ends_at ?? $event->starts_at?->copy()->addMinutes($this->defaultSlotMinutes($company));

            if (! $eventStart || ! $eventEnd) {
                continue;
            }

            if ($eventStart->lt($endsAtUtc) && $eventEnd->gt($startsAtUtc)) {
                return false;
            }
        }

        return true;
    }

    private function syncLinkedOrders(
        Company $company,
        CompanyCalendarEvent $event,
        bool $deleted = false,
    ): void {
        $metadata = is_array($event->metadata) ? $event->metadata : [];
        $metadataOrderId = data_get($metadata, 'order_id');

        $query = CompanyClientOrder::query()
            ->where('company_id', $company->id)
            ->where(function ($builder) use ($event, $metadataOrderId): void {
                $builder->where('metadata->appointment->calendar_event_id', $event->id);

                if (is_numeric($metadataOrderId)) {
                    $builder->orWhereKey((int) $metadataOrderId);
                }
            });

        $orders = $query->get();

        foreach ($orders as $order) {
            $orderMetadata = is_array($order->metadata) ? $order->metadata : [];

            if ($deleted) {
                unset($orderMetadata['appointment']);

                if ((string) $order->status === CompanyClientOrder::STATUS_APPOINTMENTS) {
                    $order->status = CompanyClientOrder::STATUS_IN_PROGRESS;
                    $order->completed_at = null;
                }
            } else {
                $appointment = is_array($orderMetadata['appointment'] ?? null)
                    ? $orderMetadata['appointment']
                    : [];
                $durationMinutes = $event->starts_at && $event->ends_at
                    ? max($event->ends_at->diffInMinutes($event->starts_at), 15)
                    : null;

                $appointment['calendar_event_id'] = $event->id;
                $appointment['starts_at'] = $event->starts_at?->toIso8601String();
                $appointment['ends_at'] = $event->ends_at?->toIso8601String();
                $appointment['timezone'] = $event->timezone;
                $appointment['status'] = $event->status;

                if ($durationMinutes !== null) {
                    $appointment['duration_minutes'] = $durationMinutes;
                }

                $orderMetadata['appointment'] = $appointment;

                $mappedStatus = $this->mapEventStatusToOrderStatus((string) $event->status, (string) $order->status);
                $order->status = $mappedStatus;
                $order->completed_at = $this->isOrderTerminalStatus($mappedStatus)
                    ? ($order->completed_at ?? now())
                    : null;
            }

            $order->metadata = $orderMetadata;
            $order->save();
        }
    }

    private function mapEventStatusToOrderStatus(string $eventStatus, string $currentOrderStatus): string
    {
        if (in_array($currentOrderStatus, [
            CompanyClientOrder::STATUS_CONFIRMED,
            CompanyClientOrder::STATUS_HANDED_TO_COURIER,
            CompanyClientOrder::STATUS_DELIVERED,
        ], true)) {
            return $currentOrderStatus;
        }

        return match ($eventStatus) {
            CompanyCalendarEvent::STATUS_COMPLETED => CompanyClientOrder::STATUS_COMPLETED,
            CompanyCalendarEvent::STATUS_CANCELED,
            CompanyCalendarEvent::STATUS_NO_SHOW => CompanyClientOrder::STATUS_CANCELED,
            CompanyCalendarEvent::STATUS_SCHEDULED,
            CompanyCalendarEvent::STATUS_CONFIRMED => CompanyClientOrder::STATUS_APPOINTMENTS,
            default => $currentOrderStatus,
        };
    }

    private function isOrderTerminalStatus(string $status): bool
    {
        return in_array($status, [
            CompanyClientOrder::STATUS_COMPLETED,
            CompanyClientOrder::STATUS_CANCELED,
            CompanyClientOrder::STATUS_DELIVERED,
        ], true);
    }

    private function normalizePhone(string $value): string
    {
        $trimmed = trim($value);
        $hasPlusPrefix = Str::startsWith($trimmed, '+');
        $digits = preg_replace('/\D+/', '', $trimmed) ?? '';

        if ($digits === '') {
            return $trimmed;
        }

        return $hasPlusPrefix ? '+'.$digits : $digits;
    }

    private function nullableTrimmed(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @return array<int, string>
     */
    private function allEventStatuses(): array
    {
        return [
            CompanyCalendarEvent::STATUS_SCHEDULED,
            CompanyCalendarEvent::STATUS_CONFIRMED,
            CompanyCalendarEvent::STATUS_COMPLETED,
            CompanyCalendarEvent::STATUS_CANCELED,
            CompanyCalendarEvent::STATUS_NO_SHOW,
        ];
    }

    private function eventPayload(CompanyCalendarEvent $event): array
    {
        $durationMinutes = null;

        if ($event->starts_at && $event->ends_at) {
            $durationMinutes = max($event->ends_at->diffInMinutes($event->starts_at), 1);
        }

        $metadata = is_array($event->metadata) ? $event->metadata : [];

        return [
            'id' => $event->id,
            'title' => (string) $event->title,
            'description' => $event->description,
            'starts_at' => $event->starts_at?->toIso8601String(),
            'ends_at' => $event->ends_at?->toIso8601String(),
            'duration_minutes' => $durationMinutes,
            'timezone' => (string) $event->timezone,
            'status' => (string) $event->status,
            'location' => $event->location,
            'meeting_link' => $event->meeting_link,
            'assistant' => $event->assistant
                ? [
                    'id' => $event->assistant->id,
                    'name' => $event->assistant->name,
                ]
                : null,
            'service' => $event->assistantService
                ? [
                    'id' => $event->assistantService->id,
                    'assistant_id' => $event->assistantService->assistant_id,
                    'name' => $event->assistantService->name,
                ]
                : null,
            'client' => $event->client
                ? [
                    'id' => $event->client->id,
                    'name' => $event->client->name,
                    'phone' => $event->client->phone,
                    'email' => $event->client->email,
                ]
                : null,
            'metadata' => $metadata,
            'created_at' => $event->created_at?->toIso8601String(),
            'updated_at' => $event->updated_at?->toIso8601String(),
        ];
    }

    private function subscriptionService(): CompanySubscriptionService
    {
        return app(CompanySubscriptionService::class);
    }
}
