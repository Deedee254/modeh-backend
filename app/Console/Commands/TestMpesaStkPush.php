<?php

namespace App\Console\Commands;

use App\Services\MpesaService;
use Illuminate\Console\Command;

class TestMpesaStkPush extends Command
{
    protected $signature = 'mpesa:test-stk {phone} {amount}';
    protected $description = 'Test STK push to a phone number';

    public function handle()
    {
        $phone = $this->argument('phone');
        $amount = $this->argument('amount');

        $this->info("Testing STK push to {$phone} for amount {$amount}...");

        try {
            $mpesa = new MpesaService(config('services.mpesa'));
            $result = $mpesa->initiateStkPush($phone, $amount);

            if (!$result['ok']) {
                $this->error("❌ FAILED!");
                $this->error("Error: " . ($result['message'] ?? 'Unknown error'));
                return 1;
            }

            $body = $result['body'] ?? [];

            $this->info("\n✅ SUCCESS!");
            $this->info("Transaction ID: {$result['tx']}");
            $this->info("Merchant Request ID: " . ($body['MerchantRequestID'] ?? 'N/A'));
            $this->info("Response Code: " . ($body['ResponseCode'] ?? '0'));
            $this->info("Response Description: " . ($body['ResponseDescription'] ?? 'Success'));
            if (isset($body['CustomerMessage'])) {
                $this->info("Customer Message: {$body['CustomerMessage']}");
            }
        } catch (\Exception $e) {
            $this->error("❌ FAILED!");
            $this->error("Error: " . $e->getMessage());
            return 1;
        }

        return 0;
    }
}
