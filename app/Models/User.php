<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public const ROLE_CUSTOMER = 'customer';
    public const ROLE_ADMIN = 'admin';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'avatar',
        'role',
        'status',
        'openai_assistant_updated_at',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'status' => 'boolean',
            'openai_assistant_updated_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    public function emailVerificationCode(): HasOne
    {
        return $this->hasOne(EmailVerificationCode::class);
    }

    public function assistants(): HasMany
    {
        return $this->hasMany(Assistant::class);
    }

    public function company(): HasOne
    {
        return $this->hasOne(Company::class);
    }

    public function assistantChannels(): HasMany
    {
        return $this->hasMany(AssistantChannel::class);
    }

    public function chats(): HasMany
    {
        return $this->hasMany(Chat::class);
    }

    public function chatMessages(): HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }

    public function companySubscription(): HasOne
    {
        return $this->hasOne(CompanySubscription::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function assistantServices(): HasMany
    {
        return $this->hasMany(AssistantService::class);
    }

    public function assistantProducts(): HasMany
    {
        return $this->hasMany(AssistantProduct::class);
    }

    public function companyClients(): HasMany
    {
        return $this->hasMany(CompanyClient::class);
    }

    public function companyClientOrders(): HasMany
    {
        return $this->hasMany(CompanyClientOrder::class);
    }

    public function companyCalendarEvents(): HasMany
    {
        return $this->hasMany(CompanyCalendarEvent::class);
    }

    public function companyClientQuestions(): HasMany
    {
        return $this->hasMany(CompanyClientQuestion::class);
    }

    public function companyClientTasks(): HasMany
    {
        return $this->hasMany(CompanyClientTask::class);
    }
}
