<?php

namespace App\Console\Commands;

use App\Models\Quiz;
use App\Models\User;
use App\Models\Question;
use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ListQuizMasterQuizzes extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'quiz:list-by-master 
                            {--quiz-master-id= : Quiz-Master user ID}
                            {--quiz-master-name= : Quiz-Master name (partial match)}
                            {--limit=50 : Number of quizzes to display}
                            {--show-transactions : Also show transaction summary}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List all quizzes belonging to a specific quiz-master';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Quiz-Master Quizzes Lookup');
        $this->newLine();

        $quizMaster = $this->findQuizMaster(
            $this->option('quiz-master-id'),
            $this->option('quiz-master-name')
        );

        if (!$quizMaster) {
            return 1;
        }

        $limit = (int) ($this->option('limit') ?? 50);
        $quizzes = $this->fetchQuizzes($quizMaster, $limit);

        if ($quizzes->isEmpty()) {
            $this->warn("No quizzes found for {$quizMaster->name}");
            return 0;
        }

        $this->displayQuizDetails($quizzes);
        $this->displaySummaryStatistics($quizzes);

        if ($this->option('show-transactions')) {
            $this->displayTransactionSummary($quizMaster);
        }

        $this->displayWalletInformation($quizMaster);

        $this->info('Lookup completed successfully!');
        return 0;
    }

    /**
     * Step 1: Find the quiz-master user
     */
    private function findQuizMaster(?string $id, ?string $name): ?User
    {
        $this->info('Step 1: Finding quiz-master user...');

        if ($id) {
            $user = User::find($id);
            if (!$user) {
                $this->error("Quiz-master user #{$id} not found.");
                return null;
            }
        } elseif ($name) {
            $user = User::where('name', 'like', "%{$name}%")
                ->orWhere('email', 'like', "%{$name}%")
                ->first();
            if (!$user) {
                $this->error("Quiz-master matching '{$name}' not found.");
                return null;
            }
        } else {
            $this->error('Please provide either --quiz-master-id or --quiz-master-name');
            return null;
        }

        $this->line("Found: {$user->name} (ID: {$user->id}, Email: {$user->email})");
        $this->newLine();

        return $user;
    }

    /**
     * Step 2: Get all quizzes for this user
     */
    private function fetchQuizzes(User $quizMaster, int $limit): Collection
    {
        $this->info('Step 2: Fetching quizzes...');
        
        $quizzes = Quiz::where('user_id', $quizMaster->id)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        if ($quizzes->isNotEmpty()) {
            $this->line("Found {$quizzes->count()} quiz(zes)");
            $this->newLine();
        }

        return $quizzes;
    }

    /**
     * Step 3: Display quizzes
     */
    private function displayQuizDetails(Collection $quizzes): void
    {
        $this->info('Step 3: Quiz Details:');
        
        $quizData = $quizzes->map(function ($quiz) {
            return [
                'ID' => $quiz->id,
                'Title' => Str::limit($quiz->title, 50),
                'Questions' => $quiz->questions_count ?? Question::where('quiz_id', $quiz->id)->count(),
                'Is Paid' => $quiz->is_paid ? 'Yes' : 'No',
                'Status' => $quiz->status ?? 'active',
                'Created' => $quiz->created_at->format('Y-m-d H:i'),
                'Updated' => $quiz->updated_at->format('Y-m-d H:i'),
            ];
        })->toArray();

        $this->table(
            ['ID', 'Title', 'Questions', 'Is Paid', 'Status', 'Created', 'Updated'],
            $quizData
        );
        $this->newLine();
    }

    /**
     * Step 4: Summary statistics
     */
    private function displaySummaryStatistics(Collection $quizzes): void
    {
        $this->info('Step 4: Summary Statistics:');
        
        $totalQuestions = $quizzes->sum(fn($q) => $q->questions_count ?? Question::where('quiz_id', $q->id)->count());
        $paidQuizzes = $quizzes->where('is_paid', true)->count();
        $freeQuizzes = $quizzes->where('is_paid', false)->count();

        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Quizzes', $quizzes->count()],
                ['Paid Quizzes', $paidQuizzes],
                ['Free Quizzes', $freeQuizzes],
                ['Total Questions', $totalQuestions],
            ]
        );
        $this->newLine();
    }

    /**
     * Step 5: Show transaction data
     */
    private function displayTransactionSummary(User $quizMaster): void
    {
        $this->info('Step 5: Transaction Summary:');
        
        $transactions = Transaction::where('quiz_master_id', $quizMaster->id)
            ->get();

        if ($transactions->isEmpty()) {
            $this->warn('No transactions found for this quiz-master');
        } else {
            $totalEarnings = $transactions->sum('quiz-master_share');
            $transactionCount = $transactions->count();
            $avgEarningPerTransaction = $transactionCount > 0 ? (float) $totalEarnings / $transactionCount : 0;

            $this->table(
                ['Metric', 'Value'],
                [
                    ['Total Transactions', $transactionCount],
                    ['Total Earnings (KES)', number_format((float) $totalEarnings, 2)],
                    ['Avg Earning per Transaction (KES)', number_format((float) $avgEarningPerTransaction, 2)],
                ]
            );
            $this->newLine();

            // Show recent transactions
            $this->info('Recent Transactions:');
            $recentTransactions = $transactions->sortByDesc('created_at')->take(10);
            
            $transactionData = $recentTransactions->map(function ($tx) {
                return [
                    'ID' => $tx->id,
                    'TX Ref' => $tx->tx_id,
                    'Quiz ID' => $tx->quiz_id,
                    'Amount' => number_format((float) $tx->amount, 2),
                    'Share' => number_format((float) $tx->{'quiz-master_share'}, 2),
                    'Status' => $tx->status,
                    'Date' => $tx->created_at->format('Y-m-d H:i'),
                ];
            })->toArray();

            $this->table(
                ['ID', 'TX Ref', 'Quiz ID', 'Amount', 'Share', 'Status', 'Date'],
                $transactionData
            );
        }
        $this->newLine();
    }

    /**
     * Step 6: Wallet information
     */
    private function displayWalletInformation(User $quizMaster): void
    {
        $this->info('Step 6: Wallet Information:');
        
        $wallet = Wallet::firstOrCreate(
            ['user_id' => $quizMaster->id],
            ['available' => 0, 'pending' => 0, 'lifetime_earned' => 0]
        );

        $this->table(
            ['Metric', 'Amount (KES)'],
            [
                ['Available Balance', number_format((float) $wallet->available, 2)],
                ['Pending Balance', number_format((float) $wallet->pending, 2)],
                ['Lifetime Earned', number_format((float) $wallet->lifetime_earned, 2)],
            ]
        );
        $this->newLine();
    }
}
