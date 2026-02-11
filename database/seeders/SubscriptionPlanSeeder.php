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
        SubscriptionPlan::query()->updateOrCreate(
            ['code' => SubscriptionPlan::CODE_STARTER_MONTHLY],
            [
                'name' => 'Starter Monthly',
                'description' => 'Monthly plan with 500 included chats. Extra chat costs 1 TJS.',
                'is_active' => true,
                'is_public' => true,
                'is_enterprise' => false,
                'billing_period_days' => 30,
                'currency' => 'TJS',
                'price' => 99.00,
                'included_chats' => 500,
                'overage_chat_price' => 1.00,
                'assistant_limit' => 1,
                'integrations_per_channel_limit' => 1,
                'sort_order' => 10,
                'features' => [
                    'support' => 'standard',
                    'notes' => 'Quantity can be set to 1-5+ when company buys multiple units.',
                ],
            ]
        );

        SubscriptionPlan::query()->updateOrCreate(
            ['code' => SubscriptionPlan::CODE_ENTERPRISE_CUSTOM],
            [
                'name' => 'Enterprise Custom',
                'description' => 'Custom enterprise plan configured manually for specific companies.',
                'is_active' => true,
                'is_public' => false,
                'is_enterprise' => true,
                'billing_period_days' => 30,
                'currency' => 'TJS',
                'price' => 0.00,
                'included_chats' => 0,
                'overage_chat_price' => 0.00,
                'assistant_limit' => 0,
                'integrations_per_channel_limit' => 0,
                'sort_order' => 999,
                'features' => [
                    'support' => 'dedicated',
                    'notes' => 'Limits/pricing are set via subscription override fields.',
                ],
            ]
        );
    }
}
