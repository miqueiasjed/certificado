<?php

namespace App\Filament\Resources\OrganRegistrationResource\Pages;

use App\Filament\Resources\OrganRegistrationResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditOrganRegistration extends EditRecord
{
    protected static string $resource = OrganRegistrationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
