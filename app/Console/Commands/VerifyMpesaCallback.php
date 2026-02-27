<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class VerifyMpesaCallback extends Command
{
    protected $signature = 'mpesa:verify-callback';
    protected $description = 'Verify M-Pesa callback endpoint is accessible and configured correctly';

    public function handle()
    {
        $this->info('🔍 M-Pesa Callback Verification\n');

        // 1. Check callback URL configuration
        $callbackUrl = config('services.mpesa.callback_url');
        $this->info('1. Callback URL Configuration:');
        $this->info("   URL: {$callbackUrl}");
        
        if (filter_var($callbackUrl, FILTER_VALIDATE_URL) === false) {
            $this->error('   ❌ Invalid URL format');
            return 1;
        }
        $this->info('   ✅ Valid URL format');

        // 2. Check if HTTPS
        if (strpos($callbackUrl, 'https://') !== 0) {
            $this->error('   ❌ Must use HTTPS (not HTTP)');
            return 1;
        }
        $this->info('   ✅ Using HTTPS');

        // 3. Check if public domain (not localhost)
        if (strpos($callbackUrl, 'localhost') !== false || strpos($callbackUrl, '127.0.0.1') !== false) {
            $this->error('   ❌ Cannot use localhost - must be publicly accessible');
            return 1;
        }
        $this->info('   ✅ Using public domain');

        // 4. Test endpoint accessibility
        $this->info("\n2. Testing Endpoint Accessibility:");
        $this->info("   Testing {$callbackUrl}...");

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $callbackUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true, // Verify SSL in production
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => json_encode(['test' => 'verification']),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            $this->error("   ❌ Connection Error: {$error}");
            $this->warn('   This could indicate:');
            $this->warn('   - Domain not pointing to your server');
            $this->warn('   - SSL certificate issue');
            $this->warn('   - Server firewall blocking connections');
            return 1;
        }

        // Accept 200, 400, 405, etc. - just need to reach the server
        if ($httpCode >= 100 && $httpCode < 600) {
            $this->info("   ✅ Endpoint accessible (HTTP {$httpCode})");
        } else {
            $this->error("   ❌ Unexpected HTTP code: {$httpCode}");
            return 1;
        }

        // 5. Check CSRF exemption
        $this->info("\n3. Checking CSRF Configuration:");
        $csrfFile = app_path('Http/Middleware/VerifyCsrfToken.php');
        if (!file_exists($csrfFile)) {
            $this->error('   ⚠️  VerifyCsrfToken.php not found');
            return 1;
        }

        $content = file_get_contents($csrfFile);
        if (strpos($content, 'api/payments/mpesa/callback') !== false) {
            $this->info('   ✅ Callback URL is CSRF-exempt');
        } else {
            $this->error('   ❌ Callback URL is NOT CSRF-exempt');
            $this->error('   Add "api/payments/mpesa/callback" to VerifyCsrfToken $except array');
            return 1;
        }

        // 6. Check route exists
        $this->info("\n4. Checking Route Configuration:");
        $apiRoutesFile = base_path('routes/api.php');
        $apiRoutes = file_get_contents($apiRoutesFile);
        if (strpos($apiRoutes, 'payments/mpesa/callback') !== false) {
            $this->info('   ✅ Callback route is registered');
        } else {
            $this->error('   ❌ Callback route not found in routes/api.php');
            return 1;
        }

        // 7. Summary
        $this->info("\n" . str_repeat('=', 60));
        $this->info('✅ All Checks Passed!');
        $this->info(str_repeat('=', 60));
        $this->info("\nYour M-Pesa callback endpoint is ready to receive Safaricom notifications.");
        $this->info("\nNext Steps:");
        $this->info("1. Whitelist these Safaricom IPs in your DigitalOcean Firewall:");
        $this->info("   - 196.201.214.200, 196.201.214.206, 196.201.213.114, 196.201.214.207");
        $this->info("   - 196.201.214.208, 196.201.213.44, 196.201.212.127, 196.201.212.138");
        $this->info("   - 196.201.212.129, 196.201.212.136, 196.201.212.74, 196.201.212.69");
        $this->info("\n2. Verify firewall is applied to your droplet");
        $this->info("\n3. Run: php artisan mpesa:test-stk 0725264955 1");
        $this->info("\n4. Complete payment on phone to trigger callback");
        $this->info("\n5. Check logs: tail -f storage/logs/laravel.log");

        return 0;
    }
}
