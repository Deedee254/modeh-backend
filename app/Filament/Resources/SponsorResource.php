<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SponsorResource\Pages;
use App\Models\Sponsor;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Table;

class SponsorResource extends Resource
{
    protected static ?string $model = Sponsor::class;

    // Use Heroicons v2 naming: 'building-office' (outline variant prefixed with 'o-')
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-building-office';
    protected static \UnitEnum|string|null $navigationGroup = 'Tournaments';
    protected static ?int $navigationSort = 3;
    public static function form(\Filament\Schemas\Schema $schema): \Filament\Schemas\Schema
    {
        return $schema->schema([
            \Filament\Schemas\Components\Section::make()
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\Textarea::make('description')
                        ->maxLength(65535),

                    // UI stores and shows logo_url
                    Forms\Components\FileUpload::make('logo_url')
                        ->image()
                        ->directory('sponsors')
                        ->required(),

                    Forms\Components\TextInput::make('website_url')
                        ->url()
                        ->maxLength(255),

                    Forms\Components\Toggle::make('is_active')
                        ->required()
                        ->default(true),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                \Filament\Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                \Filament\Tables\Columns\ImageColumn::make('logo_url')
                    ->circular(),

                \Filament\Tables\Columns\TextColumn::make('website_url')
                    ->url(fn ($record) => $record->website_url)
                    ->openUrlInNewTab(),

                \Filament\Tables\Columns\IconColumn::make('is_active')
                    ->boolean()
                    ->sortable(),

                \Filament\Tables\Columns\TextColumn::make('tournaments_count')
                    ->counts('tournaments')
                    ->sortable(),

                \Filament\Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                \Filament\Tables\Filters\TernaryFilter::make('is_active'),
            ])
            ->actions([
                \Filament\Actions\EditAction::make(),
                \Filament\Actions\DeleteAction::make()
                    ->before(function (Sponsor $record) {
                        if ($record->tournaments()->count() > 0) {
                            Notification::make()
                                ->warning()
                                ->title('Cannot delete sponsor')
                                ->body('This sponsor has tournaments associated with it.')
                                ->persistent()
                                ->send();

                            return false;
                        }
                    }),
            ])
            ->bulkActions([
                \Filament\Actions\DeleteBulkAction::make(),
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
            'index' => Pages\ListSponsors::route('/'),
            'create' => Pages\CreateSponsor::route('/create'),
            'edit' => Pages\EditSponsor::route('/{record}/edit'),
        ];
    }
}