<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Exceptions\InsufficientStockException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

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

                $unitPriceCents = $this->decimalToMinorUnits((string) $product->price);
                $lineTotalCents = $unitPriceCents * $quantity;
                $totalOrderCents += $lineTotalCents;

                $orderItemsData[] = [
                    'product' => $product,
                    'quantity' => $quantity,
                    'unit_price' => $this->minorUnitsToDecimal($unitPriceCents),
                    'line_total' => $this->minorUnitsToDecimal($lineTotalCents),
                ];
            }

            if (! empty($unavailableItems)) {
                throw new InsufficientStockException($unavailableItems);
            }

            $order = Order::create([
                'user_id' => $user->id,
                'status' => OrderStatus::PENDING,
                'total_amount' => $this->minorUnitsToDecimal($totalOrderCents),
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

    /**
     * Convert a non-negative decimal string to integer minor units (cents).
     */
    private function decimalToMinorUnits(string $amount): int
    {
        $trimmed = trim($amount);

        if (! preg_match('/^\d+(\.\d{1,2})?$/', $trimmed)) {
            throw new UnexpectedValueException("Invalid database price value: '{$amount}'");
        }

        $parts = explode('.', $trimmed);
        $dollars = (int) $parts[0];
        $cents = 0;

        if (isset($parts[1])) {
            $cents = (int) str_pad($parts[1], 2, '0', STR_PAD_RIGHT);
        }

        return ($dollars * 100) + $cents;
    }

    /**
     * Convert integer minor units (cents) to a formatted two-decimal string.
     */
    private function minorUnitsToDecimal(int $amount): string
    {
        if ($amount < 0) {
            throw new UnexpectedValueException("Invalid minor units amount: {$amount}");
        }

        $dollars = intdiv($amount, 100);
        $cents = $amount % 100;

        return sprintf('%d.%02d', $dollars, $cents);
    }
}
