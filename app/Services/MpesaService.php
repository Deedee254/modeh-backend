<?php

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

class MpesaService
{
    protected $config;
    protected $token;
    protected $tokenExpiresAt;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'environment' => 'sandbox', // or 'live'
            'consumer_key' => null,
            'consumer_secret' => null,
            'shortcode' => null,
            'passkey' => null,
            'callback_url' => null,
        ], $config);
    }

    protected function baseUrl(): string
    {
        return $this->config['environment'] === 'live'
            ? 'https://api.safaricom.co.ke'
            : 'https://sandbox.safaricom.co.ke';
    }

    protected function httpClient(array $opts = [])
    {
        return new Client(array_merge(['base_uri' => $this->baseUrl(), 'timeout' => 10], $opts));
    }

    protected function getToken(): ?string
    {
        if ($this->token && $this->tokenExpiresAt && now()->lt($this->tokenExpiresAt)) {
            return $this->token;
        }

        $client = $this->httpClient();
        $key = $this->config['consumer_key'];
        $secret = $this->config['consumer_secret'];
        if (!$key || !$secret) {
            Log::error('MpesaService: consumer credentials missing');
            return null;
        }

        try {
            $res = $client->request('GET', '/oauth/v1/generate?grant_type=client_credentials', [
                'auth' => [$key, $secret],
            ]);
            $body = json_decode((string)$res->getBody(), true);
            if (!empty($body['access_token'])) {
                $this->token = $body['access_token'];
                // token typically valid for 3600s
                $this->tokenExpiresAt = now()->addSeconds($body['expires_in'] ?? 3500);
                return $this->token;
            }
        } catch (\Exception $e) {
            Log::error('MpesaService token error: '.$e->getMessage());
        }
        return null;
    }

    protected function formatPhone(string $phone): string
    {
        $p = trim($phone);
        // normalize leading 0 to 254
        if (preg_match('/^0/', $p)) {
            $p = '254'.ltrim($p, '0');
        }
        // if starts with + remove +
        if (strpos($p, '+') === 0) $p = substr($p, 1);
        return $p;
    }

    public function initiateStkPush(string $phone, float $amount, ?string $accountRef = null): array
    {
        // If simulation flag set in config, return a fake successful response (useful for local dev)
        if (!empty($this->config['simulate'])) {
            return [
                'ok' => true,
                'tx' => 'SIMULATED-'.uniqid(),
                'message' => 'simulated stk push (sandbox)'
            ];
        }

        $token = $this->getToken();
        if (!$token) return ['ok' => false, 'message' => 'failed to obtain oauth token'];

        $shortcode = $this->config['shortcode'] ?? null;
        $passkey = $this->config['passkey'] ?? null;
        $callback = $this->config['callback_url'] ?? null;

        if (!$shortcode || !$passkey) {
            return ['ok' => false, 'message' => 'shortcode or passkey not configured'];
        }

        $timestamp = now()->format('YmdHis');
        $password = base64_encode($shortcode.$passkey.$timestamp);

        $phone = $this->formatPhone($phone);

        $payload = [
            'BusinessShortCode' => $shortcode,
            'Password' => $password,
            'Timestamp' => $timestamp,
            'TransactionType' => 'CustomerPayBillOnline',
            'Amount' => (int)ceil($amount),
            'PartyA' => $phone,
            'PartyB' => $shortcode,
            'PhoneNumber' => $phone,
            'CallBackURL' => $callback ?? ($this->baseUrl().'/mpesa/callback'),
            'AccountReference' => $accountRef ?? 'Subscription',
            'TransactionDesc' => 'Subscription payment',
        ];

        try {
            $client = $this->httpClient();
            $res = $client->request('POST', '/mpesa/stkpush/v1/processrequest', [
                'headers' => ['Authorization' => 'Bearer '.$token, 'Content-Type' => 'application/json'],
                'json' => $payload,
            ]);
            $body = json_decode((string)$res->getBody(), true);
            // successful response contains CheckoutRequestID and ResponseCode 0
            if (!empty($body['ResponseCode']) && $body['ResponseCode'] == '0') {
                $tx = $body['CheckoutRequestID'] ?? ($body['MerchantRequestID'] ?? null);
                return ['ok' => true, 'tx' => $tx, 'body' => $body];
            }
            return ['ok' => false, 'message' => $body['errorMessage'] ?? json_encode($body), 'body' => $body];
        } catch (\Exception $e) {
            Log::error('Mpesa STK error: '.$e->getMessage());
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }
}
