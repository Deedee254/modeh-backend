<?php
namespace App\Filament\Pages;
use App\Filament\Resources\Enums\NavigationGroup;
use Filament\Pages\Page;

class AdminChat extends Page
{
    // Filament uses heroicon strings; prefer the v2+ outline chat-bubble-left icon
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-left';
    protected static ?string $navigationLabel = 'Admin Chat';
    protected static ?int $navigationSort = 2;
    protected string $view = 'filament.pages.admin-chat';
    protected static ?string $slug = 'admin-chat';
    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Settings;
}
