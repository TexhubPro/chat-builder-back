<?php

namespace App\Services;

use App\Models\CompanySubscription;
use App\Models\Invoice;
use App\Models\SubscriptionPlan;
use Illuminate\Support\Str;

class BillingInvoiceService
{
    public function calculatePlanChangeTotals(
        ?CompanySubscription $currentSubscription,
        SubscriptionPlan $targetPlan,
        int $targetQuantity
    ): array {
        $quantity = max($targetQuantity, 1);
        $subtotalCents = $this->moneyToCents((string) $targetPlan->price) * $quantity;

        $creditCents = 0;

        if (
            $currentSubscription
            && $currentSubscription->isActiveAt()
            && $currentSubscription->plan
            && $currentSubscription->plan->currency === $targetPlan->currency
        ) {
            $includedChats = max($currentSubscription->resolvedIncludedChats(), 0);
            $usedChats = max((int) $currentSubscription->chat_count_current_period, 0);
            $remainingChats = max($includedChats - $usedChats, 0);

            if ($includedChats > 0 && $remainingChats > 0) {
                $currentQuantity = max((int) $currentSubscription->quantity, 1);
                $currentPriceCents = $this->moneyToCents((string) $currentSubscription->plan->price) * $currentQuantity;
                $creditCents = (int) floor(($currentPriceCents * $remainingChats) / $includedChats);
            }
        }

        $creditCents = min($creditCents, $subtotalCents);
        $totalCents = max($subtotalCents - $creditCents, 0);

        return [
            'subtotal' => $this->centsToMoney($subtotalCents),
            'credit_amount' => $this->centsToMoney($creditCents),
            'overage_amount' => '0.00',
            'total' => $this->centsToMoney($totalCents),
        ];
    }

    public function calculateRenewalTotals(CompanySubscription $subscription): array
    {
        $quantity = max((int) $subscription->quantity, 1);
        $planPriceCents = $this->moneyToCents((string) ($subscription->plan?->price ?? '0'));
        $subtotalCents = $planPriceCents * $quantity;

        $includedChats = max($subscription->resolvedIncludedChats(), 0);
        $usedChats = max((int) $subscription->chat_count_current_period, 0);
        $overageChats = max($usedChats - $includedChats, 0);

        $overageUnitPriceCents = $this->moneyToCents($subscription->resolvedOverageChatPrice());
        $overageAmountCents = $overageChats * $overageUnitPriceCents;
        $totalCents = $subtotalCents + $overageAmountCents;

        return [
            'subtotal' => $this->centsToMoney($subtotalCents),
            'overage_amount' => $this->centsToMoney($overageAmountCents),
            'total' => $this->centsToMoney($totalCents),
            'chat_included' => $includedChats,
            'chat_used' => $usedChats,
            'chat_overage' => $overageChats,
            'unit_overage_price' => $this->centsToMoney($overageUnitPriceCents),
        ];
    }

    public function generateInvoiceNumber(): string
    {
        do {
            $number = 'INV-' . now()->format('Ymd') . '-' . Str::upper(Str::random(8));
        } while (Invoice::query()->where('number', $number)->exists());

        return $number;
    }

    private function moneyToCents(string $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    private function centsToMoney(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}

