<?php

namespace App\Events;

use App\Models\Product;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Str;

class ProductRestocked implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public string $eventId;
    public int $productId;
    public string $title;
    public string $price;
    public int $availableStock;
    public string $imagePath;
    public string $restockedAt;

    /**
     * Create a new event instance.
     */
    public function __construct(Product $product, ?string $eventId = null)
    {
        $this->eventId = $eventId ?? (string) Str::uuid();
        $this->productId = $product->id;
        $this->title = $product->title;
        $this->price = number_format((float) $product->price, 2, '.', '');
        $this->availableStock = (int) $product->available_stock;
        $this->imagePath = $product->image_path;
        $this->restockedAt = now()->toIso8601String();
    }
}
