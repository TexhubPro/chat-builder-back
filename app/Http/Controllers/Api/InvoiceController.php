<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanySubscription;
use App\Models\Invoice;
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
                $cycleDays = max((int) $subscription->billing_cycle_days, 1);
                $startsAt = now();
                $expiresAt = now()->addDays($cycleDays);

                if ($subscription->expires_at && $subscription->expires_at->isFuture()) {
                    $startsAt = $subscription->starts_at ?? now();
                    $expiresAt = $subscription->expires_at->copy()->addDays($cycleDays);
                }

                $subscription->forceFill([
                    'status' => CompanySubscription::STATUS_ACTIVE,
                    'starts_at' => $startsAt,
                    'expires_at' => $expiresAt,
                    'renewal_due_at' => $expiresAt,
                    'paid_at' => now(),
                    'chat_count_current_period' => 0,
                    'chat_period_started_at' => $startsAt,
                    'chat_period_ends_at' => $expiresAt,
                ])->save();

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
}
