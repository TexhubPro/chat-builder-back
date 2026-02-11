<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubscriptionPlan extends Model
{
    use HasFactory;

    public const CODE_STARTER_MONTHLY = 'starter-monthly';
    public const CODE_ENTERPRISE_CUSTOM = 'enterprise-custom';

    protected $fillable = [
        'code',
        'name',
        'description',
        'is_active',
        'is_public',
        'is_enterprise',
        'billing_period_days',
        'currency',
        'price',
        'included_chats',
        'overage_chat_price',
        'assistant_limit',
        'integrations_per_channel_limit',
        'sort_order',
        'features',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_public' => 'boolean',
            'is_enterprise' => 'boolean',
            'billing_period_days' => 'integer',
            'price' => 'decimal:2',
            'included_chats' => 'integer',
            'overage_chat_price' => 'decimal:2',
            'assistant_limit' => 'integer',
            'integrations_per_channel_limit' => 'integer',
            'sort_order' => 'integer',
            'features' => 'array',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(CompanySubscription::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}
