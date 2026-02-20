<?php

namespace App\Filament\Resources\Companies;

use App\Filament\Resources\Companies\Pages\ManageActiveCompanies;
use App\Models\Company;
use App\Models\CompanySubscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\CompanySubscriptionService;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

class ActiveCompanyResource extends Resource
{
    protected static ?string $model = Company::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationLabel = 'Активные компании';

    protected static string | \UnitEnum | null $navigationGroup = 'Компании';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['user', 'subscription.plan'])
            ->where('status', Company::STATUS_ACTIVE)
            ->whereHas('user', function (Builder $query): void {
                $query
                    ->where('role', User::ROLE_CUSTOMER)
                    ->where('status', true);
            });
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Компания')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('user.name')
                    ->label('Владелец')
                    ->description(fn (Company $record): string => $record->user?->email ?? '—')
                    ->searchable(),
                TextColumn::make('subscription.plan.name')
                    ->label('Тариф')
                    ->default('—')
                    ->badge(),
                TextColumn::make('subscription.status')
                    ->label('Статус подписки')
                    ->formatStateUsing(fn (?string $state): string => static::subscriptionStatusLabel($state))
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        CompanySubscription::STATUS_ACTIVE => 'success',
                        CompanySubscription::STATUS_PENDING_PAYMENT => 'warning',
                        CompanySubscription::STATUS_PAST_DUE => 'warning',
                        CompanySubscription::STATUS_UNPAID => 'danger',
                        CompanySubscription::STATUS_EXPIRED => 'danger',
                        CompanySubscription::STATUS_CANCELED => 'gray',
                        default => 'gray',
                    }),
                TextColumn::make('subscription.quantity')
                    ->label('Счет')
                    ->state(fn (Company $record): int => max((int) ($record->subscription?->quantity ?? 0), 0)),
                TextColumn::make('assistant_limit')
                    ->label('Лимит ассистентов')
                    ->state(fn (Company $record): int => max((int) ($record->subscription?->resolvedAssistantLimit() ?? 0), 0)),
                TextColumn::make('integrations_limit')
                    ->label('Лимит интеграций')
                    ->state(fn (Company $record): int => max((int) ($record->subscription?->resolvedIntegrationsPerChannelLimit() ?? 0), 0)),
                TextColumn::make('chat_usage')
                    ->label('Чаты в периоде')
                    ->state(function (Company $record): string {
                        $subscription = $record->subscription;

                        if (! $subscription) {
                            return '0 / 0';
                        }

                        $used = max((int) $subscription->chat_count_current_period, 0);
                        $included = max($subscription->resolvedIncludedChats(), 0);

                        return "{$used} / {$included}";
                    }),
                TextColumn::make('created_at')
                    ->label('Дата регистрации')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->recordActions([
                Action::make('details')
                    ->label('Информация')
                    ->icon('heroicon-o-eye')
                    ->slideOver()
                    ->modalHeading('Информация о компании')
                    ->schema([
                        Placeholder::make('company_name')
                            ->label('Название компании')
                            ->content(fn (Company $record): string => $record->name ?: '—'),
                        Placeholder::make('industry')
                            ->label('Отрасль')
                            ->content(fn (Company $record): string => $record->industry ?: '—'),
                        Placeholder::make('short_description')
                            ->label('Краткое описание')
                            ->content(fn (Company $record): string => $record->short_description ?: '—'),
                        Placeholder::make('primary_goal')
                            ->label('Основная цель')
                            ->content(fn (Company $record): string => $record->primary_goal ?: '—'),
                        Placeholder::make('liddo_use_case')
                            ->label('Использование Liddo')
                            ->content(fn (Company $record): string => static::resolveUseCaseLabel(
                                data_get($record->settings, 'moderation_application.liddo_use_case')
                            )),
                        Placeholder::make('contact_email')
                            ->label('Контактный email')
                            ->content(fn (Company $record): string => $record->contact_email ?: '—'),
                        Placeholder::make('contact_phone')
                            ->label('Контактный телефон')
                            ->content(fn (Company $record): string => $record->contact_phone ?: '—'),
                        Placeholder::make('subscription_status')
                            ->label('Статус подписки')
                            ->content(fn (Company $record): string => static::subscriptionStatusLabel(
                                $record->subscription?->status
                            )),
                        Placeholder::make('subscription_plan')
                            ->label('Тариф')
                            ->content(fn (Company $record): string => $record->subscription?->plan?->name ?? '—'),
                    ])
                    ->disabledSchema()
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Закрыть'),
                Action::make('activate_subscription')
                    ->label('Активировать подписку')
                    ->icon('heroicon-o-bolt')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(fn (Company $record) => static::activateSubscription($record))
                    ->visible(fn (Company $record): bool =>
                        $record->subscription?->status !== CompanySubscription::STATUS_ACTIVE
                    ),
                Action::make('manage_subscription')
                    ->label('Настроить подписку')
                    ->icon('heroicon-o-credit-card')
                    ->slideOver()
                    ->modalHeading('Настройка подписки')
                    ->fillForm(fn (Company $record): array => static::subscriptionFormDefaults($record))
                    ->schema([
                        Select::make('subscription_plan_id')
                            ->label('Тариф')
                            ->options(static::planOptions())
                            ->searchable()
                            ->preload()
                            ->required(),
                        Select::make('status')
                            ->label('Статус подписки')
                            ->options(static::subscriptionStatusOptions())
                            ->required(),
                        TextInput::make('quantity')
                            ->label('Счет (кол-во)')
                            ->numeric()
                            ->minValue(1)
                            ->required(),
                        TextInput::make('billing_cycle_days')
                            ->label('Дней в биллинг-цикле')
                            ->numeric()
                            ->minValue(1)
                            ->required(),
                        TextInput::make('chat_count_current_period')
                            ->label('Использовано чатов (текущий период)')
                            ->numeric()
                            ->minValue(0)
                            ->required(),
                        DateTimePicker::make('starts_at')
                            ->label('Начало подписки'),
                        DateTimePicker::make('expires_at')
                            ->label('Окончание подписки'),
                        DateTimePicker::make('renewal_due_at')
                            ->label('Дата продления'),
                        DateTimePicker::make('chat_period_started_at')
                            ->label('Старт периода чатов'),
                        DateTimePicker::make('chat_period_ends_at')
                            ->label('Окончание периода чатов'),
                        TextInput::make('assistant_limit_override')
                            ->label('Лимит ассистентов (override)')
                            ->numeric()
                            ->minValue(0),
                        TextInput::make('integrations_per_channel_override')
                            ->label('Лимит интеграций на канал (override)')
                            ->numeric()
                            ->minValue(0),
                        TextInput::make('included_chats_override')
                            ->label('Лимит чатов (override)')
                            ->numeric()
                            ->minValue(0),
                        TextInput::make('overage_chat_price_override')
                            ->label('Цена оверейджа за чат (override)')
                            ->numeric()
                            ->minValue(0)
                            ->step('0.01'),
                        Textarea::make('admin_note')
                            ->label('Заметка администратора')
                            ->rows(3)
                            ->maxLength(2000),
                    ])
                    ->action(fn (Company $record, array $data) => static::saveSubscription($record, $data)),
                Action::make('deactivate_subscription')
                    ->label('Отключить подписку')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(fn (Company $record) => static::deactivateSubscription($record))
                    ->visible(fn (Company $record): bool =>
                        $record->subscription?->status === CompanySubscription::STATUS_ACTIVE
                    ),
            ]);
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageActiveCompanies::route('/'),
        ];
    }

    private static function subscriptionStatusOptions(): array
    {
        return [
            CompanySubscription::STATUS_INACTIVE => 'Неактивна',
            CompanySubscription::STATUS_PENDING_PAYMENT => 'Ожидает оплату',
            CompanySubscription::STATUS_ACTIVE => 'Активна',
            CompanySubscription::STATUS_PAST_DUE => 'Просрочена',
            CompanySubscription::STATUS_UNPAID => 'Не оплачена',
            CompanySubscription::STATUS_EXPIRED => 'Истекла',
            CompanySubscription::STATUS_CANCELED => 'Отменена',
        ];
    }

    private static function subscriptionStatusLabel(?string $status): string
    {
        return static::subscriptionStatusOptions()[$status ?? ''] ?? '—';
    }

    private static function planOptions(): array
    {
        return SubscriptionPlan::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'name', 'is_active'])
            ->mapWithKeys(static fn (SubscriptionPlan $plan): array => [
                $plan->id => $plan->is_active ? $plan->name : "{$plan->name} (неактивный тариф)",
            ])
            ->all();
    }

    private static function subscriptionFormDefaults(Company $company): array
    {
        $company->loadMissing('subscription.plan');
        $subscription = $company->subscription;

        $defaultPlanId = SubscriptionPlan::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->value('id');

        $metadata = is_array($subscription?->metadata) ? $subscription->metadata : [];

        return [
            'subscription_plan_id' => $subscription?->subscription_plan_id ?? $defaultPlanId,
            'status' => $subscription?->status ?? CompanySubscription::STATUS_INACTIVE,
            'quantity' => max((int) ($subscription?->quantity ?? 1), 1),
            'billing_cycle_days' => max((int) ($subscription?->billing_cycle_days ?? 30), 1),
            'chat_count_current_period' => max((int) ($subscription?->chat_count_current_period ?? 0), 0),
            'starts_at' => static::formatDateTime($subscription?->starts_at),
            'expires_at' => static::formatDateTime($subscription?->expires_at),
            'renewal_due_at' => static::formatDateTime($subscription?->renewal_due_at),
            'chat_period_started_at' => static::formatDateTime($subscription?->chat_period_started_at),
            'chat_period_ends_at' => static::formatDateTime($subscription?->chat_period_ends_at),
            'assistant_limit_override' => $subscription?->assistant_limit_override,
            'integrations_per_channel_override' => $subscription?->integrations_per_channel_override,
            'included_chats_override' => $subscription?->included_chats_override,
            'overage_chat_price_override' => $subscription?->overage_chat_price_override,
            'admin_note' => is_string($metadata['admin_note'] ?? null) ? $metadata['admin_note'] : null,
        ];
    }

    private static function saveSubscription(Company $company, array $data): void
    {
        $company->loadMissing('subscription');

        $subscription = $company->subscription ?? new CompanySubscription();
        $subscription->company_id = $company->id;
        $subscription->user_id = $company->user_id;

        $planId = isset($data['subscription_plan_id']) ? (int) $data['subscription_plan_id'] : 0;
        $planExists = $planId > 0 && SubscriptionPlan::query()->whereKey($planId)->exists();

        if (! $planExists) {
            Notification::make()
                ->title('Выберите корректный тариф.')
                ->danger()
                ->send();

            return;
        }

        $status = (string) ($data['status'] ?? CompanySubscription::STATUS_INACTIVE);

        if (! array_key_exists($status, static::subscriptionStatusOptions())) {
            Notification::make()
                ->title('Выберите корректный статус подписки.')
                ->danger()
                ->send();

            return;
        }

        $quantity = max((int) ($data['quantity'] ?? 1), 1);
        $billingCycleDays = max((int) ($data['billing_cycle_days'] ?? 30), 1);
        $chatCountCurrentPeriod = max((int) ($data['chat_count_current_period'] ?? 0), 0);

        $startsAt = static::toCarbon($data['starts_at'] ?? null);
        $expiresAt = static::toCarbon($data['expires_at'] ?? null);
        $renewalDueAt = static::toCarbon($data['renewal_due_at'] ?? null);
        $chatPeriodStartedAt = static::toCarbon($data['chat_period_started_at'] ?? null);
        $chatPeriodEndsAt = static::toCarbon($data['chat_period_ends_at'] ?? null);

        if ($status === CompanySubscription::STATUS_ACTIVE) {
            $now = now();
            $startsAt ??= $subscription->starts_at ? Carbon::instance($subscription->starts_at) : $now;
            $chatPeriodStartedAt ??= $subscription->chat_period_started_at
                ? Carbon::instance($subscription->chat_period_started_at)
                : $startsAt->copy();
            $chatPeriodEndsAt ??= $subscription->chat_period_ends_at
                ? Carbon::instance($subscription->chat_period_ends_at)
                : $chatPeriodStartedAt->copy()->addDays($billingCycleDays);

            if ($chatPeriodEndsAt->lte($chatPeriodStartedAt)) {
                $chatPeriodEndsAt = $chatPeriodStartedAt->copy()->addDays($billingCycleDays);
            }

            $renewalDueAt ??= $chatPeriodEndsAt->copy();
            $expiresAt ??= $chatPeriodEndsAt->copy();
        }

        $metadata = is_array($subscription->metadata) ? $subscription->metadata : [];
        $adminNote = trim((string) ($data['admin_note'] ?? ''));

        if ($adminNote !== '') {
            $metadata['admin_note'] = $adminNote;
        } else {
            unset($metadata['admin_note']);
        }

        $subscription->forceFill([
            'subscription_plan_id' => $planId,
            'status' => $status,
            'quantity' => $quantity,
            'billing_cycle_days' => $billingCycleDays,
            'chat_count_current_period' => $chatCountCurrentPeriod,
            'starts_at' => $startsAt,
            'expires_at' => $expiresAt,
            'renewal_due_at' => $renewalDueAt,
            'chat_period_started_at' => $chatPeriodStartedAt,
            'chat_period_ends_at' => $chatPeriodEndsAt,
            'assistant_limit_override' => static::toNullableInt($data['assistant_limit_override'] ?? null),
            'integrations_per_channel_override' => static::toNullableInt($data['integrations_per_channel_override'] ?? null),
            'included_chats_override' => static::toNullableInt($data['included_chats_override'] ?? null),
            'overage_chat_price_override' => static::toNullableDecimal($data['overage_chat_price_override'] ?? null),
            'paid_at' => $status === CompanySubscription::STATUS_ACTIVE
                ? ($subscription->paid_at ?? now())
                : null,
            'canceled_at' => $status === CompanySubscription::STATUS_CANCELED
                ? ($subscription->canceled_at ?? now())
                : null,
            'metadata' => $metadata === [] ? null : $metadata,
        ])->save();

        $service = app(CompanySubscriptionService::class);
        $service->synchronizeBillingPeriods($subscription);
        $service->syncAssistantAccess($company->fresh());

        Notification::make()
            ->title('Подписка обновлена.')
            ->success()
            ->send();
    }

    private static function activateSubscription(Company $company): void
    {
        $service = app(CompanySubscriptionService::class);
        $subscription = $service->ensureCurrentSubscription($company);

        if (! $subscription->subscription_plan_id) {
            $defaultPlanId = SubscriptionPlan::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->value('id');

            if (! $defaultPlanId) {
                Notification::make()
                    ->title('Не найден активный тариф для активации подписки.')
                    ->danger()
                    ->send();

                return;
            }

            $subscription->subscription_plan_id = (int) $defaultPlanId;
        }

        $now = now();
        $cycleDays = max((int) $subscription->billing_cycle_days, 1);

        $subscription->forceFill([
            'status' => CompanySubscription::STATUS_ACTIVE,
            'quantity' => max((int) $subscription->quantity, 1),
            'starts_at' => $now,
            'paid_at' => $now,
            'canceled_at' => null,
            'chat_period_started_at' => $now,
            'chat_period_ends_at' => $now->copy()->addDays($cycleDays),
            'renewal_due_at' => $now->copy()->addDays($cycleDays),
            'expires_at' => $now->copy()->addDays($cycleDays),
        ])->save();

        $service->syncAssistantAccess($company->fresh());

        Notification::make()
            ->title('Подписка активирована.')
            ->success()
            ->send();
    }

    private static function deactivateSubscription(Company $company): void
    {
        $service = app(CompanySubscriptionService::class);
        $subscription = $service->ensureCurrentSubscription($company);

        $subscription->forceFill([
            'status' => CompanySubscription::STATUS_INACTIVE,
            'canceled_at' => now(),
        ])->save();

        $service->syncAssistantAccess($company->fresh());

        Notification::make()
            ->title('Подписка отключена.')
            ->warning()
            ->send();
    }

    private static function toNullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return max((int) $value, 0);
    }

    private static function toNullableDecimal(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return max(round((float) $value, 2), 0);
    }

    private static function toCarbon(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof Carbon) {
            return $value->copy();
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        if ($normalized === '') {
            return null;
        }

        try {
            return Carbon::parse($normalized);
        } catch (Throwable) {
            return null;
        }
    }

    private static function formatDateTime(mixed $value): ?string
    {
        $dateTime = static::toCarbon($value);

        return $dateTime?->format('Y-m-d H:i:s');
    }

    private static function resolveUseCaseLabel(?string $code): string
    {
        return match ($code) {
            'lead_generation' => 'Сбор лидов',
            'support_automation' => 'Автоматизация поддержки',
            'sales_automation' => 'Автоматизация продаж',
            'appointments' => 'Записи и бронирования',
            'orders' => 'Заказы и доставка',
            'other' => 'Другое',
            default => '—',
        };
    }
}
