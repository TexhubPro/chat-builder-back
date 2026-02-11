<?php

namespace App\Services;

use App\Models\CompanySubscription;
use App\Models\Invoice;

class BillingRenewalInvoiceService
{
    public function __construct(
        private readonly BillingInvoiceService $billingInvoiceService,
        private readonly CompanySubscriptionService $companySubscriptionService,
    ) {
    }

    public function generateUpcomingRenewalInvoices(int $daysAhead = 3): int
    {
        $maxDaysAhead = max($daysAhead, 1);
        $rangeStart = now()->copy()->addDay()->startOfDay();
        $rangeEnd = now()->copy()->addDays($maxDaysAhead)->endOfDay();

        $subscriptions = CompanySubscription::query()
            ->with(['plan', 'company'])
            ->where('status', CompanySubscription::STATUS_ACTIVE)
            ->where('quantity', '>', 0)
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [$rangeStart, $rangeEnd])
            ->get();

        $processedCount = 0;

        foreach ($subscriptions as $subscription) {
            if (!$subscription->plan || !$subscription->company || !$subscription->expires_at) {
                continue;
            }

            $this->companySubscriptionService->synchronizeBillingPeriods($subscription);
            $subscription->refresh()->load(['plan', 'company']);

            if (
                $subscription->status !== CompanySubscription::STATUS_ACTIVE
                || !$subscription->expires_at
                || !$subscription->plan
            ) {
                continue;
            }

            $periodStart = $subscription->expires_at->copy();
            $periodEnd = $periodStart->copy()->addDays(max((int) $subscription->billing_cycle_days, 1));

            $paidRenewalExists = Invoice::query()
                ->where('company_subscription_id', $subscription->id)
                ->where('status', Invoice::STATUS_PAID)
                ->where('period_started_at', $periodStart)
                ->exists();

            if ($paidRenewalExists) {
                continue;
            }

            $totals = $this->billingInvoiceService->calculateRenewalTotals($subscription);
            $quantity = max((int) $subscription->quantity, 1);

            $payload = [
                'company_id' => $subscription->company_id,
                'user_id' => $subscription->user_id,
                'company_subscription_id' => $subscription->id,
                'subscription_plan_id' => $subscription->subscription_plan_id,
                'status' => Invoice::STATUS_ISSUED,
                'currency' => (string) $subscription->plan->currency,
                'subtotal' => $totals['subtotal'],
                'overage_amount' => $totals['overage_amount'],
                'total' => $totals['total'],
                'amount_paid' => '0.00',
                'chat_included' => $totals['chat_included'],
                'chat_used' => $totals['chat_used'],
                'chat_overage' => $totals['chat_overage'],
                'unit_overage_price' => $totals['unit_overage_price'],
                'period_started_at' => $periodStart,
                'period_ended_at' => $periodEnd,
                'issued_at' => now(),
                'due_at' => $periodStart,
                'notes' => 'Auto-generated renewal invoice before subscription expiration.',
                'metadata' => [
                    'purpose' => 'renewal',
                    'quantity' => $quantity,
                    'days_until_expiration' => now()->diffInDays($subscription->expires_at, false),
                ],
            ];

            $existing = Invoice::query()
                ->where('company_subscription_id', $subscription->id)
                ->where('period_started_at', $periodStart)
                ->whereIn('status', [
                    Invoice::STATUS_DRAFT,
                    Invoice::STATUS_ISSUED,
                    Invoice::STATUS_OVERDUE,
                    Invoice::STATUS_FAILED,
                ])
                ->orderByDesc('id')
                ->first();

            if ($existing) {
                $existing->forceFill($payload)->save();
                $processedCount++;

                continue;
            }

            Invoice::query()->create([
                ...$payload,
                'number' => $this->billingInvoiceService->generateInvoiceNumber(),
            ]);

            $processedCount++;
        }

        return $processedCount;
    }
}

