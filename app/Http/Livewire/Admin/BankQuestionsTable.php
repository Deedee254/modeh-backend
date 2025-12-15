<?php

namespace App\Http\Livewire\Admin;

use Livewire\Component;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Actions\BulkAction;
use Filament\Actions\Action;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Tables\Contracts\HasTable;
use Filament\Support\Contracts\TranslatableContentDriver;
use App\Models\Question;
use App\Models\Tournament;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class BankQuestionsTable extends Component implements HasTable, HasSchemas, HasActions
{
    use InteractsWithTable;
    use InteractsWithSchemas;
    use InteractsWithActions;

    public $tournamentId;
    public $targetField; // when set, component will emit selected IDs to the frontend instead of attaching
    public $initialFilters = [];
    public $selected = [];
    // The InteractsWithSchemas trait provides schema-caching helpers and the
    // underlying $isCachingSchemas property (protected). Do not redeclare the
    // property here to avoid incompatible re-definition errors.

    public function mount($tournamentId = null, $targetField = null, $initialFilters = [])
    {
        $this->tournamentId = $tournamentId;
        $this->targetField = $targetField;
        $this->initialFilters = $initialFilters;
    }

    protected function getTableQuery(): Builder
    {
        $query = Question::query()->where('is_banked', true);

        // Filter by topic_id from the form (when creating)
        if (!empty($this->initialFilters['topic_id'])) {
            $query->where('topic_id', $this->initialFilters['topic_id']);
        }
        // Filter by topic_id from the tournament (when editing)
        elseif ($this->tournamentId) {
            $t = Tournament::find($this->tournamentId);
            if ($t && $t->topic_id) {
                $query->where('topic_id', $t->topic_id);
            }
        }

        return $query;
    }

    protected function getTableColumns(): array
    {
        return [
            // Use Filament's native bulk actions/selection UI (v4) instead of a manual checkbox column
            Tables\Columns\TextColumn::make('content')
                ->label('Question')
                ->wrap()
                ->limit(140)
                ->sortable(),

            // Full body preview column
            Tables\Columns\TextColumn::make('body')
                ->label('Full body')
                ->wrap()
                ->limit(400)
                ->toggleable(),

            Tables\Columns\TextColumn::make('grade.name')->label('Grade')->sortable()->searchable(),
            Tables\Columns\TextColumn::make('subject.name')->label('Subject')->sortable()->searchable(),
            Tables\Columns\TextColumn::make('topic.name')->label('Topic')->sortable()->searchable(),
            Tables\Columns\TextColumn::make('level.name')->label('Level')->sortable()->searchable(),

            Tables\Columns\TextColumn::make('marks')
                ->label('Marks')
                ->sortable()
                ->searchable(),

            Tables\Columns\TextColumn::make('difficulty')
                ->label('Difficulty')
                ->sortable()
                ->searchable(),

            Tables\Columns\TextColumn::make('created_at')
                ->label('Created')
                ->dateTime('M d, Y')
                ->sortable(),
        ];
    }

    protected function getTableFilters(): array
    {
        return [
            Tables\Filters\SelectFilter::make('is_approved')
                ->label('Approval Status')
                ->options([
                    1 => 'Approved',
                    0 => 'Pending',
                ])
                ->query(function (Builder $query, $value) {
                    $query->where('is_approved', (int) $value);
                }),
        ];
    }

    protected function getTableActions(): array
    {
        return [];
    }

    protected function getTableBulkActions(): array
    {
        return [
            BulkAction::make('attachSelected')
                ->label('Attach selected')
                ->requiresConfirmation()
                ->action(function (\Illuminate\Support\Collection $records) {
                    $ids = $records->pluck('id')->toArray();

                    if (empty($ids)) {
                        $this->dispatch('notify', ['type' => 'warning', 'message' => 'No questions selected']);
                        return;
                    }

                    if ($this->tournamentId) {
                        $t = Tournament::find($this->tournamentId);
                        if (! $t) {
                            $this->dispatch('notify', ['type' => 'danger', 'message' => 'Tournament not found']);
                            return;
                        }

                        try {
                            $t->questions()->syncWithoutDetaching($ids);
                            $this->dispatch('notify', ['type' => 'success', 'message' => sprintf('Added %d question(s) to tournament', count($ids))]);
                            $this->dispatch('modeh:bank-attached', ['tournamentId' => $this->tournamentId, 'ids' => $ids]);
                        } catch (\Exception $e) {
                            $this->dispatch('notify', ['type' => 'danger', 'message' => 'Failed to attach questions: ' . $e->getMessage()]);
                        }

                        return;
                    }

                    if ($this->targetField) {
                        // Ship selected ids back to the frontend so the create form can pick them up
                        $this->dispatch('modeh:bank-selected', ['field' => $this->targetField, 'ids' => $ids]);
                        $this->dispatch('notify', ['type' => 'success', 'message' => sprintf('Selected %d question(s)', count($ids))]);
                    }
                })
                ->deselectRecordsAfterCompletion(),
        ];
    }

    protected function getTableHeaderActions(): array
    {
        return [
            Action::make('attach')
                ->label('Attach selected')
                ->action(fn () => $this->mountTableBulkAction('attachSelected'))
                ->icon('heroicon-o-paper-clip'),
        ];
    }

    

    public function makeFilamentTranslatableContentDriver(): ?TranslatableContentDriver
    {
        return null;
    }

    public function render()
    {
        return view('livewire.admin.bank-questions-table');
    }

    public function attachSelected(): void
    {
        // Prefer manual selection (checkboxes) if used, otherwise fall back to Filament's selection API
        $ids = [];
        if (!empty($this->selected)) {
            $ids = $this->selected;
        } else {
            $records = $this->getSelectedTableRecords();
            $ids = $records->pluck('id')->toArray();
        }

        if (empty($ids)) {
            $this->dispatch('notify', ['type' => 'warning', 'message' => 'No questions selected']);
            return;
        }

        if ($this->tournamentId) {
            $t = Tournament::find($this->tournamentId);
                if (! $t) {
                $this->dispatch('notify', ['type' => 'danger', 'message' => 'Tournament not found']);
                return;
            }

            try {
                $t->questions()->syncWithoutDetaching($ids);
                $this->dispatch('notify', ['type' => 'success', 'message' => sprintf('Added %d question(s) to tournament', count($ids))]);
                // notify parent Filament UI to refresh relation manager
                $this->dispatch('modeh:bank-attached', ['tournamentId' => $this->tournamentId, 'ids' => $ids]);
            } catch (\Exception $e) {
                $this->dispatch('notify', ['type' => 'danger', 'message' => 'Failed to attach questions: ' . $e->getMessage()]);
            }
        } elseif ($this->targetField) {
            // Ship selected ids back to the frontend so the create form can pick them up
            $this->dispatch('modeh:bank-selected', ['field' => $this->targetField, 'ids' => $ids]);
            $this->dispatch('notify', ['type' => 'success', 'message' => sprintf('Selected %d question(s)', count($ids))]);
        }
    }

    public function toggleRowSelection(int $id): void
    {
        if (in_array($id, $this->selected)) {
            $this->selected = array_values(array_filter($this->selected, fn($v) => $v !== $id));
        } else {
            $this->selected[] = $id;
        }
    }
}
