<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SettleQuizMasterShares extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'settle:quiz-master-shares {--dry-run : Run without making changes} {--user-id= : Settle for a specific user ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Credit quiz-masters with their shares from transactions where they were missed';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $dryRun = $this->option('dry-run');
        $userId = $this->option('user-id');

        $this->info('🔄 Starting Quiz Master Settlement Process...');
        $this->newLine();

        if ($dryRun) {
            $this->warn('⚠️  DRY RUN MODE - No changes will be made');
            $this->newLine();
        }

        // Find transactions where quiz-master_id is NULL but should have been credited
        $query = Transaction::whereNotNull('quiz_id')
            ->where('status', 'confirmed')
            ->where('quiz-master_share', '>', 0);

        // Check for NULL quiz-master_id
        $query->where(function ($q) {
            $q->whereNull('quiz-master_id');
        });

        if ($userId) {
            $this->info("Filtering for user ID: {$userId}");
            $query->where('user_id', $userId);
        }

        $transactions = $query->get();

        if ($transactions->isEmpty()) {
            $this->info('✅ No transactions found that need settlement.');
            return Command::SUCCESS;
        }

        $this->warn("Found {$transactions->count()} transactions that need settlement");
        $this->newLine();

        $totalToSettle = 0;
        $settledByUser = [];

        foreach ($transactions as $transaction) {
            $quiz = $transaction->quiz;
            if (!$quiz) {
                $this->warn("⚠️  Transaction #{$transaction->id} has no associated quiz - skipping");
                continue;
            }

            $quizMasterId = $quiz->user_id;
            if (!$quizMasterId) {
                $this->warn("⚠️  Quiz #{$quiz->id} has no user_id - skipping");
                continue;
            }

            $amount = $transaction->{'quiz-master_share'};
            $totalToSettle += $amount;

            if (!isset($settledByUser[$quizMasterId])) {
                $settledByUser[$quizMasterId] = 0;
            }
            $settledByUser[$quizMasterId] += $amount;

            $this->line("Transaction #{$transaction->id}: Quizee #{$transaction->user_id} → Quiz Master #{$quizMasterId} : KES {$amount}");
        }

        $this->newLine();
        $this->info("Total to be settled: KES {$totalToSettle}");
        $this->newLine();

        $this->table(['Quiz Master ID', 'Total Amount'], 
            collect($settledByUser)->map(fn($amount, $userId) => [$userId, "KES {$amount}"])->toArray()
        );

        $this->newLine();

        if ($dryRun) {
            $this->info('✅ Dry run completed. Run without --dry-run to apply changes.');
            return Command::SUCCESS;
        }

        if (!$this->confirm('Do you want to proceed with settlement?')) {
            $this->info('Cancelled.');
            return Command::SUCCESS;
        }

        $this->newLine();
        $this->info('💳 Processing settlement...');

        DB::beginTransaction();

        try {
            $settledCount = 0;

            foreach ($transactions as $transaction) {
                $quiz = $transaction->quiz;
                if (!$quiz || !$quiz->user_id) {
                    continue;
                }

                $quizMasterId = $quiz->user_id;
                $amount = $transaction->{'quiz-master_share'};

                // Update transaction to set quiz-master_id
                $transaction->update([
                    'quiz-master_id' => $quizMasterId,
                ]);

                // Credit wallet
                $wallet = Wallet::firstOrCreate(
                    ['user_id' => $quizMasterId],
                    [
                        'available' => 0,
                        'pending' => 0,
                        'lifetime_earned' => 0,
                    ]
                );

                $wallet->increment('available', $amount);
                $wallet->increment('lifetime_earned', $amount);

                $settledCount++;
            }

            DB::commit();

            $this->newLine();
            $this->info("✅ Settlement completed successfully!");
            $this->info("Settled {$settledCount} transactions");
            $this->info("Total amount distributed: KES {$totalToSettle}");

            return Command::SUCCESS;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("❌ Settlement failed: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }
}
