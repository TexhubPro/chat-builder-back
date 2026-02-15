<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\TemporaryPasswordMail;
use App\Models\Company;
use App\Models\User;
use App\Services\CompanySubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CompanyEmployeeController extends Controller
{
    public const PAGE_DASHBOARD = 'dashboard';
    public const PAGE_CLIENT_REQUESTS = 'client-requests';
    public const PAGE_CLIENT_QUESTIONS = 'client-questions';
    public const PAGE_CLIENT_CHATS = 'client-chats';
    public const PAGE_CLIENT_BASE = 'client-base';
    public const PAGE_CALENDAR = 'calendar';
    public const PAGE_ASSISTANT_TRAINING = 'assistant-training';
    public const PAGE_INTEGRATIONS = 'integrations';
    public const PAGE_PRODUCTS_SERVICES = 'products-services';
    public const PAGE_BILLING = 'billing';
    public const PAGE_BUSINESS_SETTINGS = 'business-settings';
    public const PAGE_EMPLOYEES = 'employees';

    private const AVAILABLE_PAGE_ACCESS = [
        self::PAGE_DASHBOARD,
        self::PAGE_CLIENT_REQUESTS,
        self::PAGE_CLIENT_QUESTIONS,
        self::PAGE_CLIENT_CHATS,
        self::PAGE_CLIENT_BASE,
        self::PAGE_CALENDAR,
        self::PAGE_ASSISTANT_TRAINING,
        self::PAGE_INTEGRATIONS,
        self::PAGE_PRODUCTS_SERVICES,
        self::PAGE_BILLING,
        self::PAGE_BUSINESS_SETTINGS,
        self::PAGE_EMPLOYEES,
    ];

    public function index(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        $company = $this->resolveCompany($user);
        $this->assertUserCanManageEmployees($company, $user);

        $employees = $company->employees()
            ->where('role', User::ROLE_EMPLOYEE)
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return response()->json([
            'employees' => $employees
                ->map(fn (User $employee): array => $this->employeePayload($employee))
                ->values(),
            'available_page_access' => self::AVAILABLE_PAGE_ACCESS,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'phone' => ['required', 'string', 'max:32'],
            'page_access' => ['required', 'array', 'min:1'],
            'page_access.*' => ['string', Rule::in(self::AVAILABLE_PAGE_ACCESS)],
            'status' => ['nullable', 'boolean'],
        ]);

        $user = $this->resolveUser($request);
        $company = $this->resolveCompany($user);
        $this->assertUserCanManageEmployees($company, $user);

        $email = Str::lower(trim((string) $validated['email']));
        $phone = $this->normalizePhone((string) $validated['phone']);

        if ($phone === null) {
            throw ValidationException::withMessages([
                'phone' => 'Phone number format is invalid. Use international format like +12345678900.',
            ]);
        }

        if (User::query()->where('phone', $phone)->exists()) {
            throw ValidationException::withMessages([
                'phone' => 'The phone has already been taken.',
            ]);
        }

        $temporaryPassword = Str::password(14);

        $employee = DB::transaction(function () use (
            $company,
            $user,
            $validated,
            $email,
            $phone,
            $temporaryPassword
        ): User {
            $employee = User::query()->create([
                'name' => trim((string) $validated['name']),
                'email' => $email,
                'phone' => $phone,
                'role' => User::ROLE_EMPLOYEE,
                'status' => (bool) ($validated['status'] ?? true),
                'company_id' => $company->id,
                'page_access' => $this->normalizePageAccess($validated['page_access'] ?? []),
                'created_by_user_id' => $user->id,
                'temporary_password_sent_at' => now(),
                'password' => $temporaryPassword,
            ]);

            $employee->forceFill([
                'email_verified_at' => now(),
            ])->save();

            Mail::to($employee->email)->send(new TemporaryPasswordMail(
                name: $employee->name,
                temporaryPassword: $temporaryPassword,
            ));

            return $employee;
        });

        return response()->json([
            'message' => 'Employee created successfully. Temporary password was sent by email.',
            'employee' => $this->employeePayload($employee->fresh()),
        ], 201);
    }

    public function update(Request $request, int $employeeId): JsonResponse
    {
        $user = $this->resolveUser($request);
        $company = $this->resolveCompany($user);
        $this->assertUserCanManageEmployees($company, $user);
        $employee = $this->resolveEmployee($company, $employeeId);

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($employee->id),
            ],
            'phone' => ['required', 'string', 'max:32'],
            'page_access' => ['required', 'array', 'min:1'],
            'page_access.*' => ['string', Rule::in(self::AVAILABLE_PAGE_ACCESS)],
            'status' => ['required', 'boolean'],
        ]);

        $email = Str::lower(trim((string) $validated['email']));
        $phone = $this->normalizePhone((string) $validated['phone']);

        if ($phone === null) {
            throw ValidationException::withMessages([
                'phone' => 'Phone number format is invalid. Use international format like +12345678900.',
            ]);
        }

        $phoneTaken = User::query()
            ->where('phone', $phone)
            ->whereKeyNot($employee->id)
            ->exists();

        if ($phoneTaken) {
            throw ValidationException::withMessages([
                'phone' => 'The phone has already been taken.',
            ]);
        }

        $employee->forceFill([
            'name' => trim((string) $validated['name']),
            'email' => $email,
            'phone' => $phone,
            'status' => (bool) $validated['status'],
            'page_access' => $this->normalizePageAccess($validated['page_access'] ?? []),
        ])->save();

        return response()->json([
            'message' => 'Employee updated successfully.',
            'employee' => $this->employeePayload($employee->fresh()),
        ]);
    }

    public function destroy(Request $request, int $employeeId): JsonResponse
    {
        $user = $this->resolveUser($request);
        $company = $this->resolveCompany($user);
        $this->assertUserCanManageEmployees($company, $user);
        $employee = $this->resolveEmployee($company, $employeeId);

        $employee->tokens()->delete();
        $employee->delete();

        return response()->json([
            'message' => 'Employee deleted successfully.',
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
        return $this->subscriptionService()->provisionDefaultWorkspaceForUser(
            $user->id,
            $user->name
        );
    }

    private function resolveEmployee(Company $company, int $employeeId): User
    {
        /** @var User|null $employee */
        $employee = $company->employees()
            ->where('role', User::ROLE_EMPLOYEE)
            ->whereKey($employeeId)
            ->first();

        if (! $employee) {
            abort(404, 'Employee not found.');
        }

        return $employee;
    }

    private function assertUserCanManageEmployees(Company $company, User $user): void
    {
        if ($company->user_id !== $user->id) {
            abort(403, 'Only company owner can manage employees.');
        }
    }

    /**
     * @param array<int, mixed> $value
     * @return list<string>
     */
    private function normalizePageAccess(array $value): array
    {
        $result = [];

        foreach ($value as $entry) {
            if (! is_string($entry)) {
                continue;
            }

            $key = trim($entry);
            if ($key === '' || ! in_array($key, self::AVAILABLE_PAGE_ACCESS, true)) {
                continue;
            }

            $result[] = $key;
        }

        return array_values(array_unique($result));
    }

    private function employeePayload(User $employee): array
    {
        return [
            'id' => $employee->id,
            'name' => $employee->name,
            'email' => $employee->email,
            'phone' => $employee->phone,
            'role' => $employee->role,
            'status' => (bool) $employee->status,
            'company_id' => $employee->company_id,
            'page_access' => $this->normalizePageAccess($employee->page_access ?? []),
            'temporary_password_sent_at' => optional($employee->temporary_password_sent_at)?->toIso8601String(),
            'created_at' => optional($employee->created_at)?->toIso8601String(),
            'updated_at' => optional($employee->updated_at)?->toIso8601String(),
        ];
    }

    private function normalizePhone(string $value): ?string
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        $normalized = preg_replace('/[^0-9+]/', '', $trimmed);
        if (! is_string($normalized)) {
            return null;
        }

        if ($normalized === '') {
            return null;
        }

        if ($normalized[0] !== '+') {
            $normalized = '+'.$normalized;
        }

        if (! preg_match('/^\+[1-9][0-9]{7,14}$/', $normalized)) {
            return null;
        }

        return $normalized;
    }

    private function subscriptionService(): CompanySubscriptionService
    {
        return app(CompanySubscriptionService::class);
    }
}
