<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Exceptions\InvalidOrderStatusTransitionException;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class OrderStatusService
{
    /**
     * Update an order status safely within a database transaction.
     *
     * @return array{order: Order, history: OrderStatusHistory|null, changed: bool}
     */
    public function updateStatus(Order $order, User $actor, OrderStatus $requestedStatus): array
    {
        return DB::transaction(function () use ($order, $actor, $requestedStatus) {
            /** @var Order $lockedOrder */
            $lockedOrder = Order::where('id', $order->id)->lockForUpdate()->firstOrFail();

            $currentStatus = $lockedOrder->status;

            if ($currentStatus === $requestedStatus) {
                return [
                    'order' => $lockedOrder,
                    'history' => null,
                    'changed' => false,
                ];
            }

            if (! $currentStatus->canTransitionTo($requestedStatus)) {
                throw new InvalidOrderStatusTransitionException($currentStatus, $requestedStatus);
            }

            $lockedOrder->status = $requestedStatus;
            $lockedOrder->save();

            $history = OrderStatusHistory::create([
                'order_id' => $lockedOrder->id,
                'previous_status' => $currentStatus,
                'new_status' => $requestedStatus,
                'changed_by_user_id' => $actor->id,
                'changed_at' => now(),
            ]);

            return [
                'order' => $lockedOrder,
                'history' => $history,
                'changed' => true,
            ];
        }, 3);
    }
}
