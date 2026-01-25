<?php

namespace App\Http\Controllers\Api;

use App\Services\TournamentQuestionService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class QuestionImportController
{
    /**
     * Parse an uploaded CSV or Excel file and return parsed rows
     * Reuses TournamentQuestionService::parseCsv() for consistent parsing logic
     */
    public function parse(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'file' => 'required|file|mimes:csv,txt,xls,xlsx,xlsm|max:10240', // 10MB limit
            ]);

            $file = $request->file('file');
            $path = $file->getRealPath();
            $ext = strtolower($file->getClientOriginalExtension());

            // Reuse the existing parsing logic from TournamentQuestionService
            $service = new TournamentQuestionService();
            [$headers, $rows] = $service->parseCsv($path, $ext);

            if (empty($headers)) {
                return response()->json(
                    ['error' => 'File is empty or unreadable'],
                    400
                );
            }

            // Return parsed data to the frontend
            return response()->json([
                'headers' => $headers,
                'rows' => $rows,
                'count' => count($rows),
            ]);
        } catch (\Throwable $e) {
            Log::error('Question import parsing failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'file' => $request->file('file')->getClientOriginalName() ?? 'unknown',
            ]);

            return response()->json(
                ['error' => 'Failed to parse file: ' . $e->getMessage()],
                400
            );
        }
    }
}
