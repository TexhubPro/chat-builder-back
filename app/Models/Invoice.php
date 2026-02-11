<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_ISSUED = 'issued';
    public const STATUS_PAID = 'paid';
    public const STATUS_OVERDUE = 'overdue';
    public const STATUS_VOID = 'void';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'company_id',
        'user_id',
        'company_subscription_id',
        'subscription_plan_id',
        'number',
        'status',
        'currency',
        'subtotal',
        'overage_amount',
        'total',
        'amount_paid',
        'chat_included',
        'chat_used',
        'chat_overage',
        'unit_overage_price',
        'period_started_at',
        'period_ended_at',
        'issued_at',
        'due_at',
        'paid_at',
        'notes',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'overage_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'chat_included' => 'integer',
            'chat_used' => 'integer',
            'chat_overage' => 'integer',
            'unit_overage_price' => 'decimal:2',
            'period_started_at' => 'datetime',
            'period_ended_at' => 'datetime',
            'issued_at' => 'datetime',
            'due_at' => 'datetime',
            'paid_at' => 'datetime',
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

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(CompanySubscription::class, 'company_subscription_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }
}
