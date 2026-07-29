<?php

namespace Tests\Feature\Order;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_creation_works_without_idempotency_key(): void
    {
        $product = Product::factory()->create(['price' => 50.00, 'available_stock' => 10]);
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/orders', [
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2],
            ],
        ]);

        $response->assertStatus(201)
            ->assertHeader('Idempotency-Replayed', 'false')
            ->assertJson([
                'message' => 'Order created successfully.',
                'data' => [
                    'total_amount' => '100.00',
                ],
            ]);

        $this->assertDatabaseCount('orders', 1);
        $this->assertEquals(8, $product->fresh()->available_stock);
    }

    public function test_first_request_with_idempotency_key_creates_order_and_deducts_stock(): void
    {
        $product = Product::factory()->create(['price' => 25.00, 'available_stock' => 10]);
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->withHeader('Idempotency-Key', 'unique-key-1001')
            ->postJson('/api/v1/orders', [
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 2],
                ],
            ]);

        $response->assertStatus(201)
            ->assertHeader('Idempotency-Replayed', 'false')
            ->assertJson([
                'message' => 'Order created successfully.',
                'data' => [
                    'total_amount' => '50.00',
                ],
            ]);

        $this->assertDatabaseCount('orders', 1);
        $this->assertEquals(8, $product->fresh()->available_stock);
    }

    public function test_replaying_same_key_and_identical_payload_returns_existing_order_without_duplicate_stock_deduction(): void
    {
        $product = Product::factory()->create(['price' => 30.00, 'available_stock' => 10]);
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $payload = [
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2],
            ],
        ];

        $firstResponse = $this->withHeader('Idempotency-Key', 'replay-key-2002')
            ->postJson('/api/v1/orders', $payload);

        $firstResponse->assertStatus(201)
            ->assertHeader('Idempotency-Replayed', 'false');

        $firstOrderId = $firstResponse->json('data.id');

        $secondResponse = $this->withHeader('Idempotency-Key', 'replay-key-2002')
            ->postJson('/api/v1/orders', $payload);

        $secondResponse->assertStatus(200)
            ->assertHeader('Idempotency-Replayed', 'true')
            ->assertJson([
                'message' => 'Order already created for this idempotency key.',
                'data' => [
                    'id' => $firstOrderId,
                ],
            ]);

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_items', 1);
        $this->assertEquals(8, $product->fresh()->available_stock);
    }

    public function test_same_items_in_different_array_order_are_treated_as_same_canonical_request(): void
    {
        $p1 = Product::factory()->create(['price' => 10.00, 'available_stock' => 10]);
        $p2 = Product::factory()->create(['price' => 20.00, 'available_stock' => 10]);
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $payload1 = [
            'items' => [
                ['product_id' => $p1->id, 'quantity' => 1],
                ['product_id' => $p2->id, 'quantity' => 2],
            ],
        ];

        $payload2 = [
            'items' => [
                ['product_id' => $p2->id, 'quantity' => 2],
                ['product_id' => $p1->id, 'quantity' => 1],
            ],
        ];

        $firstResponse = $this->withHeader('Idempotency-Key', 'canon-key-3003')
            ->postJson('/api/v1/orders', $payload1);

        $firstResponse->assertStatus(201)->assertHeader('Idempotency-Replayed', 'false');
        $orderId = $firstResponse->json('data.id');

        $secondResponse = $this->withHeader('Idempotency-Key', 'canon-key-3003')
            ->postJson('/api/v1/orders', $payload2);

        $secondResponse->assertStatus(200)
            ->assertHeader('Idempotency-Replayed', 'true')
            ->assertJsonPath('data.id', $orderId);

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_items', 2);
    }

    public function test_reusing_same_key_with_different_quantity_returns_409_conflict(): void
    {
        $product = Product::factory()->create(['price' => 15.00, 'available_stock' => 20]);
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->withHeader('Idempotency-Key', 'conflict-key-4004')
            ->postJson('/api/v1/orders', [
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 2],
                ],
            ])
            ->assertStatus(201);

        $conflictResponse = $this->withHeader('Idempotency-Key', 'conflict-key-4004')
            ->postJson('/api/v1/orders', [
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 3],
                ],
            ]);

        $conflictResponse->assertStatus(409)
            ->assertJson([
                'message' => 'The idempotency key has already been used with a different order request.',
            ]);

        $this->assertDatabaseCount('orders', 1);
        $this->assertEquals(18, $product->fresh()->available_stock);
    }

    public function test_reusing_same_key_with_different_products_returns_409_conflict(): void
    {
        $p1 = Product::factory()->create(['price' => 10.00, 'available_stock' => 10]);
        $p2 = Product::factory()->create(['price' => 20.00, 'available_stock' => 10]);
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->withHeader('Idempotency-Key', 'conflict-prod-5005')
            ->postJson('/api/v1/orders', [
                'items' => [
                    ['product_id' => $p1->id, 'quantity' => 1],
                ],
            ])
            ->assertStatus(201);

        $conflictResponse = $this->withHeader('Idempotency-Key', 'conflict-prod-5005')
            ->postJson('/api/v1/orders', [
                'items' => [
                    ['product_id' => $p2->id, 'quantity' => 1],
                ],
            ]);

        $conflictResponse->assertStatus(409)
            ->assertJson([
                'message' => 'The idempotency key has already been used with a different order request.',
            ]);

        $this->assertDatabaseCount('orders', 1);
    }

    public function test_same_idempotency_key_can_be_used_by_two_different_users(): void
    {
        $product = Product::factory()->create(['price' => 40.00, 'available_stock' => 10]);

        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        Sanctum::actingAs($user1);
        $res1 = $this->withHeader('Idempotency-Key', 'shared-user-key')
            ->postJson('/api/v1/orders', [
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 1],
                ],
            ]);
        $res1->assertStatus(201);

        Sanctum::actingAs($user2);
        $res2 = $this->withHeader('Idempotency-Key', 'shared-user-key')
            ->postJson('/api/v1/orders', [
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 1],
                ],
            ]);
        $res2->assertStatus(201);

        $this->assertDatabaseCount('orders', 2);
        $this->assertNotEquals($res1->json('data.id'), $res2->json('data.id'));
    }

    public function test_invalid_header_values_return_http_422(): void
    {
        $product = Product::factory()->create(['available_stock' => 10]);
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        // Header longer than 64 characters
        $tooLongKey = str_repeat('a', 65);
        $this->withHeader('Idempotency-Key', $tooLongKey)
            ->postJson('/api/v1/orders', [
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 1],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['idempotency_key']);

        // Header containing spaces
        $this->withHeader('Idempotency-Key', 'invalid key with spaces')
            ->postJson('/api/v1/orders', [
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 1],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['idempotency_key']);

        // Header containing unsupported characters (@, !, #)
        $this->withHeader('Idempotency-Key', 'key@invalid!')
            ->postJson('/api/v1/orders', [
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 1],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['idempotency_key']);
    }

    public function test_body_field_named_idempotency_key_is_ignored_and_cannot_replace_header(): void
    {
        $product = Product::factory()->create(['available_stock' => 10]);
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $payload1 = [
            'idempotency_key' => 'body-fake-key',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ];

        $payload2 = [
            'idempotency_key' => 'body-fake-key',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2],
            ],
        ];

        $res1 = $this->postJson('/api/v1/orders', $payload1);
        $res1->assertStatus(201)->assertHeader('Idempotency-Replayed', 'false');

        $res2 = $this->postJson('/api/v1/orders', $payload2);
        $res2->assertStatus(201)->assertHeader('Idempotency-Replayed', 'false');

        $this->assertDatabaseCount('orders', 2);
    }

    public function test_insufficient_stock_request_does_not_reserve_key(): void
    {
        $product = Product::factory()->create(['available_stock' => 1]);
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $payload = [
            'items' => [
                ['product_id' => $product->id, 'quantity' => 5],
            ],
        ];

        // First attempt fails due to stock
        $this->withHeader('Idempotency-Key', 'stock-retry-key')
            ->postJson('/api/v1/orders', $payload)
            ->assertStatus(409)
            ->assertJson([
                'message' => 'One or more products do not have enough stock.',
            ]);

        $this->assertDatabaseCount('orders', 0);

        // Restock product
        $product->available_stock = 10;
        $product->save();

        // Retry with same key succeeds
        $retryResponse = $this->withHeader('Idempotency-Key', 'stock-retry-key')
            ->postJson('/api/v1/orders', $payload);

        $retryResponse->assertStatus(201)
            ->assertHeader('Idempotency-Replayed', 'false');

        $this->assertDatabaseCount('orders', 1);
    }

    public function test_validation_failure_does_not_reserve_key(): void
    {
        $product = Product::factory()->create(['available_stock' => 10]);
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        // Failed validation (missing items)
        $this->withHeader('Idempotency-Key', 'validation-key')
            ->postJson('/api/v1/orders', ['items' => []])
            ->assertStatus(422);

        $this->assertDatabaseCount('orders', 0);

        // Subsequent request with same key succeeds
        $this->withHeader('Idempotency-Key', 'validation-key')
            ->postJson('/api/v1/orders', [
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 1],
                ],
            ])
            ->assertStatus(201);

        $this->assertDatabaseCount('orders', 1);
    }

    public function test_idempotency_key_and_request_hash_are_not_present_in_api_responses(): void
    {
        $product = Product::factory()->create(['available_stock' => 10]);
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->withHeader('Idempotency-Key', 'secret-key-999')
            ->postJson('/api/v1/orders', [
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 1],
                ],
            ]);

        $response->assertStatus(201)
            ->assertJsonMissingPath('data.idempotency_key')
            ->assertJsonMissingPath('data.request_hash');
    }
}
