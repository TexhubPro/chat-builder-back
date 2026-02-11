<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssistantService extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'company_id',
        'assistant_id',
        'name',
        'description',
        'terms_conditions',
        'price',
        'currency',
        'photo_urls',
        'is_active',
        'sort_order',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
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

    public function clientOrders(): HasMany
    {
        return $this->hasMany(CompanyClientOrder::class);
    }

    public function calendarEvents(): HasMany
    {
        return $this->hasMany(CompanyCalendarEvent::class);
    }
}
