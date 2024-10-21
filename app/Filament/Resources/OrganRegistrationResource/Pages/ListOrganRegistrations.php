<?php

namespace App\Filament\Resources\OrganRegistrationResource\Pages;

use App\Filament\Resources\OrganRegistrationResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListOrganRegistrations extends ListRecords
{
    protected static string $resource = OrganRegistrationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
