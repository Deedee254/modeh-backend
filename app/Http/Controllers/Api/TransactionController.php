<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    /**
     * Get user's transaction history with pagination and filters
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'page' => 'integer|min:1',
            'per_page' => 'integer|between:10,100',
            'type' => 'string|in:subscription,one_off,renewal',
            'status' => 'string|in:draft,pending,paid,cancelled',
            'sort_by' => 'string|in:created_at,amount,status',
            'sort_order' => 'string|in:asc,desc',
        ]);

        $query = Invoice::where('user_id', auth()->id());

        // Filter by type
        if ($request->filled('type')) {
            $typeMap = [
                'subscription' => 'App\\Models\\Subscription',
                'one_off' => 'App\\Models\\OneOffPurchase',
            ];
            $query->where('invoiceable_type', $typeMap[$validated['type']] ?? null);
        }

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $validated['status']);
        }

        // Sorting
        $sortBy = $validated['sort_by'] ?? 'created_at';
        $sortOrder = $validated['sort_order'] ?? 'desc';
        $query->orderBy($sortBy, $sortOrder);

        $invoices = $query->with('invoiceable')
            ->paginate($validated['per_page'] ?? 15, ['*'], 'page', $validated['page'] ?? 1);

        return response()->json([
            'data' => $invoices->items(),
            'pagination' => [
                'total' => $invoices->total(),
                'per_page' => $invoices->perPage(),
                'current_page' => $invoices->currentPage(),
                'last_page' => $invoices->lastPage(),
                'has_more' => $invoices->hasMorePages(),
            ],
        ]);
    }

    /**
     * Get upcoming subscription renewals within N days
     */
    public function renewals(Request $request)
    {
        // Note: Laravel validation doesn't support a `default` rule.
        // Keep the field optional and apply a default after validation.
        $validated = $request->validate([
            'days_ahead' => 'integer|min:1|max:365',
        ]);

    // Ensure daysAhead is numeric (validation ensures integer-like input, but it may be a string)
    $daysAhead = isset($validated['days_ahead']) ? (int) $validated['days_ahead'] : 30;
    $cutoffDate = now()->addDays($daysAhead);

        // Get active subscriptions renewing soon based on ends_at date
        $renewals = Invoice::where('user_id', auth()->id())
            ->where('invoiceable_type', 'App\\Models\\Subscription')
            ->where('status', 'paid')
            ->with('invoiceable')
            ->get()
            ->filter(function ($invoice) use ($cutoffDate) {
                $sub = $invoice->invoiceable;
                if (!$sub || !$sub->ends_at || $sub->status !== 'active') {
                    return false;
                }
                // Show subscriptions ending between now and cutoffDate
                return $sub->ends_at <= $cutoffDate && $sub->ends_at >= now();
            })
            ->values();

        return response()->json([
            'data' => $renewals,
            'count' => count($renewals),
        ]);
    }

    /**
     * Get detailed view of a single invoice
     */
    public function show(Invoice $invoice)
    {
        // Ensure user owns this invoice
        if ($invoice->user_id !== auth()->id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        return response()->json([
            'data' => $invoice->load('invoiceable'),
        ]);
    }

    /**
     * Download invoice as PDF
     */
    public function download(Invoice $invoice)
    {
        // Ensure user owns this invoice
        if ($invoice->user_id !== auth()->id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Generate and return PDF
        try {
            $service = app(\App\Services\InvoiceService::class);
            $pdf = $service->generatePdf($invoice);

            return response()->download(
                $pdf,
                "invoice-{$invoice->invoice_number}.pdf",
                ['Content-Type' => 'application/pdf']
            );
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Could not generate PDF',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
