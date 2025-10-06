<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Actions\Action;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use BackedEnum;
use UnitEnum;

/**
 * Declare dynamic Livewire/Filament methods for static analysis tools.
 *
 * Filament pages are Livewire components at runtime and provide
 * dispatchBrowserEvent() via Livewire, but some static analyzers
 * (PHPStan/IDE) can't see that. Declaring it here silences those
 * warnings while keeping runtime behavior unchanged.
 *
 * @method void dispatchBrowserEvent(string $event, array $payload = [])
 */
class EchoMonitoring extends Page
{
    // Use Filament 4 typed properties to remain compatible with the project's Filament version
    protected static BackedEnum|string|null $navigationIcon = Heroicon::RectangleStack;
    protected static UnitEnum|string|null $navigationGroup = 'Monitoring';
    // Filament\Pages\Page declares $view as a typed string property; match that here.
    protected string $view = 'filament.pages.echo-monitoring';
    protected static ?int $navigationSort = 10;
    protected static ?string $navigationLabel = 'Echo Monitoring';

    protected function getActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Refresh')
                ->icon('heroicon-o-arrow-path')
                ->action('doRefresh')
                ->extraAttributes(['id' => 'echo-refresh'])
                ->color('primary'),

            Action::make('toggleAuto')
                ->label('Auto Refresh')
                ->icon('heroicon-o-play-pause')
                ->action('toggleAutoRefresh')
                ->extraAttributes(['id' => 'echo-toggle'])
                ->color('gray'),

            Action::make('prune')
                ->label('Prune')
                ->icon('heroicon-o-trash')
                ->action('doPrune')
                ->requiresConfirmation()
                ->color('danger')
                ->modalHeading('Prune Echo metrics')
                ->modalSubheading('This will remove old metric buckets according to configured retention.')
                ->modalWidth('lg')
                ->modalContent(fn ($livewire) => view('filament.pages.echo-prune-result', ['result' => $livewire->getActionData()])->render())
                ->extraAttributes(['id' => 'echo-prune']),
        ];
    }
    public function doRefresh()
    {
        try {
            $health = Http::get(config('app.url') . '/api/admin/echo/health')->json();
            $stats = Http::get(config('app.url') . '/api/admin/echo/stats')->json();
            $this->dispatchBrowserEvent('echo:refreshed', ['ok' => true]);
            $this->notify('success', 'Echo stats refreshed');
            return ['health' => $health, 'stats' => $stats];
        } catch (\Exception $e) {
            $this->dispatchBrowserEvent('echo:refreshed', ['ok' => false, 'error' => $e->getMessage()]);
            $this->notify('danger', 'Failed to refresh Echo stats: ' . $e->getMessage());
            return [];
        }
    }

    public function doPrune()
    {
        try {
            $days = 30;
            try {
                $row = \App\Models\ChatMetricsSetting::first();
                if ($row && $row->retention_days) $days = (int) $row->retention_days;
            } catch (\Throwable $e) { }

            $exitCode = Artisan::call('metrics:prune-buckets', ['--days' => $days]);
            $output = Str::limit(Artisan::output(), 2000);
            $this->dispatchBrowserEvent('echo:pruned', ['exit' => $exitCode]);
            $this->notify('success', "Prune finished (exit {$exitCode})");
            return ['exit' => $exitCode, 'output' => $output];
        } catch (\Exception $e) {
            $this->dispatchBrowserEvent('echo:pruned', ['error' => $e->getMessage()]);
            $this->notify('danger', 'Prune failed: ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    public function toggleAutoRefresh(): void
    {
        // This action only toggles from the UI; the Blade script listens to this event
        $this->dispatchBrowserEvent('echo:auto-toggle');
    }

}
