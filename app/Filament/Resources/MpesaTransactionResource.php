<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MpesaTransactionResource\Pages;
use App\Models\MpesaTransaction;
use App\Models\OneOffPurchase;
use App\Models\Invoice;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Actions\Action;
use Filament\Support\Enums\FontWeight;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

class MpesaTransactionResource extends Resource
{
    protected static ?string $model = MpesaTransaction::class;
    protected static ?string $navigationLabel = 'M-Pesa Transactions';
    /** @var string|null $navigationGroup */
    protected static \UnitEnum|string|null $navigationGroup = 'Payments & Subscriptions';
    protected static ?int $navigationSort = 4;
    protected static ?string $recordTitleAttribute = 'mpesa_receipt';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('mpesa_receipt')
                    ->label('Receipt #')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('checkout_request_id')
                    ->label('Checkout ID')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('phone')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('amount')
                    ->money('KES')
                    ->sortable(),
                BadgeColumn::make('status')
                    ->colors([
                        'success' => 'success',
                        'warning' => 'pending',
                        'danger' => 'failed',
                        'secondary' => 'cancelled',
                    ])
                    ->sortable(),
                TextColumn::make('billable_type')
                    ->label('Type')
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'App\Models\Subscription' => 'Subscription',
                        'App\Models\OneOffPurchase' => 'One-Off Purchase',
                        default => str_replace('App\\Models\\', '', $state),
                    })
                    ->sortable(),
                TextColumn::make('transaction_date')
                    ->label('Tx Date')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'success' => 'Success',
                        'failed' => 'Failed',
                        'cancelled' => 'Cancelled',
                    ]),
                SelectFilter::make('billable_type')
                    ->options([
                        'App\Models\Subscription' => 'Subscription',
                        'App\Models\OneOffPurchase' => 'One-Off Purchase',
                    ]),
                Filter::make('failed_one_off_purchases')
                    ->label('Failed One-Off Purchases (M-Pesa Charged)')
                    ->query(function (Builder $query) {
                        return $query
                            ->where('status', 'success')
                            ->where('billable_type', 'App\Models\OneOffPurchase')
                            ->whereHas('billable', function ($q) {
                                $q->where('status', 'failed');
                            });
                    }),
                Filter::make('missing_invoice')
                    ->label('Missing Invoice')
                    ->query(function (Builder $query) {
                        return $query
                            ->where('status', 'success')
                            ->where('billable_type', 'App\Models\OneOffPurchase')
                            ->whereDoesntHave('billable.invoices');
                    }),
            ])
            ->actions([
                /* @phpstan-ignore-next-line */
                Action::make('createInvoice')
                    ->label('Create Invoice')
                    ->icon('heroicon-o-document-plus')
                    ->action(function (MpesaTransaction $record) {
                        try {
                            // Get the one-off purchase
                            if ($record->billable_type !== 'App\Models\OneOffPurchase') {
                                throw new \Exception('Only works for One-Off Purchases');
                            }

                            $purchase = OneOffPurchase::find($record->billable_id);
                            if (!$purchase) {
                                throw new \Exception('Purchase not found');
                            }

                            // Check if invoice already exists
                            $existing = Invoice::where('invoiceable_type', OneOffPurchase::class)
                                ->where('invoiceable_id', $purchase->id)
                                ->first();

                            if ($existing) {
                                \Filament\Notifications\Notification::make()
                                    ->warning()
                                    ->title('Invoice Exists')
                                    ->body("Invoice {$existing->invoice_number} already exists for this purchase")
                                    ->send();
                                return;
                            }

                            // Create invoice
                            $itemType = ucfirst($purchase->item_type);
                            $invoice = Invoice::createWithUniqueNumber([
                                'invoiceable_type' => OneOffPurchase::class,
                                'invoiceable_id' => $purchase->id,
                                'user_id' => $purchase->user_id,
                                'amount' => $purchase->amount,
                                'currency' => 'KES',
                                'description' => "{$itemType} Unlock - Item #{$purchase->item_id}",
                                'status' => 'paid',
                                'paid_at' => now(),
                                'payment_method' => 'mpesa',
                                'transaction_id' => $record->checkout_request_id,
                                'meta' => [
                                    'item_type' => $purchase->item_type,
                                    'item_id' => $purchase->item_id,
                                    'mpesa_receipt' => $record->mpesa_receipt,
                                ],
                            ]);

                            Log::info('[Admin] Manual invoice created for failed purchase', [
                                'invoice_id' => $invoice->id,
                                'invoice_number' => $invoice->invoice_number,
                                'purchase_id' => $purchase->id,
                                'mpesa_receipt' => $record->mpesa_receipt,
                            ]);

                            // Send invoice email
                            if ($purchase->user) {
                                $purchase->user->notify(new \App\Notifications\InvoiceGeneratedNotification($invoice));
                            }

                            \Filament\Notifications\Notification::make()
                                ->success()
                                ->title('Invoice Created')
                                ->body("Invoice {$invoice->invoice_number} created successfully")
                                ->send();
                        } catch (\Throwable $e) {
                            Log::error('[Admin] Failed to create manual invoice', [
                                'error' => $e->getMessage(),
                                'mpesa_transaction_id' => $record->id,
                            ]);

                            \Filament\Notifications\Notification::make()
                                ->danger()
                                ->title('Error')
                                ->body($e->getMessage())
                                ->send();
                        }
                    })
                    ->visible(fn (MpesaTransaction $record) => 
                        $record->billable_type === 'App\Models\OneOffPurchase' &&
                        $record->status === 'success' &&
                        !Invoice::where('invoiceable_type', OneOffPurchase::class)
                            ->where('invoiceable_id', $record->billable_id)
                            ->exists()
                    ),
                /* @phpstan-ignore-next-line */
                Action::make('createTransactions')
                    ->label('Create Platform Transactions')
                    ->icon('heroicon-o-arrow-path')
                    ->action(function (MpesaTransaction $record) {
                        try {
                            if ($record->billable_type !== 'App\Models\OneOffPurchase') {
                                throw new \Exception('Only works for One-Off Purchases');
                            }

                            $purchase = OneOffPurchase::find($record->billable_id);
                            if (!$purchase) {
                                throw new \Exception('Purchase not found');
                            }

                            // Check if transactions already exist
                            $existing = \App\Models\Transaction::where('tx_id', $record->checkout_request_id)->first();
                            if ($existing) {
                                throw new \Exception('Platform transactions already exist for this M-Pesa receipt');
                            }

                            // Re-process the purchase to create transactions
                            // This would require calling the payment service logic
                            // For now, provide instructions to the admin
                            \Filament\Notifications\Notification::make()
                                ->warning()
                                ->title('Manual Action Required')
                                ->body("To create platform transactions, use: php artisan reconcile:purchase {$purchase->id}")
                                ->send();
                        } catch (\Throwable $e) {
                            \Filament\Notifications\Notification::make()
                                ->danger()
                                ->title('Error')
                                ->body($e->getMessage())
                                ->send();
                        }
                    })
                    ->visible(fn (MpesaTransaction $record) => 
                        $record->billable_type === 'App\Models\OneOffPurchase' &&
                        $record->status === 'success'
                    ),
                /* @phpstan-ignore-next-line */
                Action::make('viewPurchase')
                    ->label('View Purchase')
                    ->icon('heroicon-o-eye')
                    ->url(fn (MpesaTransaction $record) => $record->billable_type === 'App\Models\OneOffPurchase'
                        ? route('filament.admin.resources.one-off-purchases.edit', $record->billable_id)
                        : null)
                    ->openUrlInNewTab(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMpesaTransactions::route('/'),
        ];
    }
}
