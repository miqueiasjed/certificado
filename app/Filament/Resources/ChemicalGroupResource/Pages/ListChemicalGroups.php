<?php

namespace App\Filament\Resources\ChemicalGroupResource\Pages;

use App\Filament\Resources\ChemicalGroupResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListChemicalGroups extends ListRecords
{
    protected static string $resource = ChemicalGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
