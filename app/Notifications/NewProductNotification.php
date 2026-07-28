<?php

namespace App\Notifications;

use App\Events\ProductCreated;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Storage;

class NewProductNotification extends Notification
{
    public array $productData;
    public int|string $userId;

    /**
     * Create a new notification instance.
     */
    public function __construct(ProductCreated|array $productData, int|string $userId)
    {
        if ($productData instanceof ProductCreated) {
            $this->productData = [
                'product_id' => $productData->productId,
                'title' => $productData->title,
                'price' => $productData->price,
                'description' => $productData->description,
                'available_stock' => $productData->availableStock,
                'image_path' => $productData->imagePath,
                'created_at' => $productData->createdAt,
            ];
        } else {
            $this->productData = $productData;
        }

        $this->userId = $userId;

        $seed = "new-product:{$this->productData['product_id']}:user:{$this->userId}";
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
            'event' => 'product_created',
            'product_id' => (int) $this->productData['product_id'],
            'title' => (string) $this->productData['title'],
            'price' => (string) $this->productData['price'],
            'description' => (string) $this->productData['description'],
            'available_stock' => (int) $this->productData['available_stock'],
            'image_url' => Storage::disk('public')->url($this->productData['image_path']),
            'created_at' => (string) $this->productData['created_at'],
        ];
    }

    /**
     * Get the notification's database type.
     */
    public function databaseType(mixed $notifiable): string
    {
        return 'new_product';
    }
}
