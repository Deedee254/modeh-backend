<?php

namespace App\Filament\Resources\TournamentResource\Pages;

use App\Filament\Resources\TournamentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\CreateRecord;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class CreateTournament extends CreateRecord
{
    protected static string $resource = TournamentResource::class;

    

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['status'] = 'upcoming';
        $data['created_by'] = auth()->id();

        // Handle sponsor_details JSON structure
        if (isset($data['sponsor_details']) && is_array($data['sponsor_details'])) {
            $data['sponsor_details'] = array_filter($data['sponsor_details'], fn($v) => $v !== null && $v !== '');
            if (empty($data['sponsor_details'])) {
                $data['sponsor_details'] = null;
            }
        }

        // Handle rules - ensure it's JSON encoded if it's an array
        if (isset($data['rules']) && is_array($data['rules'])) {
            $data['rules'] = json_encode($data['rules']);
        }

        Log::info('Tournament creation form data prepared', [
            'user_id' => auth()->id(),
            'tournament_name' => $data['name'] ?? null,
            'status' => $data['status'],
            'start_date' => $data['start_date'] ?? null,
            'end_date' => $data['end_date'] ?? null,
            'entry_fee' => $data['entry_fee'] ?? null,
            'prize_pool' => $data['prize_pool'] ?? null,
            'open_to_subscribers' => $data['open_to_subscribers'] ?? null,
            'rules' => $data['rules'] ?? null,
        ]);

        return $data;
    }

    protected function beforeCreate(): void
    {
        // Validate all required fields first
        $this->validateRequiredFields();
        
        // Then validate dates
        $this->validateDates();
    }

    private function validateRequiredFields(): void
    {
        $errors = [];
        
        // Check required fields
        if (empty($this->data['name'] ?? null)) {
            $errors['name'] = 'Tournament name is required.';
        }
        
        if (empty($this->data['description'] ?? null)) {
            $errors['description'] = 'Tournament description is required.';
        }
        
        if (empty($this->data['start_date'] ?? null)) {
            $errors['start_date'] = 'Tournament start date is required.';
        }
        
        if (empty($this->data['end_date'] ?? null)) {
            $errors['end_date'] = 'Tournament end date is required.';
        }
        
        if (empty($this->data['level_id'] ?? null)) {
            $errors['level_id'] = 'Level is required.';
        }
        
        if (empty($this->data['grade_id'] ?? null)) {
            $errors['grade_id'] = 'Grade is required.';
        }
        
        if (empty($this->data['subject_id'] ?? null)) {
            $errors['subject_id'] = 'Subject is required.';
        }
        
        if (empty($this->data['topic_id'] ?? null)) {
            $errors['topic_id'] = 'Topic is required.';
        }

        if (!empty($errors)) {
            $errorList = implode("\n", array_values($errors));
            
            Log::warning('Tournament creation validation failed: missing required fields', [
                'user_id' => auth()->id(),
                'tournament_name' => $this->data['name'] ?? null,
                'missing_fields' => array_keys($errors),
                'errors' => $errors,
            ]);

            Notification::make()
                ->danger()
                ->title('Missing Required Fields')
                ->body("Please fill in all required fields:\n\n" . $errorList)
                ->persistent()
                ->send();

            foreach ($errors as $field => $error) {
                $this->addError($field, $error);
            }
            
            $this->halt();
        }
    }

    private function validateDates(): void
    {
        // Validate dates - handle both string and Carbon instances
        try {
            $startDate = $this->data['start_date'] instanceof \Carbon\Carbon 
                ? $this->data['start_date'] 
                : \Carbon\Carbon::parse($this->data['start_date']);
            
            $endDate = $this->data['end_date'] instanceof \Carbon\Carbon 
                ? $this->data['end_date'] 
                : \Carbon\Carbon::parse($this->data['end_date']);

            $now = now();

            Log::debug('Tournament creation validation', [
                'user_id' => auth()->id(),
                'tournament_name' => $this->data['name'] ?? null,
                'start_date' => $startDate->toDateTimeString(),
                'end_date' => $endDate->toDateTimeString(),
                'now' => $now->toDateTimeString(),
            ]);

            if ($startDate->isBefore($now)) {
                $errorMsg = "Start date must be in the future. Current date is {$now->format('M d, Y H:i')}. Please choose a date after {$now->addDay()->format('M d, Y')}";
                
                Log::warning('Tournament creation validation failed: start date in past', [
                    'user_id' => auth()->id(),
                    'tournament_name' => $this->data['name'] ?? null,
                    'start_date' => $startDate->toDateTimeString(),
                    'now' => $now->toDateTimeString(),
                    'error_message' => $errorMsg,
                ]);

                Notification::make()
                    ->danger()
                    ->title('Invalid Start Date')
                    ->body($errorMsg)
                    ->send();

                $this->addError('start_date', $errorMsg);
                $this->halt();
            }

            if ($endDate->isBefore($startDate)) {
                $errorMsg = "End date must be after start date. Start date: {$startDate->format('M d, Y H:i')}, End date: {$endDate->format('M d, Y H:i')}";
                
                Log::warning('Tournament creation validation failed: end date before start date', [
                    'user_id' => auth()->id(),
                    'tournament_name' => $this->data['name'] ?? null,
                    'start_date' => $startDate->toDateTimeString(),
                    'end_date' => $endDate->toDateTimeString(),
                    'error_message' => $errorMsg,
                ]);

                Notification::make()
                    ->danger()
                    ->title('Invalid End Date')
                    ->body($errorMsg)
                    ->send();

                $this->addError('end_date', $errorMsg);
                $this->halt();
            }
        } catch (\Exception $e) {
            $errorMsg = 'Invalid date format. Please use the date picker to select valid dates.';
            
            Log::error('Tournament creation validation error', [
                'user_id' => auth()->id(),
                'tournament_name' => $this->data['name'] ?? null,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'error_message' => $errorMsg,
            ]);

            Notification::make()
                ->danger()
                ->title('Date Format Error')
                ->body($errorMsg)
                ->send();

            $this->addError('start_date', $errorMsg);
            $this->halt();
        }
    }

    protected function afterCreate(): void
    {
        // Log successful tournament creation
        Log::info('Tournament successfully created', [
            'user_id' => auth()->id(),
            'tournament_id' => $this->record->id,
            'tournament_name' => $this->record->name,
            'status' => $this->record->status,
            'start_date' => $this->record->start_date?->toDateTimeString(),
            'end_date' => $this->record->end_date?->toDateTimeString(),
            'entry_fee' => $this->record->entry_fee,
            'prize_pool' => $this->record->prize_pool,
            'open_to_subscribers' => $this->record->open_to_subscribers,
            'created_at' => $this->record->created_at?->toDateTimeString(),
        ]);

        // Show success notification
        Notification::make()
            ->success()
            ->title('Tournament Created')
            ->body('Tournament ready! You can now add questions from the bank or import a CSV file.')
            ->send();
    }
}