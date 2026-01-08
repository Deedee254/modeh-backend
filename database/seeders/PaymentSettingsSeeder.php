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
            'revenue_share' => 20.00,
        ]);

        // Live placeholder
        PaymentSetting::updateOrCreate(['gateway' => 'mpesa'], [
            'revenue_share' => 20.00,
        ]);
    }
}
