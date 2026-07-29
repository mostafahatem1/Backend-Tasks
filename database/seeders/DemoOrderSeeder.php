<?php

namespace Database\Seeders;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use Illuminate\Database\Seeder;

class DemoOrderSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $products = Product::all()->keyBy('id');

        $orders = [
            [
                'user_id' => 2, // Postman User
                'status' => OrderStatus::PENDING,
                'created_at' => '2026-07-20 10:00:00',
                'items' => [
                    ['product_id' => 1, 'quantity' => 1],
                ],
                'histories' => [],
            ],
            [
                'user_id' => 2, // Postman User
                'status' => OrderStatus::CONFIRMED,
                'created_at' => '2026-07-21 11:00:00',
                'items' => [
                    ['product_id' => 2, 'quantity' => 1],
                    ['product_id' => 4, 'quantity' => 1],
                ],
                'histories' => [
                    [
                        'previous_status' => OrderStatus::PENDING,
                        'new_status' => OrderStatus::CONFIRMED,
                        'changed_by_user_id' => 1,
                        'changed_at' => '2026-07-21 11:30:00',
                    ],
                ],
            ],
            [
                'user_id' => 4, // Other User
                'status' => OrderStatus::PROCESSING,
                'created_at' => '2026-07-22 12:00:00',
                'items' => [
                    ['product_id' => 7, 'quantity' => 2],
                ],
                'histories' => [
                    [
                        'previous_status' => OrderStatus::PENDING,
                        'new_status' => OrderStatus::CONFIRMED,
                        'changed_by_user_id' => 1,
                        'changed_at' => '2026-07-22 12:30:00',
                    ],
                    [
                        'previous_status' => OrderStatus::CONFIRMED,
                        'new_status' => OrderStatus::PROCESSING,
                        'changed_by_user_id' => 1,
                        'changed_at' => '2026-07-22 13:00:00',
                    ],
                ],
            ],
            [
                'user_id' => 2, // Postman User
                'status' => OrderStatus::SHIPPED,
                'created_at' => '2026-07-23 13:00:00',
                'items' => [
                    ['product_id' => 11, 'quantity' => 1],
                ],
                'histories' => [
                    [
                        'previous_status' => OrderStatus::PENDING,
                        'new_status' => OrderStatus::CONFIRMED,
                        'changed_by_user_id' => 1,
                        'changed_at' => '2026-07-23 13:30:00',
                    ],
                    [
                        'previous_status' => OrderStatus::CONFIRMED,
                        'new_status' => OrderStatus::PROCESSING,
                        'changed_by_user_id' => 1,
                        'changed_at' => '2026-07-23 14:00:00',
                    ],
                    [
                        'previous_status' => OrderStatus::PROCESSING,
                        'new_status' => OrderStatus::SHIPPED,
                        'changed_by_user_id' => 1,
                        'changed_at' => '2026-07-23 15:00:00',
                    ],
                ],
            ],
            [
                'user_id' => 4, // Other User
                'status' => OrderStatus::DELIVERED,
                'created_at' => '2026-07-24 14:00:00',
                'items' => [
                    ['product_id' => 6, 'quantity' => 1],
                ],
                'histories' => [
                    [
                        'previous_status' => OrderStatus::PENDING,
                        'new_status' => OrderStatus::CONFIRMED,
                        'changed_by_user_id' => 1,
                        'changed_at' => '2026-07-24 14:30:00',
                    ],
                    [
                        'previous_status' => OrderStatus::CONFIRMED,
                        'new_status' => OrderStatus::PROCESSING,
                        'changed_by_user_id' => 1,
                        'changed_at' => '2026-07-24 15:00:00',
                    ],
                    [
                        'previous_status' => OrderStatus::PROCESSING,
                        'new_status' => OrderStatus::SHIPPED,
                        'changed_by_user_id' => 1,
                        'changed_at' => '2026-07-24 16:00:00',
                    ],
                    [
                        'previous_status' => OrderStatus::SHIPPED,
                        'new_status' => OrderStatus::DELIVERED,
                        'changed_by_user_id' => 1,
                        'changed_at' => '2026-07-24 17:00:00',
                    ],
                ],
            ],
            [
                'user_id' => 2, // Postman User
                'status' => OrderStatus::CANCELLED,
                'created_at' => '2026-07-25 15:00:00',
                'items' => [
                    ['product_id' => 8, 'quantity' => 1],
                ],
                'histories' => [
                    [
                        'previous_status' => OrderStatus::PENDING,
                        'new_status' => OrderStatus::CANCELLED,
                        'changed_by_user_id' => 1,
                        'changed_at' => '2026-07-25 15:30:00',
                    ],
                ],
            ],
        ];

        foreach ($orders as $orderData) {
            $totalAmount = 0.0;
            $itemsData = [];

            foreach ($orderData['items'] as $itemSpec) {
                $product = $products[$itemSpec['product_id']];
                $unitPrice = (float) $product->price;
                $quantity = (int) $itemSpec['quantity'];
                $lineTotal = $unitPrice * $quantity;
                $totalAmount += $lineTotal;

                $itemsData[] = [
                    'product_id' => $product->id,
                    'product_title' => $product->title,
                    'unit_price' => number_format($unitPrice, 2, '.', ''),
                    'quantity' => $quantity,
                    'line_total' => number_format($lineTotal, 2, '.', ''),
                ];
            }

            $order = Order::create([
                'user_id' => $orderData['user_id'],
                'status' => $orderData['status'],
                'total_amount' => number_format($totalAmount, 2, '.', ''),
                'idempotency_key' => null,
                'request_hash' => null,
                'created_at' => $orderData['created_at'],
                'updated_at' => $orderData['created_at'],
            ]);

            foreach ($itemsData as $itemData) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $itemData['product_id'],
                    'product_title' => $itemData['product_title'],
                    'unit_price' => $itemData['unit_price'],
                    'quantity' => $itemData['quantity'],
                    'line_total' => $itemData['line_total'],
                    'created_at' => $orderData['created_at'],
                    'updated_at' => $orderData['created_at'],
                ]);
            }

            foreach ($orderData['histories'] as $historyData) {
                OrderStatusHistory::create([
                    'order_id' => $order->id,
                    'previous_status' => $historyData['previous_status'],
                    'new_status' => $historyData['new_status'],
                    'changed_by_user_id' => $historyData['changed_by_user_id'],
                    'changed_at' => $historyData['changed_at'],
                ]);
            }
        }
    }
}
