<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanySubscription;
use App\Models\SubscriptionPlan;
use Illuminate\Support\Str;

class CompanySubscriptionService
{
    public function provisionDefaultWorkspaceForUser(int $userId, string $userName): Company
    {
        $company = Company::query()->where('user_id', $userId)->first();

        if (!$company) {
            $baseName = trim($userName) !== '' ? trim($userName) : 'Company';
            $companyName = Str::limit($baseName . ' Company', 160, '');
            $slugBase = Str::slug($baseName);

            if ($slugBase === '') {
                $slugBase = 'company';
            }

            $company = Company::query()->create([
                'user_id' => $userId,
                'name' => $companyName,
                'slug' => Str::limit($slugBase . '-' . $userId, 191, ''),
                'status' => Company::STATUS_ACTIVE,
            ]);
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

        if (!$subscription) {
            $company->assistants()->where('is_active', true)->update(['is_active' => false]);

            return;
        }

        if ($subscription->status === CompanySubscription::STATUS_ACTIVE && $subscription->isExpiredAt()) {
            $subscription->forceFill([
                'status' => CompanySubscription::STATUS_EXPIRED,
            ])->save();
        }

        if (!$subscription->isActiveAt()) {
            $company->assistants()->where('is_active', true)->update(['is_active' => false]);

            return;
        }

        $assistantLimit = $subscription->resolvedAssistantLimit();

        if ($assistantLimit <= 0) {
            $company->assistants()->where('is_active', true)->update(['is_active' => false]);

            return;
        }

        $allowedAssistantIds = $company->assistants()
            ->orderBy('id')
            ->limit($assistantLimit)
            ->pluck('id')
            ->all();

        if ($allowedAssistantIds === []) {
            return;
        }

        $company->assistants()
            ->whereIn('id', $allowedAssistantIds)
            ->where('is_active', false)
            ->update(['is_active' => true]);

        $company->assistants()
            ->whereNotIn('id', $allowedAssistantIds)
            ->where('is_active', true)
            ->update(['is_active' => false]);
    }
}
