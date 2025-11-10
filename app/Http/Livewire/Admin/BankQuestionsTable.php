<?php

namespace App\Http\Livewire\Admin;

use Livewire\Component;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
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
    // The InteractsWithSchemas trait provides schema-caching helpers and the
    // underlying $isCachingSchemas property (protected). Do not redeclare the
    // property here to avoid incompatible re-definition errors.

    public function mount($tournamentId = null, $targetField = null, $initialFilters = [])
    {
        $this->tournamentId = $tournamentId;
        $this->targetField = $targetField;
        $this->initialFilters = $initialFilters;

        // Apply initial filters to the table's filter data
        $this->tableFilters['grade_id']['value'] = $initialFilters['grade_id'] ?? null;
        $this->tableFilters['level_id']['value'] = $initialFilters['level_id'] ?? null;
        $this->tableFilters['subject_id']['value'] = $initialFilters['subject_id'] ?? null;
        $this->tableFilters['topic']['value'] = $initialFilters['topic_id'] ?? null;
    }

    protected function getTableQuery(): Builder
    {
        $query = Question::query()->where('is_banked', true);

        if ($this->tournamentId) {
            $t = Tournament::find($this->tournamentId);
            if ($t) {
                if ($t->grade_id) $query->where('grade_id', $t->grade_id);
                if ($t->subject_id) $query->where('subject_id', $t->subject_id);
                if ($t->level_id) $query->where('level_id', $t->level_id);
            }
        }

        return $query->orderBy('id', 'desc');
    }

    protected function getTableColumns(): array
    {
        return [
            Tables\Columns\TextColumn::make('content')
                ->label('Question')
                ->wrap()
                ->limit(140),

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
        ];
    }

    protected function getTableFilters(): array
    {
        return [
            Tables\Filters\SelectFilter::make('level_id')
                ->relationship('level', 'name')
                ->label('Level'),

            Tables\Filters\SelectFilter::make('grade_id')
                ->relationship('grade', 'name')
                ->label('Grade'),

            Tables\Filters\SelectFilter::make('topic')
                ->relationship('topic', 'name')
                ->label('Topic'),

            Tables\Filters\SelectFilter::make('is_approved')
                ->label('Approved')
                ->options([
                    1 => 'Yes',
                    0 => 'No',
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
        $records = $this->getSelectedTableRecords();
        $ids = $records->pluck('id')->toArray();

        if (empty($ids)) {
            $this->dispatchBrowserEvent('notify', ['type' => 'warning', 'message' => 'No questions selected']);
            return;
        }

        if ($this->tournamentId) {
            $t = Tournament::find($this->tournamentId);
            if (! $t) {
                $this->dispatchBrowserEvent('notify', ['type' => 'danger', 'message' => 'Tournament not found']);
                return;
            }

            try {
                $t->questions()->syncWithoutDetaching($ids);
                $this->dispatchBrowserEvent('notify', ['type' => 'success', 'message' => sprintf('Added %d question(s) to tournament', count($ids))]);
                // notify parent Filament UI to refresh relation manager
                $this->dispatchBrowserEvent('modeh:bank-attached', ['tournamentId' => $this->tournamentId, 'ids' => $ids]);
            } catch (\Exception $e) {
                $this->dispatchBrowserEvent('notify', ['type' => 'danger', 'message' => 'Failed to attach questions: ' . $e->getMessage()]);
            }
        } elseif ($this->targetField) {
            // Ship selected ids back to the frontend so the create form can pick them up
            $this->dispatchBrowserEvent('modeh:bank-selected', ['field' => $this->targetField, 'ids' => $ids]);
            $this->dispatchBrowserEvent('notify', ['type' => 'success', 'message' => sprintf('Selected %d question(s)', count($ids))]);
        }
    }
}
