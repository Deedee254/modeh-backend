<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ChatResource\Pages;
use App\Models\Message;
// use Filament\Forms;
use Filament\Resources\Resource;
// use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;

// DateFilter not available in this Filament version; omit date filter or use a custom filter if needed

class ChatResource extends Resource
{
    protected static ?string $model = Message::class;

    // For Filament v4, navigationIcon can be string|BackedEnum|null
    // Use the Heroicons v2+ name for the outline "chat-bubble-left" icon set
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-chat-bubble-left';

    protected static \UnitEnum|string|null $navigationGroup = 'Community';

    protected static ?int $navigationSort = 1;

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMessages::route('/'),
            // hide create/edit routes for messages in admin UI to prevent manual creation/editing
            'edit' => Pages\EditMessage::route('/{record}/edit'),
            'view' => Pages\SenderMessages::route('/sender/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        // Filament 4: Use policies for access control, keep query simple
        return $query->latest();
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['content', 'sender.name', 'recipient.name'];
    }
}
