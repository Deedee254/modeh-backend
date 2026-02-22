<?php

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

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
        
        // Trim whitespace from string config values
        foreach (['consumer_key', 'consumer_secret', 'shortcode', 'passkey', 'callback_url'] as $key) {
            if (is_string($this->config[$key])) {
                $this->config[$key] = trim($this->config[$key]);
            }
        }
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
        // Try cache first (recommended to reduce token generation requests)
        $cacheKey = 'mpesa.token';
        $cached = Cache::get($cacheKey);
        if (!empty($cached)) {
            return $cached;
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
                $expiresIn = (int)($body['expires_in'] ?? 3500);
                $this->tokenExpiresAt = now()->addSeconds($expiresIn);

                // Store in cache with a small safety margin (30s)
                $ttl = max(30, $expiresIn - 30);
                try {
                    Cache::put($cacheKey, $this->token, $ttl);
                } catch (\Throwable $e) {
                    // Cache may not be available in some environments; ignore safely
                    Log::warning('MpesaService: failed to cache token: '.$e->getMessage());
                }

                return $this->token;
            }
        } catch (\Exception $e) {
            Log::error('MpesaService token error: '.$e->getMessage());
        }
        return null;
    }

    /**
     * Normalize Kenyan phone numbers to 254XXXXXXXXX format.
     * Returns null when the number is invalid.
     */
    public function normalizePhone(?string $phone): ?string
    {
        if (!is_string($phone) || trim($phone) === '') {
            return null;
        }

        $p = preg_replace('/\D+/', '', trim($phone));
        if (!$p) {
            return null;
        }

        if (str_starts_with($p, '0') && strlen($p) === 10) {
            $p = '254' . substr($p, 1);
        } elseif ((str_starts_with($p, '7') || str_starts_with($p, '1')) && strlen($p) === 9) {
            $p = '254' . $p;
        }

        if (!preg_match('/^254(7|1)\d{8}$/', $p)) {
            return null;
        }

        return $p;
    }

    public function initiateStkPush(string $phone, float $amount, ?string $accountRef = null, ?string $traceId = null): array
    {
        // Build and send STK push to Daraja (sandbox or live depending on config)

        $token = $this->getToken();
        if (!$token) return ['ok' => false, 'message' => 'failed to obtain oauth token'];

        $shortcode = $this->config['shortcode'] ?? null;
        $passkey = $this->config['passkey'] ?? null;
        $callback = $this->config['callback_url'] ?? null;

        if (!$shortcode || !$passkey) {
            return ['ok' => false, 'message' => 'shortcode or passkey not configured'];
        }

        if (!$callback || !is_string($callback) || trim($callback) === '') {
            return ['ok' => false, 'message' => 'callback_url not configured'];
        }

        $timestamp = now()->format('YmdHis');
        $password = base64_encode($shortcode.$passkey.$timestamp);

        $normalizedPhone = $this->normalizePhone($phone);
        if (!$normalizedPhone) {
            return ['ok' => false, 'message' => 'Invalid phone number. Use 2547XXXXXXXX or 07XXXXXXXX'];
        }

        $ref = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($accountRef ?? 'Subscription'));
        $ref = substr($ref !== '' ? $ref : 'Subscription', 0, 20);

        $payload = [
            'BusinessShortCode' => $shortcode,
            'Password' => $password,
            'Timestamp' => $timestamp,
            'TransactionType' => 'CustomerPayBillOnline',
            'Amount' => max(1, (int) ceil($amount)),
            'PartyA' => $normalizedPhone,
            'PartyB' => $shortcode,
            'PhoneNumber' => $normalizedPhone,
            'CallBackURL' => $callback,
            'AccountReference' => $ref,
            'TransactionDesc' => 'Subscription payment',
        ];

        try {
            Log::info('[MPESA] STK Push initiated', [
                'trace_id' => $traceId,
                'phone' => $normalizedPhone,
                'amount' => $amount,
                'account_ref' => $ref,
            ]);

            // Temporary debug: log outgoing payload without secrets (do not log Password)
            $payloadToLog = $payload;
            if (isset($payloadToLog['Password'])) unset($payloadToLog['Password']);
            Log::debug('[MPESA] Outgoing STK payload (safe)', $payloadToLog);

            $client = $this->httpClient();
            $res = $client->request('POST', '/mpesa/stkpush/v1/processrequest', [
                'headers' => ['Authorization' => 'Bearer '.$token, 'Content-Type' => 'application/json'],
                'json' => $payload,
            ]);
            $rawBody = (string)$res->getBody();
            // Log raw response for troubleshooting
            Log::debug('[MPESA] Raw STK response body', ['body' => $rawBody]);
            $body = json_decode($rawBody, true);
            
            // successful response contains CheckoutRequestID and ResponseCode 0 (can be int or string)
            $responseCode = $body['ResponseCode'] ?? null;
            if ($responseCode !== null && ((int)$responseCode === 0 || $responseCode === '0')) {
                $tx = $body['CheckoutRequestID'] ?? ($body['MerchantRequestID'] ?? null);
                Log::info('[MPESA] STK Push successful', [
                    'trace_id' => $traceId,
                    'phone' => $normalizedPhone,
                    'tx' => $tx,
                    'response' => $body,
                ]);
                return ['ok' => true, 'tx' => $tx, 'body' => $body];
            }
            
            $errorMsg = $body['errorMessage'] ?? json_encode($body);
            Log::warning('[MPESA] STK Push failed', [
                'trace_id' => $traceId,
                'phone' => $normalizedPhone,
                'response_code' => $body['ResponseCode'] ?? 'unknown',
                'error_message' => $errorMsg,
                'full_response' => $body,
            ]);
            return ['ok' => false, 'message' => $errorMsg, 'body' => $body];
        } catch (\Exception $e) {
            Log::error('[MPESA] STK Push exception', [
                'trace_id' => $traceId,
                'phone' => $normalizedPhone,
                'amount' => $amount,
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Query the status of a previously initiated STK Push.
     * Used for reconciliation when callback was missed or for manual status checks.
     *
     * @param string $checkoutRequestId The CheckoutRequestID from the initial STK Push response
     * @return array Normalized response: ['ok' => bool, 'status' => 'success|pending|failed', 'result_code' => int|null, ...]
     */
    public function queryStkPush(string $checkoutRequestId): array
    {
        if (empty(trim($checkoutRequestId))) {
            return ['ok' => false, 'message' => 'checkoutRequestId is required'];
        }

        $token = $this->getToken();
        if (!$token) {
            return ['ok' => false, 'message' => 'failed to obtain oauth token'];
        }

        $shortcode = $this->config['shortcode'] ?? null;
        $passkey = $this->config['passkey'] ?? null;

        if (!$shortcode || !$passkey) {
            return ['ok' => false, 'message' => 'shortcode or passkey not configured'];
        }

        $timestamp = now()->format('YmdHis');
        $password = base64_encode($shortcode . $passkey . $timestamp);

        $payload = [
            'BusinessShortCode' => $shortcode,
            'Password' => $password,
            'Timestamp' => $timestamp,
            'CheckoutRequestID' => $checkoutRequestId,
        ];

        try {
            Log::info('[MPESA] STK Push Query initiated', [
                'checkout_request_id' => $checkoutRequestId,
            ]);

            // Temporary debug: log outgoing query payload without secrets
            $payloadToLog = $payload;
            if (isset($payloadToLog['Password'])) unset($payloadToLog['Password']);
            Log::debug('[MPESA] Outgoing STK query payload (safe)', $payloadToLog);

            $client = $this->httpClient();
            $res = $client->request('POST', '/mpesa/stkpushquery/v1/query', [
                'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
                'json' => $payload,
            ]);

            $rawBody = (string)$res->getBody();
            // Log raw response from Daraja for troubleshooting
            Log::debug('[MPESA] Raw STK query response body', ['body' => $rawBody]);
            $body = json_decode($rawBody, true);

            // Check HTTP status and basic response structure
            if ($res->getStatusCode() !== 200) {
                Log::warning('[MPESA] STK Push Query HTTP error', [
                    'checkout_request_id' => $checkoutRequestId,
                    'status_code' => $res->getStatusCode(),
                    'body' => $body,
                ]);
                return ['ok' => false, 'message' => 'Daraja HTTP error', 'status_code' => $res->getStatusCode(), 'body' => $body];
            }

            // Check ResponseCode (should be 0 for accepted)
            $responseCode = $body['ResponseCode'] ?? null;
            if ($responseCode === null || (int) $responseCode !== 0) {
                Log::warning('[MPESA] STK Push Query failed response code', [
                    'checkout_request_id' => $checkoutRequestId,
                    'response_code' => $responseCode ?? 'unknown',
                    'response_description' => $body['ResponseDescription'] ?? '',
                ]);
                return ['ok' => false, 'message' => $body['ResponseDescription'] ?? 'Query failed', 'body' => $body];
            }

            // Normalize status based on ResultCode
            $resultCode = $body['ResultCode'] ?? null;
            $status = 'pending'; // default
            $resultDesc = $body['ResultDesc'] ?? '';

            if ($resultCode === '0' || $resultCode === 0) {
                $status = 'success';
            } elseif ($resultCode === '1032' || $resultCode === 1032) {
                // User cancelled on phone.
                $status = 'cancelled';
            } elseif ($resultCode !== null && $resultCode !== '') {
                // Non-zero result code means failed or cancelled
                $status = 'failed';
            }
            // else: no result code yet, still pending

            Log::info('[MPESA] STK Push Query result', [
                'checkout_request_id' => $checkoutRequestId,
                'status' => $status,
                'result_code' => $resultCode,
                'result_desc' => $resultDesc,
            ]);

            return [
                'ok' => true,
                'status' => $status,
                'result_code' => $resultCode,
                'result_desc' => $resultDesc,
                'mpesa_receipt' => $body['MpesaReceiptNumber'] ?? ($body['ReceiptNumber'] ?? null),
                'amount' => $body['Amount'] ?? null,
                'phone' => $body['PhoneNumber'] ?? ($body['MSISDN'] ?? null),
                'transaction_date' => $body['TransactionDate'] ?? null,
                'merchant_request_id' => $body['MerchantRequestID'] ?? null,
                'checkout_request_id' => $body['CheckoutRequestID'] ?? $checkoutRequestId,
                'raw' => $body,
            ];
        } catch (\Exception $e) {
            Log::error('[MPESA] STK Push Query exception', [
                'checkout_request_id' => $checkoutRequestId,
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }
}
