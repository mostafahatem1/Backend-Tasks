<?php

namespace App\Notifications;

use App\Events\ProductRestocked;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Storage;

class ProductBackInStockNotification extends Notification
{
    public array $eventData;
    public int|string $userId;

    /**
     * Create a new notification instance.
     */
    public function __construct(ProductRestocked|array $eventData, int|string $userId)
    {
        if ($eventData instanceof ProductRestocked) {
            $this->eventData = [
                'event_id' => $eventData->eventId,
                'product_id' => $eventData->productId,
                'title' => $eventData->title,
                'price' => $eventData->price,
                'available_stock' => $eventData->availableStock,
                'image_path' => $eventData->imagePath,
                'restocked_at' => $eventData->restockedAt,
            ];
        } else {
            $this->eventData = $eventData;
        }

        $this->userId = $userId;

        $seed = "back-in-stock:{$this->eventData['event_id']}:product:{$this->eventData['product_id']}:user:{$this->userId}";
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
            'event' => 'product_back_in_stock',
            'product_id' => (int) $this->eventData['product_id'],
            'title' => (string) $this->eventData['title'],
            'price' => (string) $this->eventData['price'],
            'available_stock' => (int) $this->eventData['available_stock'],
            'image_url' => Storage::disk('public')->url($this->eventData['image_path']),
            'restocked_at' => (string) $this->eventData['restocked_at'],
        ];
    }

    /**
     * Get the notification's database type.
     */
    public function databaseType(mixed $notifiable): string
    {
        return 'product_back_in_stock';
    }
}
