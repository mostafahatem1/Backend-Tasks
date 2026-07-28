<?php

namespace App\Notifications;

use App\Events\OrderStatusChanged;
use Illuminate\Notifications\Notification;

class OrderStatusChangedNotification extends Notification
{
    public array $eventData;
    public int|string $userId;

    /**
     * Create a new notification instance.
     */
    public function __construct(OrderStatusChanged|array $eventData, int|string $userId)
    {
        if ($eventData instanceof OrderStatusChanged) {
            $this->eventData = [
                'history_id' => $eventData->historyId,
                'order_id' => $eventData->orderId,
                'previous_status' => $eventData->previousStatus,
                'new_status' => $eventData->newStatus,
                'changed_by_user_id' => $eventData->changedByUserId,
                'changed_at' => $eventData->changedAt,
            ];
        } else {
            $this->eventData = $eventData;
        }

        $this->userId = $userId;

        $seed = "order-status-change:{$this->eventData['history_id']}:order:{$this->eventData['order_id']}:user:{$this->userId}";
        $hash = md5($seed);

        $this->id = sprintf(
            '%08s-%04s-%04s-%04s-%12s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 12, 4),
            substr($hash, 16, 4),
            substr($hash, 20, 12)
        );
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(mixed $notifiable): array
    {
        return ['database'];
    }

    /**
     * Get the database representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(mixed $notifiable): array
    {
        return [
            'event' => 'order_status_changed',
            'order_id' => (int) $this->eventData['order_id'],
            'previous_status' => (string) $this->eventData['previous_status'],
            'new_status' => (string) $this->eventData['new_status'],
            'changed_by_user_id' => (int) $this->eventData['changed_by_user_id'],
            'changed_at' => (string) $this->eventData['changed_at'],
        ];
    }

    /**
     * Get the notification's database type.
     */
    public function databaseType(mixed $notifiable): string
    {
        return 'order_status_changed';
    }
}
