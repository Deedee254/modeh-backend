<?php
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$controller = app()->make(\App\Http\Controllers\Api\EchoMonitoringController::class);
$response = $controller->stats(new \Illuminate\Http\Request());
if ($response instanceof \Illuminate\Http\JsonResponse) {
    echo $response->getContent() . "\n";
} else {
    var_dump($response);
}
