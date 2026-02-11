<?php

namespace Database\Seeders;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

class SubscriptionPlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        SubscriptionPlan::query()
            ->where('code', SubscriptionPlan::CODE_ENTERPRISE_CUSTOM)
            ->delete();

        $channels = ['instagram', 'telegram', 'web_widget', 'api'];

        $basePlans = [
            [
                'key' => 'starter',
                'monthly_code' => SubscriptionPlan::CODE_STARTER_MONTHLY,
                'name' => 'Starter',
                'sort_order' => 10,
                'support' => 'standard',
                'monthly_price' => 30.00,
                'included_chats' => 400,
                'overage_chat_price' => 1.00,
                'assistant_limit' => 1,
                'integrations_per_channel_limit' => 1,
            ],
            [
                'key' => 'growth',
                'monthly_code' => 'growth-monthly',
                'name' => 'Growth',
                'sort_order' => 20,
                'support' => 'priority',
                'monthly_price' => 50.00,
                'included_chats' => 700,
                'overage_chat_price' => 1.00,
                'assistant_limit' => 2,
                'integrations_per_channel_limit' => 2,
            ],
            [
                'key' => 'scale',
                'monthly_code' => 'scale-monthly',
                'name' => 'Scale',
                'sort_order' => 30,
                'support' => 'priority',
                'monthly_price' => 100.00,
                'included_chats' => 1500,
                'overage_chat_price' => 1.00,
                'assistant_limit' => 3,
                'integrations_per_channel_limit' => 3,
            ],
            [
                'key' => 'enterprise',
                'monthly_code' => 'enterprise-monthly',
                'name' => 'Enterprise',
                'sort_order' => 40,
                'support' => 'dedicated',
                'monthly_price' => 250.00,
                'included_chats' => 5000,
                'overage_chat_price' => 0.75,
                'assistant_limit' => 6,
                'integrations_per_channel_limit' => 6,
            ],
        ];

        $periods = [
            [
                'code_suffix' => 'monthly',
                'label' => 'Monthly',
                'days' => 30,
                'price_multiplier' => 1.00,
                'discount_percent' => 0,
                'sort_offset' => 0,
            ],
            [
                'code_suffix' => 'quarterly',
                'label' => 'Quarterly',
                'days' => 90,
                'price_multiplier' => 2.70,
                'discount_percent' => 10,
                'sort_offset' => 100,
            ],
            [
                'code_suffix' => 'semiannual',
                'label' => 'Semiannual',
                'days' => 180,
                'price_multiplier' => 4.80,
                'discount_percent' => 20,
                'sort_offset' => 200,
            ],
        ];

        foreach ($basePlans as $basePlan) {
            foreach ($periods as $period) {
                $code = $period['code_suffix'] === 'monthly'
                    ? $basePlan['monthly_code']
                    : $basePlan['key'] . '-' . $period['code_suffix'];

                $periodPrice = number_format(
                    $basePlan['monthly_price'] * $period['price_multiplier'],
                    2,
                    '.',
                    '',
                );

                $description = $period['discount_percent'] > 0
                    ? sprintf(
                        '%s plan with %d%% discount for %d days.',
                        $basePlan['name'],
                        $period['discount_percent'],
                        $period['days'],
                    )
                    : sprintf('%s plan for %d days.', $basePlan['name'], $period['days']);

                SubscriptionPlan::query()->updateOrCreate(
                    ['code' => $code],
                    [
                        'name' => $basePlan['name'] . ' ' . $period['label'],
                        'description' => $description,
                        'is_active' => true,
                        'is_public' => true,
                        'is_enterprise' => false,
                        'billing_period_days' => $period['days'],
                        'currency' => 'USD',
                        'price' => $periodPrice,
                        'included_chats' => $basePlan['included_chats'],
                        'overage_chat_price' => $basePlan['overage_chat_price'],
                        'assistant_limit' => $basePlan['assistant_limit'],
                        'integrations_per_channel_limit' => $basePlan['integrations_per_channel_limit'],
                        'sort_order' => $basePlan['sort_order'] + $period['sort_offset'],
                        'features' => [
                            'support' => $basePlan['support'],
                            'channels' => $channels,
                            'discount_percent' => $period['discount_percent'],
                        ],
                    ]
                );
            }
        }
    }
}
