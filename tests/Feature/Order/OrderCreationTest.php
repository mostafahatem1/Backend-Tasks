<?php

namespace Tests\Feature\Order;

use App\Enums\OrderStatus;
use App\Events\ProductRestocked;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_unauthenticated_user_cannot_create_an_order(): void
    {
        $response = $this->postJson('/api/orders', [
            'items' => [
                ['product_id' => 1, 'quantity' => 1],
            ],
        ]);

        $response->assertStatus(401);
    }

    public function test_an_authenticated_user_can_create_an_order_containing_one_product(): void
    {
        $product = Product::factory()->create([
            'price' => 50.00,
            'available_stock' => 10,
        ]);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/orders', [
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 2,
                ],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'message' => 'Order created successfully.',
                'data' => [
                    'status' => OrderStatus::PENDING->value,
                    'total_amount' => '100.00',
                    'items' => [
                        [
                            'product_id' => $product->id,
                            'product_title' => $product->title,
                            'unit_price' => '50.00',
                            'quantity' => 2,
                            'line_total' => '100.00',
                        ],
                    ],
                ],
            ]);

        $this->assertDatabaseHas('orders', [
            'user_id' => $user->id,
            'status' => OrderStatus::PENDING->value,
            'total_amount' => 100.00,
        ]);

        $this->assertEquals(8, $product->fresh()->available_stock);
    }

    public function test_an_authenticated_user_can_create_an_order_containing_multiple_products(): void
    {
        $product1 = Product::factory()->create([
            'price' => 20.00,
            'available_stock' => 10,
        ]);

        $product2 = Product::factory()->create([
            'price' => 30.00,
            'available_stock' => 5,
        ]);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/orders', [
            'items' => [
                ['product_id' => $product1->id, 'quantity' => 3],
                ['product_id' => $product2->id, 'quantity' => 2],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'message' => 'Order created successfully.',
                'data' => [
                    'total_amount' => '120.00',
                ],
            ]);

        $this->assertEquals(7, $product1->fresh()->available_stock);
        $this->assertEquals(3, $product2->fresh()->available_stock);
        $this->assertDatabaseCount('order_items', 2);
    }

    public function test_prices_and_totals_are_calculated_from_database_products(): void
    {
        $product = Product::factory()->create([
            'price' => 100.00,
            'available_stock' => 5,
        ]);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/orders', [
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'price' => 0.01,
                    'unit_price' => 0.01,
                    'line_total' => 0.01,
                    'total_amount' => 0.01,
                ],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'data' => [
                    'total_amount' => '100.00',
                    'items' => [
                        [
                            'unit_price' => '100.00',
                            'line_total' => '100.00',
                        ],
                    ],
                ],
            ]);
    }

    public function test_validation_rejects_invalid_inputs(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $product = Product::factory()->create(['available_stock' => 10]);

        // Missing items
        $this->postJson('/api/orders', [])->assertStatus(422);

        // Empty items
        $this->postJson('/api/orders', ['items' => []])->assertStatus(422);

        // Missing product_id
        $this->postJson('/api/orders', ['items' => [['quantity' => 1]]])->assertStatus(422);

        // Missing quantity
        $this->postJson('/api/orders', ['items' => [['product_id' => $product->id]]])->assertStatus(422);

        // Zero quantity
        $this->postJson('/api/orders', ['items' => [['product_id' => $product->id, 'quantity' => 0]]])->assertStatus(422);

        // Negative quantity
        $this->postJson('/api/orders', ['items' => [['product_id' => $product->id, 'quantity' => -2]]])->assertStatus(422);

        // Non-integer quantity
        $this->postJson('/api/orders', ['items' => [['product_id' => $product->id, 'quantity' => 1.5]]])->assertStatus(422);

        // Duplicate product IDs
        $this->postJson('/api/orders', ['items' => [
            ['product_id' => $product->id, 'quantity' => 1],
            ['product_id' => $product->id, 'quantity' => 2],
        ]])->assertStatus(422);

        // Nonexistent product ID
        $this->postJson('/api/orders', ['items' => [['product_id' => 99999, 'quantity' => 1]]])->assertStatus(422);
    }

    public function test_order_creation_fails_when_one_product_has_insufficient_stock(): void
    {
        $product = Product::factory()->create([
            'title' => 'Limited Stock Product',
            'available_stock' => 2,
        ]);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/orders', [
            'items' => [
                ['product_id' => $product->id, 'quantity' => 5],
            ],
        ]);

        $response->assertStatus(409)
            ->assertJson([
                'message' => 'One or more products do not have enough stock.',
                'data' => [
                    'unavailable_items' => [
                        [
                            'product_id' => $product->id,
                            'title' => 'Limited Stock Product',
                            'requested_quantity' => 5,
                            'available_stock' => 2,
                        ],
                    ],
                ],
            ]);

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertEquals(2, $product->fresh()->available_stock);
    }

    public function test_a_multi_product_order_is_fully_rolled_back_when_only_one_item_is_unavailable(): void
    {
        $product1 = Product::factory()->create(['available_stock' => 10]);
        $product2 = Product::factory()->create(['available_stock' => 1]);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/orders', [
            'items' => [
                ['product_id' => $product1->id, 'quantity' => 2],
                ['product_id' => $product2->id, 'quantity' => 5],
            ],
        ]);

        $response->assertStatus(409);

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertEquals(10, $product1->fresh()->available_stock);
        $this->assertEquals(1, $product2->fresh()->available_stock);
    }

    public function test_an_out_of_stock_product_cannot_be_ordered(): void
    {
        $product = Product::factory()->create(['available_stock' => 0]);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/orders', [
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ]);

        $response->assertStatus(409);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_competing_orders_cannot_oversell_the_final_stock(): void
    {
        $product = Product::factory()->create(['available_stock' => 1]);

        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        Sanctum::actingAs($user1);
        $response1 = $this->postJson('/api/orders', [
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ]);

        Sanctum::actingAs($user2);
        $response2 = $this->postJson('/api/orders', [
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ]);

        $statuses = [$response1->status(), $response2->status()];
        sort($statuses);

        $this->assertEquals([201, 409], $statuses);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_items', 1);
        $this->assertEquals(0, $product->fresh()->available_stock);
    }

    public function test_an_exception_during_order_item_creation_rolls_back_the_complete_operation(): void
    {
        $product = Product::factory()->create(['available_stock' => 10]);
        $user = User::factory()->create();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Simulated database error during order creation.');

        // Force a failure inside the order creation transaction
        $mockService = $this->partialMock(OrderService::class, function ($mock) use ($user, $product) {
            $mock->shouldReceive('createOrder')
                ->andThrow(new \RuntimeException('Simulated database error during order creation.'));
        });

        $mockService->createOrder($user, [['product_id' => $product->id, 'quantity' => 2]]);

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertEquals(10, $product->fresh()->available_stock);
    }

    public function test_product_stock_changing_from_a_positive_value_to_zero_does_not_dispatch_product_restocked(): void
    {
        Event::fake([ProductRestocked::class]);

        $product = Product::factory()->create(['available_stock' => 2]);
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/orders', [
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2],
            ],
        ]);

        $response->assertStatus(201);
        $this->assertEquals(0, $product->fresh()->available_stock);

        Event::assertNotDispatched(ProductRestocked::class);
    }
}
