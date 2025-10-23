<?php

namespace App\Filament\Resources\TournamentResource\Pages;

use App\Filament\Resources\TournamentResource;
use Filament\Actions\EditAction;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Tables;
use Illuminate\Database\Eloquent\Builder;

class ViewTournament extends ViewRecord
{
    protected static string $resource = TournamentResource::class;

    protected function getActions(): array
    {
        return [
            EditAction::make()
                ->visible(fn () => $this->record->status === 'upcoming'),
            Action::make('generate_matches')
                ->action(fn () => $this->record->generateMatches())
                ->requiresConfirmation()
                ->visible(fn () => $this->record->status === 'upcoming')
                ->color('success')
                ->icon('heroicon-o-play'),
            Action::make('view_leaderboard')
                ->url(fn () => route('admin.tournaments.leaderboard', $this->record))
                ->visible(fn () => in_array($this->record->status, ['active', 'completed']))
                ->color('secondary')
                ->icon('heroicon-o-chart-bar'),
        ];
    }

    public function getTabs(): array
    {
        return [
            'details' => \Filament\Schemas\Components\Tabs\Tab::make('Details')
                ->schema([
                    // Tournament details form schema here...
                    Forms\Components\TextInput::make('name')
                        ->disabled(),
                    Forms\Components\RichEditor::make('description')
                        ->disabled(),
                    Forms\Components\Grid::make(2)
                        ->schema([
                            Forms\Components\DateTimePicker::make('start_date')
                                ->disabled(),
                            Forms\Components\DateTimePicker::make('end_date')
                                ->disabled(),
                            Forms\Components\TextInput::make('prize_pool')
                                ->disabled()
                                ->prefix('$'),
                            Forms\Components\TextInput::make('entry_fee')
                                ->disabled()
                                ->prefix('$'),
                        ]),
                ]),

            'participants' => \Filament\Schemas\Components\Tabs\Tab::make('Participants')
                ->schema([
                    \Filament\Schemas\Components\EmbeddedTable::make('embedded-tables.participants-table', fn () => ['tournamentId' => $this->record->id]),
                ]),

            'battles' => \Filament\Schemas\Components\Tabs\Tab::make('Battles')
                ->schema([
                    \Filament\Schemas\Components\EmbeddedTable::make('embedded-tables.battles-table', fn () => ['tournamentId' => $this->record->id]),
                ]),

            'questions' => \Filament\Schemas\Components\Tabs\Tab::make('Questions')
                ->schema([
                    \Filament\Schemas\Components\EmbeddedTable::make('embedded-tables.questions-table', fn () => ['tournamentId' => $this->record->id]),
                ]),

            'sponsor' => Tables\Actions\Tab::make('Sponsor')
                ->schema([
                    \Filament\Schemas\Components\Section::make()
                        ->schema([
                            Forms\Components\TextInput::make('sponsor.name')
                                ->disabled(),
                            Forms\Components\TextInput::make('sponsor_banner_url')
                                ->disabled(),
                            Forms\Components\Textarea::make('sponsor_message')
                                ->disabled(),
                            Forms\Components\TextInput::make('sponsor.website_url')
                                ->disabled(),
                        ])
                        ->visible(fn () => $this->record->sponsor_id !== null)
                ])
                ->visible(fn () => $this->record->sponsor_id !== null),
        ];
    }
}