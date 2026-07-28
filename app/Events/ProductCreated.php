<?php

namespace App\Events;

use App\Models\Product;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class ProductCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public int $productId;
    public string $title;
    public string $price;
    public string $description;
    public int $availableStock;
    public string $imagePath;
    public string $createdAt;

    /**
     * Create a new event instance.
     */
    public function __construct(Product $product)
    {
        $this->productId = $product->id;
        $this->title = $product->title;
        $this->price = number_format((float) $product->price, 2, '.', '');
        $this->description = $product->description;
        $this->availableStock = (int) $product->available_stock;
        $this->imagePath = $product->image_path;
        $this->createdAt = $product->created_at?->toIso8601String() ?? now()->toIso8601String();
    }
}
