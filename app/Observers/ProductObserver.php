<?php

namespace App\Observers;

use App\Models\Product;
use App\Traits\UploadTrait;

class ProductObserver
{
    use UploadTrait;

    /**
     * Handle the Product "deleted" event.
     */
    public function deleted(Product $product): void
    {
        $this->removeFile($product->image_path);
    }
}
