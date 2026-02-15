<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AssistantService;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Company;
use App\Models\CompanyCalendarEvent;
use App\Models\CompanyClientOrder;
use App\Models\CompanyClientQuestion;
use App\Models\CompanySubscription;
use App\Models\User;
use App\Services\CompanySubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DashboardOverviewController extends Controller
{
    public function overview(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        $company = $this->resolveCompany($user);
        $subscription = $company->subscription()->with('plan')->first();

        if ($subscription) {
            $this->subscriptionService()->synchronizeBillingPeriods($subscription);
            $subscription = $company->subscription()->with('plan')->first();
        }

        $now = Carbon::now();
        $startOfToday = $now->copy()->startOfDay();
        $startOfCurrentMonth = $now->copy()->startOfMonth();
        $startOfPreviousMonth = $startOfCurrentMonth->copy()->subMonthNoOverflow();
        $endOfPreviousMonth = $startOfCurrentMonth->copy()->subSecond();
        $subscriptionUsage = $this->subscriptionUsagePayload($subscription);

        $chatStatusCounts = $company->chats()
            ->select('status', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $channelChatCounts = $company->chats()
            ->select('channel', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('channel')
            ->pluck('aggregate', 'channel');

        $channelMessageCounts = ChatMessage::query()
            ->join('chats', 'chats.id', '=', 'chat_messages.chat_id')
            ->where('chat_messages.company_id', $company->id)
            ->select('chats.channel as channel', DB::raw('COUNT(chat_messages.id) as aggregate'))
            ->groupBy('chats.channel')
            ->pluck('aggregate', 'channel');

        $totalChats = (int) $company->chats()->count();
        $totalMessages = (int) $company->messages()->count();
        $unreadMessages = (int) $company->chats()->sum('unread_count');
        $messagesToday = (int) $company->messages()->where('created_at', '>=', $startOfToday)->count();
        $activeChatsToday = (int) $company->chats()->where('last_message_at', '>=', $startOfToday)->count();
        $outboundMessages = (int) $company->messages()->where('direction', ChatMessage::DIRECTION_OUTBOUND)->count();
        $assistantOutboundMessages = (int) $company->messages()
            ->where('direction', ChatMessage::DIRECTION_OUTBOUND)
            ->where('sender_type', ChatMessage::SENDER_ASSISTANT)
            ->count();
        $automationRate = $this->percentage($assistantOutboundMessages, $outboundMessages);

        $currentMonthRevenue = $this->completedOrdersRevenueForPeriod(
            $company->id,
            $startOfCurrentMonth,
            $now,
        );

        $previousMonthRevenue = $this->completedOrdersRevenueForPeriod(
            $company->id,
            $startOfPreviousMonth,
            $endOfPreviousMonth,
        );

        $pendingRevenue = (float) CompanyClientOrder::query()
            ->where('company_id', $company->id)
            ->whereIn('status', $this->pendingRevenueStatuses())
            ->sum('total_price');

        $orderStatusCounts = $company->clientOrders()
            ->select('status', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $questionStatusCounts = $company->clientQuestions()
            ->select('status', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $upcomingAppointments = (int) $company->calendarEvents()
            ->whereBetween('starts_at', [$now, $now->copy()->addDays(7)])
            ->whereIn('status', [
                CompanyCalendarEvent::STATUS_SCHEDULED,
                CompanyCalendarEvent::STATUS_CONFIRMED,
            ])
            ->count();

        $servicesCount = (int) $company->assistantServices()->count();
        $productsCount = (int) $company->assistantProducts()->count();
        $activeServicesCount = (int) $company->assistantServices()->where('is_active', true)->count();
        $activeProductsCount = (int) $company->assistantProducts()->where('is_active', true)->count();
        $averageServicePrice = (float) ($company->assistantServices()->avg('price') ?? 0);
        $averageProductPrice = (float) ($company->assistantProducts()->avg('price') ?? 0);

        $settings = is_array($company->settings) ? $company->settings : [];
        $businessSettings = is_array($settings['business'] ?? null) ? $settings['business'] : [];
        $currency = strtoupper(trim((string) ($businessSettings['currency'] ?? 'TJS')));
        $timezone = (string) ($businessSettings['timezone'] ?? config('app.timezone', 'UTC'));

        return response()->json([
            'company' => [
                'id' => $company->id,
                'name' => $company->name,
                'currency' => $currency !== '' ? $currency : 'TJS',
                'timezone' => $timezone !== '' ? $timezone : (string) config('app.timezone', 'UTC'),
            ],
            'kpis' => [
                'total_chats' => $totalChats,
                'total_messages' => $totalMessages,
                'total_clients' => (int) $company->clients()->count(),
                'revenue_current_month' => $this->decimalString($currentMonthRevenue),
                'automation_rate_percent' => $automationRate,
            ],
            'limits' => $subscriptionUsage,
            'subscription' => [
                'status' => $subscription?->status,
                'plan' => $subscription?->plan
                    ? [
                        'id' => $subscription->plan->id,
                        'code' => $subscription->plan->code,
                        'name' => $subscription->plan->name,
                        'price' => (string) $subscription->plan->price,
                        'currency' => $subscription->plan->currency,
                        'billing_period_days' => (int) $subscription->plan->billing_period_days,
                    ]
                    : null,
                'expires_at' => $subscription?->expires_at?->toISOString(),
                'renewal_due_at' => $subscription?->renewal_due_at?->toISOString(),
            ],
            'chats' => [
                'unread_messages' => $unreadMessages,
                'messages_today' => $messagesToday,
                'active_chats_today' => $activeChatsToday,
                'messages_last_7_days' => $this->messagesHistoryPayload($company->id, $now),
                'status_breakdown' => [
                    'open' => (int) ($chatStatusCounts[Chat::STATUS_OPEN] ?? 0),
                    'pending' => (int) ($chatStatusCounts[Chat::STATUS_PENDING] ?? 0),
                    'closed' => (int) ($chatStatusCounts[Chat::STATUS_CLOSED] ?? 0),
                    'archived' => (int) ($chatStatusCounts[Chat::STATUS_ARCHIVED] ?? 0),
                ],
                'channel_breakdown' => $this->channelBreakdownPayload($channelChatCounts, $channelMessageCounts),
            ],
            'revenue' => [
                'currency' => $currency !== '' ? $currency : 'TJS',
                'current_month_paid' => $this->decimalString($currentMonthRevenue),
                'previous_month_paid' => $this->decimalString($previousMonthRevenue),
                'pending_amount' => $this->decimalString($pendingRevenue),
                'growth_percent' => $this->growthPercent($currentMonthRevenue, $previousMonthRevenue),
                'history_last_6_months' => $this->revenueHistoryPayload($company->id, $now),
            ],
            'catalog' => [
                'assistants_total' => (int) $company->assistants()->count(),
                'assistants_active' => (int) $company->assistants()->where('is_active', true)->count(),
                'services_total' => $servicesCount,
                'services_active' => $activeServicesCount,
                'products_total' => $productsCount,
                'products_active' => $activeProductsCount,
                'average_service_price' => $this->decimalString($averageServicePrice),
                'average_product_price' => $this->decimalString($averageProductPrice),
                'top_services' => $this->topServicesPayload($company->id),
            ],
            'operations' => [
                'orders' => [
                    'new' => (int) ($orderStatusCounts[CompanyClientOrder::STATUS_NEW] ?? 0),
                    'in_progress' => (int) ($orderStatusCounts[CompanyClientOrder::STATUS_IN_PROGRESS] ?? 0),
                    'appointments' => (int) ($orderStatusCounts[CompanyClientOrder::STATUS_APPOINTMENTS] ?? 0),
                    'confirmed' => (int) ($orderStatusCounts[CompanyClientOrder::STATUS_CONFIRMED] ?? 0),
                    'handed_to_courier' => (int) ($orderStatusCounts[CompanyClientOrder::STATUS_HANDED_TO_COURIER] ?? 0),
                    'delivered' => (int) ($orderStatusCounts[CompanyClientOrder::STATUS_DELIVERED] ?? 0),
                    'completed' => (int) ($orderStatusCounts[CompanyClientOrder::STATUS_COMPLETED] ?? 0),
                    'canceled' => (int) ($orderStatusCounts[CompanyClientOrder::STATUS_CANCELED] ?? 0),
                ],
                'questions' => [
                    'open' => (int) ($questionStatusCounts[CompanyClientQuestion::STATUS_OPEN] ?? 0),
                    'in_progress' => (int) ($questionStatusCounts[CompanyClientQuestion::STATUS_IN_PROGRESS] ?? 0),
                    'answered' => (int) ($questionStatusCounts[CompanyClientQuestion::STATUS_ANSWERED] ?? 0),
                    'closed' => (int) ($questionStatusCounts[CompanyClientQuestion::STATUS_CLOSED] ?? 0),
                ],
                'upcoming_appointments_7d' => $upcomingAppointments,
            ],
        ]);
    }

    private function subscriptionUsagePayload(?CompanySubscription $subscription): array
    {
        if (! $subscription) {
            return [
                'included_chats' => 0,
                'used_chats' => 0,
                'remaining_chats' => 0,
                'overage_chats' => 0,
                'usage_percent' => 0,
                'assistant_limit' => 0,
                'integrations_per_channel_limit' => 0,
                'overage_chat_price' => '0.00',
            ];
        }

        $includedChats = max($subscription->resolvedIncludedChats(), 0);
        $usedChats = max((int) $subscription->chat_count_current_period, 0);
        $remainingChats = max($includedChats - $usedChats, 0);
        $overageChats = max($usedChats - $includedChats, 0);

        return [
            'included_chats' => $includedChats,
            'used_chats' => $usedChats,
            'remaining_chats' => $remainingChats,
            'overage_chats' => $overageChats,
            'usage_percent' => $this->percentage($usedChats, $includedChats),
            'assistant_limit' => $subscription->resolvedAssistantLimit(),
            'integrations_per_channel_limit' => $subscription->resolvedIntegrationsPerChannelLimit(),
            'overage_chat_price' => (string) $subscription->resolvedOverageChatPrice(),
        ];
    }

    private function messagesHistoryPayload(int $companyId, Carbon $now): array
    {
        $start = $now->copy()->subDays(6)->startOfDay();
        $messages = ChatMessage::query()
            ->where('company_id', $companyId)
            ->where('created_at', '>=', $start)
            ->get(['created_at']);

        $counts = [];

        for ($offset = 6; $offset >= 0; $offset--) {
            $date = $now->copy()->subDays($offset)->toDateString();
            $counts[$date] = 0;
        }

        foreach ($messages as $message) {
            if (! $message->created_at) {
                continue;
            }

            $date = $message->created_at->toDateString();
            if (! array_key_exists($date, $counts)) {
                continue;
            }

            $counts[$date]++;
        }

        $payload = [];

        foreach ($counts as $date => $count) {
            $payload[] = [
                'date' => $date,
                'count' => $count,
            ];
        }

        return $payload;
    }

    private function revenueHistoryPayload(int $companyId, Carbon $now): array
    {
        $start = $now->copy()->startOfMonth()->subMonthsNoOverflow(5);

        $orders = CompanyClientOrder::query()
            ->where('company_id', $companyId)
            ->whereIn('status', $this->completedRevenueStatuses())
            ->where(function ($query) use ($start): void {
                $query
                    ->where('completed_at', '>=', $start)
                    ->orWhere(function ($fallback) use ($start): void {
                        $fallback
                            ->whereNull('completed_at')
                            ->where('updated_at', '>=', $start);
                    });
            })
            ->get(['completed_at', 'updated_at', 'created_at', 'total_price']);

        $history = [];

        for ($offset = 5; $offset >= 0; $offset--) {
            $month = $now->copy()->startOfMonth()->subMonthsNoOverflow($offset);
            $key = $month->format('Y-m');
            $history[$key] = 0.0;
        }

        foreach ($orders as $order) {
            $effectiveCompletedAt = $order->completed_at ?? $order->updated_at ?? $order->created_at;

            if (! $effectiveCompletedAt) {
                continue;
            }

            $key = $effectiveCompletedAt->copy()->startOfMonth()->format('Y-m');

            if (! array_key_exists($key, $history)) {
                continue;
            }

            $history[$key] += (float) $order->total_price;
        }

        $payload = [];
        foreach ($history as $month => $amount) {
            $payload[] = [
                'month' => $month,
                'amount' => $this->decimalString($amount),
            ];
        }

        return $payload;
    }

    private function completedOrdersRevenueForPeriod(
        int $companyId,
        Carbon $startsAt,
        Carbon $endsAt,
    ): float {
        return (float) CompanyClientOrder::query()
            ->where('company_id', $companyId)
            ->whereIn('status', $this->completedRevenueStatuses())
            ->where(function ($query) use ($startsAt, $endsAt): void {
                $query
                    ->whereBetween('completed_at', [$startsAt, $endsAt])
                    ->orWhere(function ($fallback) use ($startsAt, $endsAt): void {
                        $fallback
                            ->whereNull('completed_at')
                            ->whereBetween('updated_at', [$startsAt, $endsAt]);
                    });
            })
            ->sum('total_price');
    }

    /**
     * @return array<int, string>
     */
    private function completedRevenueStatuses(): array
    {
        return [
            CompanyClientOrder::STATUS_COMPLETED,
            CompanyClientOrder::STATUS_DELIVERED,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function pendingRevenueStatuses(): array
    {
        return [
            CompanyClientOrder::STATUS_NEW,
            CompanyClientOrder::STATUS_IN_PROGRESS,
            CompanyClientOrder::STATUS_APPOINTMENTS,
            CompanyClientOrder::STATUS_CONFIRMED,
            CompanyClientOrder::STATUS_HANDED_TO_COURIER,
        ];
    }

    private function channelBreakdownPayload(Collection $chatCounts, Collection $messageCounts): array
    {
        $channels = [];

        foreach ($chatCounts as $channel => $count) {
            $key = (string) $channel;
            if ($key === '') {
                continue;
            }

            $channels[$key] = [
                'channel' => $key,
                'chats' => (int) $count,
                'messages' => 0,
            ];
        }

        foreach ($messageCounts as $channel => $count) {
            $key = (string) $channel;
            if ($key === '') {
                continue;
            }

            if (! isset($channels[$key])) {
                $channels[$key] = [
                    'channel' => $key,
                    'chats' => 0,
                    'messages' => 0,
                ];
            }

            $channels[$key]['messages'] = (int) $count;
        }

        return collect($channels)
            ->values()
            ->sortByDesc('messages')
            ->values()
            ->all();
    }

    private function topServicesPayload(int $companyId): array
    {
        $rows = CompanyClientOrder::query()
            ->where('company_id', $companyId)
            ->whereNotNull('assistant_service_id')
            ->select(
                'assistant_service_id',
                DB::raw('COUNT(*) as orders_count'),
                DB::raw('COALESCE(SUM(total_price), 0) as revenue')
            )
            ->groupBy('assistant_service_id')
            ->orderByDesc('orders_count')
            ->limit(5)
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $serviceNames = AssistantService::query()
            ->whereIn('id', $rows->pluck('assistant_service_id')->all())
            ->pluck('name', 'id');

        return $rows
            ->map(function (CompanyClientOrder $row) use ($serviceNames): array {
                $serviceId = (int) $row->assistant_service_id;

                return [
                    'id' => $serviceId,
                    'name' => (string) ($serviceNames[$serviceId] ?? ('Service #'.$serviceId)),
                    'orders_count' => (int) $row->orders_count,
                    'revenue' => $this->decimalString((float) $row->revenue),
                ];
            })
            ->values()
            ->all();
    }

    private function percentage(int $value, int $max): int
    {
        if ($max <= 0) {
            return $value > 0 ? 100 : 0;
        }

        $percent = (int) round(($value / $max) * 100);

        return max(0, min(100, $percent));
    }

    private function growthPercent(float $current, float $previous): ?float
    {
        if ($previous <= 0.0) {
            if ($current <= 0.0) {
                return 0.0;
            }

            return null;
        }

        return round((($current - $previous) / $previous) * 100, 2);
    }

    private function decimalString(float $value): string
    {
        return number_format($value, 2, '.', '');
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
