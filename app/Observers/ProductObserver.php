<?php

namespace App\Observers;

use App\Events\ProductRestocked;
use App\Models\Product;
use App\Traits\UploadTrait;

class ProductObserver
{
    use UploadTrait;

    /**
     * Handle the Product "updated" event.
     */
    public function updated(Product $product): void
    {
        if (
            $product->wasChanged('available_stock') &&
            (int) $product->getOriginal('available_stock') === 0 &&
            (int) $product->available_stock > 0
        ) {
            try {
                ProductRestocked::dispatch($product);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * Handle the Product "deleted" event.
     */
    public function deleted(Product $product): void
    {
        $this->removeFile($product->image_path);
    }
}
