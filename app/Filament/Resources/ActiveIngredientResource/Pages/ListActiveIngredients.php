<?php

namespace App\Filament\Resources\ActiveIngredientResource\Pages;

use App\Filament\Resources\ActiveIngredientResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListActiveIngredients extends ListRecords
{
    protected static string $resource = ActiveIngredientResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
