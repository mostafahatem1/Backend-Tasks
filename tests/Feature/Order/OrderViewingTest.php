<?php

namespace Tests\Feature\Order;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderViewingTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_unauthenticated_request_cannot_list_orders(): void
    {
        $response = $this->getJson('/api/v1/orders');

        $response->assertStatus(401);
    }

    public function test_an_unauthenticated_request_cannot_view_an_order(): void
    {
        $order = Order::factory()->create();

        $response = $this->getJson("/api/v1/orders/{$order->id}");

        $response->assertStatus(401);
    }

    public function test_a_regular_user_lists_only_their_own_orders(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $order1 = Order::factory()->create(['user_id' => $user1->id]);
        $order2 = Order::factory()->create(['user_id' => $user1->id]);
        $order3 = Order::factory()->create(['user_id' => $user2->id]);

        Sanctum::actingAs($user1);

        $response = $this->getJson('/api/v1/orders');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');

        $returnedIds = array_column($response->json('data'), 'id');
        $this->assertContains($order1->id, $returnedIds);
        $this->assertContains($order2->id, $returnedIds);
        $this->assertNotContains($order3->id, $returnedIds);
    }

    public function test_regular_user_orders_are_ordered_by_newest_id_first(): void
    {
        $user = User::factory()->create();

        $order1 = Order::factory()->create(['user_id' => $user->id]);
        $order2 = Order::factory()->create(['user_id' => $user->id]);
        $order3 = Order::factory()->create(['user_id' => $user->id]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/orders');

        $response->assertStatus(200);

        $returnedIds = array_column($response->json('data'), 'id');
        $this->assertEquals([$order3->id, $order2->id, $order1->id], $returnedIds);
    }

    public function test_an_admin_can_list_all_orders(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $order1 = Order::factory()->create(['user_id' => $user1->id]);
        $order2 = Order::factory()->create(['user_id' => $user2->id]);

        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/orders');

        $response->assertStatus(200);

        $returnedIds = array_column($response->json('data'), 'id');
        $this->assertContains($order1->id, $returnedIds);
        $this->assertContains($order2->id, $returnedIds);
    }

    public function test_a_regular_user_can_view_their_own_order(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['price' => 50.00]);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'total_amount' => 100.00,
        ]);

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_title' => $product->title,
            'unit_price' => 50.00,
            'quantity' => 2,
            'line_total' => 100.00,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/v1/orders/{$order->id}");

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Order retrieved successfully.',
                'data' => [
                    'id' => $order->id,
                    'user_id' => $user->id,
                    'status' => $order->status->value,
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
    }

    public function test_a_regular_user_cannot_view_another_users_order(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

        $order = Order::factory()->create(['user_id' => $owner->id]);

        Sanctum::actingAs($otherUser);

        $response = $this->getJson("/api/v1/orders/{$order->id}");

        $response->assertStatus(403);
    }

    public function test_an_admin_can_view_another_users_order(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id]);

        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/v1/orders/{$order->id}");

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'id' => $order->id,
                    'user_id' => $user->id,
                ],
            ]);
    }

    public function test_a_missing_order_returns_json_http_404(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/orders/99999');

        $response->assertStatus(404);
    }

    public function test_order_items_use_their_saved_snapshot_values(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id]);

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_title' => 'Historical Product Title',
            'unit_price' => 25.00,
            'quantity' => 3,
            'line_total' => 75.00,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/v1/orders/{$order->id}");

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'items' => [
                        [
                            'product_title' => 'Historical Product Title',
                            'unit_price' => '25.00',
                            'quantity' => 3,
                            'line_total' => '75.00',
                        ],
                    ],
                ],
            ]);
    }

    public function test_updating_or_deleting_current_product_does_not_change_historical_order_item_snapshot_data(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create([
            'title' => 'Original Product',
            'price' => 40.00,
        ]);

        $order = Order::factory()->create(['user_id' => $user->id]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_title' => 'Original Product',
            'unit_price' => 40.00,
            'quantity' => 1,
            'line_total' => 40.00,
        ]);

        // Update product title and price
        $product->update([
            'title' => 'Updated Product Title',
            'price' => 999.99,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/v1/orders/{$order->id}");

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'items' => [
                        [
                            'product_title' => 'Original Product',
                            'unit_price' => '40.00',
                        ],
                    ],
                ],
            ]);

        // Delete product
        $product->delete();

        $responseAfterDelete = $this->getJson("/api/v1/orders/{$order->id}");

        $responseAfterDelete->assertStatus(200)
            ->assertJson([
                'data' => [
                    'items' => [
                        [
                            'product_title' => 'Original Product',
                            'unit_price' => '40.00',
                        ],
                    ],
                ],
            ]);
    }

    public function test_the_index_endpoint_ignores_attempts_to_select_another_user_through_user_id_query_parameter(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $order1 = Order::factory()->create(['user_id' => $user1->id]);
        $order2 = Order::factory()->create(['user_id' => $user2->id]);

        Sanctum::actingAs($user1);

        $response = $this->getJson("/api/v1/orders?user_id={$user2->id}");

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $returnedIds = array_column($response->json('data'), 'id');
        $this->assertContains($order1->id, $returnedIds);
        $this->assertNotContains($order2->id, $returnedIds);
    }
}
