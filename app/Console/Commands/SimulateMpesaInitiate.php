<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\User;
use App\Models\PaymentSetting;
use App\Services\MpesaService;

class SimulateMpesaInitiate extends Command
{
    protected $signature = 'simulate:mpesa {packageId=2} {userId=1} {phone?}';
    protected $description = 'Simulate creating a subscription and initiating Mpesa STK push';

    public function handle()
    {
        $packageId = $this->argument('packageId');
        $userId = $this->argument('userId');
        $phone = $this->argument('phone');

        $user = User::find($userId);
        if (!$user) { $this->error('User not found'); return 1; }

        $package = Package::find($packageId);
        if (!$package) { $this->error('Package not found'); return 1; }

        $sub = Subscription::create([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'status' => 'pending',
            'gateway' => 'mpesa',
            'gateway_meta' => ['phone' => $phone ?? $user->phone ?? null],
        ]);

    // Prefer sandbox settings for simulation when available
    $setting = PaymentSetting::where('gateway', 'mpesa_sandbox')->first() ?: PaymentSetting::where('gateway', 'mpesa')->first();
        $config = $setting ? ($setting->config ?? []) : [];
        $service = new MpesaService($config);
        $amount = (float) ($package->price ?? 0);

        // Determine a fallback phone: explicit arg > subscription meta > user.phone > payment setting test phone > env > placeholder
        $phone = $phone ?? ($sub->gateway_meta['phone'] ?? null) ?? $user->phone ?? ($config['test_phone'] ?? null) ?? env('MPESA_TEST_PHONE', null);
        if (!$phone) {
            $this->warn('No phone number available; using default sandbox number 254700000000 for simulation');
            $phone = '254700000000';
        }

        // Ensure phone and amount types are correct
        $phone = (string) $phone;
        $amount = (float) $amount;

        $res = $service->initiateStkPush($phone, $amount, 'Subscription-'.$sub->id);

        // Persist tx into subscription gateway_meta when available
        if (!empty($res['ok']) && !empty($res['tx'])) {
            $meta = $sub->gateway_meta ?? [];
            $meta['tx'] = $res['tx'];
            $meta['initiated_at'] = now();
            $sub->gateway_meta = $meta;
            $sub->save();
        }

        $this->info('Simulate result: '.json_encode($res));
        return 0;
    }
}
