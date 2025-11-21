<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InstitutionResource\Pages;
use App\Models\Institution;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;

class InstitutionResource extends Resource
{
    protected static ?string $model = Institution::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-building-office';

    protected static \UnitEnum|string|null $navigationGroup = 'Institution Management';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Institution Details')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255),

                        Forms\Components\TextInput::make('slug')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),

                        Forms\Components\TextInput::make('email')
                            ->email()
                            ->maxLength(255),

                        Forms\Components\TextInput::make('phone')
                            ->tel()
                            ->maxLength(255),

                        Forms\Components\TextInput::make('website')
                            ->url()
                            ->maxLength(255),

                        Forms\Components\Textarea::make('address')
                            ->maxLength(65535)
                            ->columnSpanFull(),

                        Forms\Components\FileUpload::make('logo_url')
                            ->image()
                            ->directory('institution-logos'),

                        Forms\Components\Toggle::make('is_active')
                            ->default(true),
                    ])->columns(2),

                Section::make('Parent Institution')
                    ->schema([
                        Forms\Components\Select::make('parent_id')
                            ->relationship('parent', 'name')
                            ->searchable()
                            ->preload(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('slug')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('email')
                    ->searchable(),

                Tables\Columns\TextColumn::make('phone'),

                Tables\Columns\IconColumn::make('is_active')
                    ->boolean(),

                Tables\Columns\TextColumn::make('users_count')
                    ->label('Members')
                    ->counts('users'),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active Status')
                    ->boolean()
                    ->trueLabel('Active only')
                    ->falseLabel('Inactive only')
                    ->native(false),
            ])
            ->actions([
                \Filament\Actions\ViewAction::make(),
                \Filament\Actions\EditAction::make(),
                \Filament\Actions\Action::make('analytics')
                    ->label('Analytics')
                    ->icon('heroicon-o-chart-bar')
                    ->url(fn (Institution $record): string => route('filament.admin.resources.institutions.analytics', $record)),
                \Filament\Actions\Action::make('manage_members')
                    ->label('Manage Members')
                    ->icon('heroicon-o-users')
                    ->url(fn (Institution $record): string => route('filament.admin.resources.institutions.members', $record)),
            ])
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make(),
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
            'index' => Pages\ListInstitutions::route('/'),
            'create' => Pages\CreateInstitution::route('/create'),
            'view' => Pages\ViewInstitution::route('/{record}'),
            'edit' => Pages\EditInstitution::route('/{record}/edit'),
            'analytics' => Pages\InstitutionAnalytics::route('/{record}/analytics'),
            'members' => Pages\ManageInstitutionMembers::route('/{record}/members'),
            'member-analytics' => Pages\MemberAnalytics::route('/{institution}/member/{user}/analytics'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withCount('users');
    }
}