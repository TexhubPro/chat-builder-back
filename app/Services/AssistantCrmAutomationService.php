<?php

namespace App\Services;

use App\Models\Assistant;
use App\Models\Chat;
use App\Models\Company;
use App\Models\CompanyCalendarEvent;
use App\Models\CompanyClient;
use App\Models\CompanyClientOrder;
use App\Models\CompanyClientQuestion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class AssistantCrmAutomationService
{
    private const ORDER_FIELD_OPTIONS = [
        'client_name',
        'phone',
        'service_name',
        'address',
        'amount',
        'note',
    ];

    private const APPOINTMENT_FIELD_OPTIONS = [
        'client_name',
        'phone',
        'service_name',
        'address',
        'appointment_date',
        'appointment_time',
        'appointment_duration_minutes',
        'amount',
        'note',
    ];

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
        $recentRequests = $this->recentRequestsForChatLines($company, $chat, $timezone, 8);
        $orderRequiredFields = $this->requiredOrderFields($company);
        $appointmentRequiredFields = $this->requiredAppointmentFields($company);
        $allowedResponseLanguages = $this->allowedResponseLanguages($company);
        $deliveryConfig = $this->deliveryConfig($company);
        $catalogLines = $this->catalogLines($assistant, 30);
        $settingsSnapshot = $this->companySettingsSnapshot(
            $company,
            $appointmentsEnabled,
            $appointmentConfig,
            $deliveryConfig,
            $orderRequiredFields,
            $appointmentRequiredFields,
            $allowedResponseLanguages,
        );

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
            '- Allowed response languages for this company: '.implode(', ', $allowedResponseLanguages).'.',
            '- Appointment booking enabled: '.($appointmentsEnabled ? 'yes' : 'no'),
            '- Appointment slot minutes: '.(string) $appointmentConfig['slot_minutes'],
            '- Appointment buffer minutes: '.(string) $appointmentConfig['buffer_minutes'],
            '- Appointment max days ahead: '.(string) $appointmentConfig['max_days_ahead'],
            '- Appointment auto-confirm: '.($appointmentConfig['auto_confirm'] ? 'yes' : 'no'),
            '- Delivery enabled: '.($deliveryConfig['enabled'] ? 'yes' : 'no'),
            '- Company settings snapshot JSON: '.$settingsSnapshot,
            '- Working schedule:',
        ];

        if ($deliveryConfig['enabled']) {
            $contextLines[] = '- Delivery address required: '.($deliveryConfig['require_delivery_address'] ? 'yes' : 'no');
            $contextLines[] = '- Delivery datetime required: '.($deliveryConfig['require_delivery_datetime'] ? 'yes' : 'no');
            $contextLines[] = '- Delivery default ETA minutes: '.(string) $deliveryConfig['default_eta_minutes'];
            $contextLines[] = '- Delivery fee: '.number_format((float) $deliveryConfig['fee'], 2, '.', '').' TJS';
            $contextLines[] = '- Delivery free from amount: '.($deliveryConfig['free_from_amount'] !== null
                ? number_format((float) $deliveryConfig['free_from_amount'], 2, '.', '').' TJS'
                : 'not configured');
            $contextLines[] = '- Delivery window: '.$deliveryConfig['available_from'].'-'.$deliveryConfig['available_to'];

            if ($deliveryConfig['notes'] !== null && $deliveryConfig['notes'] !== '') {
                $contextLines[] = '- Delivery notes: '.Str::limit($deliveryConfig['notes'], 200, '');
            }
        }

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

        $contextLines[] = '- Recent requests linked to this chat (use order_id for update/cancel actions):';
        if ($recentRequests === []) {
            $contextLines[] = '  - none';
        } else {
            foreach ($recentRequests as $line) {
                $contextLines[] = '  - '.$line;
            }
        }

        $contextLines[] = '- Company catalog with fixed prices (use exact amount from DB for crm_action):';
        if ($catalogLines === []) {
            $contextLines[] = '  - none';
        } else {
            foreach ($catalogLines as $line) {
                $contextLines[] = '  - '.$line;
            }
        }

        $contextLines[] = 'CRM automation policy:';
        $contextLines[] = '- Required fields for order in this company: '.implode(', ', $orderRequiredFields).'.';
        $contextLines[] = '- Required fields for appointment in this company: '.implode(', ', $appointmentRequiredFields).'.';
        $contextLines[] = '- Strict mode: ask only fields from required list for the current action.';
        $contextLines[] = '- If a field is not in required list, do not ask it.';
        $contextLines[] = '- For order, ask only missing fields from the required order list.';
        $contextLines[] = '- For appointment, ask only missing fields from the required appointment list.';
        $contextLines[] = '- create_appointment must always include appointment_date, appointment_time and appointment_duration_minutes in crm_action.';
        $contextLines[] = '- If order item exists in company catalog, use exact catalog item name and catalog price in crm_action amount.';
        $contextLines[] = '- Do not ask customer for amount when catalog item is found.';
        $contextLines[] = '- Delivery settings apply only to delivery orders.';
        $contextLines[] = '- If delivery order requires delivery datetime, include delivery_datetime in crm_action format YYYY-MM-DD HH:MM.';
        $contextLines[] = '- If required data is missing, ask concise follow-up questions and DO NOT emit crm_action.';
        $contextLines[] = '- Reply only in one of allowed response languages listed above.';
        $contextLines[] = '- If customer writes in another language, politely ask to continue in allowed languages.';
        $contextLines[] = '- All actions and replies must strictly follow company settings snapshot JSON above.';
        $contextLines[] = '- If customer asks to cancel an existing request, use cancel_order or cancel_appointment action.';
        $contextLines[] = '- If customer asks to change booking date/time, use reschedule_appointment action.';
        $contextLines[] = '- For update/cancel actions prefer order_id from recent requests list. If not available, latest request in current chat will be used.';
        $contextLines[] = '- If a customer asks a company-related question you cannot answer from current context/instructions/catalog, create a client question for manager follow-up.';
        $contextLines[] = '- Do not create client questions for sensitive personal/confidential data or topics unrelated to this company.';
        $contextLines[] = '- When all required data is collected, append exactly one machine block at the very end:';
        $contextLines[] = '  <crm_action>{"action":"create_order","client_name":"...","phone":"...","service_name":"...","address":"...","delivery_datetime":"YYYY-MM-DD HH:MM","amount":0,"note":"..."}</crm_action>';
        $contextLines[] = '- For appointment booking use:';
        $contextLines[] = '  <crm_action>{"action":"create_appointment","client_name":"...","phone":"...","service_name":"...","address":"...","appointment_date":"YYYY-MM-DD","appointment_time":"HH:MM","appointment_duration_minutes":60,"amount":0,"note":"..."}</crm_action>';
        $contextLines[] = '- For canceling existing request use:';
        $contextLines[] = '  <crm_action>{"action":"cancel_order","order_id":123,"reason":"..."}</crm_action>';
        $contextLines[] = '- For canceling existing appointment use:';
        $contextLines[] = '  <crm_action>{"action":"cancel_appointment","order_id":123,"reason":"..."}</crm_action>';
        $contextLines[] = '- For rescheduling existing appointment use:';
        $contextLines[] = '  <crm_action>{"action":"reschedule_appointment","order_id":123,"appointment_date":"YYYY-MM-DD","appointment_time":"HH:MM","appointment_duration_minutes":60,"note":"..."}</crm_action>';
        $contextLines[] = '- For unanswered company-related question use:';
        $contextLines[] = '  <crm_action>{"action":"create_question","description":"...","company_related":true,"covered_in_instructions":false,"contains_sensitive_data":false}</crm_action>';
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
            'cancel_order' => $this->cancelOrderFromPayload(
                $company,
                $chat,
                $payload,
                false
            ),
            'cancel_appointment' => $this->cancelOrderFromPayload(
                $company,
                $chat,
                $payload,
                true
            ),
            'reschedule_appointment' => $this->rescheduleAppointmentFromPayload(
                $company,
                $chat,
                $assistant,
                $payload
            ),
            'create_question' => $this->createQuestionFromPayload(
                $company,
                $chat,
                $assistant,
                $payload
            ),
            default => null,
        };
    }

    private function createQuestionFromPayload(
        Company $company,
        Chat $chat,
        Assistant $assistant,
        array $payload
    ): ?string {
        if ($this->boolFromPayload($payload['company_related'] ?? true) !== true) {
            return null;
        }

        if ($this->boolFromPayload($payload['contains_sensitive_data'] ?? false) === true) {
            return null;
        }

        if ($this->boolFromPayload($payload['covered_in_instructions'] ?? false) === true) {
            return null;
        }

        $description = trim((string) ($payload['description'] ?? $payload['question'] ?? $payload['note'] ?? ''));
        if ($description === '') {
            return null;
        }

        $description = Str::limit(
            preg_replace('/\s+/u', ' ', strip_tags($description)) ?: $description,
            2000,
            ''
        );

        if ($description === '') {
            return null;
        }

        if ($this->questionLooksCoveredByAssistantInstructions($assistant, $description)) {
            return null;
        }

        if ($this->hasActiveQuestionForChat($company, $chat->id)) {
            return null;
        }

        $linkedClient = $this->resolveLinkedClient($company, $chat);
        $phone = $this->fallbackPhoneForChat($chat, $linkedClient) ?? ('chat-'.$chat->id);
        $clientName = $this->fallbackClientNameForChat($chat, $linkedClient, $phone);
        $address = $this->resolveAddressFromPayloadOrContext($payload, $chat, $linkedClient);

        $client = $linkedClient ?? $this->resolveOrCreateClient(
            $company,
            $chat,
            $clientName,
            $phone,
            $address,
        );

        if (! $client) {
            return null;
        }

        CompanyClientQuestion::query()->create([
            'user_id' => $company->user_id,
            'company_id' => $company->id,
            'company_client_id' => $client->id,
            'assistant_id' => $assistant->id,
            'description' => $description,
            'status' => CompanyClientQuestion::STATUS_OPEN,
            'board_column' => 'new',
            'metadata' => [
                'source' => 'assistant_crm_action',
                'chat_id' => $chat->id,
                'source_channel' => $chat->channel,
                'assistant_action' => $payload,
            ],
        ]);

        return null;
    }

    private function createOrderFromPayload(
        Company $company,
        Chat $chat,
        Assistant $assistant,
        array $payload,
        bool $bookAppointment
    ): ?string {
        if ($bookAppointment && ! $this->appointmentsEnabledForCompany($company)) {
            return null;
        }

        $timezone = $this->companyTimezone($company);
        $requiredFields = $bookAppointment
            ? $this->requiredAppointmentFields($company)
            : $this->requiredOrderFields($company);
        $linkedClient = $this->resolveLinkedClient($company, $chat);

        $rawPhone = $this->normalizePhone($payload['phone'] ?? null);
        $fallbackPhone = $this->fallbackPhoneForChat($chat, $linkedClient);
        $phone = $rawPhone ?? $fallbackPhone ?? 'chat-'.$chat->id;

        $rawClientName = trim((string) ($payload['client_name'] ?? ''));
        $clientName = $rawClientName !== ''
            ? $rawClientName
            : $this->fallbackClientNameForChat($chat, $linkedClient, $phone);

        $rawServiceName = trim((string) ($payload['service_name'] ?? ''));
        $serviceName = $rawServiceName !== ''
            ? $rawServiceName
            : ($bookAppointment ? 'Appointment request' : 'Order request');

        $address = $this->resolveAddressFromPayloadOrContext($payload, $chat, $linkedClient);
        $note = trim((string) ($payload['note'] ?? ''));

        $hasAmountValue = array_key_exists('amount', $payload) && is_numeric($payload['amount']);
        $amount = $hasAmountValue ? $this->normalizedAmount($payload['amount']) : 0.0;
        $currency = 'TJS';
        $catalogMatch = $this->resolveCatalogItemByName($assistant, $serviceName);
        if ($catalogMatch !== null) {
            $serviceName = (string) $catalogMatch['name'];
            $amount = (float) $catalogMatch['price'];
            $currency = (string) $catalogMatch['currency'];
            $hasAmountValue = true;
        }

        $deliveryConfig = $this->deliveryConfig($company);
        $deliveryDateTime = $this->resolveDeliveryDateTimeFromPayload($payload, $timezone);

        $appointmentDate = null;
        $appointmentTime = null;
        $appointmentDuration = null;
        $startsAtLocal = null;
        $endsAtLocal = null;
        $startsAtUtc = null;
        $endsAtUtc = null;

        if ($bookAppointment) {
            $appointmentDateCandidate = trim((string) ($payload['appointment_date'] ?? ''));
            $appointmentTimeCandidate = trim((string) ($payload['appointment_time'] ?? ''));
            $appointmentDurationCandidate = $this->normalizeDurationMinutes(
                $payload['appointment_duration_minutes'] ?? $payload['duration_minutes'] ?? null
            );

            if ($appointmentDurationCandidate === null) {
                return null;
            }

            $hasDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $appointmentDateCandidate) === 1;
            $hasTime = preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $appointmentTimeCandidate) === 1;

            if (! $hasDate || ! $hasTime) {
                return null;
            }

            if (! $hasDate || ! $hasTime) {
                return null;
            }

            $startsAtLocal = Carbon::createFromFormat(
                'Y-m-d H:i',
                "{$appointmentDateCandidate} {$appointmentTimeCandidate}",
                $timezone
            );
            $endsAtLocal = (clone $startsAtLocal)->addMinutes($appointmentDurationCandidate);

            if (! $this->isAppointmentSlotAvailable($company, $startsAtLocal, $endsAtLocal)) {
                return null;
            }

            $appointmentDate = $appointmentDateCandidate;
            $appointmentTime = $appointmentTimeCandidate;
            $appointmentDuration = $appointmentDurationCandidate;
            $startsAtUtc = $startsAtLocal->copy()->utc();
            $endsAtUtc = $endsAtLocal->copy()->utc();
        }

        $fieldValues = [
            'client_name' => $clientName,
            'phone' => $phone,
            'service_name' => $serviceName,
            'address' => $address,
            'amount_set' => $hasAmountValue,
            'note' => $note,
            'appointment_date' => $appointmentDate,
            'appointment_time' => $appointmentTime,
            'appointment_duration_minutes' => $appointmentDuration,
        ];

        foreach ($requiredFields as $requiredField) {
            if (! $this->isRequiredFieldValuePresent($requiredField, $fieldValues)) {
                return null;
            }
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
            'required_fields' => $requiredFields,
            'assistant_action' => $payload,
        ];

        if ($catalogMatch !== null) {
            $orderMetadata['catalog_match'] = [
                'type' => (string) $catalogMatch['type'],
                'id' => (int) $catalogMatch['id'],
                'name' => (string) $catalogMatch['name'],
                'price' => $catalogMatch['price'],
                'currency' => (string) $catalogMatch['currency'],
            ];
        }

        if (! $bookAppointment && $deliveryConfig['enabled']) {
            $orderMetadata['delivery'] = [
                'enabled' => true,
                'required_address' => (bool) $deliveryConfig['require_delivery_address'],
                'required_datetime' => (bool) $deliveryConfig['require_delivery_datetime'],
                'requested_datetime' => $deliveryDateTime,
                'default_eta_minutes' => (int) $deliveryConfig['default_eta_minutes'],
                'fee' => (float) $deliveryConfig['fee'],
                'free_from_amount' => $deliveryConfig['free_from_amount'],
                'available_from' => (string) $deliveryConfig['available_from'],
                'available_to' => (string) $deliveryConfig['available_to'],
                'notes' => $deliveryConfig['notes'],
            ];
        }

        $calendarEvent = null;

        if ($bookAppointment) {
            if (
                $appointmentDate === null
                || $appointmentTime === null
                || $appointmentDuration === null
                || $startsAtUtc === null
                || $endsAtUtc === null
            ) {
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
                'location' => $address !== '' ? Str::limit($address, 255, '') : null,
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
            'currency' => Str::limit($currency, 12, ''),
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

    private function cancelOrderFromPayload(
        Company $company,
        Chat $chat,
        array $payload,
        bool $appointmentOnly
    ): ?string {
        $order = $this->resolveOrderForAction($company, $chat, $payload, $appointmentOnly);
        if (! $order) {
            return null;
        }

        $metadata = is_array($order->metadata) ? $order->metadata : [];
        $reason = trim((string) ($payload['reason'] ?? $payload['note'] ?? ''));
        $hadAppointment = $this->orderHasAppointment($order);
        $calendarEvent = $this->resolveOrderCalendarEvent($company, $order, $payload);

        if ($calendarEvent) {
            $eventMetadata = is_array($calendarEvent->metadata) ? $calendarEvent->metadata : [];
            $eventMetadata['order_id'] = $order->id;
            $eventMetadata['source'] = 'assistant_crm_action';
            $eventMetadata['canceled_by'] = 'assistant';
            $eventMetadata['canceled_at'] = now()->toIso8601String();
            if ($reason !== '') {
                $eventMetadata['cancellation_reason'] = Str::limit($reason, 500, '');
            }

            $calendarEvent->forceFill([
                'status' => CompanyCalendarEvent::STATUS_CANCELED,
                'metadata' => $eventMetadata,
            ])->save();

            if (is_array($metadata['appointment'] ?? null)) {
                $metadata['appointment']['status'] = CompanyCalendarEvent::STATUS_CANCELED;
                $metadata['appointment']['canceled_at'] = now()->toIso8601String();
                if ($reason !== '') {
                    $metadata['appointment']['cancellation_reason'] = Str::limit($reason, 500, '');
                }
            }
        }

        $metadata['canceled'] = true;
        $metadata['canceled_at'] = now()->toIso8601String();
        if ($reason !== '') {
            $metadata['cancellation_reason'] = Str::limit($reason, 500, '');
        }
        $metadata['assistant_cancel_action'] = $payload;

        $order->forceFill([
            'status' => CompanyClientOrder::STATUS_COMPLETED,
            'completed_at' => $order->completed_at ?? now(),
            'metadata' => $metadata,
        ])->save();

        if ($calendarEvent || $hadAppointment || $appointmentOnly) {
            return 'Запись отменена и перенесена в завершенные.';
        }

        return 'Заявка отменена и перенесена в завершенные.';
    }

    private function rescheduleAppointmentFromPayload(
        Company $company,
        Chat $chat,
        Assistant $assistant,
        array $payload
    ): ?string {
        if (! $this->appointmentsEnabledForCompany($company)) {
            return null;
        }

        $order = $this->resolveOrderForAction($company, $chat, $payload, true);
        if (! $order) {
            return null;
        }

        $appointmentDate = trim((string) ($payload['appointment_date'] ?? ''));
        $appointmentTime = trim((string) ($payload['appointment_time'] ?? ''));
        $appointmentDuration = $this->normalizeDurationMinutes(
            $payload['appointment_duration_minutes'] ?? $payload['duration_minutes'] ?? null
        );

        if (
            preg_match('/^\d{4}-\d{2}-\d{2}$/', $appointmentDate) !== 1
            || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $appointmentTime) !== 1
            || $appointmentDuration === null
        ) {
            return null;
        }

        $timezone = $this->companyTimezone($company);
        try {
            $startsAtLocal = Carbon::createFromFormat(
                'Y-m-d H:i',
                "{$appointmentDate} {$appointmentTime}",
                $timezone
            );
        } catch (Throwable) {
            return null;
        }

        $endsAtLocal = (clone $startsAtLocal)->addMinutes($appointmentDuration);
        $calendarEvent = $this->resolveOrderCalendarEvent($company, $order, $payload);

        if (! $this->isAppointmentSlotAvailable($company, $startsAtLocal, $endsAtLocal, $calendarEvent?->id)) {
            return null;
        }

        $startsAtUtc = $startsAtLocal->copy()->utc();
        $endsAtUtc = $endsAtLocal->copy()->utc();

        $metadata = is_array($order->metadata) ? $order->metadata : [];
        $location = trim((string) ($payload['address'] ?? ($metadata['address'] ?? '')));
        $note = trim((string) ($payload['note'] ?? ''));

        if ($location !== '') {
            $metadata['address'] = Str::limit($location, 255, '');
        }

        if ($note !== '') {
            $order->notes = Str::limit($note, 2000, '');
        }

        if ($calendarEvent) {
            $eventMetadata = is_array($calendarEvent->metadata) ? $calendarEvent->metadata : [];
            $eventMetadata['order_id'] = $order->id;
            $eventMetadata['source'] = 'assistant_crm_action';
            $eventMetadata['rescheduled_at'] = now()->toIso8601String();

            $calendarEvent->forceFill([
                'title' => 'Appointment: '.Str::limit((string) $order->service_name, 120, ''),
                'description' => $order->notes,
                'starts_at' => $startsAtUtc,
                'ends_at' => $endsAtUtc,
                'timezone' => $timezone,
                'status' => CompanyCalendarEvent::STATUS_SCHEDULED,
                'location' => $location !== '' ? Str::limit($location, 255, '') : null,
                'metadata' => $eventMetadata,
            ])->save();
        } else {
            $calendarEvent = CompanyCalendarEvent::query()->create([
                'user_id' => $company->user_id,
                'company_id' => $company->id,
                'company_client_id' => $order->company_client_id,
                'assistant_id' => $order->assistant_id ?? $assistant->id,
                'assistant_service_id' => $order->assistant_service_id,
                'title' => 'Appointment: '.Str::limit((string) $order->service_name, 120, ''),
                'description' => $order->notes,
                'starts_at' => $startsAtUtc,
                'ends_at' => $endsAtUtc,
                'timezone' => $timezone,
                'status' => CompanyCalendarEvent::STATUS_SCHEDULED,
                'location' => $location !== '' ? Str::limit($location, 255, '') : null,
                'metadata' => [
                    'source' => 'assistant_crm_action',
                    'order_id' => $order->id,
                    'chat_id' => $chat->id,
                ],
            ]);
        }

        $metadata['appointment'] = [
            'calendar_event_id' => $calendarEvent->id,
            'starts_at' => $calendarEvent->starts_at?->toIso8601String(),
            'ends_at' => $calendarEvent->ends_at?->toIso8601String(),
            'timezone' => $calendarEvent->timezone,
            'duration_minutes' => $appointmentDuration,
            'status' => CompanyCalendarEvent::STATUS_SCHEDULED,
        ];
        $metadata['assistant_reschedule_action'] = $payload;

        $order->forceFill([
            'status' => CompanyClientOrder::STATUS_APPOINTMENTS,
            'completed_at' => null,
            'metadata' => $metadata,
        ])->save();

        return 'Запись обновлена.';
    }

    private function resolveOrderForAction(
        Company $company,
        Chat $chat,
        array $payload,
        bool $requireAppointment
    ): ?CompanyClientOrder {
        $orderId = $this->positiveInt($payload['order_id'] ?? null);
        if ($orderId !== null) {
            $order = CompanyClientOrder::query()
                ->where('company_id', $company->id)
                ->whereKey($orderId)
                ->first();

            if ($order && (! $requireAppointment || $this->orderHasAppointment($order))) {
                return $order;
            }
        }

        $calendarEventId = $this->positiveInt($payload['calendar_event_id'] ?? null);
        if ($calendarEventId !== null) {
            $calendarEvent = CompanyCalendarEvent::query()
                ->where('company_id', $company->id)
                ->whereKey($calendarEventId)
                ->first();

            if ($calendarEvent) {
                $eventMetadata = is_array($calendarEvent->metadata) ? $calendarEvent->metadata : [];
                $metadataOrderId = $this->positiveInt($eventMetadata['order_id'] ?? null);

                if ($metadataOrderId !== null) {
                    $order = CompanyClientOrder::query()
                        ->where('company_id', $company->id)
                        ->whereKey($metadataOrderId)
                        ->first();

                    if ($order && (! $requireAppointment || $this->orderHasAppointment($order))) {
                        return $order;
                    }
                }

                $orders = CompanyClientOrder::query()
                    ->where('company_id', $company->id)
                    ->orderByDesc('ordered_at')
                    ->orderByDesc('id')
                    ->limit(200)
                    ->get()
                    ->filter(fn (CompanyClientOrder $order): bool => $this->extractCalendarEventIdFromOrderMetadata($order->metadata) === $calendarEventId)
                    ->values();

                $candidate = $this->selectBestOrderCandidate($orders->all(), $requireAppointment);
                if ($candidate) {
                    return $candidate;
                }
            }
        }

        $phone = $this->normalizePhone($payload['phone'] ?? null);
        if ($phone !== null) {
            $client = $company->clients()
                ->where('phone', $phone)
                ->first();

            if ($client) {
                $orders = CompanyClientOrder::query()
                    ->where('company_id', $company->id)
                    ->where('company_client_id', $client->id)
                    ->orderByDesc('ordered_at')
                    ->orderByDesc('id')
                    ->limit(50)
                    ->get();

                $candidate = $this->selectBestOrderCandidate($orders->all(), $requireAppointment);
                if ($candidate) {
                    return $candidate;
                }
            }
        }

        $chatCandidates = [];
        $payloadChatId = $this->positiveInt($payload['chat_id'] ?? null);
        if ($payloadChatId !== null) {
            $chatCandidates[] = $payloadChatId;
        }
        $chatCandidates[] = (int) $chat->id;
        $chatCandidates = array_values(array_unique(array_filter($chatCandidates, static fn (int $chatId): bool => $chatId > 0)));

        foreach ($chatCandidates as $chatId) {
            $orders = CompanyClientOrder::query()
                ->where('company_id', $company->id)
                ->orderByDesc('ordered_at')
                ->orderByDesc('id')
                ->limit(200)
                ->get()
                ->filter(fn (CompanyClientOrder $order): bool => $this->metadataHasChatLink($order->metadata, $chatId))
                ->values();

            $candidate = $this->selectBestOrderCandidate($orders->all(), $requireAppointment);
            if ($candidate) {
                return $candidate;
            }
        }

        $linkedClient = $this->resolveLinkedClient($company, $chat);
        if ($linkedClient) {
            $orders = CompanyClientOrder::query()
                ->where('company_id', $company->id)
                ->where('company_client_id', $linkedClient->id)
                ->orderByDesc('ordered_at')
                ->orderByDesc('id')
                ->limit(50)
                ->get();

            return $this->selectBestOrderCandidate($orders->all(), $requireAppointment);
        }

        return null;
    }

    /**
     * @param array<int, CompanyClientOrder> $orders
     */
    private function selectBestOrderCandidate(array $orders, bool $requireAppointment): ?CompanyClientOrder
    {
        if ($orders === []) {
            return null;
        }

        $passes = [
            static fn (CompanyClientOrder $order): bool => (string) $order->status !== CompanyClientOrder::STATUS_COMPLETED,
            static fn (CompanyClientOrder $order): bool => true,
        ];

        foreach ($passes as $statusFilter) {
            foreach ($orders as $order) {
                if (! $statusFilter($order)) {
                    continue;
                }

                if ($this->isArchivedOrder($order->metadata)) {
                    continue;
                }

                if ($requireAppointment && ! $this->orderHasAppointment($order)) {
                    continue;
                }

                return $order;
            }
        }

        return null;
    }

    private function resolveOrderCalendarEvent(
        Company $company,
        CompanyClientOrder $order,
        array $payload
    ): ?CompanyCalendarEvent {
        $payloadEventId = $this->positiveInt($payload['calendar_event_id'] ?? null);
        $metadataEventId = $this->extractCalendarEventIdFromOrderMetadata($order->metadata);
        $eventId = $payloadEventId ?? $metadataEventId;

        if ($eventId === null) {
            return null;
        }

        return CompanyCalendarEvent::query()
            ->where('company_id', $company->id)
            ->whereKey($eventId)
            ->first();
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

    private function catalogLines(Assistant $assistant, int $limit): array
    {
        $maxItems = max(min($limit, 80), 1);
        $serviceLimit = max((int) ceil($maxItems / 2), 1);
        $productLimit = max($maxItems - $serviceLimit, 1);

        $services = $assistant->services()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->limit($serviceLimit)
            ->get([
                'id',
                'name',
                'price',
                'currency',
            ]);

        $products = $assistant->products()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->limit($productLimit)
            ->get([
                'id',
                'name',
                'price',
                'currency',
                'stock_quantity',
                'is_unlimited_stock',
            ]);

        $lines = [];

        foreach ($services as $service) {
            $lines[] = sprintf(
                '[service #%d] %s | %s %s',
                (int) $service->id,
                Str::limit((string) $service->name, 120, ''),
                number_format($this->normalizedAmount($service->price), 2, '.', ''),
                (string) ($service->currency ?: 'TJS')
            );
        }

        foreach ($products as $product) {
            $stockLabel = (bool) $product->is_unlimited_stock
                ? 'unlimited stock'
                : ('stock '.max((int) ($product->stock_quantity ?? 0), 0));
            $lines[] = sprintf(
                '[product #%d] %s | %s %s | %s',
                (int) $product->id,
                Str::limit((string) $product->name, 120, ''),
                number_format($this->normalizedAmount($product->price), 2, '.', ''),
                (string) ($product->currency ?: 'TJS'),
                $stockLabel
            );
        }

        return $lines;
    }

    private function resolveCatalogItemByName(Assistant $assistant, string $name): ?array
    {
        $needle = $this->catalogLookupKey($name);
        if ($needle === '') {
            return null;
        }

        $services = $assistant->services()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->limit(200)
            ->get([
                'id',
                'name',
                'price',
                'currency',
            ]);

        foreach ($services as $service) {
            if ($this->catalogLookupKey((string) $service->name) !== $needle) {
                continue;
            }

            return [
                'type' => 'service',
                'id' => (int) $service->id,
                'name' => trim((string) $service->name),
                'price' => $this->normalizedAmount($service->price),
                'currency' => trim((string) ($service->currency ?: 'TJS')),
            ];
        }

        $products = $assistant->products()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->limit(200)
            ->get([
                'id',
                'name',
                'price',
                'currency',
            ]);

        foreach ($products as $product) {
            if ($this->catalogLookupKey((string) $product->name) !== $needle) {
                continue;
            }

            return [
                'type' => 'product',
                'id' => (int) $product->id,
                'name' => trim((string) $product->name),
                'price' => $this->normalizedAmount($product->price),
                'currency' => trim((string) ($product->currency ?: 'TJS')),
            ];
        }

        return null;
    }

    private function catalogLookupKey(string $value): string
    {
        $normalized = Str::lower(trim($value));
        if ($normalized === '') {
            return '';
        }

        return (string) preg_replace('/\s+/u', ' ', $normalized);
    }

    private function deliveryConfig(Company $company): array
    {
        $settings = is_array($company->settings) ? $company->settings : [];
        $rawFreeFrom = data_get($settings, 'delivery.free_from_amount');

        return [
            'enabled' => (bool) data_get($settings, 'delivery.enabled', false),
            'require_delivery_address' => (bool) data_get($settings, 'delivery.require_delivery_address', true),
            'require_delivery_datetime' => (bool) data_get($settings, 'delivery.require_delivery_datetime', true),
            'default_eta_minutes' => max((int) data_get($settings, 'delivery.default_eta_minutes', 120), 15),
            'fee' => $this->normalizedAmount(data_get($settings, 'delivery.fee', 0)),
            'free_from_amount' => is_numeric($rawFreeFrom) ? $this->normalizedAmount($rawFreeFrom) : null,
            'available_from' => $this->normalizeClockTime((string) data_get($settings, 'delivery.available_from', '09:00'), '09:00'),
            'available_to' => $this->normalizeClockTime((string) data_get($settings, 'delivery.available_to', '21:00'), '21:00'),
            'notes' => $this->normalizeNullableText(data_get($settings, 'delivery.notes')),
        ];
    }

    private function resolveDeliveryDateTimeFromPayload(array $payload, string $timezone): ?string
    {
        $directCandidates = [
            $payload['delivery_datetime'] ?? null,
            $payload['delivery_at'] ?? null,
        ];

        foreach ($directCandidates as $candidate) {
            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            $parsed = $this->parseDateTimeInTimezone($candidate, $timezone);
            if ($parsed !== null) {
                return $parsed->setTimezone($timezone)->format('Y-m-d H:i');
            }
        }

        $date = trim((string) ($payload['delivery_date'] ?? ''));
        $time = trim((string) ($payload['delivery_time'] ?? ''));
        if (
            preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1
            && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time) === 1
        ) {
            $parsed = $this->parseDateTimeInTimezone($date.' '.$time, $timezone);

            return $parsed?->setTimezone($timezone)->format('Y-m-d H:i');
        }

        return null;
    }

    private function parseDateTimeInTimezone(string $value, string $timezone): ?Carbon
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        $formats = [
            'Y-m-d H:i',
            'Y-m-d\TH:i',
            'Y-m-d\TH:i:s',
            \DateTimeInterface::ATOM,
        ];

        foreach ($formats as $format) {
            try {
                return Carbon::createFromFormat($format, $trimmed, $timezone);
            } catch (Throwable) {
                // Continue to next format.
            }
        }

        try {
            return Carbon::parse($trimmed, $timezone);
        } catch (Throwable) {
            return null;
        }
    }

    private function normalizeClockTime(string $value, string $fallback): string
    {
        $trimmed = trim($value);
        if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $trimmed) === 1) {
            return $trimmed;
        }

        return $fallback;
    }

    private function normalizeNullableText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function positiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $intValue = (int) $value;

        return $intValue > 0 ? $intValue : null;
    }

    private function isArchivedOrder(mixed $metadata): bool
    {
        if (! is_array($metadata)) {
            return false;
        }

        $archived = data_get($metadata, 'archived');

        return $archived === true
            || $archived === 1
            || $archived === '1';
    }

    private function extractCalendarEventIdFromOrderMetadata(mixed $metadata): ?int
    {
        if (! is_array($metadata)) {
            return null;
        }

        return $this->positiveInt(data_get($metadata, 'appointment.calendar_event_id'));
    }

    private function orderHasAppointment(CompanyClientOrder $order): bool
    {
        return $this->extractCalendarEventIdFromOrderMetadata($order->metadata) !== null;
    }

    private function metadataHasChatLink(mixed $metadata, int $chatId): bool
    {
        if (! is_array($metadata) || $chatId <= 0) {
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

    private function hasActiveQuestionForChat(Company $company, int $chatId): bool
    {
        if ($chatId <= 0) {
            return false;
        }

        return CompanyClientQuestion::query()
            ->where('company_id', $company->id)
            ->whereIn('board_column', ['new', 'in_progress'])
            ->where(function ($builder) use ($chatId): void {
                $builder
                    ->where('metadata->chat_id', $chatId)
                    ->orWhere('metadata->source_chat_id', $chatId)
                    ->orWhere('metadata->chat->id', $chatId)
                    ->orWhere('metadata->source->chat_id', $chatId);
            })
            ->exists();
    }

    private function questionLooksCoveredByAssistantInstructions(Assistant $assistant, string $description): bool
    {
        $descriptionTokens = $this->tokenizeForInstructionCoverage($description);
        if ($descriptionTokens === []) {
            return false;
        }

        $instructionSources = array_filter([
            trim((string) config('openai.assistant.base_instructions', '')),
            trim((string) config('openai.assistant.base_limits', '')),
            trim((string) ($assistant->instructions ?? '')),
            trim((string) ($assistant->restrictions ?? '')),
        ], static fn (string $value): bool => $value !== '');

        if ($instructionSources === []) {
            return false;
        }

        $instructionCorpus = mb_strtolower(implode(' ', $instructionSources));
        $matched = 0;

        foreach ($descriptionTokens as $token) {
            if (mb_strlen($token) < 4) {
                continue;
            }

            if (str_contains($instructionCorpus, $token)) {
                $matched++;
            }
        }

        return $matched >= 3;
    }

    /**
     * @return list<string>
     */
    private function tokenizeForInstructionCoverage(string $value): array
    {
        $normalized = mb_strtolower(trim($value));
        if ($normalized === '') {
            return [];
        }

        $parts = preg_split('/[^\p{L}\p{N}]+/u', $normalized);
        if (! is_array($parts)) {
            return [];
        }

        $tokens = [];

        foreach ($parts as $part) {
            $token = trim($part);

            if ($token === '') {
                continue;
            }

            $tokens[] = $token;
        }

        return array_values(array_unique($tokens));
    }

    private function boolFromPayload(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        if (! is_string($value)) {
            return null;
        }

        $normalized = Str::lower(trim($value));
        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }

        if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        return null;
    }

    private function recentRequestsForChatLines(Company $company, Chat $chat, string $timezone, int $limit): array
    {
        $requests = CompanyClientOrder::query()
            ->where('company_id', $company->id)
            ->with(['client:id,name,phone'])
            ->orderByDesc('ordered_at')
            ->orderByDesc('id')
            ->limit(max($limit * 5, 40))
            ->get()
            ->filter(fn (CompanyClientOrder $order): bool => $this->metadataHasChatLink($order->metadata, (int) $chat->id))
            ->reject(fn (CompanyClientOrder $order): bool => $this->isArchivedOrder($order->metadata))
            ->take(max($limit, 1))
            ->values();

        $lines = [];

        foreach ($requests as $order) {
            $metadata = is_array($order->metadata) ? $order->metadata : [];
            $phone = trim((string) ($order->client?->phone ?? ($metadata['phone'] ?? '')));
            $serviceName = trim((string) ($order->service_name ?? ''));
            $serviceName = $serviceName !== '' ? $serviceName : 'Order';
            $orderedAt = ($order->ordered_at ?? $order->created_at)?->copy()->setTimezone($timezone);
            $orderedAtLabel = $orderedAt ? $orderedAt->format('Y-m-d H:i') : '-';
            $status = trim((string) ($order->status ?? CompanyClientOrder::STATUS_NEW));

            $line = sprintf(
                '#%d | status=%s | service=%s | phone=%s | created=%s',
                (int) $order->id,
                $status !== '' ? $status : CompanyClientOrder::STATUS_NEW,
                Str::limit($serviceName, 80, ''),
                $phone !== '' ? Str::limit($phone, 32, '') : '-',
                $orderedAtLabel
            );

            $appointment = is_array($metadata['appointment'] ?? null) ? $metadata['appointment'] : null;
            $appointmentStart = is_string($appointment['starts_at'] ?? null) ? trim((string) $appointment['starts_at']) : '';
            if ($appointmentStart !== '') {
                try {
                    $appointmentStartLabel = Carbon::parse($appointmentStart)->setTimezone($timezone)->format('Y-m-d H:i');
                    $line .= ' | appointment='.$appointmentStartLabel;
                } catch (Throwable) {
                    // Ignore invalid appointment datetime.
                }
            }

            $lines[] = $line;
        }

        return $lines;
    }

    private function requiredOrderFields(Company $company): array
    {
        $settings = is_array($company->settings) ? $company->settings : [];

        return $this->normalizeRequiredFields(
            data_get($settings, 'crm.order_required_fields'),
            ['phone', 'service_name', 'address'],
            self::ORDER_FIELD_OPTIONS,
        );
    }

    private function requiredAppointmentFields(Company $company): array
    {
        $settings = is_array($company->settings) ? $company->settings : [];

        return $this->normalizeRequiredFields(
            data_get($settings, 'crm.appointment_required_fields'),
            [
                'phone',
                'service_name',
                'address',
                'appointment_date',
                'appointment_time',
                'appointment_duration_minutes',
            ],
            self::APPOINTMENT_FIELD_OPTIONS,
        );
    }

    private function normalizeRequiredFields(
        mixed $rawFields,
        array $defaults,
        array $allowed
    ): array {
        $values = is_array($rawFields) ? $rawFields : $defaults;
        $allowedLookup = array_fill_keys($allowed, true);
        $normalized = [];

        foreach ($values as $value) {
            if (! is_string($value)) {
                continue;
            }

            $field = trim($value);
            if ($field === '' || ! isset($allowedLookup[$field])) {
                continue;
            }

            $normalized[] = $field;
        }

        return array_values(array_unique($normalized));
    }

    private function normalizePhone(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        if ($normalized === '') {
            return null;
        }

        return Str::limit($normalized, 32, '');
    }

    private function resolveLinkedClient(Company $company, Chat $chat): ?CompanyClient
    {
        $chatMetadata = is_array($chat->metadata) ? $chat->metadata : [];
        $linkedClientId = data_get($chatMetadata, 'company_client_id');

        if (! is_numeric($linkedClientId)) {
            return null;
        }

        return $company->clients()->whereKey((int) $linkedClientId)->first();
    }

    private function fallbackPhoneForChat(Chat $chat, ?CompanyClient $linkedClient): ?string
    {
        $chatMetadata = is_array($chat->metadata) ? $chat->metadata : [];

        $candidates = [
            $linkedClient?->phone,
            data_get($chatMetadata, 'phone'),
            data_get($chatMetadata, 'client_phone'),
            data_get($chatMetadata, 'contact.phone'),
            data_get($chatMetadata, 'contacts.phone'),
            $chat->channel_user_id,
        ];

        foreach ($candidates as $candidate) {
            $phone = $this->normalizePhone($candidate);

            if ($phone !== null) {
                return $phone;
            }
        }

        return null;
    }

    private function fallbackClientNameForChat(Chat $chat, ?CompanyClient $linkedClient, string $phone): string
    {
        if ($linkedClient && trim((string) $linkedClient->name) !== '') {
            return trim((string) $linkedClient->name);
        }

        $chatName = trim((string) ($chat->name ?? ''));
        if ($chatName !== '') {
            return $chatName;
        }

        return 'Client '.$phone;
    }

    private function resolveAddressFromPayloadOrContext(
        array $payload,
        Chat $chat,
        ?CompanyClient $linkedClient
    ): string {
        $address = trim((string) ($payload['address'] ?? ''));
        if ($address !== '') {
            return $address;
        }

        $linkedMetadata = is_array($linkedClient?->metadata) ? $linkedClient->metadata : [];
        $chatMetadata = is_array($chat->metadata) ? $chat->metadata : [];
        $candidates = [
            data_get($linkedMetadata, 'address'),
            data_get($chatMetadata, 'address'),
            data_get($chatMetadata, 'contact.address'),
            data_get($chatMetadata, 'contacts.address'),
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

        return '';
    }

    private function normalizeDurationMinutes(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $duration = (int) round((float) $value);

        if ($duration < 15 || $duration > 720) {
            return null;
        }

        return $duration;
    }

    private function allowedResponseLanguages(Company $company): array
    {
        $settings = is_array($company->settings) ? $company->settings : [];

        return $this->normalizeRequiredFields(
            data_get($settings, 'ai.response_languages'),
            ['ru'],
            ['ru', 'en', 'tg', 'uz', 'tr', 'fa'],
        );
    }

    private function isRequiredFieldValuePresent(string $field, array $values): bool
    {
        return match ($field) {
            'client_name',
            'phone',
            'service_name',
            'address',
            'note' => trim((string) ($values[$field] ?? '')) !== '',
            'amount' => (bool) ($values['amount_set'] ?? false),
            'appointment_date',
            'appointment_time' => trim((string) ($values[$field] ?? '')) !== '',
            'appointment_duration_minutes' => is_int($values['appointment_duration_minutes'] ?? null),
            default => true,
        };
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
            'auto_confirm' => (bool) data_get($settings, 'appointment.auto_confirm', true),
        ];
    }

    private function companySettingsSnapshot(
        Company $company,
        bool $appointmentsEnabled,
        array $appointmentConfig,
        array $deliveryConfig,
        array $orderRequiredFields,
        array $appointmentRequiredFields,
        array $allowedResponseLanguages
    ): string {
        $settings = is_array($company->settings) ? $company->settings : [];
        $businessAddress = data_get($settings, 'business.address');
        $accountType = (string) data_get($settings, 'account_type', 'without_appointments');
        $schedule = is_array(data_get($settings, 'business.schedule'))
            ? data_get($settings, 'business.schedule')
            : [];
        $companyName = trim((string) ($company->name ?? ''));

        $snapshot = [
            'company_profile' => [
                'id' => (int) $company->id,
                'name' => $companyName !== '' ? $companyName : null,
                'short_description' => $this->normalizeNullableText($company->short_description),
                'industry' => $this->normalizeNullableText($company->industry),
                'primary_goal' => $this->normalizeNullableText($company->primary_goal),
                'contact_email' => $this->normalizeNullableText($company->contact_email),
                'contact_phone' => $this->normalizeNullableText($company->contact_phone),
                'website' => $this->normalizeNullableText($company->website),
                'status' => $this->normalizeNullableText($company->status),
            ],
            'account_type' => $accountType,
            'business' => [
                'address' => is_string($businessAddress) ? trim($businessAddress) : null,
                'timezone' => $this->companyTimezone($company),
                'schedule' => $schedule,
            ],
            'appointment' => [
                'enabled' => $appointmentsEnabled,
                'slot_minutes' => (int) ($appointmentConfig['slot_minutes'] ?? 30),
                'buffer_minutes' => (int) ($appointmentConfig['buffer_minutes'] ?? 0),
                'max_days_ahead' => (int) ($appointmentConfig['max_days_ahead'] ?? 30),
                'auto_confirm' => (bool) ($appointmentConfig['auto_confirm'] ?? true),
            ],
            'delivery' => [
                'enabled' => (bool) ($deliveryConfig['enabled'] ?? false),
                'require_delivery_address' => (bool) ($deliveryConfig['require_delivery_address'] ?? true),
                'require_delivery_datetime' => (bool) ($deliveryConfig['require_delivery_datetime'] ?? true),
                'default_eta_minutes' => (int) ($deliveryConfig['default_eta_minutes'] ?? 120),
                'fee' => (float) ($deliveryConfig['fee'] ?? 0),
                'free_from_amount' => $deliveryConfig['free_from_amount'] ?? null,
                'available_from' => (string) ($deliveryConfig['available_from'] ?? '09:00'),
                'available_to' => (string) ($deliveryConfig['available_to'] ?? '21:00'),
                'notes' => $deliveryConfig['notes'] ?? null,
            ],
            'crm' => [
                'order_required_fields' => array_values($orderRequiredFields),
                'appointment_required_fields' => array_values($appointmentRequiredFields),
            ],
            'ai' => [
                'response_languages' => array_values($allowedResponseLanguages),
            ],
        ];

        $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (! is_string($json) || trim($json) === '') {
            return '{}';
        }

        return $json;
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
        Carbon $endsAtLocal,
        ?int $ignoreCalendarEventId = null
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
            ->when(
                $ignoreCalendarEventId !== null,
                fn ($query) => $query->whereKeyNot($ignoreCalendarEventId)
            )
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
