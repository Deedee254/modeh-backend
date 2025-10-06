<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\PaymentSetting;

class PaymentSettingsSeeder extends Seeder
{
    public function run()
    {
        // Sandbox placeholder
        PaymentSetting::updateOrCreate(['gateway' => 'mpesa_sandbox'], [
            'config' => [
                'environment' => 'sandbox',
                'consumer_key' => env('MPESA_SANDBOX_CONSUMER_KEY', 'sandbox-key'),
                'consumer_secret' => env('MPESA_SANDBOX_CONSUMER_SECRET', 'sandbox-secret'),
                'shortcode' => env('MPESA_SANDBOX_SHORTCODE', '174379'),
                'passkey' => env('MPESA_SANDBOX_PASSKEY', 'sandbox-passkey'),
                'callback_url' => env('MPESA_SANDBOX_CALLBACK', url('/api/payments/mpesa/callback')),
                // simulate STK pushes locally when sandbox credentials are not provided
                'simulate' => env('MPESA_SANDBOX_SIMULATE', true),
            ],
            'revenue_share' => 20.00,
        ]);

        // Live placeholder
        PaymentSetting::updateOrCreate(['gateway' => 'mpesa'], [
            'config' => [
                'environment' => 'live',
                'consumer_key' => env('MPESA_CONSUMER_KEY', ''),
                'consumer_secret' => env('MPESA_CONSUMER_SECRET', ''),
                'shortcode' => env('MPESA_SHORTCODE', ''),
                'passkey' => env('MPESA_PASSKEY', ''),
                'callback_url' => env('MPESA_CALLBACK', url('/api/payments/mpesa/callback')),
            ],
            'revenue_share' => 20.00,
        ]);
    }
}
