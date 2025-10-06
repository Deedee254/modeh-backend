<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Subscription;
use App\Services\MpesaService;

class RenewSubscriptions extends Command
{
    protected $signature = 'subscriptions:renew';
    protected $description = 'Process auto-renewal for subscriptions whose ends_at has passed';

    public function handle()
    {
        $this->info('Looking for subscriptions to renew...');
        $subs = Subscription::with('package','user')->where('auto_renew', true)->where('status', 'active')->whereNotNull('ends_at')->where('ends_at', '<=', now())->get();
        foreach ($subs as $sub) {
            $this->info('Attempting renew for subscription '.$sub->id.' user '.$sub->user_id);
            $service = new MpesaService();
            $amount = $sub->package->price ?? 0;
            $phone = $sub->gateway_meta['phone'] ?? ($sub->user->phone ?? null);
            if (!$phone) { $this->error('No phone for subscription '.$sub->id); continue; }
            $res = $service->initiateStkPush($phone, $amount, 'Renew-'.$sub->id);
            if ($res['ok']) {
                // mark started and push new tx
                $sub->gateway_meta = array_merge($sub->gateway_meta ?? [], ['last_renew_tx' => $res['tx'], 'renew_initiated_at' => now()]);
                $sub->save();
                $this->info('Renew initiated: '.$res['tx']);
            } else {
                $this->error('Renew failed for '.$sub->id);
            }
        }

        $this->info('Done.');
    }
}
