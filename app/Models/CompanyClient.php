<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CompanyClient extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_ARCHIVED = 'archived';
    public const STATUS_BLOCKED = 'blocked';

    protected $fillable = [
        'user_id',
        'company_id',
        'name',
        'phone',
        'email',
        'notes',
        'status',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
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

    public function orders(): HasMany
    {
        return $this->hasMany(CompanyClientOrder::class);
    }

    public function calendarEvents(): HasMany
    {
        return $this->hasMany(CompanyCalendarEvent::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(CompanyClientQuestion::class, 'company_client_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(CompanyClientTask::class, 'company_client_id');
    }
}
