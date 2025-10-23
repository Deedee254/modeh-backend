<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SiteSettingResource\Pages;
use App\Models\SiteSetting;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;

class SiteSettingResource extends Resource
{
    protected static ?string $model = SiteSetting::class;
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-cog';
    protected static ?string $navigationLabel = 'Site Settings';
    protected static ?int $navigationSort = 1;

    public static function form(\Filament\Schemas\Schema $schema): \Filament\Schemas\Schema
    {
        return $schema->schema([
            \Filament\Schemas\Components\Section::make()
                ->schema([
                    \Filament\Forms\Components\Toggle::make('auto_approve_topics')->label('Auto-approve Topics')->default(true),
                    \Filament\Forms\Components\Toggle::make('auto_approve_quizzes')->label('Auto-approve Quizzes')->default(true),
                    \Filament\Forms\Components\Toggle::make('auto_approve_questions')->label('Auto-approve Questions')->default(true),
                ])
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id'),
                TextColumn::make('auto_approve_topics')->label('Topics')->formatStateUsing(fn($s)=> $s? 'On':'Off'),
                TextColumn::make('auto_approve_quizzes')->label('Quizzes')->formatStateUsing(fn($s)=> $s? 'On':'Off'),
                TextColumn::make('auto_approve_questions')->label('Questions')->formatStateUsing(fn($s)=> $s? 'On':'Off'),
            ])
            ->actions([
                \Filament\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\EditSiteSettings::route('/'),
        ];
    }
}
