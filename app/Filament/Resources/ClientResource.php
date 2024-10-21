<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ClientResource\Pages;
use App\Filament\Resources\ClientResource\RelationManagers;
use App\Models\Client;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Leandrocfe\FilamentPtbrFormFields\Document;

class ClientResource extends Resource
{
    protected static ?string $model = Client::class;

    public static function getModelLabel(): string
    {
        return __('Clients');
    }

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
        ->schema([
            Forms\Components\TextInput::make('name')
                ->translateLabel()
                ->required()
                ->maxLength(255),
            Forms\Components\DatePicker::make('date_of_birth')
                ->translateLabel()
                ->required()
                ->maxDate(now()),
            Forms\Components\TextInput::make('email')
                ->translateLabel()
                ->email()
                ->required()
                ->maxLength(255),
            Forms\Components\TextInput::make('phone')
                ->translateLabel()
                ->tel()
                ->required(),
            Document::make('cnpj')
                ->label('CPF/CNPJ')
                ->required()
                ->dynamic(),
            Forms\Components\TextInput::make('address')
                ->translateLabel()
                ->required()
                ->maxLength(255),
            Forms\Components\TextInput::make('neighborhood')
                ->translateLabel()
                ->required()
                ->maxLength(255),
            Forms\Components\TextInput::make('city')
                ->translateLabel()
                ->required()
                ->maxLength(255),
            Forms\Components\TextInput::make('number')
                ->translateLabel()
                ->numeric()
                ->required()
                ->maxLength(255),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                ->translateLabel(),
                Tables\Columns\TextColumn::make('date_of_birth')
                ->translateLabel(),
                Tables\Columns\TextColumn::make('email')
                ->translateLabel(),
                Tables\Columns\TextColumn::make('phone')
                ->translateLabel(),
                Tables\Columns\TextColumn::make('address')
                ->translateLabel(),
                Tables\Columns\TextColumn::make('neighborhood')
                ->translateLabel(),
                Tables\Columns\TextColumn::make('city')
                ->translateLabel(),
                Tables\Columns\TextColumn::make('number')
                ->translateLabel(),
                // Tables\Columns\TextColumn::make('owner.name'),
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
            'index' => Pages\ListClients::route('/'),
            'create' => Pages\CreateClient::route('/create'),
            'edit' => Pages\EditClient::route('/{record}/edit'),
        ];
    }
}
