<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Institution;
use App\Models\User;
use App\Models\Quizee;
use App\Models\QuizMaster;
use App\Services\OnboardingService;
use App\Services\InstitutionPackageUsageService;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class InstitutionMemberImportController extends Controller
{
    public function importCsv(Request $request, Institution $institution)
    {
        /** @var Institution $institution */
        $user = $request->user();
        
        // Only institution managers can import members
        $isManager = $institution->users()
            ->where('users.id', $user->id)
            ->wherePivot('role', 'institution-manager')
            ->exists();
            
        if (!$isManager) {
            return response()->json(['ok' => false, 'message' => 'Forbidden'], 403);
        }

        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:10240', // 10MB limit
            'default_role' => 'nullable|string|in:quizee,quiz-master',
            'level_id' => 'nullable|exists:levels,id',
            'grade_id' => 'nullable|exists:grades,id',
            'subjects' => 'nullable|array',
            'subjects.*' => 'exists:subjects,id',
        ]);

        $defaultRole = $request->input('default_role', 'quizee');
        $levelId = $request->input('level_id');
        $gradeId = $request->input('grade_id');
        $subjects = $request->input('subjects', []);

        $file = $request->file('file');
        $path = $file->getRealPath();

        $rows = $this->parseCsv($path);
        if (empty($rows)) {
            return response()->json(['ok' => false, 'message' => 'The uploaded CSV file is empty or could not be parsed.'], 400);
        }

        $imported = [];
        $skipped = [];
        $linked = [];

        $onboardingService = new OnboardingService();

        foreach ($rows as $index => $row) {
            $normalized = $this->normalizeRow($row);
            
            $name = trim($normalized['name'] ?? '');
            $email = strtolower(trim($normalized['email'] ?? ''));
            $role = strtolower(trim($normalized['role'] ?? $defaultRole));
            
            // Normalize role to valid options
            if (!in_array($role, ['quizee', 'quiz-master'])) {
                $role = $defaultRole;
            }

            if (empty($name) || empty($email)) {
                $skipped[] = [
                    'row' => $index + 2, // 1-indexed + header row
                    'email' => $email ?: 'N/A',
                    'name' => $name ?: 'N/A',
                    'reason' => 'Name or Email is missing.',
                ];
                continue;
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $skipped[] = [
                    'row' => $index + 2,
                    'email' => $email,
                    'name' => $name,
                    'reason' => 'Invalid email address format.',
                ];
                continue;
            }

            try {
                DB::transaction(function () use (
                    $email, $name, $role, $normalized, $institution, $user, $levelId, $gradeId, $subjects,
                    $onboardingService, &$imported, &$linked, &$skipped, $index
                ) {
                    $existingUser = User::where('email', $email)->first();

                    if ($existingUser) {
                        // Check if already in the institution
                        $alreadyAttached = DB::table('institution_user')
                            ->where('institution_id', $institution->id)
                            ->where('user_id', $existingUser->id)
                            ->exists();

                        if ($alreadyAttached) {
                            $skipped[] = [
                                'row' => $index + 2,
                                'email' => $email,
                                'name' => $name,
                                'reason' => 'Already a member of this institution.',
                            ];
                            return;
                        }

                        // Attach existing user to institution
                        DB::table('institution_user')->insert([
                            'institution_id' => $institution->id,
                            'user_id' => $existingUser->id,
                            'role' => $role,
                            'status' => 'active',
                            'invited_by' => $user->id,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);

                        // Ensure profile exists
                        $profileData = [
                            'institution' => $institution->name,
                            'level_id' => $levelId,
                            'grade_id' => $gradeId,
                            'subjects' => $subjects,
                            'institution_verified' => true,
                            'verified_institution_id' => $institution->id,
                        ];

                        if ($role === 'quizee') {
                            $profile = Quizee::where('user_id', $existingUser->id)->first();
                            if (!$profile) {
                                Quizee::create(array_merge(['user_id' => $existingUser->id], $profileData));
                            } else {
                                $profile->update(array_filter($profileData));
                            }
                        } else {
                            $profile = QuizMaster::where('user_id', $existingUser->id)->first();
                            if (!$profile) {
                                QuizMaster::create(array_merge(['user_id' => $existingUser->id], $profileData));
                            } else {
                                $profile->update(array_filter($profileData));
                            }
                        }

                        $existingUser->refresh();
                        $onboardingService->syncProfileCompletionStatus($existingUser);

                        $linked[] = [
                            'email' => $email,
                            'name' => $name,
                            'role' => $role,
                        ];
                    } else {
                        // Create new user
                        $passwordText = !empty($normalized['password']) ? $normalized['password'] : Str::random(8);

                        $newUser = User::create([
                            'name' => $name,
                            'email' => $email,
                            'password' => Hash::make($passwordText),
                            'role' => $role,
                        ]);

                        // Create profile
                        $profileData = [
                            'user_id' => $newUser->id,
                            'institution' => $institution->name,
                            'level_id' => $levelId,
                            'grade_id' => $gradeId,
                            'subjects' => $subjects,
                            'institution_verified' => true,
                            'verified_institution_id' => $institution->id,
                        ];

                        if ($role === 'quizee') {
                            Quizee::create($profileData);
                        } else {
                            QuizMaster::create($profileData);
                        }

                        // Attach to pivot table
                        DB::table('institution_user')->insert([
                            'institution_id' => $institution->id,
                            'user_id' => $newUser->id,
                            'role' => $role,
                            'status' => 'active',
                            'invited_by' => $user->id,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);

                        // Record seat usage
                        try {
                            InstitutionPackageUsageService::recordSeatUsage($institution, $newUser);
                        } catch (\Throwable $e) {
                            Log::warning('[Institution Import] Failed to record seat usage: ' . $e->getMessage());
                        }

                        // Try to assign active subscription seat
                        $activeSub = $institution->activeSubscription();
                        if ($activeSub && $activeSub->package) {
                            try {
                                $activeSub->assignUser($newUser->id, $user->id);
                            } catch (\Throwable $e) {
                                Log::warning('[Institution Import] Failed to assign subscription seat: ' . $e->getMessage());
                            }
                        }

                        $newUser->refresh();
                        $onboardingService->syncProfileCompletionStatus($newUser);

                        $imported[] = [
                            'email' => $email,
                            'name' => $name,
                            'role' => $role,
                            'password' => $passwordText,
                        ];
                    }
                });
            } catch (\Throwable $e) {
                Log::error('[Institution Import] Transaction failed for row ' . ($index + 2) . ': ' . $e->getMessage());
                $skipped[] = [
                    'row' => $index + 2,
                    'email' => $email,
                    'name' => $name,
                    'reason' => 'Internal error: ' . $e->getMessage(),
                ];
            }
        }

        $message = sprintf(
            'Import complete: %d new accounts created, %d existing accounts linked, %d skipped.',
            count($imported),
            count($linked),
            count($skipped)
        );

        return response()->json([
            'ok' => true,
            'message' => $message,
            'imported' => $imported,
            'linked' => $linked,
            'skipped' => $skipped,
        ], 200);
    }

    private function parseCsv(string $path): array
    {
        $FH = fopen($path, 'r');
        if (!$FH) return [];

        // Detect and remove UTF-8 BOM
        $bom = fread($FH, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($FH);
        }

        $rawHeaders = fgetcsv($FH);
        if ($rawHeaders === false) {
            fclose($FH);
            return [];
        }

        // Clean headers: lowercase, trim, remove non-alphanumeric chars
        $headers = array_map(function ($h) {
            $h = mb_convert_encoding($h, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
            return preg_replace('/[^a-z0-9_]/', '', strtolower(trim((string)$h)));
        }, $rawHeaders);

        $rows = [];
        while (($row = fgetcsv($FH)) !== false) {
            $row = array_map(function ($value) {
                return trim(mb_convert_encoding($value, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252'));
            }, $row);
            
            // Map row values to headers
            $mappedRow = [];
            foreach ($headers as $index => $header) {
                if (empty($header)) continue;
                $mappedRow[$header] = $row[$index] ?? '';
            }
            if (!empty($mappedRow)) {
                $rows[] = $mappedRow;
            }
        }
        fclose($FH);
        return $rows;
    }

    private function normalizeRow(array $row): array
    {
        $normalized = [];
        
        // Find name
        foreach (['name', 'fullname', 'full_name', 'username', 'displayname'] as $key) {
            if (isset($row[$key])) {
                $normalized['name'] = $row[$key];
                break;
            }
        }
        
        // Find email
        foreach (['email', 'emailaddress', 'mail', 'email_address'] as $key) {
            if (isset($row[$key])) {
                $normalized['email'] = $row[$key];
                break;
            }
        }
        
        // Find role
        foreach (['role', 'userrole', 'role_name', 'type', 'usertype'] as $key) {
            if (isset($row[$key])) {
                $normalized['role'] = strtolower(trim($row[$key]));
                break;
            }
        }
        
        // Find password
        foreach (['password', 'pass', 'pwd'] as $key) {
            if (isset($row[$key])) {
                $normalized['password'] = $row[$key];
                break;
            }
        }
        
        return $normalized;
    }
}
