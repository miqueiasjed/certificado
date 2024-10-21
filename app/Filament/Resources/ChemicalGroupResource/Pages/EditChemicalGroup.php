<?php

namespace App\Filament\Resources\ChemicalGroupResource\Pages;

use App\Filament\Resources\ChemicalGroupResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditChemicalGroup extends EditRecord
{
    protected static string $resource = ChemicalGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
