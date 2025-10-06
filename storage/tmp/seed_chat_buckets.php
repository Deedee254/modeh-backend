<?php
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

for ($i = 9; $i >= 0; $i--) {
    $b = now()->subMinutes($i)->format('YmdHi');
    \App\Models\ChatMetricBucket::updateOrCreate(
        ['metric_key' => 'messages_per_minute', 'bucket' => $b],
        ['value' => rand(0, 10), 'last_updated_at' => now()]
    );
}

echo "seeded\n";
