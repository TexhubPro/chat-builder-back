<?php

namespace App\Filament\Resources\Companies\Pages;

use App\Filament\Resources\Companies\ActiveCompanyResource;
use Filament\Resources\Pages\ManageRecords;

class ManageActiveCompanies extends ManageRecords
{
    protected static string $resource = ActiveCompanyResource::class;

    protected static ?string $title = 'Активные компании';

    protected function getHeaderActions(): array
    {
        return [];
    }
}
