<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PackageResource\Pages;
use App\Models\Package;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Filament\Resources\Resource;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\FileUpload;
use Filament\Tables\Columns\TextColumn;

class PackageResource extends Resource
{
    protected static ?string $model = Package::class;

    public static function schema(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->required(),
            Textarea::make('description'),
            TextInput::make('price')->numeric()->required(),
            TextInput::make('currency')->default('KES'),
            TextInput::make('duration_days')->numeric()->default(30),
            FileUpload::make('cover_image')->image()->directory('packages')->preserveFilenames(),
            Repeater::make('features')->schema([TextInput::make('feature')])->columnSpan('full'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('title'),
            TextColumn::make('price')->money('currency'),
            TextColumn::make('duration_days')->label('Days'),
            TextColumn::make('created_at')->date(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPackages::route('/'),
            'create' => Pages\CreatePackage::route('/create'),
            'edit' => Pages\EditPackage::route('/{record}/edit'),
        ];
    }
}
