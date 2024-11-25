<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CertificateResource\Pages;
use App\Models\Certificate;
use Filament\Tables\Actions\Action;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;


class CertificateResource extends Resource
{
    protected static ?string $model = Certificate::class;

    public static function getModelLabel(): string
    {
        return __('Certificate');
    }

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\DatePicker::make('date')
                ->translateLabel()
                ->required(),
                Forms\Components\DatePicker::make('assurance')
                ->translateLabel()
                ->required(),
                Forms\Components\Select::make('client_id')
                ->translateLabel()
                ->relationship('client', 'name')
                ->searchable()
                ->preload(),
                Forms\Components\CheckboxList::make('products')
                ->translateLabel()
                ->relationship('products', 'name'),
                // ->searchable()
                // ->preload(),
                Forms\Components\Select::make('service_id')
                ->translateLabel()
                ->relationship('service', 'description')
                ->searchable()
                ->preload()
                ->createOptionForm([
                    Forms\Components\TextInput::make('description')
                        ->translateLabel()
                        ->required()
                        ->maxLength(255),
                ]),
                Forms\Components\CheckboxList::make('procedures')
                ->translateLabel()
                ->label('Procedures')
                ->relationship('procedures', 'name')
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('client.name')->label('Cliente'),
                TextColumn::make('service.description')->label('Serviço'),
                TextColumn::make('date')->label('Data')->formatStateUsing(fn ($state) => \Carbon\Carbon::parse($state)->format('d/m/Y')),

                TextColumn::make('assurance')->label('Garantia')->formatStateUsing(fn ($state) => \Carbon\Carbon::parse($state)->format('d/m/Y')),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Action::make('Gerar')
                ->label('Emitir Certificado')
                ->icon('heroicon-o-document-minus')
                ->url(fn (Certificate $record) => route('certificates.pdf', $record)) // Definindo a URL da rota
                ->openUrlInNewTab(), // Abre o PDF em uma nova aba
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
            // ProceduresRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCertificates::route('/'),
            'create' => Pages\CreateCertificate::route('/create'),
            'edit' => Pages\EditCertificate::route('/{record}/edit'),
        ];
    }
}
