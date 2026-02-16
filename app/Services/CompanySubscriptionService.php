<?php

namespace App\Services;

use App\Models\Chat;
use App\Models\Company;
use App\Models\CompanySubscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CompanySubscriptionService
{
    private const CHAT_USAGE_METADATA_KEY = 'billing';

    private const CHAT_USAGE_LAST_CHARGED_AT_KEY = 'last_usage_charged_at';

    private ?bool $usersTableHasCompanyId = null;

    public function provisionDefaultWorkspaceForUser(int $userId, string $userName): Company
    {
        $user = User::query()->find($userId);
        $hasUserCompanyColumn = $this->usersTableHasCompanyId();

        $company = null;

        if ($user && $hasUserCompanyColumn && $user->company_id) {
            $company = Company::query()->find((int) $user->company_id);
        }

        if (! $company) {
            $company = Company::query()
                ->where('user_id', $userId)
                ->orderByDesc('id')
                ->first();
        }

        if (! $company) {
            $baseName = trim($userName) !== '' ? trim($userName) : 'Company';
            $companyName = Str::limit($baseName.' Company', 160, '');
            $slugBase = Str::slug($baseName);

            if ($slugBase === '') {
                $slugBase = 'company';
            }

            $company = Company::query()->create([
                'user_id' => $userId,
                'name' => $companyName,
                'slug' => Str::limit($slugBase.'-'.$userId, 191, ''),
                'status' => Company::STATUS_ACTIVE,
            ]);
        }

        if ($user && $hasUserCompanyColumn && $user->company_id !== $company->id) {
            $user->forceFill([
                'company_id' => $company->id,
            ])->save();
        }

        $this->ensureCurrentSubscription($company);

        return $company;
    }

    public function ensureCurrentSubscription(Company $company): CompanySubscription
    {
        $defaultPlan = SubscriptionPlan::query()
            ->where('code', (string) config('billing.default_plan_code', SubscriptionPlan::CODE_STARTER_MONTHLY))
            ->where('is_active', true)
            ->first()
            ?? SubscriptionPlan::query()
                ->where('is_active', true)
                ->where('is_enterprise', false)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->first();

        return CompanySubscription::query()->firstOrCreate(
            ['company_id' => $company->id],
            [
                'user_id' => $company->user_id,
                'subscription_plan_id' => $defaultPlan?->id,
                'status' => CompanySubscription::STATUS_INACTIVE,
                'quantity' => 0,
                'billing_cycle_days' => max((int) ($defaultPlan?->billing_period_days ?? 30), 1),
                'metadata' => [
                    'note' => 'Subscription created automatically. Activate after successful payment.',
                ],
            ]
        );
    }

    public function syncAssistantAccess(Company $company): void
    {
        $subscription = $company->subscription()->with('plan')->first();

        if (! $subscription) {
            $company->assistants()->where('is_active', true)->update(['is_active' => false]);

            return;
        }

        $this->synchronizeBillingPeriods($subscription);
        $subscription->refresh()->load('plan');

        if (! $subscription->isActiveAt()) {
            $company->assistants()->where('is_active', true)->update(['is_active' => false]);

            return;
        }

        $assistantLimit = $subscription->resolvedAssistantLimit();

        if ($assistantLimit <= 0) {
            $company->assistants()->where('is_active', true)->update(['is_active' => false]);

            return;
        }

        $activeAssistantIds = $company->assistants()
            ->where('is_active', true)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        if ($activeAssistantIds === []) {
            return;
        }

        if (count($activeAssistantIds) <= $assistantLimit) {
            return;
        }

        $allowedAssistantIds = array_slice($activeAssistantIds, 0, $assistantLimit);
        $company->assistants()
            ->whereNotIn('id', $allowedAssistantIds)
            ->where('is_active', true)
            ->update(['is_active' => false]);
    }

    public function synchronizeBillingPeriods(CompanySubscription $subscription): CompanySubscription
    {
        $now = Carbon::now();
        $cycleDays = max((int) $subscription->billing_cycle_days, 1);
        $updated = false;

        if (
            $subscription->status === CompanySubscription::STATUS_ACTIVE
            && $subscription->chat_period_ends_at
            && $subscription->chat_period_ends_at->lte($now)
        ) {
            $nextPeriodStart = $subscription->chat_period_ends_at->copy();
            $nextPeriodEnd = $nextPeriodStart->copy()->addDays($cycleDays);

            while ($nextPeriodEnd->lte($now)) {
                $nextPeriodStart = $nextPeriodEnd;
                $nextPeriodEnd = $nextPeriodStart->copy()->addDays($cycleDays);
            }

            $subscription->chat_count_current_period = 0;
            $subscription->chat_period_started_at = $nextPeriodStart;
            $subscription->chat_period_ends_at = $nextPeriodEnd;
            $updated = true;
        }

        if ($subscription->status === CompanySubscription::STATUS_ACTIVE && $subscription->isExpiredAt()) {
            $subscription->status = CompanySubscription::STATUS_EXPIRED;
            $updated = true;
        }

        if ($updated) {
            $subscription->save();
        }

        return $subscription;
    }

    public function incrementChatUsage(Company $company, int $count = 1): void
    {
        if ($count <= 0) {
            return;
        }

        $subscription = $company->subscription()->with('plan')->first();

        if (! $subscription) {
            return;
        }

        $this->synchronizeBillingPeriods($subscription);
        $subscription->refresh()->load('plan');

        if (! $subscription->isActiveAt()) {
            return;
        }

        $subscription->increment('chat_count_current_period', $count);
    }

    public function incrementChatUsageForChat(
        Company $company,
        Chat $chat,
        int $count = 1,
        mixed $occurredAt = null,
    ): bool {
        if ($count <= 0) {
            return false;
        }

        if ((int) $chat->company_id !== (int) $company->id) {
            return false;
        }

        $eventAt = $this->normalizeUsageTimestamp($occurredAt) ?? now();
        $windowHours = max((int) config('billing.chat_usage_window_hours', 48), 1);

        return (bool) DB::transaction(function () use ($company, $chat, $count, $eventAt, $windowHours): bool {
            $subscription = CompanySubscription::query()
                ->where('company_id', $company->id)
                ->lockForUpdate()
                ->first();

            if (! $subscription) {
                return false;
            }

            $this->synchronizeBillingPeriods($subscription);
            $subscription->refresh()->load('plan');

            if (! $subscription->isActiveAt()) {
                return false;
            }

            $lockedChat = Chat::query()
                ->whereKey($chat->id)
                ->where('company_id', $company->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedChat || ! $this->isChatEligibleForUsage($lockedChat)) {
                return false;
            }

            $metadata = is_array($lockedChat->metadata) ? $lockedChat->metadata : [];
            $billingMeta = is_array($metadata[self::CHAT_USAGE_METADATA_KEY] ?? null)
                ? $metadata[self::CHAT_USAGE_METADATA_KEY]
                : [];

            $lastChargedAt = $this->normalizeUsageTimestamp(
                $billingMeta[self::CHAT_USAGE_LAST_CHARGED_AT_KEY] ?? null
            );

            if ($lastChargedAt !== null && $eventAt->lt($lastChargedAt->copy()->addHours($windowHours))) {
                return false;
            }

            $subscription->increment('chat_count_current_period', $count);

            $billingMeta[self::CHAT_USAGE_LAST_CHARGED_AT_KEY] = $eventAt->toIso8601String();
            $metadata[self::CHAT_USAGE_METADATA_KEY] = $billingMeta;

            $lockedChat->forceFill([
                'metadata' => $metadata,
            ])->save();

            return true;
        });
    }

    private function isChatEligibleForUsage(Chat $chat): bool
    {
        if (! in_array((string) $chat->status, [Chat::STATUS_OPEN, Chat::STATUS_PENDING], true)) {
            return false;
        }

        $metadata = is_array($chat->metadata) ? $chat->metadata : [];
        $rawIsActive = $metadata['is_active'] ?? null;

        if ($rawIsActive === false || $rawIsActive === 0) {
            return false;
        }

        if (is_string($rawIsActive)) {
            $normalized = Str::lower(trim($rawIsActive));
            if (in_array($normalized, ['0', 'false', 'off', 'no'], true)) {
                return false;
            }
        }

        return true;
    }

    private function normalizeUsageTimestamp(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof Carbon) {
            return $value->copy();
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (is_numeric($value)) {
            $numeric = (string) $value;
            $digitsOnly = preg_replace('/\D+/', '', $numeric) ?? '';

            if ($digitsOnly !== '' && strlen($digitsOnly) <= 10) {
                return Carbon::createFromTimestamp((int) $value);
            }

            return Carbon::createFromTimestampMs((int) $value);
        }

        if (is_string($value)) {
            try {
                return Carbon::parse($value);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    private function usersTableHasCompanyId(): bool
    {
        if ($this->usersTableHasCompanyId !== null) {
            return $this->usersTableHasCompanyId;
        }

        $this->usersTableHasCompanyId = Schema::hasColumn('users', 'company_id');

        return $this->usersTableHasCompanyId;
    }
}
