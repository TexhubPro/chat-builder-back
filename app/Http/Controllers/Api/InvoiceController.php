<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanySubscription;
use App\Models\Invoice;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\CompanySubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InvoiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        $company = $this->resolveCompany($user);

        $invoices = Invoice::query()
            ->where('company_id', $company->id)
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return response()->json([
            'invoices' => $invoices,
        ]);
    }

    public function pay(Request $request, Invoice $invoice): JsonResponse
    {
        $user = $this->resolveUser($request);
        $company = $this->resolveCompany($user);

        if ($invoice->company_id !== $company->id) {
            return response()->json([
                'message' => 'Invoice not found.',
            ], 404);
        }

        if ($invoice->status === Invoice::STATUS_PAID) {
            return response()->json([
                'message' => 'Invoice is already paid.',
                'invoice' => $invoice,
                'subscription' => $company->subscription()->with('plan')->first(),
            ]);
        }

        $result = DB::transaction(function () use ($company, $invoice): array {
            $invoice->forceFill([
                'status' => Invoice::STATUS_PAID,
                'amount_paid' => (string) $invoice->total,
                'paid_at' => now(),
            ])->save();

            /** @var CompanySubscription|null $subscription */
            $subscription = $company->subscription()->with('plan')->first();

            if ($subscription) {
                $this->subscriptionService()->synchronizeBillingPeriods($subscription);
                $subscription->refresh()->load('plan');

                $invoiceMetadata = is_array($invoice->metadata) ? $invoice->metadata : [];
                $purpose = $this->resolveInvoicePurpose($invoiceMetadata);
                $quantity = max((int) ($invoiceMetadata['quantity'] ?? $subscription->quantity ?? 1), 1);

                $targetPlan = $this->resolveTargetPlan($invoice, $subscription);
                $cycleDays = max((int) ($targetPlan?->billing_period_days ?? $subscription->billing_cycle_days ?? 30), 1);

                $subscriptionMetadata = is_array($subscription->metadata) ? $subscription->metadata : [];
                unset($subscriptionMetadata['pending_plan_id'], $subscriptionMetadata['pending_plan_code'], $subscriptionMetadata['pending_quantity']);
                $subscriptionMetadata['last_paid_invoice_id'] = $invoice->id;

                if ($purpose === 'renewal' && $subscription->expires_at && $subscription->expires_at->isFuture()) {
                    $nextPeriodStart = $invoice->period_started_at?->copy() ?? $subscription->expires_at->copy();
                    $nextPeriodEnd = $invoice->period_ended_at?->copy() ?? $nextPeriodStart->copy()->addDays($cycleDays);

                    $subscription->forceFill([
                        'subscription_plan_id' => $targetPlan?->id ?? $subscription->subscription_plan_id,
                        'status' => CompanySubscription::STATUS_ACTIVE,
                        'quantity' => $quantity,
                        'billing_cycle_days' => $cycleDays,
                        'expires_at' => $nextPeriodEnd,
                        'renewal_due_at' => $nextPeriodEnd,
                        'paid_at' => now(),
                        'metadata' => $subscriptionMetadata,
                    ])->save();
                } else {
                    $startsAt = now();
                    $expiresAt = $startsAt->copy()->addDays($cycleDays);

                    $subscription->forceFill([
                        'subscription_plan_id' => $targetPlan?->id ?? $subscription->subscription_plan_id,
                        'status' => CompanySubscription::STATUS_ACTIVE,
                        'quantity' => $quantity,
                        'billing_cycle_days' => $cycleDays,
                        'starts_at' => $startsAt,
                        'expires_at' => $expiresAt,
                        'renewal_due_at' => $expiresAt,
                        'paid_at' => now(),
                        'chat_count_current_period' => 0,
                        'chat_period_started_at' => $startsAt,
                        'chat_period_ends_at' => $expiresAt,
                        'metadata' => $subscriptionMetadata,
                    ])->save();
                }

                $this->subscriptionService()->syncAssistantAccess($company);
            }

            return [$invoice->fresh(), $subscription?->fresh()->load('plan')];
        });

        /** @var Invoice $paidInvoice */
        $paidInvoice = $result[0];
        $subscription = $result[1];

        return response()->json([
            'message' => 'Payment completed successfully.',
            'invoice' => $paidInvoice,
            'subscription' => $subscription,
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

    private function subscriptionService(): CompanySubscriptionService
    {
        return app(CompanySubscriptionService::class);
    }

    private function resolveInvoicePurpose(array $invoiceMetadata): string
    {
        $purpose = (string) ($invoiceMetadata['purpose'] ?? '');

        if ($purpose === 'renewal') {
            return 'renewal';
        }

        return 'plan_change';
    }

    private function resolveTargetPlan(Invoice $invoice, CompanySubscription $subscription): ?SubscriptionPlan
    {
        if ($invoice->subscription_plan_id) {
            return SubscriptionPlan::query()->find($invoice->subscription_plan_id);
        }

        if ($subscription->subscription_plan_id) {
            return SubscriptionPlan::query()->find($subscription->subscription_plan_id);
        }

        return null;
    }
}
