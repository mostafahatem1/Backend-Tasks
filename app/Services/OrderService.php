<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Exceptions\IdempotencyKeyConflictException;
use App\Exceptions\InsufficientStockException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Support\OrderIdempotencyDuplicateKeyDetector;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

class OrderService
{
    /**
     * Create a new order with atomic stock deduction and concurrency-safe locking.
     *
     * @param  array<int, array{product_id: int, quantity: int}>  $items
     * @return array{order: Order, replayed: bool}
     */
    public function createOrder(User $user, array $items, ?string $idempotencyKey = null): array
    {
        $requestHash = $idempotencyKey !== null ? $this->generateRequestHash($items) : null;

        if ($idempotencyKey === null) {
            $result = DB::transaction(function () use ($user, $items) {
                return $this->executeOrderCreationProcess($user, $items, null, null);
            }, 3);

            return $result;
        }

        try {
            return DB::transaction(function () use ($user, $items, $idempotencyKey, $requestHash) {
                // First existing-key check (before locking products)
                $existingOrder = $this->findExistingOrder($user->id, $idempotencyKey);
                if ($existingOrder) {
                    return $this->resolveExistingOrder($existingOrder, $requestHash, $idempotencyKey);
                }

                return $this->executeOrderCreationProcess($user, $items, $idempotencyKey, $requestHash);
            }, 3);
        } catch (QueryException $e) {
            if (OrderIdempotencyDuplicateKeyDetector::isDuplicateKey($e)) {
                $existingOrder = $this->findExistingOrder($user->id, $idempotencyKey);

                if (! $existingOrder) {
                    throw $e;
                }

                return $this->resolveExistingOrder($existingOrder, $requestHash, $idempotencyKey);
            }

            throw $e;
        }
    }

    /**
     * Find an existing order by user ID and idempotency key.
     */
    private function findExistingOrder(int $userId, string $idempotencyKey): ?Order
    {
        return Order::where('user_id', $userId)
            ->where('idempotency_key', $idempotencyKey)
            ->with('items')
            ->first();
    }

    /**
     * Resolve an existing order replay or throw a conflict exception.
     *
     * @return array{order: Order, replayed: bool}
     */
    private function resolveExistingOrder(Order $existingOrder, string $requestHash, string $idempotencyKey): array
    {
        if (! hash_equals((string) $existingOrder->request_hash, $requestHash)) {
            throw new IdempotencyKeyConflictException($idempotencyKey);
        }

        return [
            'order' => $existingOrder->relationLoaded('items') ? $existingOrder : $existingOrder->load('items'),
            'replayed' => true,
        ];
    }

    /**
     * Execute the order creation process including product locking and stock validation.
     *
     * @return array{order: Order, replayed: bool}
     */
    private function executeOrderCreationProcess(
        User $user,
        array $items,
        ?string $idempotencyKey,
        ?string $requestHash
    ): array {
        $requestedProductIds = array_column($items, 'product_id');
        sort($requestedProductIds);

        $products = Product::whereIn('id', $requestedProductIds)
            ->orderBy('id', 'asc')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        // Second existing-key check (after acquiring product locks)
        if ($idempotencyKey !== null) {
            $existingOrder = $this->findExistingOrder($user->id, $idempotencyKey);
            if ($existingOrder) {
                return $this->resolveExistingOrder($existingOrder, $requestHash, $idempotencyKey);
            }
        }

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
            'idempotency_key' => $idempotencyKey,
            'request_hash' => $requestHash,
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

        return [
            'order' => $order->load('items'),
            'replayed' => false,
        ];
    }

    /**
     * Generate canonical SHA-256 request hash from normalized items.
     *
     * @param  array<int, array{product_id: int, quantity: int}>  $items
     */
    private function generateRequestHash(array $items): string
    {
        $normalized = array_map(function ($item) {
            return [
                'product_id' => (int) $item['product_id'],
                'quantity' => (int) $item['quantity'],
            ];
        }, $items);

        usort($normalized, fn ($a, $b) => $a['product_id'] <=> $b['product_id']);

        return hash('sha256', json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
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
