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

class QuestionsImporter extends Importer
{
    protected static ?string $model = Question::class;

    public static function getColumns(): array
    {
        // Define common importable columns. The ImportAction UI will allow mapping CSV headers to these.
        return [
            ImportColumn::make('id')->label('ID')->guess(['id','question_id'])->exampleHeader('id'),
            ImportColumn::make('type')->label('Type')->guess(['type'])->example('mcq')->exampleHeader('type'),
            ImportColumn::make('body')->label('Text')->requiredMapping()->exampleHeader('text'),

            // options can be provided as pipe-separated in 'options' or as option1..option6 columns
            ImportColumn::make('options')->label('Options')->guess(['options','choices'])->exampleHeader('options'),
            ImportColumn::make('option1')->label('Option 1')->guess(['option1','opt1'])->exampleHeader('option1'),
            ImportColumn::make('option2')->label('Option 2')->guess(['option2','opt2'])->exampleHeader('option2'),
            ImportColumn::make('option3')->label('Option 3')->guess(['option3','opt3'])->exampleHeader('option3'),
            ImportColumn::make('option4')->label('Option 4')->guess(['option4','opt4'])->exampleHeader('option4'),
            ImportColumn::make('option5')->label('Option 5')->guess(['option5','opt5'])->exampleHeader('option5'),
            ImportColumn::make('option6')->label('Option 6')->guess(['option6','opt6'])->exampleHeader('option6'),

            ImportColumn::make('answers')->label('Answers')->guess(['answers'])->exampleHeader('answers'),
            ImportColumn::make('marks')->label('Marks')->guess(['marks'])->exampleHeader('marks'),
            ImportColumn::make('difficulty')->label('Difficulty')->guess(['difficulty'])->exampleHeader('difficulty'),
            ImportColumn::make('explanation')->label('Explanation')->guess(['explanation'])->exampleHeader('explanation'),
            ImportColumn::make('youtube_url')->label('YouTube URL')->guess(['youtube','youtube_url'])->exampleHeader('youtube_url'),
            ImportColumn::make('media')->label('Media')->guess(['media'])->exampleHeader('media'),

            // bank & approval flags
            ImportColumn::make('is_banked')->label('Is Banked')->guess(['is_banked','banked'])->exampleHeader('is_banked'),
            ImportColumn::make('is_approved')->label('Is Approved')->guess(['is_approved','approved'])->exampleHeader('is_approved'),

            // taxonomy fields (id or name)
            ImportColumn::make('grade_id')->label('Grade')->guess(['grade','grade_id','grade_name'])->relationship('grade', ['id','name']),
            ImportColumn::make('subject_id')->label('Subject')->guess(['subject','subject_id','subject_name'])->relationship('subject', ['id','name']),
            ImportColumn::make('topic_id')->label('Topic')->guess(['topic','topic_id','topic_name'])->relationship('topic', ['id','name']),
            ImportColumn::make('level_id')->label('Level')->guess(['level','level_id','level_name'])->relationship('level', ['id','name']),
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
        // Build attributes from parsed CSV row and write them to the record instance.
        $record = $this->record ?? new Question();

        $data = $this->data;

        // Basic fields
        $record->type = Str::lower(trim($data['type'] ?? 'mcq'));
        $record->body = trim($data['body'] ?? $data['text'] ?? '') ?: '';
        $record->marks = is_numeric($data['marks'] ?? null) ? floatval($data['marks']) : ($data['marks'] ?? null);
        $record->difficulty = is_numeric($data['difficulty'] ?? null) ? intval($data['difficulty']) : ($data['difficulty'] ?? null);
        $record->explanation = $data['explanation'] ?? null;
        $record->youtube_url = $data['youtube_url'] ?? null;

        // Flags
        $record->is_banked = filter_var($data['is_banked'] ?? ($data['banked'] ?? false), FILTER_VALIDATE_BOOLEAN);
        $record->is_approved = filter_var($data['is_approved'] ?? ($data['approved'] ?? false), FILTER_VALIDATE_BOOLEAN);

        // Options: collect option1..6, or parse 'options' column separated by | or ||
        $options = [];
        for ($i = 1; $i <= 6; $i++) {
            $k = "option{$i}";
            if (isset($data[$k]) && (string)($data[$k]) !== '') {
                $options[] = trim($data[$k]);
            }
        }

        if (empty($options) && ! empty($data['options'] ?? null)) {
            // allow both | and |\| separators — prefer pipe
            $raw = $data['options'];
            if (is_string($raw)) {
                $parts = preg_split('/\|+/', $raw);
                $options = array_map(fn($v) => trim($v), array_filter($parts, fn($v) => (string)$v !== ''));
            }
        }

        $record->options = ! empty($options) ? array_values($options) : null;

        // Answers: may be numbers (positions) or text; support comma or pipe separators
        $answersRaw = $data['answers'] ?? null;
        $answers = [];
        if (is_string($answersRaw) && $answersRaw !== '') {
            $parts = preg_split('/[,|]+/', $answersRaw);
            $answers = array_map(fn($v) => trim($v), array_filter($parts, fn($v) => $v !== ''));
        }

        // Normalize answers according to type
        if ($record->type === 'mcq') {
            if (! empty($answers)) {
                $first = $answers[0];
                if (is_numeric($first)) {
                    // treat numeric answers as 1-based positions -> convert to zero-based
                    $record->correct = intval($first) - 1;
                } else {
                    // match by option text
                    $idx = null;
                    foreach (($record->options ?? []) as $ii => $opt) {
                        if (trim((string)$opt) === (string)$first) { $idx = $ii; break; }
                    }
                    $record->correct = $idx ?? null;
                }
                // Also keep a copy in answers as strings for compatibility
                $record->answers = [ (string) ($first) ];
            }
        } elseif (in_array($record->type, ['multi', 'fill_blank'])) {
            if (! empty($answers)) {
                $norm = array_map(function($a) use ($record) {
                    if (is_numeric($a)) {
                        return intval($a) - 1; // convert 1-based to 0-based
                    }
                    return (string)$a;
                }, $answers);
                if ($record->type === 'multi') {
                    $record->corrects = $norm;
                } else {
                    // fill_blank treat answers as strings
                    $record->answers = array_map('strval', $answers);
                }
            }
        } else {
            // short, numeric, etc. -- store as answers strings
            if (! empty($answers)) {
                $record->answers = array_map('strval', $answers);
            }
        }

        // Taxonomy resolution: accept either id or human-readable name
        if (! empty($data['grade_id'] ?? null)) {
            $record->grade_id = intval($data['grade_id']);
        } elseif (! empty($data['grade'] ?? null)) {
            $g = Grade::whereRaw('lower(name) = ?', [Str::lower($data['grade'])])->first();
            $record->grade_id = $g?->id;
        }

        if (! empty($data['subject_id'] ?? null)) {
            $record->subject_id = intval($data['subject_id']);
        } elseif (! empty($data['subject'] ?? null)) {
            $s = Subject::whereRaw('lower(name) = ?', [Str::lower($data['subject'])])->first();
            $record->subject_id = $s?->id;
        }

        if (! empty($data['topic_id'] ?? null)) {
            $record->topic_id = intval($data['topic_id']);
        } elseif (! empty($data['topic'] ?? null)) {
            $t = Topic::whereRaw('lower(name) = ?', [Str::lower($data['topic'])])->first();
            $record->topic_id = $t?->id;
        }

        if (! empty($data['level_id'] ?? null)) {
            $record->level_id = intval($data['level_id']);
        } elseif (! empty($data['level'] ?? null)) {
            $l = Level::whereRaw('lower(name) = ?', [Str::lower($data['level'])])->first();
            $record->level_id = $l?->id;
        }

        $this->record = $record;
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        return __(':count rows imported', ['count' => $import->total_rows]);
    }
}
