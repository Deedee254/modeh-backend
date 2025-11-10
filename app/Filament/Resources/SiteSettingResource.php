<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SiteSettingResource\Pages;
use App\Models\SiteSetting;
use Filament\Forms;
use Filament\Schemas\Components\Section;
// Use Forms for component access via Forms\Components\...
// Toggle will be referenced as Forms\Components\Toggle below.
use Filament\Resources\Resource;

class SiteSettingResource extends Resource
{
    protected static ?string $model = SiteSetting::class;
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-cog';
    protected static ?string $navigationLabel = 'Site Settings';
    protected static ?int $navigationSort = 1;

    public static function form(\Filament\Schemas\Schema $schema): \Filament\Schemas\Schema
    {
        return $schema->schema([
            Section::make()
                ->schema([
                    Forms\Components\Toggle::make('auto_approve_topics')->label('Auto-approve Topics')->default(true),
                    Forms\Components\Toggle::make('auto_approve_quizzes')->label('Auto-approve Quizzes')->default(true),
                    Forms\Components\Toggle::make('auto_approve_questions')->label('Auto-approve Questions')->default(true),
                ])
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\EditSiteSettings::route('/'),
        ];
    }
}
