<?php

namespace App\Console\Commands;

use App\Models\Quiz;
use App\Models\User;
use Illuminate\Console\Command;

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

        $quizMasterId = $this->option('quiz-master-id');
        $quizMasterName = $this->option('quiz-master-name');
        $limit = $this->option('limit') ?? 50;
        $showTransactions = $this->option('show-transactions');

        // Step 1: Find the quiz-master user
        $this->info('Step 1: Finding quiz-master user...');
        
        $quizMaster = null;

        if ($quizMasterId) {
            $quizMaster = User::find($quizMasterId);
            if (!$quizMaster) {
                $this->error("Quiz-master user #{$quizMasterId} not found.");
                return 1;
            }
        } elseif ($quizMasterName) {
            $quizMaster = User::where('name', 'like', "%{$quizMasterName}%")
                ->orWhere('email', 'like', "%{$quizMasterName}%")
                ->first();
            if (!$quizMaster) {
                $this->error("Quiz-master matching '{$quizMasterName}' not found.");
                return 1;
            }
        } else {
            $this->error('Please provide either --quiz-master-id or --quiz-master-name');
            return 1;
        }

        $this->line("Found: {$quizMaster->name} (ID: {$quizMaster->id}, Email: {$quizMaster->email})");
        $this->newLine();

        // Step 2: Get all quizzes for this user
        $this->info('Step 2: Fetching quizzes...');
        
        $quizzes = Quiz::where('user_id', $quizMaster->id)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        if ($quizzes->isEmpty()) {
            $this->warn("No quizzes found for {$quizMaster->name}");
            return 0;
        }

        $this->line("Found {$quizzes->count()} quiz(zes)");
        $this->newLine();

        // Step 3: Display quizzes
        $this->info('Step 3: Quiz Details:');
        
        $quizData = $quizzes->map(function ($quiz) {
            return [
                'ID' => $quiz->id,
                'Title' => \Illuminate\Support\Str::limit($quiz->title, 50),
                'Questions' => $quiz->questions_count ?? \App\Models\Question::where('quiz_id', $quiz->id)->count(),
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

        // Step 4: Summary statistics
        $this->info('Step 4: Summary Statistics:');
        
        $totalQuestions = $quizzes->sum(fn($q) => $q->questions_count ?? \App\Models\Question::where('quiz_id', $q->id)->count());
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

        // Step 5: Show transaction data if requested
        if ($showTransactions) {
            $this->info('Step 5: Transaction Summary:');
            
            $transactions = \App\Models\Transaction::where('quiz-master_id', $quizMaster->id)
                ->get();

            if ($transactions->isEmpty()) {
                $this->warn('No transactions found for this quiz-master');
            } else {
                $totalEarnings = $transactions->sum('quiz-master_share');
                $transactionCount = $transactions->count();
                $avgEarningPerTransaction = $transactionCount > 0 ? $totalEarnings / $transactionCount : 0;

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

        // Step 6: Wallet information
        $this->info('Step 6: Wallet Information:');
        
        $wallet = \App\Models\Wallet::firstOrCreate(
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

        $this->info('Lookup completed successfully!');
        return 0;
    }
}
