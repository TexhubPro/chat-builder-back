<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssistantProduct extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'company_id',
        'assistant_id',
        'name',
        'sku',
        'description',
        'terms_conditions',
        'price',
        'currency',
        'stock_quantity',
        'is_unlimited_stock',
        'photo_urls',
        'is_active',
        'sort_order',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'stock_quantity' => 'integer',
            'is_unlimited_stock' => 'boolean',
            'photo_urls' => 'array',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function assistant(): BelongsTo
    {
        return $this->belongsTo(Assistant::class);
    }
}
