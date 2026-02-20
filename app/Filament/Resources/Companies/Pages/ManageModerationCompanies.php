<?php

namespace App\Filament\Resources\Companies\Pages;

use App\Filament\Resources\Companies\ModerationCompanyResource;
use Filament\Resources\Pages\ManageRecords;

class ManageModerationCompanies extends ManageRecords
{
    protected static string $resource = ModerationCompanyResource::class;

    protected static ?string $title = 'Компании на модерации';

    protected function getHeaderActions(): array
    {
        return [];
    }
}
