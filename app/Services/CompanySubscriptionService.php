<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanySubscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class CompanySubscriptionService
{
    public function provisionDefaultWorkspaceForUser(int $userId, string $userName): Company
    {
        $user = User::query()->find($userId);

        $company = null;

        if ($user && $user->company_id) {
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

        if ($user && $user->company_id !== $company->id) {
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
}
