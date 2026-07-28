<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductStockNotificationRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductStockNotificationRequestController extends Controller
{
    public function store(Request $request, Product $product): JsonResponse
    {
        $user = $request->user();

        return DB::transaction(function () use ($user, $product) {
            $lockedProduct = Product::where('id', $product->id)->lockForUpdate()->first();

            if (! $lockedProduct || (int) $lockedProduct->available_stock > 0) {
                return apiResponse(
                    message: 'Product is currently in stock.',
                    status: 409
                );
            }

            $existingRequest = ProductStockNotificationRequest::where('user_id', $user->id)
                ->where('product_id', $lockedProduct->id)
                ->first();

            if ($existingRequest) {
                return apiResponse(
                    data: [
                        'id' => $existingRequest->id,
                        'product_id' => $existingRequest->product_id,
                        'created_at' => $existingRequest->created_at?->toIso8601String(),
                    ],
                    message: 'Stock notification request already exists.',
                    status: 200
                );
            }

            try {
                $newRequest = ProductStockNotificationRequest::create([
                    'user_id' => $user->id,
                    'product_id' => $lockedProduct->id,
                ]);

                return apiResponse(
                    data: [
                        'id' => $newRequest->id,
                        'product_id' => $newRequest->product_id,
                        'created_at' => $newRequest->created_at?->toIso8601String(),
                    ],
                    message: 'Stock notification request created successfully.',
                    status: 201
                );
            } catch (\Illuminate\Database\QueryException $e) {
                $message = strtolower($e->getMessage());
                if (
                    str_contains($message, '1062') ||
                    str_contains($message, '23505') ||
                    str_contains($message, 'unique constraint failed') ||
                    str_contains($message, 'unique')
                ) {
                    $existingRequest = ProductStockNotificationRequest::where('user_id', $user->id)
                        ->where('product_id', $lockedProduct->id)
                        ->first();

                    if ($existingRequest) {
                        return apiResponse(
                            data: [
                                'id' => $existingRequest->id,
                                'product_id' => $existingRequest->product_id,
                                'created_at' => $existingRequest->created_at?->toIso8601String(),
                            ],
                            message: 'Stock notification request already exists.',
                            status: 200
                        );
                    }
                }

                throw $e;
            }
        });
    }
}
