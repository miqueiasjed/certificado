<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AntidoteResource\Pages;
use App\Filament\Resources\AntidoteResource\RelationManagers;
use App\Models\Antidote;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class AntidoteResource extends Resource
{
    protected static ?string $model = Antidote::class;

    public static function getModelLabel(): string
    {
        return __('Antidotes');
    }

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

                        protected static ?string $navigationGroup = 'Cadastros';

    protected static ?int $navigationSort = 2;



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
            'index' => Pages\ListAntidotes::route('/'),
            'create' => Pages\CreateAntidote::route('/create'),
            'edit' => Pages\EditAntidote::route('/{record}/edit'),
        ];
    }
}
