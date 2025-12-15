<?php

namespace App\Imports;

use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use App\Models\Question;
use App\Models\Grade;
use App\Models\Subject;
use App\Models\Topic;
use App\Models\Level;
use Illuminate\Support\Str;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Schemas\Components\Utilities\Get;

class QuestionsImporter extends Importer
{
    protected static ?string $model = Question::class;

    public static function getColumns(): array
    {
        // Clean, simplified columns for tournament question import
        return [
            ImportColumn::make('type')->label('Type')->guess(['type'])->example('mcq')->exampleHeader('type'),
            ImportColumn::make('body')->label('Text')->requiredMapping()->exampleHeader('text'),

            // Four options
            ImportColumn::make('option1')->label('Option 1')->guess(['option1','opt1'])->exampleHeader('option1'),
            ImportColumn::make('option2')->label('Option 2')->guess(['option2','opt2'])->exampleHeader('option2'),
            ImportColumn::make('option3')->label('Option 3')->guess(['option3','opt3'])->exampleHeader('option3'),
            ImportColumn::make('option4')->label('Option 4')->guess(['option4','opt4'])->exampleHeader('option4'),

            ImportColumn::make('answers')->label('Answers')->guess(['answers'])->exampleHeader('answers'),
            ImportColumn::make('marks')->label('Marks')->guess(['marks'])->exampleHeader('marks'),
            ImportColumn::make('difficulty')->label('Difficulty')->guess(['difficulty'])->exampleHeader('difficulty'),
        ];
    }

    public static function getOptionsFormComponents(): array
    {
        // Minimal options form - just auto-flag for tournament imports
        return [
            Hidden::make('auto_banked')
                ->default(true),

            Hidden::make('auto_approved')
                ->default(true),
        ];
    }

    public function resolveRecord(): ?\Illuminate\Database\Eloquent\Model
    {
        // If CSV provides an ID we will try to find it; otherwise create a new Question model to import into.
        $keyName = app(static::getModel())->getKeyName();
        $keyColumnName = $this->columnMap[$keyName] ?? $keyName;

        if (! empty($this->data[$keyColumnName] ?? null)) {
            return static::getModel()::find($this->data[$keyColumnName]);
        }

        return new Question();
    }

    public function fillRecord(): void
    {
        $record = $this->record ?? new Question();
        $data = $this->data;
        $options = $this->options ?? [];

        // Basic fields
        $record->type = Str::lower(trim($data['type'] ?? 'mcq'));
        $record->body = trim($data['body'] ?? $data['text'] ?? '') ?: '';
        $record->marks = is_numeric($data['marks'] ?? null) ? floatval($data['marks']) : 1;
        $record->difficulty = is_numeric($data['difficulty'] ?? null) ? intval($data['difficulty']) : 2;

        // Auto-flags from tournament context
        $record->is_banked = filter_var(
            $options['auto_banked'] ?? true,
            FILTER_VALIDATE_BOOLEAN
        );

        $record->is_approved = filter_var(
            $options['auto_approved'] ?? true,
            FILTER_VALIDATE_BOOLEAN
        );

        // Collect 4 options from option1..option4
        $opts = [];
        for ($i = 1; $i <= 4; $i++) {
            $k = "option{$i}";
            if (isset($data[$k]) && (string)($data[$k]) !== '') {
                $opts[] = trim($data[$k]);
            }
        }
        $record->options = ! empty($opts) ? array_values($opts) : null;

        // Parse answers (1-based position or text)
        $answersRaw = $data['answers'] ?? null;
        $answers = [];
        if (is_string($answersRaw) && $answersRaw !== '') {
            $parts = preg_split('/[,|]+/', $answersRaw);
            $answers = array_map(fn($v) => trim($v), array_filter($parts, fn($v) => $v !== ''));
        }

        // Handle MCQ answers
        if ($record->type === 'mcq' && ! empty($answers)) {
            $first = $answers[0];
            $correctIndex = null;
            if (is_numeric($first)) {
                // 1-based position -> 0-based
                $position = intval($first);
                $optionCount = count($record->options ?? []);
                if ($position >= 1 && $position <= $optionCount) {
                    $correctIndex = $position - 1;
                }
            } else {
                // Match by text
                $firstTrimmed = trim((string)$first);
                foreach (($record->options ?? []) as $ii => $opt) {
                    if (trim((string)$opt) === $firstTrimmed) {
                        $correctIndex = $ii;
                        break;
                    }
                }
            }
            
            // Store as array in answers field (as strings to match frontend format)
            if ($correctIndex !== null) {
                $record->answers = [(string)$correctIndex];
            }
        }

        // Automatically assign tournament taxonomy to imported questions
        if (!empty($options['level_id'])) {
            $record->level_id = intval($options['level_id']);
        }
        if (!empty($options['grade_id'])) {
            $record->grade_id = intval($options['grade_id']);
        }
        if (!empty($options['subject_id'])) {
            $record->subject_id = intval($options['subject_id']);
        }
        if (!empty($options['topic_id'])) {
            $record->topic_id = intval($options['topic_id']);
        }

        $this->record = $record;
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        return __(':count rows imported', ['count' => $import->total_rows]);
    }

    /**
     * Override save to skip creating import records
     * We only save Question models to the database
     */
    public function save(): static
    {
        // Save the question record only, don't create an import record
        if ($this->record) {
            $this->record->save();
        }
        return $this;
    }
}
