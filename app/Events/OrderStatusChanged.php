<?php

namespace App\Events;

use App\Models\OrderStatusHistory;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class OrderStatusChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public int $historyId;
    public int $orderId;
    public int $ownerUserId;
    public string $previousStatus;
    public string $newStatus;
    public int $changedByUserId;
    public string $changedAt;

    /**
     * Create a new event instance.
     */
    public function __construct(OrderStatusHistory $history, int $ownerUserId)
    {
        $this->historyId = $history->id;
        $this->orderId = $history->order_id;
        $this->ownerUserId = $ownerUserId;
        $this->previousStatus = $history->previous_status instanceof \BackedEnum ? $history->previous_status->value : (string) $history->previous_status;
        $this->newStatus = $history->new_status instanceof \BackedEnum ? $history->new_status->value : (string) $history->new_status;
        $this->changedByUserId = $history->changed_by_user_id;
        $this->changedAt = $history->changed_at?->toIso8601String() ?? now()->toIso8601String();
    }
}
