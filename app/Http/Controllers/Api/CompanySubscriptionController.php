<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanySubscription;
use App\Models\Invoice;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\BillingInvoiceService;
use App\Services\CompanySubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CompanySubscriptionController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        $company = $this->resolveCompany($user);
        $subscription = $company->subscription()->with('plan')->first();

        if (!$subscription) {
            return response()->json([
                'company' => $company,
                'subscription' => null,
                'usage' => null,
            ]);
        }

        $this->subscriptionService()->synchronizeBillingPeriods($subscription);
        $this->subscriptionService()->syncAssistantAccess($company);
        $subscription->refresh();
        $subscription->load('plan');

        return response()->json([
            'company' => $company,
            'subscription' => $subscription,
            'usage' => $this->usagePayload($subscription),
        ]);
    }

    public function checkout(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'plan_code' => ['required', 'string', 'max:80'],
            'quantity' => ['required', 'integer', 'min:1', 'max:50'],
        ]);

        $user = $this->resolveUser($request);
        $company = $this->resolveCompany($user);
        $plan = SubscriptionPlan::query()
            ->where('code', (string) $validated['plan_code'])
            ->where('is_active', true)
            ->first();

        if (!$plan) {
            return response()->json([
                'message' => 'Subscription plan not found.',
            ], 404);
        }

        if ($plan->is_enterprise || !$plan->is_public) {
            return response()->json([
                'message' => 'Enterprise plan is configured manually by support.',
            ], 422);
        }

        $quantity = (int) $validated['quantity'];

        $result = DB::transaction(function () use ($company, $user, $plan, $quantity): array {
            /** @var CompanySubscription $subscription */
            $subscription = $company->subscription()->with('plan')->firstOrNew();
            $this->subscriptionService()->synchronizeBillingPeriods($subscription);
            $subscription->loadMissing('plan');

            $wasActive = $subscription->exists && $subscription->isActiveAt();
            $cycleDays = max((int) $plan->billing_period_days, 1);
            $totals = $this->billingInvoiceService()->calculatePlanChangeTotals(
                $wasActive ? $subscription : null,
                $plan,
                $quantity,
            );

            $metadata = is_array($subscription->metadata) ? $subscription->metadata : [];
            $metadata['checkout_source'] = 'frontend';
            $metadata['pending_plan_id'] = $plan->id;
            $metadata['pending_plan_code'] = $plan->code;
            $metadata['pending_quantity'] = $quantity;

            $subscription->forceFill([
                'user_id' => $user->id,
                'status' => $wasActive ? CompanySubscription::STATUS_ACTIVE : CompanySubscription::STATUS_PENDING_PAYMENT,
                'billing_cycle_days' => $cycleDays,
                'metadata' => $metadata,
            ])->save();

            if (!$wasActive) {
                $subscription->forceFill([
                    'subscription_plan_id' => $plan->id,
                    'quantity' => $quantity,
                    'renewal_due_at' => now()->addDays($cycleDays),
                ])->save();
            }

            $startsAt = now();
            $endsAt = $startsAt->copy()->addDays($cycleDays);
            $includedChats = max((int) $plan->included_chats * max($quantity, 1), 0);
            $invoice = Invoice::query()->create([
                'company_id' => $company->id,
                'user_id' => $user->id,
                'company_subscription_id' => $subscription->id,
                'subscription_plan_id' => $plan->id,
                'number' => $this->billingInvoiceService()->generateInvoiceNumber(),
                'status' => Invoice::STATUS_ISSUED,
                'currency' => (string) $plan->currency,
                'subtotal' => $totals['subtotal'],
                'overage_amount' => $totals['overage_amount'],
                'total' => $totals['total'],
                'amount_paid' => '0.00',
                'chat_included' => $includedChats,
                'chat_used' => 0,
                'chat_overage' => 0,
                'unit_overage_price' => (string) $plan->overage_chat_price,
                'period_started_at' => $startsAt,
                'period_ended_at' => $endsAt,
                'issued_at' => now(),
                'due_at' => now()->addDay(),
                'notes' => 'Auto-generated invoice for subscription checkout or plan update.',
                'metadata' => [
                    'purpose' => 'plan_change',
                    'quantity' => $quantity,
                    'credit_amount' => $totals['credit_amount'],
                    'current_plan_code' => $subscription->plan?->code,
                    'target_plan_code' => $plan->code,
                ],
            ]);

            $this->subscriptionService()->syncAssistantAccess($company);

            return [$subscription->fresh()->load('plan'), $invoice];
        });

        /** @var CompanySubscription $subscription */
        $subscription = $result[0];
        /** @var Invoice $invoice */
        $invoice = $result[1];

        return response()->json([
            'message' => 'Checkout created. Complete payment to activate subscription.',
            'subscription' => $subscription,
            'usage' => $this->usagePayload($subscription),
            'invoice' => $invoice,
        ], 201);
    }

    private function usagePayload(CompanySubscription $subscription): array
    {
        $included = $subscription->resolvedIncludedChats();
        $used = max((int) $subscription->chat_count_current_period, 0);

        return [
            'included_chats' => $included,
            'used_chats' => $used,
            'remaining_chats' => max($included - $used, 0),
            'overage_chats' => max($used - $included, 0),
            'overage_chat_price' => $subscription->resolvedOverageChatPrice(),
            'assistant_limit' => $subscription->resolvedAssistantLimit(),
            'integrations_per_channel_limit' => $subscription->resolvedIntegrationsPerChannelLimit(),
        ];
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

    private function subscriptionService(): CompanySubscriptionService
    {
        return app(CompanySubscriptionService::class);
    }

    private function billingInvoiceService(): BillingInvoiceService
    {
        return app(BillingInvoiceService::class);
    }
}
