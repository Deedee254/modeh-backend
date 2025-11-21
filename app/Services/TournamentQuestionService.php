<?php

namespace App\Services;

use App\Models\Question;
use App\Models\Tournament;
use App\Models\TournamentBattle;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;

class TournamentQuestionService
{
    public function attachQuestionsFromCsv(Tournament $tournament, TournamentBattle $battle, UploadedFile $file)
    {
        try {
            $path = $file->getRealPath();
            $ext = strtolower($file->getClientOriginalExtension());

            [$headers, $rows] = $this->parseCsv($path, $ext);

            if (empty($headers)) {
                throw new \Exception('Empty or unreadable file');
            }

            $idKeyIndexes = $this->getIdKeyIndexes($headers);

            DB::beginTransaction();

            if (!empty($idKeyIndexes)) {
                $attachData = $this->getAttachDataFromIds($rows, $idKeyIndexes);
            } else {
                $attachData = $this->createQuestionsFromRows($rows, $headers, $tournament);
            }

            if (!empty($attachData)) {
                $battle->questions()->detach();
                $battle->questions()->attach($attachData);
            }

            DB::commit();

            return ['attached' => count($attachData), 'questions' => $battle->questions()->get()];
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('attachQuestionsToBattle: exception processing uploaded file', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'tournament_id' => $tournament->id ?? null,
                'battle_id' => $battle->id ?? null,
            ]);
            throw $e;
        }
    }

    private function parseCsv(string $path, string $ext): array
    {
        $headers = [];
        $rows = [];
        if (in_array($ext, ['xls', 'xlsx', 'xlsm'])) {
            $spreadsheet = IOFactory::load($path);
            $sheet = $spreadsheet->getActiveSheet();
            $array = $sheet->toArray(null, true, true, true);
            if (empty($array)) return [[], []];
            $first = array_shift($array);
            $headers = array_map(function ($h) { return strtolower(trim((string)$h)); }, array_values($first));
            foreach ($array as $r) {
                $rows[] = array_values($r);
            }
        } else {
            $FH = fopen($path, 'r');
            if (!$FH) return [[], []];
            $rawHeaders = fgetcsv($FH);
            if ($rawHeaders === false) { fclose($FH); return [[], []]; }
            $headers = array_map(function ($h) { return strtolower(trim((string)$h)); }, $rawHeaders);
            while (($row = fgetcsv($FH)) !== false) {
                $rows[] = $row;
            }
            fclose($FH);
        }
        return [$headers, $rows];
    }

    private function getIdKeyIndexes(array $headers): array
    {
        $idKeyIndexes = [];
        foreach ($headers as $i => $h) {
            if (in_array($h, ['id', 'question_id', 'questionid', 'question id'])) { $idKeyIndexes[] = $i; }
        }
        return $idKeyIndexes;
    }

    private function getAttachDataFromIds(array $rows, array $idKeyIndexes): array
    {
        $idIdx = $idKeyIndexes[0];
        $ids = [];
        foreach ($rows as $row) {
            if (!isset($row[$idIdx])) continue;
            $val = trim((string)$row[$idIdx]);
            if ($val === '') continue;
            if (is_numeric($val)) $ids[] = intval($val);
        }
        if (empty($ids)) {
            throw new \Exception('No question ids found in file');
        }
        $questions = Question::whereIn('id', $ids)->get()->keyBy('id');
        $attachData = [];
        foreach ($ids as $i => $qid) {
            if ($questions->has($qid)) {
                $attachData[$qid] = ['position' => $i];
            }
        }
        return $attachData;
    }

    private function createQuestionsFromRows(array $rows, array $headers, Tournament $tournament): array
    {
        $canonical = array_map(function ($h) { return preg_replace('/[^a-z0-9_]/', '_', $h); }, $headers);
        $created = [];
        $userId = auth()->id();

        foreach ($rows as $rIdx => $row) {
            $rowData = [];
            foreach ($canonical as $i => $key) {
                $rowData[$key] = isset($row[$i]) ? trim((string)$row[$i]) : null;
            }

            $body = $rowData['body'] ?? $rowData['prompt'] ?? $rowData['question'] ?? $rowData['text'] ?? null;
            if (!$body) continue;
            $type = $rowData['type'] ?? $rowData['question_type'] ?? 'mcq';

            $options = $this->getOptions($rowData);
            $correct = null;
            $corrects = null;
            $rawCorrect = $rowData['correct'] ?? $rowData['correct_answer'] ?? $rowData['answers'] ?? null;
            if ($rawCorrect !== null) {
                if (strpos($rawCorrect, '|') !== false || strpos($rawCorrect, ',') !== false) {
                    $parts = array_map('trim', preg_split('/[|,]/', $rawCorrect));
                    $mapped = [];
                    foreach ($parts as $p) {
                        if (is_numeric($p)) $mapped[] = intval($p);
                        else {
                            $idx = null;
                            foreach ($options as $oi => $opt) { if (strcasecmp($opt['text'], $p) === 0) { $idx = $oi; break; } }
                            if ($idx !== null) $mapped[] = $idx;
                        }
                    }
                    $corrects = array_values(array_unique(array_filter($mapped, 'is_int')));
                } else {
                    $p = trim((string)$rawCorrect);
                    if (is_numeric($p)) $correct = intval($p);
                    else {
                        foreach ($options as $oi => $opt) { if (strcasecmp($opt['text'], $p) === 0) { $correct = $oi; break; } }
                    }
                }
            }

            $createData = [
                'body' => $body,
                'type' => $type,
                'options' => $options ?: null,
                'marks' => isset($rowData['marks']) && is_numeric($rowData['marks']) ? floatval($rowData['marks']) : null,
                'difficulty' => $rowData['difficulty'] ?? null,
                'explanation' => $rowData['explanation'] ?? null,
                'youtube_url' => $rowData['youtube_url'] ?? $rowData['youtube'] ?? null,
                'subject_id' => $tournament->subject_id,
                'topic_id' => $tournament->topic_id,
                'grade_id' => $tournament->grade_id,
                'level_id' => $tournament->level_id,
                'is_banked' => true,
                'is_approved' => true,
            ];
            if ($corrects !== null) $createData['corrects'] = $corrects;
            if ($correct !== null) $createData['correct'] = $correct;
            if ($userId) $createData['created_by'] = $userId;

            $question = Question::create($createData);
            $created[] = $question;
        }

        $attachData = [];
        foreach ($created as $i => $q) {
            $attachData[$q->id] = ['position' => $i];
        }
        return $attachData;
    }

    private function getOptions(array $rowData): array
    {
        $options = [];
        if (!empty($rowData['options'])) {
            $maybe = $rowData['options'];
            $decoded = json_decode($maybe, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                foreach ($decoded as $opt) {
                    if (is_array($opt) && isset($opt['text'])) $options[] = ['text' => $opt['text']];
                    elseif (is_string($opt)) $options[] = ['text' => $opt];
                }
            } else {
                $parts = array_filter(array_map('trim', explode('|', $maybe)));
                foreach ($parts as $p) $options[] = ['text' => $p];
            }
        } elseif (!empty($rowData['choices'])) {
            $parts = array_filter(array_map('trim', preg_split('/[|,]/', $rowData['choices'])));
            foreach ($parts as $p) $options[] = ['text' => $p];
        } else {
            for ($i = 1; $i <= 10; $i++) {
                $k1 = 'option_' . $i;
                $k2 = 'option' . $i;
                if (!empty($rowData[$k1])) {
                    $options[] = ['text' => $rowData[$k1]];
                } elseif (!empty($rowData[$k2])) {
                    $options[] = ['text' => $rowData[$k2]];
                }
            }
        }
        return $options;
    }
}
