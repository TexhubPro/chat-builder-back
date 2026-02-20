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
use Illuminate\Support\Str;
use TexHub\AlifBank\Client as AlifBankClient;

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

    public function pay(Request $request, string $invoice): JsonResponse
    {
        $user = $this->resolveUser($request);
        $invoiceModel = $this->resolveInvoiceForPayment($invoice, $user->id);

        if (!$invoiceModel) {
            return response()->json([
                'message' => 'Invoice not found.',
            ], 404);
        }

        $company = $invoiceModel->company;

        if (!$company) {
            return response()->json([
                'message' => 'Invoice not found.',
            ], 404);
        }

        if ($invoiceModel->status === Invoice::STATUS_PAID) {
            return response()->json([
                'message' => 'Invoice is already paid.',
                'invoice' => $invoiceModel,
                'subscription' => $company->subscription()->with('plan')->first(),
            ]);
        }

        if ($this->alifMode() === 'local') {
            [$paidInvoice, $subscription] = $this->markInvoiceAsPaidAndActivateSubscription($invoiceModel);

            return response()->json([
                'message' => 'Payment completed successfully.',
                'invoice' => $paidInvoice,
                'subscription' => $subscription,
            ]);
        }

        $amount = (float) $invoiceModel->total;

        if ($amount <= 0) {
            [$paidInvoice, $subscription] = $this->markInvoiceAsPaidAndActivateSubscription($invoiceModel);

            return response()->json([
                'message' => 'Payment completed successfully.',
                'invoice' => $paidInvoice,
                'subscription' => $subscription,
            ]);
        }

        $paymentSession = $this->buildAlifPaymentSession($invoiceModel, $user);

        if (!$paymentSession) {
            return response()->json([
                'message' => 'Unable to initialize Alif payment session.',
            ], 422);
        }

        $invoiceModel = DB::transaction(function () use ($invoiceModel, $paymentSession): Invoice {
            $metadata = $this->mergeInvoiceMetadata(
                $invoiceModel->metadata,
                [
                    'alif' => [
                        'mode' => $paymentSession['mode'],
                        'order_id' => $paymentSession['payload']['order_id'] ?? null,
                        'checkout_url' => $paymentSession['checkout_url'],
                        'requested_at' => now()->toIso8601String(),
                        'status_normalized' => 'pending',
                    ],
                ],
            );

            $invoiceModel->forceFill([
                'status' => Invoice::STATUS_PENDING,
                'metadata' => $metadata,
                'paid_at' => null,
                'amount_paid' => '0.00',
            ])->save();

            return $invoiceModel->fresh();
        });

        return response()->json([
            'message' => 'Payment session created. Continue in Alif.',
            'invoice' => $invoiceModel,
            'subscription' => $company->subscription()->with('plan')->first(),
            'payment' => $paymentSession,
        ]);
    }

    public function alifCallback(Request $request): JsonResponse
    {
        $payload = $request->all();
        $client = $this->makeAlifClient();

        if (!$client->verifyCallback($payload)) {
            return response()->json([
                'ok' => false,
                'message' => 'Invalid callback signature.',
            ]);
        }

        $orderId = (string) ($payload['order_id'] ?? $payload['orderId'] ?? '');
        $invoice = $this->resolveInvoiceByAlifOrderId($orderId);

        if (!$invoice) {
            return response()->json([
                'ok' => false,
                'message' => 'Invoice not found.',
            ]);
        }

        $normalizedStatus = $this->normalizeAlifStatus((string) ($payload['status'] ?? ''));
        $metadataPatch = [
            'alif' => [
                'callback_received_at' => now()->toIso8601String(),
                'callback_payload' => $payload,
                'status_raw' => (string) ($payload['status'] ?? ''),
                'status_normalized' => $normalizedStatus,
                'transaction_id' => (string) ($payload['transaction_id'] ?? $payload['transactionId'] ?? ''),
            ],
        ];

        if ($normalizedStatus === 'success') {
            [$paidInvoice, $subscription] = $this->markInvoiceAsPaidAndActivateSubscription($invoice, $metadataPatch);

            return response()->json([
                'ok' => true,
                'message' => 'Payment completed successfully.',
                'status' => 'success',
                'invoice' => $paidInvoice,
                'subscription' => $subscription,
            ]);
        }

        if ($invoice->status === Invoice::STATUS_PAID) {
            $invoice->forceFill([
                'metadata' => $this->mergeInvoiceMetadata($invoice->metadata, $metadataPatch),
            ])->save();

            return response()->json([
                'ok' => true,
                'message' => 'Invoice is already paid.',
                'status' => 'success',
                'invoice' => $invoice->fresh(),
            ]);
        }

        if ($normalizedStatus === 'failed') {
            $invoice->forceFill([
                'status' => Invoice::STATUS_FAILED,
                'paid_at' => null,
                'amount_paid' => '0.00',
                'metadata' => $this->mergeInvoiceMetadata($invoice->metadata, $metadataPatch),
            ])->save();

            return response()->json([
                'ok' => true,
                'message' => 'Invoice marked as failed by Alif callback.',
                'status' => 'failed',
                'invoice' => $invoice->fresh(),
            ]);
        }

        $invoice->forceFill([
            'status' => Invoice::STATUS_PENDING,
            'metadata' => $this->mergeInvoiceMetadata($invoice->metadata, $metadataPatch),
        ])->save();

        return response()->json([
            'ok' => true,
            'message' => 'Payment is pending confirmation.',
            'status' => 'pending',
            'invoice' => $invoice->fresh(),
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

    private function markInvoiceAsPaidAndActivateSubscription(Invoice $invoice, array $metadataPatch = []): array
    {
        return DB::transaction(function () use ($invoice, $metadataPatch): array {
            $invoice->refresh();
            $invoice->forceFill([
                'status' => Invoice::STATUS_PAID,
                'amount_paid' => (string) $invoice->total,
                'paid_at' => now(),
                'metadata' => $this->mergeInvoiceMetadata($invoice->metadata, $metadataPatch),
            ])->save();

            /** @var Company|null $company */
            $company = Company::query()->find($invoice->company_id);
            $subscription = null;

            if ($company) {
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
            }

            return [$invoice->fresh(), $subscription?->fresh()->load('plan')];
        });
    }

    private function alifMode(): string
    {
        $mode = strtolower((string) config('billing.alif.mode', 'local'));

        if (!in_array($mode, ['local', 'test', 'production'], true)) {
            return 'local';
        }

        return $mode;
    }

    private function makeAlifClient(): AlifBankClient
    {
        $mode = $this->alifMode();
        $config = config('alifbank', []);

        $config['environment'] = $mode === 'production' ? 'production' : 'test';
        $config['base_url'] = (string) config('billing.alif.production_checkout_url', $config['base_url'] ?? 'https://web.alif.tj/');
        $config['test_base_url'] = (string) config('billing.alif.test_checkout_url', $config['test_base_url'] ?? 'https://test-web.alif.tj/');
        $config['callback_url'] = (string) config('billing.alif.callback_url', route('api.billing.alif.callback'));

        $returnUrl = (string) config('billing.alif.return_url', '');

        if ($returnUrl !== '') {
            $config['return_url'] = $returnUrl;
        }

        return new AlifBankClient($config);
    }

    private function buildAlifPaymentSession(Invoice $invoice, User $user): ?array
    {
        $client = $this->makeAlifClient();
        $orderId = $this->buildAlifOrderId($invoice->id);
        $returnUrl = (string) config('billing.alif.return_url', '');
        $callbackUrl = (string) config('billing.alif.callback_url', route('api.billing.alif.callback'));

        $payload = $client->buildCheckoutPayload(
            orderId: $orderId,
            amount: (float) $invoice->total,
            callbackUrl: $callbackUrl !== '' ? $callbackUrl : null,
            returnUrl: $returnUrl !== '' ? $returnUrl : null,
            email: $user->email,
            name: $user->name !== '' ? $user->name : null,
            phone: $user->phone !== '' ? $user->phone : null,
        );

        if (!$payload) {
            return null;
        }

        $normalizedPayload = [];

        foreach ($payload as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $normalizedPayload[(string) $key] = (string) $value;
        }

        return [
            'provider' => 'alifbank',
            'mode' => $this->alifMode() === 'production' ? 'production' : 'test',
            'method' => 'post',
            'checkout_url' => $client->checkoutUrl(),
            'payload' => $normalizedPayload,
        ];
    }

    private function buildAlifOrderId(int $invoiceId): string
    {
        return 'INV-' . $invoiceId . '-ALIF-' . Str::upper(Str::random(8));
    }

    private function resolveInvoiceByAlifOrderId(string $orderId): ?Invoice
    {
        if ($orderId === '') {
            return null;
        }

        if (preg_match('/^INV-(\d+)-ALIF-[A-Z0-9]+$/', $orderId, $matches) === 1) {
            $invoiceId = (int) ($matches[1] ?? 0);
            $invoice = Invoice::query()->find($invoiceId);

            if ($invoice) {
                $savedOrderId = (string) data_get($invoice->metadata, 'alif.order_id', '');

                if ($savedOrderId === $orderId) {
                    return $invoice;
                }
            }
        }

        return Invoice::query()->where('metadata->alif->order_id', $orderId)->first();
    }

    private function normalizeAlifStatus(string $rawStatus): string
    {
        $status = strtolower(trim($rawStatus));

        if (in_array($status, ['success', 'succeeded', 'paid', 'completed', 'ok', 'approved'], true)) {
            return 'success';
        }

        if (in_array($status, ['failed', 'error', 'declined', 'rejected', 'cancelled', 'canceled'], true)) {
            return 'failed';
        }

        return 'pending';
    }

    private function resolveInvoiceForPayment(string $invoiceIdentifier, int $userId): ?Invoice
    {
        $query = Invoice::query()->whereHas('company', function ($builder) use ($userId): void {
            $builder->where('user_id', $userId);
        });

        if (ctype_digit($invoiceIdentifier)) {
            $invoiceById = (clone $query)
                ->whereKey((int) $invoiceIdentifier)
                ->first();

            if ($invoiceById) {
                return $invoiceById;
            }
        }

        return (clone $query)
            ->where('number', $invoiceIdentifier)
            ->first();
    }

    private function mergeInvoiceMetadata(mixed $currentMetadata, array $patch): array
    {
        $base = is_array($currentMetadata) ? $currentMetadata : [];

        return array_replace_recursive($base, $patch);
    }
}
