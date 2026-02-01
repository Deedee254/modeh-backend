<?php

namespace App\Console\Commands;

use App\Jobs\ReconsileMpesaTransaction;
use App\Models\MpesaTransaction;
use Illuminate\Console\Command;

class ProcessMpesaRetries extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mpesa:retry {--limit=10 : Max transactions to process}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process pending M-PESA transactions that are due for retry';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $limit = (int)$this->option('limit');

        // Find pending transactions ready for retry
        $transactions = MpesaTransaction::pendingRetry()
            ->limit($limit)
            ->get();

        if ($transactions->isEmpty()) {
            $this->info('No pending M-PESA transactions to retry.');
            return 0;
        }

        $this->info("Found {$transactions->count()} pending transaction(s) to retry.");

        foreach ($transactions as $transaction) {
            $this->info("Dispatching retry for transaction {$transaction->id} (checkout: {$transaction->checkout_request_id})");
            ReconsileMpesaTransaction::dispatch($transaction->id);
        }

        $this->info('All retry jobs dispatched.');
        return 0;
    }
}
