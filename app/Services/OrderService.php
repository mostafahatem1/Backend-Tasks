<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Exceptions\InsufficientStockException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class OrderService
{
    /**
     * Create a new order with atomic stock deduction and concurrency-safe locking.
     *
     * @param  array<int, array{product_id: int, quantity: int}>  $items
     */
    public function createOrder(User $user, array $items): Order
    {
        return DB::transaction(function () use ($user, $items) {
            $requestedProductIds = array_column($items, 'product_id');
            sort($requestedProductIds);

            $products = Product::whereIn('id', $requestedProductIds)
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $unavailableItems = [];
            $totalOrderCents = 0;
            $orderItemsData = [];

            foreach ($items as $item) {
                $productId = (int) $item['product_id'];
                $quantity = (int) $item['quantity'];

                if (! isset($products[$productId])) {
                    throw new \InvalidArgumentException("Product {$productId} does not exist.");
                }

                $product = $products[$productId];
                $availableStock = (int) $product->available_stock;

                if ($availableStock < $quantity) {
                    $unavailableItems[] = [
                        'product_id' => $product->id,
                        'title' => $product->title,
                        'requested_quantity' => $quantity,
                        'available_stock' => $availableStock,
                    ];
                    continue;
                }

                $unitPriceCents = (int) round((float) $product->price * 100);
                $lineTotalCents = $unitPriceCents * $quantity;
                $totalOrderCents += $lineTotalCents;

                $orderItemsData[] = [
                    'product' => $product,
                    'quantity' => $quantity,
                    'unit_price' => number_format($unitPriceCents / 100, 2, '.', ''),
                    'line_total' => number_format($lineTotalCents / 100, 2, '.', ''),
                ];
            }

            if (! empty($unavailableItems)) {
                throw new InsufficientStockException($unavailableItems);
            }

            $order = Order::create([
                'user_id' => $user->id,
                'status' => OrderStatus::PENDING,
                'total_amount' => number_format($totalOrderCents / 100, 2, '.', ''),
            ]);

            foreach ($orderItemsData as $itemData) {
                /** @var Product $product */
                $product = $itemData['product'];
                $quantity = (int) $itemData['quantity'];

                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'product_title' => $product->title,
                    'unit_price' => $itemData['unit_price'],
                    'quantity' => $quantity,
                    'line_total' => $itemData['line_total'],
                ]);

                $product->available_stock = (int) $product->available_stock - $quantity;
                $product->save();
            }

            return $order->load('items');
        }, 3);
    }
}
