<?php
require __DIR__ . '/../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\ChatMetricBucket;

try {
    $count = ChatMetricBucket::count();
    $min = ChatMetricBucket::orderBy('bucket','asc')->value('bucket');
    $max = ChatMetricBucket::orderBy('bucket','desc')->value('bucket');

    echo "chat_metric_buckets count: {$count}\n";
    echo "earliest bucket: " . ($min ?: 'n/a') . "\n";
    echo "latest bucket: " . ($max ?: 'n/a') . "\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
