<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ActiveIngredientResource\Pages;
use App\Filament\Resources\ActiveIngredientResource\RelationManagers;
use App\Models\ActiveIngredient;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ActiveIngredientResource extends Resource
{
    protected static ?string $model = ActiveIngredient::class;

    public static function getModelLabel(): string
    {
        return __('Active Ingredients');
    }

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

                    protected static ?string $navigationGroup = 'Cadastros';

    protected static ?int $navigationSort = 1;



    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                ->translateLabel()
                ->required()
                ->maxLength(255)
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
            'index' => Pages\ListActiveIngredients::route('/'),
            'create' => Pages\CreateActiveIngredient::route('/create'),
            'edit' => Pages\EditActiveIngredient::route('/{record}/edit'),
        ];
    }
}
