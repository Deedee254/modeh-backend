<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PaymentStatusUpdated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public int $userId,
        public string $tx,
        public string $status,
        public ?string $checkoutRequestId = null,
        public ?string $kind = null,
        public ?string $mpesaReceipt = null,
        public ?string $resultDesc = null,
    ) {
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('users.' . $this->userId);
    }

    public function broadcastAs(): string
    {
        return 'PaymentStatusUpdated';
    }

    public function broadcastWith(): array
    {
        return [
            'tx' => $this->tx,
            'checkout_request_id' => $this->checkoutRequestId ?? $this->tx,
            'status' => $this->status,
            'kind' => $this->kind,
            'mpesa_receipt' => $this->mpesaReceipt,
            'result_desc' => $this->resultDesc,
        ];
    }
}
