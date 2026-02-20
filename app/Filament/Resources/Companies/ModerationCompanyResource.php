<?php

namespace App\Filament\Resources\Companies;

use App\Filament\Resources\Companies\Pages\ManageModerationCompanies;
use App\Models\Company;
use App\Models\User;
use App\Services\CompanySubscriptionService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ModerationCompanyResource extends Resource
{
    protected static ?string $model = Company::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-shield-exclamation';

    protected static ?string $navigationLabel = 'Компании на модерации';

    protected static string | \UnitEnum | null $navigationGroup = 'Компании';

    protected static ?int $navigationSort = 10;

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
            ->with(['user'])
            ->whereNotNull('settings->moderation_application->submitted_at')
            ->whereHas('user', function (Builder $query): void {
                $query
                    ->where('role', User::ROLE_CUSTOMER)
                    ->where('status', false)
                    ->whereNotNull('email_verified_at');
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
                TextColumn::make('industry')
                    ->label('Отрасль')
                    ->default('—')
                    ->toggleable(),
                TextColumn::make('liddo_use_case')
                    ->label('Использование Liddo')
                    ->state(fn (Company $record): string => static::resolveUseCaseLabel(
                        data_get($record->settings, 'moderation_application.liddo_use_case')
                    ))
                    ->wrap(),
                TextColumn::make('contact_email')
                    ->label('Контактный email')
                    ->default('—')
                    ->toggleable(),
                TextColumn::make('contact_phone')
                    ->label('Контактный телефон')
                    ->default('—')
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('Дата заявки')
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
                        Placeholder::make('submitted_at')
                            ->label('Отправлена')
                            ->content(fn (Company $record): string =>
                                (string) data_get($record->settings, 'moderation_application.submitted_at', '—')
                            ),
                    ])
                    ->disabledSchema()
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Закрыть'),
                Action::make('approve')
                    ->label('Активировать')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Активировать компанию?')
                    ->modalDescription('Компания будет переведена из модерации в активные.')
                    ->action(fn (Company $record) => static::approveCompany($record)),
                Action::make('reject')
                    ->label('Отклонить')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->schema([
                        Textarea::make('reason')
                            ->label('Причина отклонения')
                            ->rows(4)
                            ->maxLength(1000)
                            ->required(),
                    ])
                    ->action(fn (Company $record, array $data) => static::rejectCompany(
                        $record,
                        trim((string) ($data['reason'] ?? '')),
                    )),
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
            'index' => ManageModerationCompanies::route('/'),
        ];
    }

    private static function approveCompany(Company $company): void
    {
        $company->loadMissing('user');

        if (! $company->user instanceof User) {
            Notification::make()
                ->title('Владелец компании не найден.')
                ->danger()
                ->send();

            return;
        }

        $company->user->forceFill([
            'status' => true,
        ])->save();

        $settings = is_array($company->settings) ? $company->settings : [];
        $moderationMeta = is_array($settings['moderation_application'] ?? null)
            ? $settings['moderation_application']
            : [];

        $moderationMeta['status'] = 'approved';
        $moderationMeta['reviewed_at'] = now()->toIso8601String();
        $moderationMeta['reviewed_by_user_id'] = Filament::auth()->id();
        $moderationMeta['rejection_reason'] = null;

        $settings['moderation_application'] = $moderationMeta;

        $company->forceFill([
            'status' => Company::STATUS_ACTIVE,
            'settings' => $settings,
        ])->save();

        app(CompanySubscriptionService::class)->ensureCurrentSubscription($company);
        app(CompanySubscriptionService::class)->syncAssistantAccess($company->fresh());

        Notification::make()
            ->title('Компания успешно активирована.')
            ->success()
            ->send();
    }

    private static function rejectCompany(Company $company, string $reason): void
    {
        if ($reason === '') {
            Notification::make()
                ->title('Укажите причину отклонения.')
                ->danger()
                ->send();

            return;
        }

        $company->loadMissing('user');

        if ($company->user instanceof User) {
            $company->user->forceFill([
                'status' => false,
            ])->save();
            $company->user->tokens()->delete();
        }

        $settings = is_array($company->settings) ? $company->settings : [];
        $moderationMeta = is_array($settings['moderation_application'] ?? null)
            ? $settings['moderation_application']
            : [];

        $moderationMeta['status'] = 'rejected';
        $moderationMeta['reviewed_at'] = now()->toIso8601String();
        $moderationMeta['reviewed_by_user_id'] = Filament::auth()->id();
        $moderationMeta['rejection_reason'] = $reason;

        $settings['moderation_application'] = $moderationMeta;

        $company->forceFill([
            'status' => Company::STATUS_INACTIVE,
            'settings' => $settings,
        ])->save();

        app(CompanySubscriptionService::class)->syncAssistantAccess($company->fresh());

        Notification::make()
            ->title('Компания отклонена.')
            ->warning()
            ->send();
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
