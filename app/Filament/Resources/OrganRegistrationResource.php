<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OrganRegistrationResource\Pages;
use App\Filament\Resources\OrganRegistrationResource\RelationManagers;
use App\Models\OrganRegistration;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class OrganRegistrationResource extends Resource
{
    protected static ?string $model = OrganRegistration::class;

    public static function getModelLabel(): string
    {
        return __('OrganRegistration');
    }

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('record')
                ->translateLabel(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('record')
                ->translateLabel()
                ->searchable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrganRegistrations::route('/'),
            'create' => Pages\CreateOrganRegistration::route('/create'),
            'edit' => Pages\EditOrganRegistration::route('/{record}/edit'),
        ];
    }
}
