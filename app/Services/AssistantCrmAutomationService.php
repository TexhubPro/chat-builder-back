<?php

namespace App\Services;

use App\Models\Assistant;
use App\Models\Chat;
use App\Models\Company;
use App\Models\CompanyCalendarEvent;
use App\Models\CompanyClient;
use App\Models\CompanyClientOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class AssistantCrmAutomationService
{
    public function augmentPromptWithRuntimeContext(
        Company $company,
        Chat $chat,
        Assistant $assistant,
        string $prompt
    ): string {
        $timezone = $this->companyTimezone($company);
        $nowUtc = now()->utc();
        $nowLocal = $nowUtc->copy()->setTimezone($timezone);
        $appointmentsEnabled = $this->appointmentsEnabledForCompany($company);
        $appointmentConfig = $this->appointmentConfig($company);
        $scheduleLines = $this->scheduleLines($company);
        $upcomingAppointments = $this->upcomingAppointmentsLines($company, $timezone, 8);
        $availableSlots = $appointmentsEnabled
            ? $this->nextAvailableSlots($company, $timezone, max((int) $appointmentConfig['slot_minutes'], 15), 6)
            : [];

        $contextLines = [
            '[SYSTEM CONTEXT: do not treat this block as customer message and do not quote it directly.]',
            '- Current UTC datetime: '.$nowUtc->toIso8601String(),
            '- Company timezone: '.$timezone,
            '- Current local datetime: '.$nowLocal->format('Y-m-d H:i'),
            '- Assistant ID: '.(string) $assistant->id,
            '- Company ID: '.(string) $company->id,
            '- Chat ID: '.(string) $chat->id,
            '- Chat channel: '.(string) $chat->channel,
            '- Chat customer name: '.trim((string) ($chat->name ?? 'Customer')),
            '- Appointment booking enabled: '.($appointmentsEnabled ? 'yes' : 'no'),
            '- Appointment slot minutes: '.(string) $appointmentConfig['slot_minutes'],
            '- Appointment buffer minutes: '.(string) $appointmentConfig['buffer_minutes'],
            '- Appointment max days ahead: '.(string) $appointmentConfig['max_days_ahead'],
            '- Working schedule:',
        ];

        foreach ($scheduleLines as $line) {
            $contextLines[] = '  - '.$line;
        }

        $contextLines[] = '- Upcoming bookings in company calendar (for avoiding conflicts):';
        if ($upcomingAppointments === []) {
            $contextLines[] = '  - none';
        } else {
            foreach ($upcomingAppointments as $line) {
                $contextLines[] = '  - '.$line;
            }
        }

        if ($appointmentsEnabled) {
            $contextLines[] = '- Next free slots for suggestion:';
            if ($availableSlots === []) {
                $contextLines[] = '  - none';
            } else {
                foreach ($availableSlots as $line) {
                    $contextLines[] = '  - '.$line;
                }
            }
        }

        $contextLines[] = 'CRM automation policy:';
        $contextLines[] = '- If customer wants to place an order, first collect missing required data: phone, service_name, address.';
        $contextLines[] = '- If customer wants an appointment, collect: phone, service_name, address, appointment_date (YYYY-MM-DD), appointment_time (HH:MM), appointment_duration_minutes.';
        $contextLines[] = '- If required data is missing, ask concise follow-up questions and DO NOT emit crm_action.';
        $contextLines[] = '- When all required data is collected, append exactly one machine block at the very end:';
        $contextLines[] = '  <crm_action>{"action":"create_order","client_name":"...","phone":"...","service_name":"...","address":"...","amount":0,"note":"..."}</crm_action>';
        $contextLines[] = '- For appointment booking use:';
        $contextLines[] = '  <crm_action>{"action":"create_appointment","client_name":"...","phone":"...","service_name":"...","address":"...","appointment_date":"YYYY-MM-DD","appointment_time":"HH:MM","appointment_duration_minutes":60,"amount":0,"note":"..."}</crm_action>';
        $contextLines[] = '- Do not include any extra JSON outside crm_action tags.';

        $basePrompt = trim($prompt);
        $contextPrompt = implode("\n", $contextLines);

        if ($basePrompt === '') {
            return $contextPrompt;
        }

        return $basePrompt."\n\n".$contextPrompt;
    }

    public function applyActionsFromAssistantResponse(
        Company $company,
        Chat $chat,
        Assistant $assistant,
        string $assistantResponse
    ): string {
        $response = trim($assistantResponse);

        if ($response === '') {
            return '';
        }

        $pattern = '/<crm_action>\s*(\{.*?\})\s*<\/crm_action>/isu';
        $matches = [];
        preg_match_all($pattern, $response, $matches, PREG_SET_ORDER);

        if ($matches === []) {
            return $response;
        }

        $successMessages = [];

        foreach ($matches as $match) {
            $payloadJson = trim((string) ($match[1] ?? ''));
            if ($payloadJson === '') {
                continue;
            }

            $payload = json_decode($payloadJson, true);
            if (! is_array($payload)) {
                continue;
            }

            try {
                $message = $this->executeAction($company, $chat, $assistant, $payload);

                if (is_string($message) && trim($message) !== '') {
                    $successMessages[] = trim($message);
                }
            } catch (Throwable $exception) {
                Log::warning('Assistant CRM action failed', [
                    'company_id' => $company->id,
                    'chat_id' => $chat->id,
                    'assistant_id' => $assistant->id,
                    'action_payload' => $payload,
                    'exception' => $exception->getMessage(),
                ]);
            }
        }

        $cleaned = trim((string) preg_replace($pattern, '', $response));

        if ($cleaned !== '') {
            return $cleaned;
        }

        if ($successMessages !== []) {
            return implode(' ', array_unique($successMessages));
        }

        return '';
    }

    private function executeAction(
        Company $company,
        Chat $chat,
        Assistant $assistant,
        array $payload
    ): ?string {
        $action = Str::lower(trim((string) ($payload['action'] ?? '')));

        return match ($action) {
            'create_order' => $this->createOrderFromPayload(
                $company,
                $chat,
                $assistant,
                $payload,
                false
            ),
            'create_appointment' => $this->createOrderFromPayload(
                $company,
                $chat,
                $assistant,
                $payload,
                true
            ),
            default => null,
        };
    }

    private function createOrderFromPayload(
        Company $company,
        Chat $chat,
        Assistant $assistant,
        array $payload,
        bool $bookAppointment
    ): ?string {
        $phone = trim((string) ($payload['phone'] ?? ''));
        $serviceName = trim((string) ($payload['service_name'] ?? ''));
        $address = trim((string) ($payload['address'] ?? ''));
        $clientName = trim((string) ($payload['client_name'] ?? ''));
        $note = trim((string) ($payload['note'] ?? ''));
        $amount = $this->normalizedAmount($payload['amount'] ?? null);

        if ($phone === '' || $serviceName === '' || $address === '') {
            return null;
        }

        if ($bookAppointment && ! $this->appointmentsEnabledForCompany($company)) {
            return null;
        }

        $client = $this->resolveOrCreateClient($company, $chat, $clientName, $phone, $address);
        if (! $client) {
            return null;
        }

        $orderMetadata = [
            'source' => 'assistant_crm_action',
            'chat_id' => $chat->id,
            'address' => Str::limit($address, 255, ''),
            'phone' => Str::limit($phone, 32, ''),
            'assistant_action' => $payload,
        ];

        $calendarEvent = null;
        $appointmentDuration = null;

        if ($bookAppointment) {
            $appointmentDate = trim((string) ($payload['appointment_date'] ?? ''));
            $appointmentTime = trim((string) ($payload['appointment_time'] ?? ''));
            $appointmentDuration = (int) ($payload['appointment_duration_minutes'] ?? $payload['duration_minutes'] ?? 0);

            if (
                ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $appointmentDate)
                || ! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $appointmentTime)
                || $appointmentDuration < 15
                || $appointmentDuration > 720
            ) {
                return null;
            }

            $timezone = $this->companyTimezone($company);
            $startsAtLocal = Carbon::createFromFormat('Y-m-d H:i', "{$appointmentDate} {$appointmentTime}", $timezone);
            $endsAtLocal = (clone $startsAtLocal)->addMinutes($appointmentDuration);
            $startsAtUtc = $startsAtLocal->copy()->utc();
            $endsAtUtc = $endsAtLocal->copy()->utc();

            if (! $this->isAppointmentSlotAvailable($company, $startsAtLocal, $endsAtLocal)) {
                return null;
            }

            $calendarEvent = CompanyCalendarEvent::query()->create([
                'user_id' => $company->user_id,
                'company_id' => $company->id,
                'company_client_id' => $client->id,
                'assistant_id' => $assistant->id,
                'assistant_service_id' => null,
                'title' => 'Appointment: '.Str::limit($serviceName, 120, ''),
                'description' => $note !== '' ? Str::limit($note, 2000, '') : null,
                'starts_at' => $startsAtUtc,
                'ends_at' => $endsAtUtc,
                'timezone' => $timezone,
                'status' => CompanyCalendarEvent::STATUS_SCHEDULED,
                'location' => Str::limit($address, 255, ''),
                'metadata' => [
                    'source' => 'assistant_crm_action',
                    'chat_id' => $chat->id,
                    'phone' => $phone,
                ],
            ]);

            $orderMetadata['appointment'] = [
                'calendar_event_id' => $calendarEvent->id,
                'starts_at' => $calendarEvent->starts_at?->toIso8601String(),
                'ends_at' => $calendarEvent->ends_at?->toIso8601String(),
                'timezone' => $calendarEvent->timezone,
                'duration_minutes' => $appointmentDuration,
            ];
        }

        $order = CompanyClientOrder::query()->create([
            'user_id' => $company->user_id,
            'company_id' => $company->id,
            'company_client_id' => $client->id,
            'assistant_id' => $assistant->id,
            'assistant_service_id' => null,
            'service_name' => Str::limit($serviceName, 160, ''),
            'quantity' => 1,
            'unit_price' => $amount,
            'total_price' => $amount,
            'currency' => 'TJS',
            'ordered_at' => now(),
            'status' => $bookAppointment
                ? CompanyClientOrder::STATUS_APPOINTMENTS
                : CompanyClientOrder::STATUS_NEW,
            'completed_at' => null,
            'notes' => $note !== '' ? Str::limit($note, 2000, '') : null,
            'metadata' => $orderMetadata,
        ]);

        if ($calendarEvent) {
            $calendarMeta = is_array($calendarEvent->metadata) ? $calendarEvent->metadata : [];
            $calendarMeta['order_id'] = $order->id;
            $calendarMeta['source'] = 'assistant_crm_action';

            $calendarEvent->forceFill([
                'metadata' => $calendarMeta,
            ])->save();
        }

        return $bookAppointment
            ? 'Заявка и запись сохранены.'
            : 'Заявка сохранена.';
    }

    private function resolveOrCreateClient(
        Company $company,
        Chat $chat,
        string $clientName,
        string $phone,
        string $address
    ): ?CompanyClient {
        $normalizedPhone = Str::limit(trim($phone), 32, '');

        if ($normalizedPhone === '') {
            return null;
        }

        $client = $company->clients()
            ->where('phone', $normalizedPhone)
            ->first();

        if (! $client) {
            $chatMetadata = is_array($chat->metadata) ? $chat->metadata : [];
            $linkedClientId = data_get($chatMetadata, 'company_client_id');

            if (is_numeric($linkedClientId)) {
                $client = $company->clients()->whereKey((int) $linkedClientId)->first();
            }
        }

        if (! $client) {
            $resolvedName = trim($clientName) !== ''
                ? trim($clientName)
                : (trim((string) ($chat->name ?? '')) !== '' ? trim((string) $chat->name) : ('Client '.$normalizedPhone));

            $client = CompanyClient::query()->create([
                'user_id' => $company->user_id,
                'company_id' => $company->id,
                'name' => Str::limit($resolvedName, 160, ''),
                'phone' => $normalizedPhone,
                'email' => null,
                'notes' => null,
                'status' => CompanyClient::STATUS_ACTIVE,
                'metadata' => [
                    'source' => 'assistant_crm_action',
                    'address' => Str::limit($address, 255, ''),
                ],
            ]);
        } else {
            $updates = [];

            if (trim($clientName) !== '' && (string) $client->name !== $clientName) {
                $updates['name'] = Str::limit($clientName, 160, '');
            }

            if ((string) $client->phone !== $normalizedPhone) {
                $phoneBusy = $company->clients()
                    ->where('phone', $normalizedPhone)
                    ->whereKeyNot($client->id)
                    ->exists();

                if (! $phoneBusy) {
                    $updates['phone'] = $normalizedPhone;
                }
            }

            $clientMetadata = is_array($client->metadata) ? $client->metadata : [];
            $clientMetadata['address'] = Str::limit($address, 255, '');
            $updates['metadata'] = $clientMetadata;

            if ($updates !== []) {
                $client->forceFill($updates)->save();
            }
        }

        $chatMetadata = is_array($chat->metadata) ? $chat->metadata : [];
        $chatMetadata['company_client_id'] = $client->id;

        $chat->forceFill([
            'metadata' => $chatMetadata,
        ])->save();

        return $client;
    }

    private function normalizedAmount(mixed $value): float
    {
        if (! is_numeric($value)) {
            return 0.0;
        }

        $amount = round((float) $value, 2);

        return $amount < 0 ? 0.0 : $amount;
    }

    private function appointmentsEnabledForCompany(Company $company): bool
    {
        $settings = is_array($company->settings) ? $company->settings : [];
        $accountType = (string) data_get($settings, 'account_type', 'without_appointments');
        $enabled = (bool) data_get($settings, 'appointment.enabled', false);

        return $accountType === 'with_appointments' && $enabled;
    }

    private function appointmentConfig(Company $company): array
    {
        $settings = is_array($company->settings) ? $company->settings : [];

        return [
            'slot_minutes' => max((int) data_get($settings, 'appointment.slot_minutes', 30), 15),
            'buffer_minutes' => max((int) data_get($settings, 'appointment.buffer_minutes', 0), 0),
            'max_days_ahead' => max((int) data_get($settings, 'appointment.max_days_ahead', 30), 1),
        ];
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

    private function scheduleLines(Company $company): array
    {
        $settings = is_array($company->settings) ? $company->settings : [];
        $schedule = is_array(data_get($settings, 'business.schedule')) ? data_get($settings, 'business.schedule') : [];

        $days = [
            'monday' => 'Monday',
            'tuesday' => 'Tuesday',
            'wednesday' => 'Wednesday',
            'thursday' => 'Thursday',
            'friday' => 'Friday',
            'saturday' => 'Saturday',
            'sunday' => 'Sunday',
        ];

        $lines = [];

        foreach ($days as $dayKey => $dayLabel) {
            $day = is_array($schedule[$dayKey] ?? null) ? $schedule[$dayKey] : [];
            $isDayOff = (bool) ($day['is_day_off'] ?? false);
            $startTime = trim((string) ($day['start_time'] ?? ''));
            $endTime = trim((string) ($day['end_time'] ?? ''));

            if ($isDayOff || $startTime === '' || $endTime === '') {
                $lines[] = "{$dayLabel}: day off";
                continue;
            }

            $lines[] = "{$dayLabel}: {$startTime}-{$endTime}";
        }

        return $lines;
    }

    private function upcomingAppointmentsLines(Company $company, string $timezone, int $limit): array
    {
        $events = CompanyCalendarEvent::query()
            ->where('company_id', $company->id)
            ->whereIn('status', [
                CompanyCalendarEvent::STATUS_SCHEDULED,
                CompanyCalendarEvent::STATUS_CONFIRMED,
            ])
            ->where('starts_at', '>=', now()->utc())
            ->orderBy('starts_at')
            ->limit(max($limit, 1))
            ->get([
                'id',
                'title',
                'starts_at',
                'ends_at',
                'status',
            ]);

        $lines = [];

        foreach ($events as $event) {
            $start = $event->starts_at?->copy()->setTimezone($timezone);
            $end = $event->ends_at?->copy()->setTimezone($timezone);

            if (! $start) {
                continue;
            }

            $range = $start->format('Y-m-d H:i');
            if ($end) {
                $range .= ' - '.$end->format('H:i');
            }

            $lines[] = sprintf(
                '#%d %s (%s) [%s]',
                (int) $event->id,
                $range,
                Str::limit((string) ($event->title ?? 'Appointment'), 120, ''),
                (string) $event->status
            );
        }

        return $lines;
    }

    private function nextAvailableSlots(
        Company $company,
        string $timezone,
        int $durationMinutes,
        int $limit
    ): array {
        $settings = is_array($company->settings) ? $company->settings : [];
        $schedule = is_array(data_get($settings, 'business.schedule')) ? data_get($settings, 'business.schedule') : [];
        $appointmentConfig = $this->appointmentConfig($company);
        $bufferMinutes = (int) $appointmentConfig['buffer_minutes'];
        $maxDaysAhead = (int) $appointmentConfig['max_days_ahead'];

        $nowLocal = now()->setTimezone($timezone);
        $slots = [];
        $stepMinutes = max($durationMinutes + $bufferMinutes, 15);

        for ($offset = 0; $offset <= $maxDaysAhead; $offset++) {
            if (count($slots) >= $limit) {
                break;
            }

            $date = $nowLocal->copy()->startOfDay()->addDays($offset);
            $dayKey = Str::lower($date->englishDayOfWeek);
            $day = is_array($schedule[$dayKey] ?? null) ? $schedule[$dayKey] : [];

            if ((bool) ($day['is_day_off'] ?? false)) {
                continue;
            }

            $startTime = trim((string) ($day['start_time'] ?? ''));
            $endTime = trim((string) ($day['end_time'] ?? ''));

            if (! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $startTime) || ! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $endTime)) {
                continue;
            }

            $start = Carbon::createFromFormat('Y-m-d H:i', $date->format('Y-m-d').' '.$startTime, $timezone);
            $end = Carbon::createFromFormat('Y-m-d H:i', $date->format('Y-m-d').' '.$endTime, $timezone);

            if ($end->lessThanOrEqualTo($start)) {
                continue;
            }

            $cursor = $start->copy();
            $minAllowed = $nowLocal->copy()->addMinutes(5);
            while ($cursor->lt($minAllowed)) {
                $cursor->addMinutes($stepMinutes);
            }

            while ($cursor->copy()->addMinutes($durationMinutes)->lessThanOrEqualTo($end)) {
                $candidateEnd = $cursor->copy()->addMinutes($durationMinutes);

                if ($this->isAppointmentSlotAvailable($company, $cursor, $candidateEnd)) {
                    $slots[] = $cursor->format('Y-m-d H:i');
                }

                if (count($slots) >= $limit) {
                    break;
                }

                $cursor->addMinutes($stepMinutes);
            }
        }

        return $slots;
    }

    private function isAppointmentSlotAvailable(
        Company $company,
        Carbon $startsAtLocal,
        Carbon $endsAtLocal
    ): bool {
        $timezone = $this->companyTimezone($company);
        $settings = is_array($company->settings) ? $company->settings : [];
        $schedule = is_array(data_get($settings, 'business.schedule')) ? data_get($settings, 'business.schedule') : [];
        $dayKey = Str::lower($startsAtLocal->copy()->setTimezone($timezone)->englishDayOfWeek);
        $day = is_array($schedule[$dayKey] ?? null) ? $schedule[$dayKey] : [];

        if ((bool) ($day['is_day_off'] ?? false)) {
            return false;
        }

        $startTime = trim((string) ($day['start_time'] ?? ''));
        $endTime = trim((string) ($day['end_time'] ?? ''));
        if (! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $startTime) || ! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $endTime)) {
            return false;
        }

        $dayStart = Carbon::createFromFormat('Y-m-d H:i', $startsAtLocal->format('Y-m-d').' '.$startTime, $timezone);
        $dayEnd = Carbon::createFromFormat('Y-m-d H:i', $startsAtLocal->format('Y-m-d').' '.$endTime, $timezone);

        if ($startsAtLocal->lt($dayStart) || $endsAtLocal->gt($dayEnd) || $endsAtLocal->lessThanOrEqualTo($startsAtLocal)) {
            return false;
        }

        $startsAtUtc = $startsAtLocal->copy()->utc();
        $endsAtUtc = $endsAtLocal->copy()->utc();

        $events = CompanyCalendarEvent::query()
            ->where('company_id', $company->id)
            ->whereIn('status', [
                CompanyCalendarEvent::STATUS_SCHEDULED,
                CompanyCalendarEvent::STATUS_CONFIRMED,
            ])
            ->where('starts_at', '<', $endsAtUtc)
            ->orderBy('starts_at')
            ->get([
                'starts_at',
                'ends_at',
            ]);

        foreach ($events as $event) {
            $eventStart = $event->starts_at;
            $eventEnd = $event->ends_at ?? $event->starts_at?->copy()->addMinutes(30);

            if (! $eventStart || ! $eventEnd) {
                continue;
            }

            if ($eventStart->lt($endsAtUtc) && $eventEnd->gt($startsAtUtc)) {
                return false;
            }
        }

        return true;
    }
}
