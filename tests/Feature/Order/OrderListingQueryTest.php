<?php

namespace Tests\Feature\Order;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderListingQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_unauthenticated_request_cannot_list_orders(): void
    {
        $response = $this->getJson('/api/v1/orders');

        $response->assertStatus(401);
    }

    public function test_default_listing_for_regular_user_returns_own_orders_paginated_and_sorted(): void
    {
        $user = User::factory()->create();
        Order::factory()->count(20)->create(['user_id' => $user->id]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/orders');

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Orders retrieved successfully.',
            ])
            ->assertJsonStructure([
                'message',
                'data' => [
                    '*' => [
                        'id',
                        'user_id',
                        'status',
                        'total_amount',
                        'items',
                        'created_at',
                        'updated_at',
                    ],
                ],
                'links' => [
                    'first',
                    'last',
                    'prev',
                    'next',
                ],
                'meta' => [
                    'current_page',
                    'per_page',
                    'last_page',
                    'total',
                    'from',
                    'to',
                ],
            ]);

        $this->assertCount(15, $response->json('data'));
        $this->assertEquals(1, $response->json('meta.current_page'));
        $this->assertEquals(15, $response->json('meta.per_page'));
        $this->assertEquals(2, $response->json('meta.last_page'));
        $this->assertEquals(20, $response->json('meta.total'));
        $this->assertEquals(1, $response->json('meta.from'));
        $this->assertEquals(15, $response->json('meta.to'));

        $ids = array_column($response->json('data'), 'id');
        $expectedIds = $ids;
        rsort($expectedIds);
        $this->assertEquals($expectedIds, $ids);
    }

    public function test_an_admin_can_list_orders_belonging_to_all_users(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $order1 = Order::factory()->create(['user_id' => $user1->id]);
        $order2 = Order::factory()->create(['user_id' => $user2->id]);

        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/orders');

        $response->assertStatus(200);
        $this->assertEquals(2, $response->json('meta.total'));
        $returnedIds = array_column($response->json('data'), 'id');
        $this->assertContains($order1->id, $returnedIds);
        $this->assertContains($order2->id, $returnedIds);
    }

    public function test_a_regular_user_cannot_access_another_users_orders_by_supplying_ownership_query_parameters(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $order1 = Order::factory()->create(['user_id' => $user1->id]);
        $order2 = Order::factory()->create(['user_id' => $user2->id]);

        Sanctum::actingAs($user1);

        $response = $this->getJson("/api/v1/orders?user_id={$user2->id}&role=admin");

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals($order1->id, $response->json('data.0.id'));
    }

    public function test_orders_can_be_filtered_by_every_status(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        foreach (OrderStatus::cases() as $status) {
            $matchingOrder = Order::factory()->create([
                'user_id' => $user->id,
                'status' => $status,
            ]);

            $response = $this->getJson("/api/v1/orders?status={$status->value}");

            $response->assertStatus(200);
            $this->assertCount(1, $response->json('data'));
            $this->assertEquals($matchingOrder->id, $response->json('data.0.id'));
            $this->assertEquals($status->value, $response->json('data.0.status'));
        }
    }

    public function test_orders_can_be_filtered_by_min_total(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        Order::factory()->create(['user_id' => $user->id, 'total_amount' => 10.00]);
        $o2 = Order::factory()->create(['user_id' => $user->id, 'total_amount' => 50.00]);
        $o3 = Order::factory()->create(['user_id' => $user->id, 'total_amount' => 100.00]);

        $response = $this->getJson('/api/v1/orders?min_total=50.00');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));
        $retrievedIds = array_column($response->json('data'), 'id');
        $this->assertContains($o2->id, $retrievedIds);
        $this->assertContains($o3->id, $retrievedIds);
    }

    public function test_orders_can_be_filtered_by_max_total(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $o1 = Order::factory()->create(['user_id' => $user->id, 'total_amount' => 10.00]);
        $o2 = Order::factory()->create(['user_id' => $user->id, 'total_amount' => 50.00]);
        Order::factory()->create(['user_id' => $user->id, 'total_amount' => 100.00]);

        $response = $this->getJson('/api/v1/orders?max_total=50.00');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));
        $retrievedIds = array_column($response->json('data'), 'id');
        $this->assertContains($o1->id, $retrievedIds);
        $this->assertContains($o2->id, $retrievedIds);
    }

    public function test_orders_can_be_filtered_by_an_inclusive_total_range(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        Order::factory()->create(['user_id' => $user->id, 'total_amount' => 10.00]);
        $o2 = Order::factory()->create(['user_id' => $user->id, 'total_amount' => 50.00]);
        $o3 = Order::factory()->create(['user_id' => $user->id, 'total_amount' => 75.00]);
        Order::factory()->create(['user_id' => $user->id, 'total_amount' => 100.00]);

        $response = $this->getJson('/api/v1/orders?min_total=50.00&max_total=75.00');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));
        $retrievedIds = array_column($response->json('data'), 'id');
        $this->assertContains($o2->id, $retrievedIds);
        $this->assertContains($o3->id, $retrievedIds);
    }

    public function test_orders_can_be_filtered_by_created_from(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        Order::factory()->create([
            'user_id' => $user->id,
            'created_at' => '2026-07-20 10:00:00',
        ]);
        $newerOrder = Order::factory()->create([
            'user_id' => $user->id,
            'created_at' => '2026-07-25 14:00:00',
        ]);

        $response = $this->getJson('/api/v1/orders?created_from=2026-07-25');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals($newerOrder->id, $response->json('data.0.id'));
    }

    public function test_orders_can_be_filtered_by_created_to(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $olderOrder = Order::factory()->create([
            'user_id' => $user->id,
            'created_at' => '2026-07-20 10:00:00',
        ]);
        Order::factory()->create([
            'user_id' => $user->id,
            'created_at' => '2026-07-25 14:00:00',
        ]);

        $response = $this->getJson('/api/v1/orders?created_to=2026-07-20');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals($olderOrder->id, $response->json('data.0.id'));
    }

    public function test_orders_can_be_filtered_by_an_inclusive_date_range(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        Order::factory()->create([
            'user_id' => $user->id,
            'created_at' => '2026-07-15 08:00:00',
        ]);
        $targetOrder = Order::factory()->create([
            'user_id' => $user->id,
            'created_at' => '2026-07-20 15:30:00',
        ]);
        Order::factory()->create([
            'user_id' => $user->id,
            'created_at' => '2026-07-25 18:00:00',
        ]);

        $response = $this->getJson('/api/v1/orders?created_from=2026-07-20&created_to=2026-07-20');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals($targetOrder->id, $response->json('data.0.id'));
    }

    public function test_orders_can_be_sorted_by_total_amount_ascending(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        Order::factory()->create(['user_id' => $user->id, 'total_amount' => 100.00]);
        Order::factory()->create(['user_id' => $user->id, 'total_amount' => 25.00]);
        Order::factory()->create(['user_id' => $user->id, 'total_amount' => 50.00]);

        $response = $this->getJson('/api/v1/orders?sort_by=total_amount&sort_direction=asc');

        $response->assertStatus(200);
        $totals = array_column($response->json('data'), 'total_amount');
        $this->assertEquals(['25.00', '50.00', '100.00'], $totals);
    }

    public function test_orders_can_be_sorted_by_created_at_descending(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $o1 = Order::factory()->create(['user_id' => $user->id, 'created_at' => '2026-07-20 10:00:00']);
        $o2 = Order::factory()->create(['user_id' => $user->id, 'created_at' => '2026-07-22 10:00:00']);
        $o3 = Order::factory()->create(['user_id' => $user->id, 'created_at' => '2026-07-21 10:00:00']);

        $response = $this->getJson('/api/v1/orders?sort_by=created_at&sort_direction=desc');

        $response->assertStatus(200);
        $returnedIds = array_column($response->json('data'), 'id');
        $this->assertEquals([$o2->id, $o3->id, $o1->id], $returnedIds);
    }

    public function test_orders_can_be_sorted_by_status(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        Order::factory()->create(['user_id' => $user->id, 'status' => OrderStatus::PENDING]);
        Order::factory()->create(['user_id' => $user->id, 'status' => OrderStatus::CANCELLED]);
        Order::factory()->create(['user_id' => $user->id, 'status' => OrderStatus::DELIVERED]);

        $response = $this->getJson('/api/v1/orders?sort_by=status&sort_direction=asc');

        $response->assertStatus(200);
        $statuses = array_column($response->json('data'), 'status');
        $expectedStatuses = $statuses;
        sort($expectedStatuses);
        $this->assertEquals($expectedStatuses, $statuses);
    }

    public function test_sorting_is_deterministic_when_multiple_orders_have_equal_primary_sort_values(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $o1 = Order::factory()->create(['user_id' => $user->id, 'total_amount' => 50.00]);
        $o2 = Order::factory()->create(['user_id' => $user->id, 'total_amount' => 50.00]);
        $o3 = Order::factory()->create(['user_id' => $user->id, 'total_amount' => 50.00]);

        $response = $this->getJson('/api/v1/orders?sort_by=total_amount&sort_direction=asc');

        $response->assertStatus(200);
        $returnedIds = array_column($response->json('data'), 'id');
        $expectedIds = [$o1->id, $o2->id, $o3->id];
        sort($expectedIds);
        $this->assertEquals($expectedIds, $returnedIds);
    }

    public function test_pagination_works_correctly_for_second_page(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        Order::factory()->count(25)->create(['user_id' => $user->id]);

        $response = $this->getJson('/api/v1/orders?per_page=10&page=2');

        $response->assertStatus(200);
        $this->assertCount(10, $response->json('data'));
        $this->assertEquals(2, $response->json('meta.current_page'));
        $this->assertEquals(10, $response->json('meta.per_page'));
        $this->assertEquals(25, $response->json('meta.total'));
        $this->assertEquals(3, $response->json('meta.last_page'));
    }

    public function test_pagination_links_preserve_active_query_parameters(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        Order::factory()->count(25)->create([
            'user_id' => $user->id,
            'status' => OrderStatus::CONFIRMED,
        ]);

        $response = $this->getJson('/api/v1/orders?status=confirmed&sort_by=total_amount&sort_direction=asc&per_page=10&page=1');

        $response->assertStatus(200);
        $nextLink = $response->json('links.next');
        $this->assertNotNull($nextLink);
        $this->assertStringContainsString('status=confirmed', $nextLink);
        $this->assertStringContainsString('sort_by=total_amount', $nextLink);
        $this->assertStringContainsString('sort_direction=asc', $nextLink);
        $this->assertStringContainsString('per_page=10', $nextLink);
    }

    public function test_multiple_filters_can_be_combined(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $targetOrder = Order::factory()->create([
            'user_id' => $user->id,
            'status' => OrderStatus::CONFIRMED,
            'total_amount' => 150.00,
            'created_at' => '2026-07-20 12:00:00',
        ]);
        Order::factory()->create([
            'user_id' => $user->id,
            'status' => OrderStatus::PENDING,
            'total_amount' => 150.00,
            'created_at' => '2026-07-20 12:00:00',
        ]);
        Order::factory()->create([
            'user_id' => $user->id,
            'status' => OrderStatus::CONFIRMED,
            'total_amount' => 50.00,
            'created_at' => '2026-07-20 12:00:00',
        ]);

        $response = $this->getJson('/api/v1/orders?status=confirmed&min_total=100&created_from=2026-07-20&sort_by=total_amount&sort_direction=asc');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals($targetOrder->id, $response->json('data.0.id'));
    }

    public function test_empty_result_returns_empty_data_array_and_null_from_and_to_metadata(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/orders?status=cancelled');

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Orders retrieved successfully.',
                'data' => [],
                'meta' => [
                    'current_page' => 1,
                    'per_page' => 15,
                    'last_page' => 1,
                    'total' => 0,
                    'from' => null,
                    'to' => null,
                ],
                'links' => [
                    'prev' => null,
                    'next' => null,
                ],
            ]);
    }

    public function test_invalid_query_parameters_return_http_422(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/orders?status=invalid_status')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        $this->getJson('/api/v1/orders?min_total=-10')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['min_total']);

        $this->getJson('/api/v1/orders?min_total=100&max_total=50')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['max_total']);

        $this->getJson('/api/v1/orders?min_total=10.999')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['min_total']);

        $this->getJson('/api/v1/orders?created_from=invalid-date')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['created_from']);

        $this->getJson('/api/v1/orders?created_to=invalid-date')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['created_to']);

        $this->getJson('/api/v1/orders?created_from=2026-07-25&created_to=2026-07-20')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['created_to']);

        $this->getJson('/api/v1/orders?sort_by=password')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sort_by']);

        $this->getJson('/api/v1/orders?sort_direction=invalid_direction')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sort_direction']);

        $this->getJson('/api/v1/orders?per_page=101')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['per_page']);

        $this->getJson('/api/v1/orders?per_page=0')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['per_page']);

        $this->getJson('/api/v1/orders?page=0')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['page']);
    }

    public function test_invalid_sort_by_values_cannot_reach_an_sql_order_by_clause(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/orders?sort_by=password');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['sort_by']);
    }

    public function test_order_items_remain_included_and_formatted_using_their_snapshot_values(): void
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
            'product_title' => 'Snapshot Product Title',
            'unit_price' => 50.00,
            'quantity' => 2,
            'line_total' => 100.00,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/orders');

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    [
                        'id' => $order->id,
                        'items' => [
                            [
                                'product_id' => $product->id,
                                'product_title' => 'Snapshot Product Title',
                                'unit_price' => '50.00',
                                'quantity' => 2,
                                'line_total' => '100.00',
                            ],
                        ],
                    ],
                ],
            ]);
    }
}
