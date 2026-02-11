<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyClientQuestion extends Model
{
    use HasFactory;

    public const STATUS_OPEN = 'open';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_ANSWERED = 'answered';
    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'user_id',
        'company_id',
        'company_client_id',
        'assistant_id',
        'description',
        'status',
        'board_column',
        'position',
        'resolved_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'resolved_at' => 'datetime',
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

    public function client(): BelongsTo
    {
        return $this->belongsTo(CompanyClient::class, 'company_client_id');
    }

    public function assistant(): BelongsTo
    {
        return $this->belongsTo(Assistant::class);
    }
}
