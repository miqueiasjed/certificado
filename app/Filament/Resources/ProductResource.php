<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProductResource\Pages;
use App\Filament\Resources\ProductResource\RelationManagers;
use App\Models\Product;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    public static function getModelLabel(): string
    {
        return __('Products');
    }

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?int $navigationSort = 7;



    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label('Nome')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Select::make('active_ingredient_id')
                    ->label('Princípio Ativo')
                    ->relationship('activeIngredient', 'name')
                    ->searchable()
                    ->preload()
                    ->createOptionForm([
                        Forms\Components\TextInput::make('name')
                            ->label('Nome')
                            ->required()
                            ->maxLength(255),
                    ])
                    ->createOptionAction(
                        fn (Forms\Components\Actions\Action $action) => $action
                            ->modalHeading('Criar Princípio Ativo')
                            ->modalButton('Criar Princípio Ativo')
                            ->modalSubmitActionLabel('Criar Princípio Ativo')
                    )
                    ->required(),
                Forms\Components\Select::make('chemical_group_id')
                    ->label('Grupo Químico')
                    ->relationship('chemicalGroup', 'name')
                    ->searchable()
                    ->preload()
                    ->createOptionForm([
                        Forms\Components\TextInput::make('name')
                            ->label('Nome')
                            ->required()
                            ->maxLength(255),
                    ])
                    ->createOptionAction(
                        fn (Forms\Components\Actions\Action $action) => $action
                            ->modalHeading('Criar Grupo Químico')
                            ->modalButton('Criar Grupo Químico')
                            ->modalSubmitActionLabel('Criar Grupo Químico')
                    )
                    ->required(),
                Forms\Components\Select::make('antidote_id')
                    ->label('Antídoto')
                    ->relationship('antidote', 'name')
                    ->searchable()
                    ->preload()
                    ->createOptionForm([
                        Forms\Components\TextInput::make('name')
                            ->label('Nome')
                            ->required()
                            ->maxLength(255),
                    ])
                    ->createOptionAction(
                        fn (Forms\Components\Actions\Action $action) => $action
                            ->modalHeading('Criar Antídoto')
                            ->modalButton('Criar Antídoto')
                            ->modalSubmitActionLabel('Criar Antídoto')
                    )
                    ->required(),
                Forms\Components\Select::make('organ_registration_id')
                    ->label('Reg. Min da Saude')
                    ->relationship('organRegistration', 'record')
                    ->searchable()
                    ->preload()
                    ->createOptionForm([
                        Forms\Components\TextInput::make('record')
                            ->label('Registro')
                            ->required()
                            ->maxLength(255),
                    ])
                    ->createOptionAction(
                        fn (Forms\Components\Actions\Action $action) => $action
                            ->modalHeading('Criar Reg. Min da Saude')
                            ->modalButton('Criar Reg. Min da Saude')
                            ->modalSubmitActionLabel('Criar Reg. Min da Saude')
                    ),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                ->translateLabel()
                ->translateLabel()
                ->searchable()
                ->sortable(),
                // Tables\Columns\TextColumn::make('organ_registrations.name')
                // ->searchable(),
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
            'index' => Pages\ListProducts::route('/'),
            'create' => Pages\CreateProduct::route('/create'),
            'edit' => Pages\EditProduct::route('/{record}/edit'),
        ];
    }
}
