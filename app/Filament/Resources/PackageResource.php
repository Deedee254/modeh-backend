<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PackageResource\Pages;
use App\Models\Package;
use Filament\Tables\Table;
use Filament\Resources\Resource;
use Filament\Actions\ViewAction;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Toggle;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use UnitEnum;

class PackageResource extends Resource
{
    protected static ?string $model = Package::class;
    protected static \UnitEnum|string|null $navigationGroup = 'Payments & Subscriptions';
    protected static ?int $navigationSort = 1;
    public static function form(\Filament\Schemas\Schema $schema): \Filament\Schemas\Schema
    {
        return $schema->schema([
            TextInput::make('title')->required(),
            Textarea::make('description'),
            TextInput::make('price')->numeric()->required(),
            TextInput::make('currency')->default('KES'),
            TextInput::make('duration_days')->numeric()->default(30),
            Select::make('audience')
                ->label('Audience')
                ->options([
                    'quizee' => 'Personal / Quizee',
                    'institution' => 'Institution',
                ])
                ->default('quizee')
                ->required(),
            TextInput::make('seats')
                ->label('Seats (for institutions)')
                ->numeric()
                ->minValue(1)
                ->visible(fn ($get) => $get('audience') === 'institution')
                ->rules(['nullable','integer','min:1'])
                ->helperText('Number of seats available to the institution. Visible only when Audience = Institution.'),
            Select::make('preset_limits')
                ->label('Quick add limits')
                ->options([
                    '' => '— Select a preset —',
                    'quiz_battle' => 'Add Quiz & Battle limits (keys: quiz_results, battle_results)',
                    'starter' => 'Starter preset (quiz_results=5, battle_results=2)',
                    'pro' => 'Pro preset (unlimited)',
                ])
                ->reactive()
                ->afterStateUpdated(function ($state, $set) {
                    if (! $state) return;

                    if ($state === 'quiz_battle') {
                        $set('features', [
                            ['feature' => 'Quiz results', 'key' => 'quiz_results', 'limit' => null],
                            ['feature' => 'Battle results', 'key' => 'battle_results', 'limit' => null],
                        ]);
                    }

                    if ($state === 'starter') {
                        $set('features', [
                            ['feature' => 'Quiz results', 'key' => 'quiz_results', 'limit' => 5],
                            ['feature' => 'Battle results', 'key' => 'battle_results', 'limit' => 2],
                        ]);
                    }

                    if ($state === 'pro') {
                        $set('features', [
                            ['feature' => 'Quiz results', 'key' => 'quiz_results', 'limit' => null],
                            ['feature' => 'Battle results', 'key' => 'battle_results', 'limit' => null],
                        ]);
                    }

                    // reset the preset selector so admins can pick another without clearing form manually
                    $set('preset_limits', null);
                }),
            Toggle::make('is_default')
                ->label('Set as Default Package')
                ->helperText('This package will be the default option for new users'),
            FileUpload::make('cover_image')->image()->directory('packages')->preserveFilenames(),
            Placeholder::make('features_help')
                ->label('Feature keys')
                ->content('Use key: <strong>quiz_results</strong>, <strong>battle_results</strong>, <strong>seats</strong> to map features to frontend and backend limits. If left empty a slug will be generated, but we recommend using the canonical keys for compatibility.')
                ->columnSpan('full'),

            Repeater::make('features')
                ->schema([
                    TextInput::make('feature')
                        ->label('Feature name')
                        ->placeholder('e.g. Questions per month, Storage (MB), Support seats')
                        ->required(),
                    TextInput::make('key')
                        ->label('Key (internal)')
                        ->helperText('Optional: specify internal feature key (e.g. seats, quiz_results). If left empty a slug will be generated.'),
                    TextInput::make('limit')
                        ->label('Limit')
                        ->numeric()
                        ->nullable()
                        ->minValue(0)
                        ->rules(['nullable','integer','min:0'])
                        ->helperText('Leave empty for unlimited. Set a non-negative integer to cap this feature for the package.'),
                ])
                ->columns(3)
                ->createItemButtonLabel('Add feature')
                ->columnSpan('full'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('title'),
            TextColumn::make('price')->money(fn ($record) => $record?->currency ?? 'KES'),
            TextColumn::make('duration_days')->label('Days'),
            IconColumn::make('is_default')
                ->label('Default')
                ->boolean(),
            TextColumn::make('created_at')->date(),
        ])
        ->actions([
            ViewAction::make(),
            EditAction::make(),
            DeleteAction::make(),
        ])
        ->bulkActions([
            DeleteBulkAction::make(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPackages::route('/'),
            'create' => Pages\CreatePackage::route('/create'),
            'edit' => Pages\EditPackage::route('/{record}/edit'),
            'view' => Pages\ViewPackage::route('/{record}'),
        ];
    }
}
