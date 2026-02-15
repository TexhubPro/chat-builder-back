<?php

use App\Models\Assistant;
use App\Models\AssistantService;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\CompanyClient;
use App\Models\CompanyClientOrder;
use App\Models\CompanySubscription;
use App\Models\Invoice;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\CompanySubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('authenticated user can get dashboard overview analytics', function (): void {
    $user = User::factory()->create([
        'status' => true,
        'email_verified_at' => now(),
    ]);

    $plan = SubscriptionPlan::query()->create([
        'code' => 'starter-dashboard-overview',
        'name' => 'Starter Dashboard',
        'is_active' => true,
        'is_public' => true,
        'billing_period_days' => 30,
        'currency' => 'USD',
        'price' => 30,
        'included_chats' => 400,
        'overage_chat_price' => 1,
        'assistant_limit' => 1,
        'integrations_per_channel_limit' => 1,
    ]);

    /** @var CompanySubscriptionService $subscriptionService */
    $subscriptionService = app(CompanySubscriptionService::class);
    $company = $subscriptionService->provisionDefaultWorkspaceForUser($user->id, $user->name);

    $company->forceFill([
        'settings' => [
            'business' => [
                'currency' => 'USD',
                'timezone' => 'UTC',
            ],
        ],
    ])->save();

    $subscription = $company->subscription()->firstOrFail();
    $subscription->forceFill([
        'subscription_plan_id' => $plan->id,
        'status' => CompanySubscription::STATUS_ACTIVE,
        'quantity' => 1,
        'billing_cycle_days' => 30,
        'chat_count_current_period' => 42,
        'starts_at' => now()->subDays(3),
        'expires_at' => now()->addDays(27),
        'chat_period_started_at' => now()->startOfMonth(),
        'chat_period_ends_at' => now()->startOfMonth()->addDays(30),
    ])->save();

    $assistant = Assistant::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'name' => 'Overview assistant',
        'instructions' => 'Test instructions',
        'is_active' => true,
    ]);

    $client = CompanyClient::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'name' => 'Client One',
        'phone' => '+15550001111',
        'status' => CompanyClient::STATUS_ACTIVE,
    ]);

    $chat = Chat::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'assistant_id' => $assistant->id,
        'channel' => 'telegram',
        'channel_chat_id' => 'tg-test-chat-1',
        'name' => 'Telegram chat',
        'unread_count' => 2,
        'status' => Chat::STATUS_OPEN,
        'last_message_at' => now(),
    ]);

    ChatMessage::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'chat_id' => $chat->id,
        'assistant_id' => $assistant->id,
        'sender_type' => ChatMessage::SENDER_ASSISTANT,
        'direction' => ChatMessage::DIRECTION_OUTBOUND,
        'status' => 'sent',
        'message_type' => ChatMessage::TYPE_TEXT,
        'text' => 'Hello from assistant',
        'sent_at' => now(),
    ]);

    $service = AssistantService::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'assistant_id' => $assistant->id,
        'name' => 'Haircut',
        'price' => 15,
        'currency' => 'USD',
        'is_active' => true,
    ]);

    CompanyClientOrder::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'company_client_id' => $client->id,
        'assistant_id' => $assistant->id,
        'assistant_service_id' => $service->id,
        'service_name' => $service->name,
        'quantity' => 1,
        'unit_price' => 15,
        'total_price' => 15,
        'currency' => 'USD',
        'status' => CompanyClientOrder::STATUS_NEW,
        'ordered_at' => now(),
    ]);

    CompanyClientOrder::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'company_client_id' => $client->id,
        'assistant_id' => $assistant->id,
        'assistant_service_id' => $service->id,
        'service_name' => 'Completed order',
        'quantity' => 1,
        'unit_price' => 45,
        'total_price' => 45,
        'currency' => 'USD',
        'status' => CompanyClientOrder::STATUS_COMPLETED,
        'ordered_at' => now()->subDay(),
        'completed_at' => now()->subHour(),
    ]);

    CompanyClientOrder::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'company_client_id' => $client->id,
        'assistant_id' => $assistant->id,
        'assistant_service_id' => $service->id,
        'service_name' => 'Previous month completed order',
        'quantity' => 1,
        'unit_price' => 20,
        'total_price' => 20,
        'currency' => 'USD',
        'status' => CompanyClientOrder::STATUS_DELIVERED,
        'ordered_at' => now()->subMonthNoOverflow()->subDay(),
        'completed_at' => now()->subMonthNoOverflow()->startOfMonth()->addDays(2),
    ]);

    Invoice::query()->create([
        'company_id' => $company->id,
        'user_id' => $user->id,
        'company_subscription_id' => $subscription->id,
        'subscription_plan_id' => $plan->id,
        'number' => 'INV-DASH-001',
        'status' => Invoice::STATUS_PAID,
        'currency' => 'USD',
        'subtotal' => 30,
        'overage_amount' => 0,
        'total' => 30,
        'amount_paid' => 30,
        'chat_included' => 400,
        'chat_used' => 42,
        'chat_overage' => 0,
        'unit_overage_price' => 1,
        'period_started_at' => now()->startOfMonth(),
        'period_ended_at' => now()->startOfMonth()->addDays(30),
        'issued_at' => now()->subDay(),
        'paid_at' => now(),
    ]);

    $token = $user->createToken('frontend')->plainTextToken;

    $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/dashboard/overview')
        ->assertOk()
        ->assertJsonPath('company.id', $company->id)
        ->assertJsonPath('company.currency', 'USD')
        ->assertJsonPath('kpis.total_chats', 1)
        ->assertJsonPath('kpis.total_clients', 1)
        ->assertJsonPath('kpis.revenue_current_month', '45.00')
        ->assertJsonPath('revenue.current_month_paid', '45.00')
        ->assertJsonPath('revenue.previous_month_paid', '20.00')
        ->assertJsonPath('revenue.pending_amount', '15.00')
        ->assertJsonPath('chats.status_breakdown.open', 1)
        ->assertJsonPath('catalog.services_total', 1)
        ->assertJsonPath('catalog.top_services.0.name', 'Haircut');
});
