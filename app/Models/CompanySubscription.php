<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CompanySubscription extends Model
{
    use HasFactory;

    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_PENDING_PAYMENT = 'pending_payment';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAST_DUE = 'past_due';
    public const STATUS_UNPAID = 'unpaid';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELED = 'canceled';

    protected $fillable = [
        'company_id',
        'user_id',
        'subscription_plan_id',
        'status',
        'quantity',
        'billing_cycle_days',
        'assistant_limit_override',
        'integrations_per_channel_override',
        'included_chats_override',
        'overage_chat_price_override',
        'chat_count_current_period',
        'chat_period_started_at',
        'chat_period_ends_at',
        'starts_at',
        'expires_at',
        'renewal_due_at',
        'paid_at',
        'canceled_at',
        'grace_ends_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'billing_cycle_days' => 'integer',
            'assistant_limit_override' => 'integer',
            'integrations_per_channel_override' => 'integer',
            'included_chats_override' => 'integer',
            'overage_chat_price_override' => 'decimal:2',
            'chat_count_current_period' => 'integer',
            'chat_period_started_at' => 'datetime',
            'chat_period_ends_at' => 'datetime',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'renewal_due_at' => 'datetime',
            'paid_at' => 'datetime',
            'canceled_at' => 'datetime',
            'grace_ends_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function isActiveAt(?CarbonImmutable $moment = null): bool
    {
        $moment ??= CarbonImmutable::now();

        if ($this->status !== self::STATUS_ACTIVE || $this->quantity <= 0) {
            return false;
        }

        if ($this->starts_at && $moment->lt(CarbonImmutable::instance($this->starts_at))) {
            return false;
        }

        if ($this->expires_at && !$moment->lt(CarbonImmutable::instance($this->expires_at))) {
            return false;
        }

        return true;
    }

    public function isExpiredAt(?CarbonImmutable $moment = null): bool
    {
        $moment ??= CarbonImmutable::now();

        if (!$this->expires_at) {
            return false;
        }

        return !$moment->lt(CarbonImmutable::instance($this->expires_at));
    }

    public function resolvedAssistantLimit(): int
    {
        if ($this->assistant_limit_override !== null) {
            return max($this->assistant_limit_override, 0);
        }

        $base = (int) ($this->plan?->assistant_limit ?? 0);

        return max($base * max($this->quantity, 0), 0);
    }

    public function resolvedIntegrationsPerChannelLimit(): int
    {
        if ($this->integrations_per_channel_override !== null) {
            return max($this->integrations_per_channel_override, 0);
        }

        $base = (int) ($this->plan?->integrations_per_channel_limit ?? 0);

        return max($base * max($this->quantity, 0), 0);
    }

    public function resolvedIncludedChats(): int
    {
        if ($this->included_chats_override !== null) {
            return max($this->included_chats_override, 0);
        }

        $base = (int) ($this->plan?->included_chats ?? 0);

        return max($base * max($this->quantity, 0), 0);
    }

    public function resolvedOverageChatPrice(): string
    {
        if ($this->overage_chat_price_override !== null) {
            return (string) $this->overage_chat_price_override;
        }

        return (string) ($this->plan?->overage_chat_price ?? '0.00');
    }
}
