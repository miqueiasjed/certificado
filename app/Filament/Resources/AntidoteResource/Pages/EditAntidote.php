<?php

namespace App\Filament\Resources\AntidoteResource\Pages;

use App\Filament\Resources\AntidoteResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditAntidote extends EditRecord
{
    protected static string $resource = AntidoteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
