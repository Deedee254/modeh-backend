<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ChatResource\Pages;
use App\Models\Message;
use App\Models\User;
// use Filament\Forms;
use Filament\Forms;
use Filament\Resources\Resource;
// use Filament\Tables;
use Filament\Tables;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Filament\Tables\Filters\SelectFilter;


use Filament\Support\Enums\IconSize;
use Filament\Support\Enums\IconName;

class ChatResource extends Resource
{
    protected static ?string $model = Message::class;

    // For Filament v4, navigationIcon can be string|BackedEnum|null
    // Use the Heroicons v2+ name for the outline "chat-bubble-left" icon set
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-left';

    public static function getNavigationGroup(): ?string
    {
        return 'Communication';
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
            'index' => Pages\ListMessages::route('/'),
            'create' => Pages\CreateMessage::route('/create'),
            'edit' => Pages\EditMessage::route('/{record}/edit'),
            'view' => Pages\ViewMessage::route('/{record}'),
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