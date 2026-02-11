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

        SubscriptionPlan::query()->updateOrCreate(
            ['code' => SubscriptionPlan::CODE_STARTER_MONTHLY],
            [
                'name' => 'Starter Monthly',
                'description' => 'Monthly plan with 400 included chats.',
                'is_active' => true,
                'is_public' => true,
                'is_enterprise' => false,
                'billing_period_days' => 30,
                'currency' => 'USD',
                'price' => 30.00,
                'included_chats' => 400,
                'overage_chat_price' => 1.00,
                'assistant_limit' => 1,
                'integrations_per_channel_limit' => 1,
                'sort_order' => 10,
                'features' => [
                    'support' => 'standard',
                    'channels' => ['instagram', 'telegram', 'web_widget', 'api'],
                ],
            ]
        );

        SubscriptionPlan::query()->updateOrCreate(
            ['code' => 'growth-monthly'],
            [
                'name' => 'Growth Monthly',
                'description' => 'Monthly plan with 700 included chats.',
                'is_active' => true,
                'is_public' => true,
                'is_enterprise' => false,
                'billing_period_days' => 30,
                'currency' => 'USD',
                'price' => 50.00,
                'included_chats' => 700,
                'overage_chat_price' => 1.00,
                'assistant_limit' => 2,
                'integrations_per_channel_limit' => 2,
                'sort_order' => 20,
                'features' => [
                    'support' => 'priority',
                    'channels' => ['instagram', 'telegram', 'web_widget', 'api'],
                ],
            ]
        );

        SubscriptionPlan::query()->updateOrCreate(
            ['code' => 'scale-monthly'],
            [
                'name' => 'Scale Monthly',
                'description' => 'Monthly plan with 1500 included chats.',
                'is_active' => true,
                'is_public' => true,
                'is_enterprise' => false,
                'billing_period_days' => 30,
                'currency' => 'USD',
                'price' => 100.00,
                'included_chats' => 1500,
                'overage_chat_price' => 1.00,
                'assistant_limit' => 3,
                'integrations_per_channel_limit' => 3,
                'sort_order' => 30,
                'features' => [
                    'support' => 'priority',
                    'channels' => ['instagram', 'telegram', 'web_widget', 'api'],
                ],
            ]
        );

        SubscriptionPlan::query()->updateOrCreate(
            ['code' => 'enterprise-monthly'],
            [
                'name' => 'Enterprise Monthly',
                'description' => 'Monthly plan with advanced limits for large teams.',
                'is_active' => true,
                'is_public' => true,
                'is_enterprise' => false,
                'billing_period_days' => 30,
                'currency' => 'USD',
                'price' => 250.00,
                'included_chats' => 5000,
                'overage_chat_price' => 0.75,
                'assistant_limit' => 6,
                'integrations_per_channel_limit' => 6,
                'sort_order' => 40,
                'features' => [
                    'support' => 'dedicated',
                    'channels' => ['instagram', 'telegram', 'web_widget', 'api'],
                ],
            ]
        );
    }
}
