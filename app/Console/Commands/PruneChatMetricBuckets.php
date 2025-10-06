<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Models\ChatMetricsSetting;

class PruneChatMetricBuckets extends Command
{
    protected $signature = 'metrics:prune-buckets {--days=30 : Number of days to retain}';
    protected $description = 'Prune chat_metric_buckets older than the given number of days';

    public function handle()
    {
        $daysOption = $this->option('days');
        $days = null;

        if ($daysOption !== null) {
            $days = intval($daysOption);
        }

        if (empty($days) || $days < 1) {
            // try reading from persisted setting
            try {
                $setting = ChatMetricsSetting::first();
                if ($setting && intval($setting->retention_days) > 0) {
                    $days = intval($setting->retention_days);
                }
            } catch (\Throwable $e) {
                // ignore and fallback to default
            }
        }

        if (empty($days) || $days < 1) {
            $days = 30;
        }

        $cutoff = Carbon::now()->subDays($days)->format('YmdHi');

        // delete buckets where bucket < cutoff
        $deleted = DB::table('chat_metric_buckets')->where('bucket', '<', $cutoff)->delete();

        $this->info("Pruned {$deleted} chat_metric_buckets older than {$days} days (cutoff: {$cutoff})");

        return 0;
    }
}
