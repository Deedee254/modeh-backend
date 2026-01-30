<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice {{ $invoice->invoice_number }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; color: #333; background: #f5f5f5; }
        .container { max-width: 900px; margin: 20px auto; background: white; padding: 40px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        
        .header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 3px solid #007bff; padding-bottom: 30px; margin-bottom: 30px; }
        .company-info h1 { color: #007bff; margin-bottom: 5px; }
        .company-info p { color: #666; font-size: 14px; }
        
        .invoice-details { text-align: right; }
        .invoice-details h2 { font-size: 28px; color: #333; margin-bottom: 15px; }
        .invoice-details p { margin: 5px 0; font-size: 13px; color: #666; }
        .invoice-details .status { 
            display: inline-block; 
            padding: 8px 16px; 
            border-radius: 4px; 
            font-weight: bold; 
            margin-top: 10px;
            font-size: 14px;
        }
        .status.paid { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .status.pending { background: #fff3cd; color: #856404; border: 1px solid #ffeeba; }
        .status.cancelled { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        
        .bill-to { margin-bottom: 40px; }
        .bill-to h3 { font-size: 14px; font-weight: 600; color: #666; margin-bottom: 10px; text-transform: uppercase; }
        .bill-to p { margin: 3px 0; font-size: 14px; }
        
        .items-table { width: 100%; border-collapse: collapse; margin: 30px 0; }
        .items-table thead { background: #f8f9fa; }
        .items-table th { 
            padding: 15px; 
            text-align: left; 
            font-weight: 600; 
            color: #333; 
            border-bottom: 2px solid #dee2e6;
            font-size: 13px;
            text-transform: uppercase;
        }
        .items-table td { 
            padding: 15px; 
            border-bottom: 1px solid #dee2e6;
            font-size: 14px;
        }
        .items-table td:last-child { text-align: right; }
        .items-table tr:last-child td { border-bottom: 2px solid #dee2e6; }
        
        .totals { float: right; width: 400px; margin-top: 30px; }
        .total-row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #dee2e6; font-size: 14px; }
        .total-row.total { 
            border-top: 2px solid #007bff; 
            border-bottom: 2px solid #007bff; 
            padding: 15px 0; 
            font-weight: bold; 
            font-size: 16px;
            margin-top: 10px;
        }
        .total-row.total span:last-child { color: #007bff; }
        
        .notes { clear: both; margin-top: 40px; padding-top: 20px; border-top: 1px solid #dee2e6; font-size: 13px; color: #666; }
        .notes h3 { font-size: 14px; font-weight: 600; color: #333; margin-bottom: 10px; }
        
        .footer { margin-top: 50px; padding-top: 20px; border-top: 1px solid #dee2e6; text-align: center; font-size: 12px; color: #999; }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="company-info">
                <h1>{{ $company }}</h1>
                <p>Modeh Learning Platform</p>
            </div>
            <div class="invoice-details">
                <h2>Invoice</h2>
                <p><strong>Invoice #:</strong> {{ $invoice->invoice_number }}</p>
                <p><strong>Date:</strong> {{ $invoice->created_at->format('M d, Y') }}</p>
                <p><strong>Due Date:</strong> {{ $invoice->due_at->format('M d, Y') }}</p>
                <div class="status {{ strtolower($invoice->status) }}">
                    {{ ucfirst($invoice->status) }}
                </div>
            </div>
        </div>

        <!-- Bill To -->
        <div class="bill-to">
            <h3>Bill To</h3>
            <p><strong>{{ $user->name }}</strong></p>
            <p>{{ $user->email }}</p>
            @if($user->phone)
                <p>{{ $user->phone }}</p>
            @endif
        </div>

        <!-- Items Table -->
        <table class="items-table">
            <thead>
                <tr>
                    <th>Description</th>
                    <th>Quantity</th>
                    <th>Unit Price</th>
                    <th>Amount</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>
                        @if($invoiceable && $invoiceable->getMorphClass() === 'subscriptions')
                            <strong>{{ $invoice->meta['package_name'] ?? 'Subscription' }} Package</strong>
                            <br>
                            <small>Duration: {{ $invoice->meta['duration_days'] ?? 30 }} days</small>
                        @else
                            {{ $invoice->description }}
                        @endif
                    </td>
                    <td>1</td>
                    <td>KES {{ number_format($invoice->amount, 2) }}</td>
                    <td><strong>KES {{ number_format($invoice->amount, 2) }}</strong></td>
                </tr>
            </tbody>
        </table>

        <!-- Totals -->
        <div class="totals">
            <div class="total-row">
                <span>Subtotal:</span>
                <span>KES {{ number_format($invoice->amount, 2) }}</span>
            </div>
            <div class="total-row">
                <span>Tax:</span>
                <span>KES 0.00</span>
            </div>
            <div class="total-row total">
                <span>Total:</span>
                <span>KES {{ number_format($invoice->amount, 2) }}</span>
            </div>
        </div>

        <!-- Notes -->
        @if($invoice->status === 'paid' && $invoice->paid_at)
            <div class="notes">
                <h3>Payment Information</h3>
                <p><strong>Payment Method:</strong> {{ ucfirst($invoice->payment_method ?? 'M-PESA') }}</p>
                <p><strong>Payment Date:</strong> {{ $invoice->paid_at->format('M d, Y \a\t H:i') }}</p>
                @if($invoice->meta['transaction_id'] ?? null)
                    <p><strong>Transaction ID:</strong> {{ $invoice->meta['transaction_id'] }}</p>
                @endif
            </div>
        @else
            <div class="notes">
                <h3>Payment Instructions</h3>
                <p>Thank you for choosing Modeh! Your subscription will be activated immediately upon successful payment.</p>
                <p>If you have any questions, please contact our support team.</p>
            </div>
        @endif

        <!-- Footer -->
        <div class="footer">
            <p>&copy; {{ date('Y') }} {{ $company }}. All rights reserved.</p>
            <p>This is an automated invoice. Please retain for your records.</p>
        </div>
    </div>
</body>
</html>