<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ServiceOrderResource\Pages;
use App\Models\ServiceOrder;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ServiceOrderResource extends Resource
{
    protected static ?string $model = ServiceOrder::class;

    public static function getModelLabel(): string
    {
        return __('OS');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Ordens de Serviço');
    }

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'Operacional';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Informações da OS')
                    ->schema([
                        Forms\Components\TextInput::make('order_number')
                            ->label('Número da OS')
                            ->default(fn () => \App\Models\ServiceOrder::generateOrderNumber())
                            ->disabled()
                            ->dehydrated(),

                        Forms\Components\Select::make('service_type')
                            ->label('Tipo de Serviço')
                            ->options([
                                'dedetizacao' => 'Dedetização',
                                'desinsetizacao' => 'Desinsetização',
                                'descupinizacao' => 'Descupinização',
                                'desratizacao' => 'Desratização',
                                'sanitizacao' => 'Sanitização',
                            ])
                            ->required(),

                        Forms\Components\DatePicker::make('order_date')
                            ->label('Data da OS')
                            ->required()
                            ->default(now()),

                        Forms\Components\TimePicker::make('start_time')
                            ->label('Hora de Início')
                            ->required()
                            ->seconds(false),

                        Forms\Components\TimePicker::make('end_time')
                            ->label('Hora de Fim')
                            ->required()
                            ->seconds(false),

                        Forms\Components\Select::make('client_id')
                            ->label('Cliente')
                            ->relationship('client', 'name')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->createOptionForm([
                                Forms\Components\TextInput::make('name')
                                    ->label('Nome')
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('email')
                                    ->label('E-mail')
                                    ->email()
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('phone')
                                    ->label('Telefone')
                                    ->tel()
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('cnpj')
                                    ->label('CPF/CNPJ')
                                    ->required()
                                    ->maxLength(18),
                                Forms\Components\TextInput::make('address')
                                    ->label('Endereço')
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('neighborhood')
                                    ->label('Bairro')
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('city')
                                    ->label('Cidade')
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('number')
                                    ->label('Número')
                                    ->required()
                                    ->maxLength(255),
                            ])
                            ->createOptionAction(
                                fn (Forms\Components\Actions\Action $action) => $action
                                    ->modalHeading('Criar Cliente')
                                    ->modalButton('Criar Cliente')
                                    ->modalSubmitActionLabel('Criar Cliente')
                            ),

                        Forms\Components\Select::make('technician_id')
                            ->label('Técnico Responsável')
                            ->relationship('technician', 'name', function (Builder $query) {
                                return $query->where('is_technician', true);
                            })
                            ->searchable()
                            ->preload()
                            ->placeholder('Selecione um técnico')
                            ->createOptionForm([
                                Forms\Components\TextInput::make('name')
                                    ->label('Nome')
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('email')
                                    ->label('E-mail')
                                    ->email()
                                    ->required()
                                    ->maxLength(255)
                                    ->unique(),
                                Forms\Components\TextInput::make('phone')
                                    ->label('Telefone')
                                    ->tel()
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('cpf')
                                    ->label('CPF')
                                    ->maxLength(14),
                                Forms\Components\Textarea::make('address')
                                    ->label('Endereço')
                                    ->maxLength(500),
                                Forms\Components\Hidden::make('is_technician')
                                    ->default(true),
                                Forms\Components\Hidden::make('password')
                                    ->default('password123')
                                    ->dehydrateStateUsing(fn ($state) => bcrypt($state)),
                            ])
                            ->createOptionAction(
                                fn (Forms\Components\Actions\Action $action) => $action
                                    ->modalHeading('Criar Técnico')
                                    ->modalButton('Criar Técnico')
                                    ->modalSubmitActionLabel('Criar Técnico')
                            ),

                        Forms\Components\Select::make('status')
                            ->label('Status')
                            ->options([
                                'pending' => 'Pendente',
                                'in_progress' => 'Em Andamento',
                                'completed' => 'Concluída',
                                'cancelled' => 'Cancelada',
                            ])
                            ->default('pending')
                            ->required(),
                    ])->columns(2),

                Forms\Components\Section::make('Cômodos do Ambiente')
                    ->schema([
                        Forms\Components\Repeater::make('rooms')
                            ->relationship()
                            ->schema([
                                Forms\Components\TextInput::make('name')
                                    ->label('Nome do Cômodo')
                                    ->placeholder('Ex: Sala, Quarto, Cozinha')
                                    ->maxLength(255),

                                Forms\Components\Textarea::make('description')
                                    ->label('Descrição')
                                    ->placeholder('Descrição adicional do cômodo')
                                    ->maxLength(500),

                                Forms\Components\TextInput::make('quantity')
                                    ->label('Quantidade')
                                    ->numeric()
                                    ->default(1)
                                    ->minValue(1)
                                    ->maxValue(100),
                            ])
                            ->columns(3)
                            ->defaultItems(0)
                            ->reorderable(false)
                            ->collapsible()
                            ->itemLabel(fn (array $state): ?string => $state['name'] ?? 'Novo Cômodo'),
                    ]),

                Forms\Components\Section::make('Dispositivos')
                    ->schema([
                        Forms\Components\Repeater::make('devices')
                            ->relationship()
                            ->schema([
                                Forms\Components\TextInput::make('name')
                                    ->label('Nome do Dispositivo')
                                    ->placeholder('Ex: Ar condicionado, Ventilador')
                                    ->maxLength(255),

                                Forms\Components\TextInput::make('brand')
                                    ->label('Marca')
                                    ->placeholder('Marca do dispositivo')
                                    ->maxLength(100),

                                Forms\Components\TextInput::make('model')
                                    ->label('Modelo')
                                    ->placeholder('Modelo do dispositivo')
                                    ->maxLength(100),

                                Forms\Components\TextInput::make('quantity')
                                    ->label('Quantidade')
                                    ->numeric()
                                    ->default(1)
                                    ->minValue(1)
                                    ->maxValue(100),

                                Forms\Components\Textarea::make('description')
                                    ->label('Descrição')
                                    ->placeholder('Descrição do dispositivo')
                                    ->maxLength(500),
                            ])
                            ->columns(3)
                            ->defaultItems(0)
                            ->reorderable(false)
                            ->collapsible()
                            ->itemLabel(fn (array $state): ?string => $state['name'] ?? 'Novo Dispositivo'),
                    ]),

                Forms\Components\Section::make('Produtos Necessários')
                    ->schema([
                        Forms\Components\Repeater::make('products')
                            ->relationship()
                            ->schema([
                                Forms\Components\Select::make('product_id')
                                    ->label('Produto')
                                    ->relationship('product', 'name')
                                    ->searchable()
                                    ->preload()
                                    ->required(),

                                Forms\Components\TextInput::make('quantity')
                                    ->label('Quantidade')
                                    ->numeric()
                                    ->default(1)
                                    ->minValue(1)
                                    ->required(),

                                Forms\Components\Textarea::make('notes')
                                    ->label('Observações')
                                    ->placeholder('Observações específicas do produto'),
                            ])
                            ->columns(3)
                            ->defaultItems(0)
                            ->reorderable(false)
                            ->collapsible()
                            ->itemLabel(fn (array $state): ?string => $state['product_id'] ? \App\Models\Product::find($state['product_id'])?->name : 'Novo Produto'),
                    ]),

                Forms\Components\Section::make('Observações')
                    ->schema([
                        Forms\Components\Textarea::make('description')
                            ->label('Descrição do Serviço')
                            ->placeholder('Descreva o serviço a ser realizado'),

                        Forms\Components\Textarea::make('observations')
                            ->label('Observações')
                            ->placeholder('Observações adicionais'),

                        Forms\Components\Textarea::make('special_instructions')
                            ->label('Instruções Especiais para o Técnico')
                            ->placeholder('Instruções específicas para o técnico executar o serviço')
                            ->rows(4),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('order_number')
                    ->label('OS #')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('service_type')
                    ->label('Tipo de Serviço')
                    ->formatStateUsing(fn (string $state): string => match($state) {
                        'dedetizacao' => 'Dedetização',
                        'desinsetizacao' => 'Desinsetização',
                        'descupinizacao' => 'Descupinização',
                        'desratizacao' => 'Desratização',
                        'sanitizacao' => 'Sanitização',
                        default => 'Desconhecido',
                    }),

                Tables\Columns\TextColumn::make('order_date')
                    ->label('Data')
                    ->date('d/m/Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('start_time')
                    ->label('Início')
                    ->time('H:i'),

                Tables\Columns\TextColumn::make('end_time')
                    ->label('Fim')
                    ->time('H:i'),

                Tables\Columns\TextColumn::make('client.name')
                    ->label('Cliente')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('technician.name')
                    ->label('Técnico')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->colors([
                        'warning' => 'pending',
                        'info' => 'in_progress',
                        'success' => 'completed',
                        'danger' => 'cancelled',
                    ])
                    ->formatStateUsing(fn (string $state): string => match($state) {
                        'pending' => 'Pendente',
                        'in_progress' => 'Em Andamento',
                        'completed' => 'Concluída',
                        'cancelled' => 'Cancelada',
                        default => 'Desconhecido',
                    }),

                Tables\Columns\TextColumn::make('rooms_count')
                    ->label('Cômodos')
                    ->counts('rooms'),

                Tables\Columns\TextColumn::make('devices_count')
                    ->label('Dispositivos')
                    ->counts('devices'),

                Tables\Columns\TextColumn::make('products_count')
                    ->label('Produtos')
                    ->counts('products'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'pending' => 'Pendente',
                        'in_progress' => 'Em Andamento',
                        'completed' => 'Concluída',
                        'cancelled' => 'Cancelada',
                    ]),

                Tables\Filters\Filter::make('order_date')
                    ->form([
                        Forms\Components\DatePicker::make('order_date_from')
                            ->label('Data de'),
                        Forms\Components\DatePicker::make('order_date_to')
                            ->label('Data até'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['order_date_from'],
                                fn (Builder $query, $date): Builder => $query->whereDate('order_date', '>=', $date),
                            )
                            ->when(
                                $data['order_date_to'],
                                fn (Builder $query, $date): Builder => $query->whereDate('order_date', '<=', $date),
                            );
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('pdf')
                    ->label('Gerar PDF')
                    ->icon('heroicon-o-document-arrow-down')
                    ->url(fn (ServiceOrder $record) => route('service-orders.pdf', $record))
                    ->openUrlInNewTab(),
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
            'index' => Pages\ListServiceOrders::route('/'),
            'create' => Pages\CreateServiceOrder::route('/create'),
            'edit' => Pages\EditServiceOrder::route('/{record}/edit'),
        ];
    }
}
