<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class MigrateQuestionOptionsToStrings extends Migration
{
    /**
     * Run the migrations.
     * Scan the questions table and convert any option objects like {"text":"..."}
     * into plain string options so options column becomes ["A","B","C","D"].
     */
    public function up()
    {
        // Process in chunks to avoid high memory usage
        DB::table('questions')->orderBy('id')->chunkById(100, function ($rows) {
            foreach ($rows as $row) {
                $raw = $row->options;
                if ($raw === null) {
                    continue;
                }

                // Decode JSON safely
                $decoded = json_decode($raw, true);
                if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                    // If not a JSON array, skip
                    continue;
                }

                // Determine if conversion needed: first element is array/object with 'text' or elements are associative
                $needsConvert = false;
                foreach ($decoded as $element) {
                    if (is_array($element) && array_key_exists('text', $element)) {
                        $needsConvert = true;
                        break;
                    }
                    if (is_object($element) && property_exists($element, 'text')) {
                        $needsConvert = true;
                        break;
                    }
                }

                if (! $needsConvert) {
                    // nothing to do
                    continue;
                }

                $new = [];
                foreach ($decoded as $element) {
                    if (is_array($element) && array_key_exists('text', $element)) {
                        $new[] = trim((string)$element['text']);
                        continue;
                    }
                    if (is_object($element) && property_exists($element, 'text')) {
                        $new[] = trim((string)$element->text);
                        continue;
                    }
                    if (is_array($element) && isset($element[0]) && is_string($element[0])) {
                        $new[] = trim($element[0]);
                        continue;
                    }
                    // Fallback: cast to string
                    $new[] = is_string($element) ? trim($element) : trim((string)$element);
                }

                DB::table('questions')->where('id', $row->id)->update([
                    'options' => json_encode(array_values($new)),
                ]);
            }
        });
    }

    /**
     * Reverse the migrations.
     * Not reversible safely — leave empty.
     */
    public function down()
    {
        // Intentionally left blank. Reverting would require restoring previous DB state.
    }
}
