<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\MpesaTransaction;
use Illuminate\Support\Facades\Auth;
use App\Services\MpesaService;
use Illuminate\Support\Facades\Log;

class PackageController extends Controller
{
    public function index()
    {
        $audience = request()->input('audience', null);
        $q = Package::where('is_active', true)->orderBy('price');
        if ($audience) {
            $q = $q->where('audience', $audience);
        }
        $packages = $q->get()->map(function ($p) {
            return [
                'id' => $p->id,
                'title' => $p->title,
                'name' => $p->name,
                'description' => $p->description,
                'short_description' => $p->short_description,
                'price' => $p->price,
                'price_display' => $p->price_display,
                'currency' => $p->currency,
                'features' => $p->features ?? [],
                'duration_days' => $p->duration_days,
                'cover_image' => $p->cover_image,
                'more_link' => $p->more_link,
                'is_active' => (bool) $p->is_active,
                'slug' => $p->slug,
                'audience' => $p->audience ?? 'quizee',
            ];
        });
        return response()->json(['packages' => $packages]);
    }

    public function subscribe(Request $request, Package $package)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['ok' => false, 'message' => 'Unauthenticated'], 401);
        }

        // Resolve subscription owner (user or institution)
        $ownerResult = $this->resolveSubscriptionOwner($request, $user);
        if (isset($ownerResult['error'])) {
            return response()->json($ownerResult['error'], $ownerResult['status']);
        }

        [$ownerType, $ownerId] = $ownerResult;

        // Validate package audience matches owner type
        $audienceValidation = $this->validatePackageAudience($package, $ownerType);
        if ($audienceValidation !== true) {
            return response()->json($audienceValidation, 422);
        }

        // Cancel any existing active subscription
        $previousSubscription = $this->cancelExistingSubscription($ownerType, $ownerId);

        $gateway = $request->input('gateway', 'mpesa');
        $packagePrice = $package->price ?? 0;

        // Handle free packages
        if ((float)$packagePrice === 0.0 || $gateway === 'free') {
            return $this->handleFreeSubscription($user, $ownerType, $ownerId, $package, $request, $previousSubscription);
        }

        // Handle paid packages
        if ($gateway === 'mpesa') {
            return $this->handleMpesaSubscription($user, $ownerType, $ownerId, $package, $request, $previousSubscription);
        }

        // Handle other gateways
        return $this->handleOtherGatewaySubscription($user, $ownerType, $ownerId, $package, $request, $previousSubscription, $gateway);
    }

    /**
     * Resolve the subscription owner (user or institution)
     * 
     * @return array [string $ownerType, int $ownerId] or ['error' => array, 'status' => int]
     */
    private function resolveSubscriptionOwner(Request $request, $user): array
    {
        $ownerType = \App\Models\User::class;
        $ownerId = $user->id;

        $requestOwnerType = $request->input('owner_type');
        if ($requestOwnerType === 'institution' || ($requestOwnerType && \str_contains($requestOwnerType, 'Institution'))) {
            $institutionId = $request->input('owner_id');
            if (!$institutionId) {
                return [
                    'error' => ['ok' => false, 'message' => 'owner_id (institution id) required for institution subscription'],
                    'status' => 422
                ];
            }

            // Find institution by ID or slug
            $instQuery = \App\Models\Institution::query();
            if (\ctype_digit(\strval($institutionId))) {
                $instQuery->where('id', (int)$institutionId);
            } else {
                $instQuery->where('slug', $institutionId);
            }
            
            $institution = $instQuery->first();
            if (!$institution) {
                return [
                    'error' => ['ok' => false, 'message' => 'Institution not found'],
                    'status' => 404
                ];
            }

            // Verify user is institution manager
            $isManager = $institution->users()
                ->where('users.id', $user->id)
                ->wherePivot('role', 'institution-manager')
                ->exists();
            
            if (!$isManager) {
                return [
                    'error' => ['ok' => false, 'message' => 'Forbidden: must be an institution-manager to subscribe on behalf of institution'],
                    'status' => 403
                ];
            }

            $ownerType = \App\Models\Institution::class;
            $ownerId = $institution->id;
        }

        return [$ownerType, $ownerId];
    }

    /**
     * Validate package audience matches owner type
     */
    private function validatePackageAudience(Package $package, string $ownerType)
    {
        $packageAudience = $package->audience ?? 'quizee';
        
        if ($ownerType === \App\Models\Institution::class && $packageAudience !== 'institution') {
            return ['ok' => false, 'message' => 'Package is not available for institutions'];
        }
        
        if ($ownerType === \App\Models\User::class && $packageAudience !== 'quizee') {
            return ['ok' => false, 'message' => 'Package is not available for users'];
        }

        return true;
    }

    /**
     * Cancel existing active subscription for the owner
     */
    private function cancelExistingSubscription(string $ownerType, int $ownerId): ?Subscription
    {
        $previous = Subscription::where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->where('status', 'active')
            ->orderByDesc('started_at')
            ->first();

        if ($previous) {
            try {
                $previous->update([
                    'status' => 'cancelled',
                    'ends_at' => now()
                ]);
            } catch (\Throwable $_) {
                // Ignore errors when cancelling previous subscription
            }
        }

        return $previous;
    }

    /**
     * Handle free subscription creation
     */
    private function handleFreeSubscription($user, string $ownerType, int $ownerId, Package $package, Request $request, ?Subscription $previousSubscription)
    {
        $subscription = Subscription::create([
            'user_id' => $user->id,
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'package_id' => $package->id,
            'status' => 'active',
            'gateway' => 'free',
            'gateway_meta' => ['phone' => $request->input('phone') ?? $user->phone ?? null],
            'started_at' => now(),
            'ends_at' => $package->duration_days ? now()->addDays($package->duration_days) : null,
        ]);

        // Create and process invoice for free subscription
        $this->createFreeSubscriptionInvoice($subscription, $package);

        return response()->json([
            'ok' => true,
            'subscription' => $subscription,
            'previous_subscription' => $previousSubscription,
            'package' => $this->formatPackageResponse($package)
        ]);
    }

    /**
     * Create invoice for free subscription
     */
    private function createFreeSubscriptionInvoice(Subscription $subscription, Package $package): void
    {
        try {
            $invoiceService = app(\App\Services\InvoiceService::class);
            $invoice = $invoiceService->createForSubscription(
                $subscription,
                "Subscription: {$package->name} (Free)"
            );
            
            $invoice->update(['status' => 'paid', 'paid_at' => now()]);
            $subscription->user->notify(new \App\Notifications\InvoiceGeneratedNotification($invoice));
            
            Log::info('[Payment] Free subscription invoice created and email sent', [
                'invoice_id' => $invoice->id,
                'subscription_id' => $subscription->id,
                'user_id' => $subscription->user_id,
            ]);
        } catch (\Throwable $e) {
            Log::error('[Payment] Free subscription invoice creation failed', [
                'subscription_id' => $subscription->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle M-Pesa subscription payment
     */
    private function handleMpesaSubscription($user, string $ownerType, int $ownerId, Package $package, Request $request, ?Subscription $previousSubscription)
    {
        $phone = $request->input('phone') ?? $user->phone ?? null;
        
        if (!$phone || !\is_string($phone) || \trim($phone) === '') {
            return response()->json([
                'ok' => false,
                'require_phone' => true,
                'message' => 'Phone number required for mpesa payments',
                'package' => $this->formatPackageResponse($package),
            ], 422);
        }

        // Validate M-Pesa configuration
        $configValidation = $this->validateMpesaConfig();
        if ($configValidation !== true) {
            return response()->json($configValidation, 500);
        }

        try {
            $config = config('services.mpesa');
            $service = new MpesaService($config);
            $amount = $package->price ?? 0;
            
            Log::info('[Payment] Initiating MPESA STK push', [
                'user_id' => $user->id,
                'package_id' => $package->id,
                'phone' => $phone,
                'amount' => $amount,
            ]);
            
            $result = $service->initiateStkPush($phone, $amount, 'Subscription-temp');
            
            if ($result['ok']) {
                $checkoutRequestId = $result['tx'];  // M-PESA's CheckoutRequestID
                
                $subscription = Subscription::create([
                    'user_id' => $user->id,
                    'owner_type' => $ownerType,
                    'owner_id' => $ownerId,
                    'package_id' => $package->id,
                    'status' => 'pending',
                    'gateway' => 'mpesa',
                    'gateway_meta' => [
                        'phone' => $phone,
                        'tx' => $checkoutRequestId,
                        'checkout_request_id' => $checkoutRequestId,
                        'initiated_at' => now()
                    ],
                ]);
                
                // Create MpesaTransaction record for reconciliation
                MpesaTransaction::create([
                    'user_id' => $user->id,
                    'checkout_request_id' => $checkoutRequestId,
                    'merchant_request_id' => $result['body']['MerchantRequestID'] ?? null,
                    'amount' => $amount,
                    'phone' => $phone,
                    'status' => 'pending',
                    'billable_type' => Subscription::class,
                    'billable_id' => $subscription->id,
                    'raw_response' => json_encode($result['body'] ?? []),
                ]);
                
                Log::info('[Payment] STK push initiated successfully', [
                    'subscription_id' => $subscription->id,
                    'user_id' => $user->id,
                    'checkout_request_id' => $checkoutRequestId,
                    'tx' => $checkoutRequestId,
                    'phone' => $phone,
                ]);
                
                return response()->json([
                    'ok' => true,
                    'subscription' => $subscription,
                    'tx' => $checkoutRequestId,
                    'checkout_request_id' => $checkoutRequestId,  // CheckoutRequestID from M-PESA
                    'message' => $result['message'] ?? null,
                    'previous_subscription' => $previousSubscription,
                    'package' => $this->formatPackageResponse($package),
                ]);
            }

            Log::error('[Payment] STK push failed', [
                'user_id' => $user->id,
                'package_id' => $package->id,
                'phone' => $phone,
                'error' => $result['message'] ?? 'unknown error',
                'response' => $result,
            ]);

            return response()->json([
                'ok' => false,
                'message' => $result['message'] ?? 'failed to initiate mpesa',
                'package' => $this->formatPackageResponse($package),
            ], 500);

        } catch (\Throwable $e) {
            Log::error('Mpesa initiate error: ' . $e->getMessage());
            return response()->json([
                'ok' => false,
                'message' => 'mpesa initiation error: ' . $e->getMessage(),
                'package' => $this->formatPackageResponse($package),
            ], 500);
        }
    }

    /**
     * Validate M-Pesa configuration
     */
    private function validateMpesaConfig()
    {
        $config = config('services.mpesa');
        $requiredKeys = ['consumer_key', 'consumer_secret', 'shortcode', 'passkey', 'callback_url'];
        
        $missing = [];
        foreach ($requiredKeys as $key) {
            if (empty($config[$key])) {
                $missing[] = $key;
            }
        }
        
        if (!empty($missing)) {
            Log::error('MpesaService: missing config keys: ' . \implode(',', $missing));
            return [
                'ok' => false,
                'code' => 'gateway_not_configured',
                'message' => 'Payment gateway not configured',
                'missing' => $missing,
            ];
        }

        return true;
    }

    /**
     * Handle other gateway subscriptions
     */
    private function handleOtherGatewaySubscription($user, string $ownerType, int $ownerId, Package $package, Request $request, ?Subscription $previousSubscription, string $gateway)
    {
        $subscription = Subscription::create([
            'user_id' => $user->id,
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'package_id' => $package->id,
            'status' => 'pending',
            'gateway' => $gateway,
            'gateway_meta' => ['phone' => $request->input('phone') ?? $user->phone ?? null],
        ]);

        return response()->json([
            'ok' => true,
            'subscription' => $subscription,
            'previous_subscription' => $previousSubscription,
            'package' => $this->formatPackageResponse($package),
        ]);
    }

    /**
     * Format package data for API response
     */
    private function formatPackageResponse(Package $package): array
    {
        return [
            'id' => $package->id,
            'title' => $package->title,
            'features' => $package->features ?? [],
        ];
    }

    /**
     * Renew an existing subscription
     * Instead of creating a new subscription, extend the existing one's ends_at date
     * POST /api/subscriptions/{subscription}/renew
     */
    public function renew(Request $request, Subscription $subscription)
    {
        $user = Auth::user();
        if (!$user || $subscription->user_id !== $user->id) {
            return response()->json(['ok' => false, 'message' => 'Unauthorized'], 403);
        }

        $package = $subscription->package;
        if (!$package) {
            return response()->json(['ok' => false, 'message' => 'Package not found'], 404);
        }

        $pkgPrice = (float)$package->price;
        if ($pkgPrice === 0.0) {
            // Free renewal: just extend the ends_at date
            $days = $package->duration_days ?? 30;
            $subscription->ends_at = \Carbon\Carbon::make($subscription->ends_at)->addDays($days);
            $subscription->save();

            Log::info('[Renewal] Free subscription renewed', [
                'subscription_id' => $subscription->id,
                'user_id' => $user->id,
                'new_ends_at' => $subscription->ends_at,
            ]);

            return response()->json(['ok' => true, 'subscription' => $subscription]);
        }

        // Paid renewal: create renewal invoice and initiate payment
        $phone = $request->phone ?? ($subscription->gateway_meta['phone'] ?? null) ?? ($user->phone ?? null);
        $gw = $subscription->gateway;

        if ($gw === 'mpesa') {
            // Validate M-PESA config
            $configCheck = $this->validateMpesaConfig();
            if ($configCheck !== true) {
                return response()->json($configCheck, 422);
            }

            // Initiate M-PESA payment for renewal
            $service = new MpesaService(config('services.mpesa'));
            $amount = $package->price ?? 0;
            $res = $service->initiateStkPush($phone, $amount, 'Renewal-'.$subscription->id);

            if ($res['ok']) {
                // Store renewal metadata
                $renewalMeta = [
                    'renewal_initiated_at' => now(),
                    'renewal_tx' => $res['tx'],
                    'renewal_original_ends_at' => $subscription->ends_at,
                ];

                $subscription->gateway_meta = array_merge($subscription->gateway_meta ?? [], $renewalMeta);
                $subscription->save();

                MpesaTransaction::create([
                    'user_id' => $user->id,
                    'checkout_request_id' => $res['tx'],
                    'merchant_request_id' => $res['body']['MerchantRequestID'] ?? null,
                    'amount' => $amount,
                    'phone' => $phone,
                    'status' => 'pending',
                    'billable_type' => Subscription::class,
                    'billable_id' => $subscription->id,
                    'raw_response' => json_encode($res['body'] ?? []),
                ]);

                Log::info('[Renewal] M-PESA renewal initiated', [
                    'subscription_id' => $subscription->id,
                    'user_id' => $user->id,
                    'tx' => $res['tx'],
                    'amount' => $amount,
                ]);

                return response()->json(['ok' => true, 'tx' => $res['tx'], 'message' => $res['message']]);
            }

            return response()->json(['ok' => false, 'message' => 'Failed to initiate renewal payment'], 500);
        }

        return response()->json(['ok' => false, 'message' => 'Gateway not supported for renewal'], 422);
    }
}
