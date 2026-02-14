<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use App\Services\CompanySubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CompanyController extends Controller
{
    private const ACCOUNT_TYPE_WITH_APPOINTMENTS = 'with_appointments';
    private const ACCOUNT_TYPE_WITHOUT_APPOINTMENTS = 'without_appointments';
    private const WEEK_DAYS = [
        'monday',
        'tuesday',
        'wednesday',
        'thursday',
        'friday',
        'saturday',
        'sunday',
    ];
    private const ORDER_REQUIRED_FIELD_OPTIONS = [
        'client_name',
        'phone',
        'service_name',
        'address',
        'amount',
        'note',
    ];
    private const APPOINTMENT_REQUIRED_FIELD_OPTIONS = [
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

    public function show(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        $company = $this->resolveCompany($user);

        return response()->json([
            'company' => $this->companyPayload($company),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $rules = [
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'short_description' => ['nullable', 'string', 'max:1000'],
            'industry' => ['nullable', 'string', 'max:120'],
            'primary_goal' => ['nullable', 'string', 'max:1000'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:32', 'regex:/^[0-9+\\-\\s()]{5,32}$/'],
            'website' => ['nullable', 'url:http,https', 'max:2048'],
            'settings' => ['required', 'array'],
            'settings.account_type' => [
                'required',
                'string',
                Rule::in([
                    self::ACCOUNT_TYPE_WITH_APPOINTMENTS,
                    self::ACCOUNT_TYPE_WITHOUT_APPOINTMENTS,
                ]),
            ],
            'settings.business' => ['nullable', 'array'],
            'settings.business.address' => ['nullable', 'string', 'max:255'],
            'settings.business.timezone' => [
                'nullable',
                'string',
                Rule::in(timezone_identifiers_list()),
            ],
            'settings.business.schedule' => ['nullable', 'array'],
            'settings.appointment' => ['nullable', 'array'],
            'settings.appointment.slot_minutes' => ['nullable', 'integer', Rule::in([15, 30, 45, 60, 90, 120])],
            'settings.appointment.buffer_minutes' => ['nullable', 'integer', 'min:0', 'max:120'],
            'settings.appointment.max_days_ahead' => ['nullable', 'integer', 'min:1', 'max:365'],
            'settings.appointment.auto_confirm' => ['nullable', 'boolean'],
            'settings.appointment.require_phone' => ['nullable', 'boolean'],
            'settings.crm' => ['nullable', 'array'],
            'settings.crm.order_required_fields' => ['nullable', 'array'],
            'settings.crm.order_required_fields.*' => ['string', Rule::in(self::ORDER_REQUIRED_FIELD_OPTIONS)],
            'settings.crm.appointment_required_fields' => ['nullable', 'array'],
            'settings.crm.appointment_required_fields.*' => ['string', Rule::in(self::APPOINTMENT_REQUIRED_FIELD_OPTIONS)],
        ];

        foreach (self::WEEK_DAYS as $day) {
            $rules["settings.business.schedule.{$day}"] = ['nullable', 'array'];
            $rules["settings.business.schedule.{$day}.is_day_off"] = ['nullable', 'boolean'];
            $rules["settings.business.schedule.{$day}.start_time"] = ['nullable', 'date_format:H:i'];
            $rules["settings.business.schedule.{$day}.end_time"] = ['nullable', 'date_format:H:i'];
        }

        $validated = $request->validate($rules);

        $user = $this->resolveUser($request);
        $company = $this->resolveCompany($user);

        $name = trim((string) $validated['name']);
        $shortDescription = $this->nullableTrimmed($validated['short_description'] ?? null);
        $industry = $this->nullableTrimmed($validated['industry'] ?? null);
        $primaryGoal = $this->nullableTrimmed($validated['primary_goal'] ?? null);
        $contactEmail = $this->nullableTrimmed($validated['contact_email'] ?? null);
        $contactPhone = $this->nullableTrimmed($validated['contact_phone'] ?? null);
        $website = $this->nullableTrimmed($validated['website'] ?? null);

        $settings = $this->normalizeSettings(
            is_array($company->settings) ? $company->settings : [],
            is_array($validated['settings'] ?? null) ? $validated['settings'] : [],
        );

        $company->forceFill([
            'name' => $name,
            'short_description' => $shortDescription,
            'industry' => $industry,
            'primary_goal' => $primaryGoal,
            'contact_email' => $contactEmail,
            'contact_phone' => $contactPhone,
            'website' => $website,
            'settings' => $settings,
        ])->save();

        return response()->json([
            'message' => 'Company settings updated successfully.',
            'company' => $this->companyPayload($company->fresh()),
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

    private function defaultSettings(): array
    {
        return [
            'account_type' => self::ACCOUNT_TYPE_WITHOUT_APPOINTMENTS,
            'business' => [
                'address' => null,
                'timezone' => (string) config('app.timezone', 'UTC'),
                'schedule' => $this->defaultBusinessSchedule(),
            ],
            'appointment' => [
                'enabled' => false,
                'slot_minutes' => 30,
                'buffer_minutes' => 0,
                'max_days_ahead' => 30,
                'auto_confirm' => true,
                'require_phone' => true,
            ],
            'crm' => [
                'order_required_fields' => [
                    'phone',
                    'service_name',
                    'address',
                ],
                'appointment_required_fields' => [
                    'phone',
                    'service_name',
                    'address',
                    'appointment_date',
                    'appointment_time',
                    'appointment_duration_minutes',
                ],
            ],
        ];
    }

    private function normalizeSettings(array $existing, array $incoming): array
    {
        $defaults = $this->defaultSettings();
        $settings = array_replace_recursive($defaults, $existing);
        $settings = array_replace_recursive($settings, $incoming);

        $accountType = (string) ($settings['account_type'] ?? self::ACCOUNT_TYPE_WITHOUT_APPOINTMENTS);
        $hasAppointments = $accountType === self::ACCOUNT_TYPE_WITH_APPOINTMENTS;

        $settings['account_type'] = $hasAppointments
            ? self::ACCOUNT_TYPE_WITH_APPOINTMENTS
            : self::ACCOUNT_TYPE_WITHOUT_APPOINTMENTS;

        if (! is_array($settings['business'] ?? null)) {
            $settings['business'] = $defaults['business'];
        }

        if (! is_array($settings['appointment'] ?? null)) {
            $settings['appointment'] = $defaults['appointment'];
        }
        if (! is_array($settings['crm'] ?? null)) {
            $settings['crm'] = $defaults['crm'];
        }

        $settings['business']['address'] = $this->nullableTrimmed($settings['business']['address'] ?? null);
        $settings['business']['timezone'] = $this->normalizedTimezone($settings['business']['timezone'] ?? null);
        $settings['business']['schedule'] = $this->normalizeBusinessSchedule(
            $settings['business']['schedule'] ?? [],
            $defaults['business']['schedule'],
        );

        $settings['appointment']['enabled'] = $hasAppointments;
        $settings['appointment']['slot_minutes'] = (int) ($settings['appointment']['slot_minutes'] ?? 30);
        $settings['appointment']['buffer_minutes'] = (int) ($settings['appointment']['buffer_minutes'] ?? 0);
        $settings['appointment']['max_days_ahead'] = (int) ($settings['appointment']['max_days_ahead'] ?? 30);
        $settings['appointment']['auto_confirm'] = (bool) ($settings['appointment']['auto_confirm'] ?? true);
        $settings['appointment']['require_phone'] = (bool) ($settings['appointment']['require_phone'] ?? true);
        $settings['crm']['order_required_fields'] = $this->normalizeRequiredFields(
            $settings['crm']['order_required_fields'] ?? [],
            $defaults['crm']['order_required_fields'],
            self::ORDER_REQUIRED_FIELD_OPTIONS,
        );
        $settings['crm']['appointment_required_fields'] = $this->normalizeRequiredFields(
            $settings['crm']['appointment_required_fields'] ?? [],
            $defaults['crm']['appointment_required_fields'],
            self::APPOINTMENT_REQUIRED_FIELD_OPTIONS,
        );

        return $settings;
    }

    private function normalizeRequiredFields(
        mixed $raw,
        array $defaults,
        array $allowed
    ): array {
        $values = is_array($raw) ? $raw : $defaults;
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

        $normalized = array_values(array_unique($normalized));

        if ($normalized === []) {
            return array_values(array_unique($defaults));
        }

        return $normalized;
    }

    private function nullableTrimmed(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function normalizedTimezone(mixed $value): string
    {
        $timezone = $this->nullableTrimmed($value);

        if ($timezone === null) {
            return (string) config('app.timezone', 'UTC');
        }

        if (! in_array($timezone, timezone_identifiers_list(), true)) {
            return (string) config('app.timezone', 'UTC');
        }

        return $timezone;
    }

    private function defaultBusinessSchedule(): array
    {
        return [
            'monday' => ['is_day_off' => false, 'start_time' => '09:00', 'end_time' => '18:00'],
            'tuesday' => ['is_day_off' => false, 'start_time' => '09:00', 'end_time' => '18:00'],
            'wednesday' => ['is_day_off' => false, 'start_time' => '09:00', 'end_time' => '18:00'],
            'thursday' => ['is_day_off' => false, 'start_time' => '09:00', 'end_time' => '18:00'],
            'friday' => ['is_day_off' => false, 'start_time' => '09:00', 'end_time' => '18:00'],
            'saturday' => ['is_day_off' => true, 'start_time' => null, 'end_time' => null],
            'sunday' => ['is_day_off' => true, 'start_time' => null, 'end_time' => null],
        ];
    }

    private function normalizeBusinessSchedule(mixed $schedule, array $defaults): array
    {
        $rawSchedule = is_array($schedule) ? $schedule : [];
        $normalized = [];

        foreach (self::WEEK_DAYS as $day) {
            $rawDay = is_array($rawSchedule[$day] ?? null) ? $rawSchedule[$day] : [];
            $defaultDay = is_array($defaults[$day] ?? null) ? $defaults[$day] : [
                'is_day_off' => true,
                'start_time' => null,
                'end_time' => null,
            ];

            $isDayOff = (bool) ($rawDay['is_day_off'] ?? $defaultDay['is_day_off']);
            $startTime = $this->normalizeTime($rawDay['start_time'] ?? $defaultDay['start_time']);
            $endTime = $this->normalizeTime($rawDay['end_time'] ?? $defaultDay['end_time']);

            if ($isDayOff) {
                $normalized[$day] = [
                    'is_day_off' => true,
                    'start_time' => null,
                    'end_time' => null,
                ];

                continue;
            }

            $fallbackStart = $this->normalizeTime($defaultDay['start_time'] ?? '09:00') ?? '09:00';
            $fallbackEnd = $this->normalizeTime($defaultDay['end_time'] ?? '18:00') ?? '18:00';
            $resolvedStart = $startTime ?? $fallbackStart;
            $resolvedEnd = $endTime ?? $fallbackEnd;

            if (! $this->isValidTimeRange($resolvedStart, $resolvedEnd)) {
                $resolvedStart = $fallbackStart;
                $resolvedEnd = $fallbackEnd;
            }

            $normalized[$day] = [
                'is_day_off' => false,
                'start_time' => $resolvedStart,
                'end_time' => $resolvedEnd,
            ];
        }

        return $normalized;
    }

    private function normalizeTime(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $time = trim($value);

        if ($time === '' || ! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) {
            return null;
        }

        return $time;
    }

    private function isValidTimeRange(string $startTime, string $endTime): bool
    {
        [$startHour, $startMinute] = array_map('intval', explode(':', $startTime));
        [$endHour, $endMinute] = array_map('intval', explode(':', $endTime));

        $start = ($startHour * 60) + $startMinute;
        $end = ($endHour * 60) + $endMinute;

        return $end > $start;
    }

    private function companyPayload(?Company $company): ?array
    {
        if (! $company) {
            return null;
        }

        return [
            'id' => (int) $company->id,
            'name' => (string) $company->name,
            'slug' => $company->slug,
            'short_description' => $company->short_description,
            'industry' => $company->industry,
            'primary_goal' => $company->primary_goal,
            'contact_email' => $company->contact_email,
            'contact_phone' => $company->contact_phone,
            'website' => $company->website,
            'status' => $company->status,
            'settings' => $this->normalizeSettings(
                is_array($company->settings) ? $company->settings : [],
                [],
            ),
            'updated_at' => $company->updated_at?->toIso8601String(),
            'created_at' => $company->created_at?->toIso8601String(),
        ];
    }

    private function subscriptionService(): CompanySubscriptionService
    {
        return app(CompanySubscriptionService::class);
    }
}
