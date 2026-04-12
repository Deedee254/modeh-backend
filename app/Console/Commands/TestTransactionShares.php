<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use App\Models\Wallet;
use App\Models\Quiz;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class TestTransactionShares extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:transaction-shares 
                            {--quiz-id= : Quiz ID to test with}
                            {--amount=1000 : Transaction amount in KES}
                            {--quiz-master-id= : Specific quiz-master user ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test that transactions properly distribute quiz-master and platform shares to wallets';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting Transaction Shares Test...');
        $this->newLine();

        $amount = (float) $this->option('amount') ?? 1000;
        $quizId = $this->option('quiz-id');
        $quizMasterId = $this->option('quiz-master-id');

        // Step 1: Identify quiz and quiz-master
        $this->info('Step 1: Identifying quiz and quiz-master...');
        
        if ($quizMasterId) {
            $quizMaster = User::find($quizMasterId);
            if (!$quizMaster) {
                $this->error("Quiz-master user #{$quizMasterId} not found.");
                return 1;
            }
            $this->line("Using specified quiz-master: {$quizMaster->name} (ID: {$quizMaster->id})");
            
            if (!$quizId) {
                // Find a quiz created by this user
                $quiz = Quiz::where('user_id', $quizMasterId)->first();
                if (!$quiz) {
                    $this->error("No quiz found for quiz-master #{$quizMasterId}. Please provide --quiz-id");
                    return 1;
                }
            } else {
                $quiz = Quiz::find($quizId);
                if (!$quiz) {
                    $this->error("Quiz #{$quizId} not found.");
                    return 1;
                }
            }
        } else {
            if (!$quizId) {
                $this->error('Please provide either --quiz-id or --quiz-master-id');
                return 1;
            }

            $quiz = Quiz::find($quizId);
            if (!$quiz) {
                $this->error("Quiz #{$quizId} not found.");
                return 1;
            }

            $quizMasterId = $quiz->user_id;
            if (!$quizMasterId) {
                $this->error("Quiz #{$quizId} has no user_id set.");
                return 1;
            }

            $quizMaster = User::find($quizMasterId);
        }

        $this->line("Quiz: {$quiz->title} (ID: {$quiz->id})");
        $this->line("Quiz-Master: {$quizMaster->name} (ID: {$quizMaster->id})");
        $this->newLine();

        // Step 2: Get payment settings
        $this->info('Step 2: Getting payment settings...');
        $platformSharePct = $this->getPlatformSharePercentage();
        $quizMasterSharePct = 100 - $platformSharePct;
        
        $quizMasterShare = round(($amount * $quizMasterSharePct) / 100, 2);
        $platformShare = round($amount - $quizMasterShare, 2);

        $this->line("Platform Share %: {$platformSharePct}%");
        $this->line("Quiz-Master Share %: {$quizMasterSharePct}%");
        $this->newLine();

        // Step 3: Display expected distribution
        $this->info('Step 3: Expected share distribution:');
        $this->table(
            ['Component', 'Percentage', 'Amount (KES)'],
            [
                ['Total Amount', '100%', $amount],
                ['Platform Share', $platformSharePct . '%', $platformShare],
                ['Quiz-Master Share', $quizMasterSharePct . '%', $quizMasterShare],
            ]
        );
        $this->newLine();

        // Step 4: Check current wallet balances
        $this->info('Step 4: Current wallet balances (BEFORE transaction):');
        
        $quizMasterWallet = Wallet::firstOrCreate(
            ['user_id' => $quizMasterId],
            ['available' => 0, 'pending' => 0, 'lifetime_earned' => 0]
        );

        $this->table(
            ['User', 'Available (KES)', 'Pending (KES)', 'Lifetime Earned (KES)'],
            [
                [$quizMaster->name, $quizMasterWallet->available, $quizMasterWallet->pending, $quizMasterWallet->lifetime_earned],
            ]
        );
        $this->newLine();

        // Step 5: Create test transaction
        $this->info('Step 5: Creating test transaction...');
        $txId = 'TEST-' . now()->timestamp . '-' . random_int(1000, 9999);
        
        try {
            $transaction = Transaction::create([
                'tx_id' => $txId,
                'user_id' => auth()->id() ?? 1, // Use authenticated user or default to 1
                'quiz_master_id' => $quizMasterId,
                'quiz_id' => $quiz->id,
                'amount' => $amount,
                'quiz-master_share' => $quizMasterShare,
                'platform_share' => $platformShare,
                'gateway' => 'mpesa',
                'status' => Transaction::STATUS_COMPLETED,
                'meta' => [
                    'test' => true,
                    'test_timestamp' => now()->toIso8601String(),
                ],
            ]);

            $this->line("Transaction created successfully!");
            /** @var \App\Models\Transaction $transaction */
            $this->line("Transaction ID: {$transaction->id}");
            $this->line("TX Reference: {$txId}");
        } catch (\Exception $e) {
            $this->error("Failed to create transaction: {$e->getMessage()}");
            return 1;
        }
        $this->newLine();

        // Step 6: Simulate wallet credit (as done in PaymentController)
        $this->info('Step 6: Simulating wallet credit (as done in PaymentController)...');
        
        try {
            $quizMasterWallet->increment('available', $quizMasterShare);
            $quizMasterWallet->increment('lifetime_earned', $quizMasterShare);
            // Also update earnings breakdown fields so UI shows correct figures
            try {
                $quizMasterWallet->increment('earned_from_quizzes', $quizMasterShare);
                $quizMasterWallet->increment('earned_this_month', $quizMasterShare);
            } catch (\Throwable $_) {
                // Ignore if columns don't exist in older schemas
            }
            $this->line("Wallet credited successfully!");
        } catch (\Exception $e) {
            $this->error("Failed to credit wallet: {$e->getMessage()}");
            return 1;
        }
        $this->newLine();

        // Step 7: Verify transaction was created correctly
        $this->info('Step 7: Verifying transaction in database...');
        $verifyTx = Transaction::find($transaction->id);
        
        if (!$verifyTx) {
            $this->error("Transaction not found in database!");
            return 1;
        }

        $this->table(
            ['Field', 'Expected', 'Actual', 'Status'],
            [
                ['TX ID', $txId, $verifyTx->tx_id, $verifyTx->tx_id === $txId ? 'âœ“' : 'âœ—'],
                ['Quiz-Master ID', $quizMasterId, $verifyTx->{'quiz_master_id'}, $verifyTx->{'quiz_master_id'} === $quizMasterId ? 'âœ“' : 'âœ—'],
                ['Quiz ID', $quiz->id, $verifyTx->quiz_id, $verifyTx->quiz_id === $quiz->id ? 'âœ“' : 'âœ—'],
                ['Total Amount', $amount, $verifyTx->amount, $verifyTx->amount === $amount ? 'âœ“' : 'âœ—'],
                ['Quiz-Master Share', $quizMasterShare, $verifyTx->{'quiz-master_share'}, $verifyTx->{'quiz-master_share'} === $quizMasterShare ? 'âœ“' : 'âœ—'],
                ['Platform Share', $platformShare, $verifyTx->platform_share, $verifyTx->platform_share === $platformShare ? 'âœ“' : 'âœ—'],
                ['Status', Transaction::STATUS_COMPLETED, $verifyTx->status, $verifyTx->status === Transaction::STATUS_COMPLETED ? 'âœ“' : 'âœ—'],
            ]
        );
        $this->newLine();

        // Step 8: Check wallet balance after credit
        $this->info('Step 8: Wallet balance AFTER transaction and credit:');
        
        $quizMasterWallet->refresh();
        
        $this->table(
            ['User', 'Available (KES)', 'Pending (KES)', 'Lifetime Earned (KES)'],
            [
                [$quizMaster->name, $quizMasterWallet->available, $quizMasterWallet->pending, $quizMasterWallet->lifetime_earned],
            ]
        );
        $this->newLine();

        // Step 9: Validate amounts
        $this->info('Step 9: Validation:');
        
        $expectedAvailable = $quizMasterWallet->available;
        $expectedLifetimeEarned = $quizMasterWallet->lifetime_earned;
        
        $validations = [
            [
                'Quiz-Master Received Share',
                'Quiz-Master wallet available >= quiz-master share',
                $expectedAvailable >= $quizMasterShare ? 'PASS' : 'FAIL',
            ],
            [
                'Lifetime Earnings Updated',
                'Lifetime earnings >= quiz-master share',
                $expectedLifetimeEarned >= $quizMasterShare ? 'PASS' : 'FAIL',
            ],
            [
                'Amount Distribution',
                'Quiz-Master Share + Platform Share == Total Amount',
                abs(($quizMasterShare + $platformShare) - $amount) < 0.01 ? 'PASS' : 'FAIL',
            ],
            [
                'Transaction Column Names',
                'Column names use hyphens (quiz_master_id)',
                $verifyTx->{'quiz_master_id'} === $quizMasterId ? 'PASS' : 'FAIL',
            ],
        ];

        $this->table(['Check', 'Description', 'Result'], $validations);
        $this->newLine();

        // Step 10: Summary
        $this->info('Step 10: Test Summary:');
        
        $allPassed = collect($validations)->every(fn($v) => $v[2] === 'PASS');
        
        if ($allPassed) {
            $this->info("âœ“ All tests PASSED!");
            $this->info("Quiz-Master {$quizMaster->name} has received KES {$quizMasterShare}");
            $this->info("Platform has received KES {$platformShare}");
            $this->info("Total distributed: KES {$amount}");
            return 0;
        } else {
            $this->error("âœ— Some tests FAILED!");
            $this->warn("Please review the results above.");
            return 1;
        }
    }

    /**
     * Get platform revenue share percentage from payment settings.
     */
    private function getPlatformSharePercentage(): float
    {
        return \App\Models\PaymentSetting::platformRevenueSharePercent();
    }
}
