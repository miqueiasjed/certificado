<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ChemicalGroupResource\Pages;
use App\Filament\Resources\ChemicalGroupResource\RelationManagers;
use App\Models\ChemicalGroup;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ChemicalGroupResource extends Resource
{
    protected static ?string $model = ChemicalGroup::class;

    public static function getModelLabel(): string
    {
        return __('Chemical Groups');
    }

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?int $navigationSort = 3;



    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                ->translateLabel(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
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
            'index' => Pages\ListChemicalGroups::route('/'),
            'create' => Pages\CreateChemicalGroup::route('/create'),
            'edit' => Pages\EditChemicalGroup::route('/{record}/edit'),
        ];
    }
}
