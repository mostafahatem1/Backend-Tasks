<?php

namespace Tests\Feature\Order;

use App\Enums\OrderStatus;
use App\Events\OrderStatusChanged;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderStatusManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_unauthenticated_request_cannot_update_an_order_status(): void
    {
        $order = Order::factory()->create(['status' => OrderStatus::PENDING]);

        $response = $this->patchJson("/api/v1/admin/orders/{$order->id}/status", [
            'status' => OrderStatus::CONFIRMED->value,
        ]);

        $response->assertStatus(401);
    }

    public function test_a_regular_authenticated_user_cannot_update_an_order_status(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create(['status' => OrderStatus::PENDING]);

        Sanctum::actingAs($user);

        $response = $this->patchJson("/api/v1/admin/orders/{$order->id}/status", [
            'status' => OrderStatus::CONFIRMED->value,
        ]);

        $response->assertStatus(403);
        $this->assertEquals(OrderStatus::PENDING, $order->fresh()->status);
        $this->assertDatabaseCount('order_status_histories', 0);
    }

    public function test_an_admin_can_perform_every_valid_transition_in_a_complete_workflow(): void
    {
        $admin = User::factory()->admin()->create();
        $order = Order::factory()->create(['status' => OrderStatus::PENDING]);

        Sanctum::actingAs($admin);

        // pending -> confirmed
        $this->patchJson("/api/v1/admin/orders/{$order->id}/status", ['status' => 'confirmed'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'confirmed');

        // confirmed -> processing
        $this->patchJson("/api/v1/admin/orders/{$order->id}/status", ['status' => 'processing'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'processing');

        // processing -> shipped
        $this->patchJson("/api/v1/admin/orders/{$order->id}/status", ['status' => 'shipped'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'shipped');

        // shipped -> delivered
        $this->patchJson("/api/v1/admin/orders/{$order->id}/status", ['status' => 'delivered'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'delivered');

        $this->assertDatabaseCount('order_status_histories', 4);
    }

    public function test_an_admin_can_cancel_from_valid_statuses(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        // pending -> cancelled
        $o1 = Order::factory()->create(['status' => OrderStatus::PENDING]);
        $this->patchJson("/api/v1/admin/orders/{$o1->id}/status", ['status' => 'cancelled'])->assertStatus(200);

        // confirmed -> cancelled
        $o2 = Order::factory()->create(['status' => OrderStatus::CONFIRMED]);
        $this->patchJson("/api/v1/admin/orders/{$o2->id}/status", ['status' => 'cancelled'])->assertStatus(200);

        // processing -> cancelled
        $o3 = Order::factory()->create(['status' => OrderStatus::PROCESSING]);
        $this->patchJson("/api/v1/admin/orders/{$o3->id}/status", ['status' => 'cancelled'])->assertStatus(200);
    }

    public function test_invalid_status_input_returns_422(): void
    {
        $admin = User::factory()->admin()->create();
        $order = Order::factory()->create(['status' => OrderStatus::PENDING]);

        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/admin/orders/{$order->id}/status", ['status' => 'invalid_status'])
            ->assertStatus(422);

        $this->patchJson("/api/v1/admin/orders/{$order->id}/status", [])
            ->assertStatus(422);
    }

    public function test_invalid_workflow_transitions_return_409(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        // pending -> shipped
        $o1 = Order::factory()->create(['status' => OrderStatus::PENDING]);
        $this->patchJson("/api/v1/admin/orders/{$o1->id}/status", ['status' => 'shipped'])
            ->assertStatus(409)
            ->assertJson([
                'message' => 'Invalid order status transition.',
                'data' => [
                    'current_status' => 'pending',
                    'requested_status' => 'shipped',
                    'allowed_statuses' => ['confirmed', 'cancelled'],
                ],
            ]);

        // confirmed -> delivered
        $o2 = Order::factory()->create(['status' => OrderStatus::CONFIRMED]);
        $this->patchJson("/api/v1/admin/orders/{$o2->id}/status", ['status' => 'delivered'])->assertStatus(409);

        // shipped -> processing
        $o3 = Order::factory()->create(['status' => OrderStatus::SHIPPED]);
        $this->patchJson("/api/v1/admin/orders/{$o3->id}/status", ['status' => 'processing'])->assertStatus(409);

        // delivered -> cancelled
        $o4 = Order::factory()->create(['status' => OrderStatus::DELIVERED]);
        $this->patchJson("/api/v1/admin/orders/{$o4->id}/status", ['status' => 'cancelled'])->assertStatus(409);

        // cancelled -> confirmed
        $o5 = Order::factory()->create(['status' => OrderStatus::CANCELLED]);
        $this->patchJson("/api/v1/admin/orders/{$o5->id}/status", ['status' => 'confirmed'])->assertStatus(409);
    }

    public function test_an_invalid_transition_makes_no_database_changes_and_dispatches_no_event(): void
    {
        Event::fake([OrderStatusChanged::class]);

        $admin = User::factory()->admin()->create();
        $order = Order::factory()->create(['status' => OrderStatus::PENDING]);

        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/admin/orders/{$order->id}/status", ['status' => 'delivered'])->assertStatus(409);

        $this->assertEquals(OrderStatus::PENDING, $order->fresh()->status);
        $this->assertDatabaseCount('order_status_histories', 0);
        Event::assertNotDispatched(OrderStatusChanged::class);
    }

    public function test_every_actual_transition_creates_one_history_row(): void
    {
        $admin = User::factory()->admin()->create();
        $order = Order::factory()->create(['status' => OrderStatus::PENDING]);

        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/admin/orders/{$order->id}/status", ['status' => 'confirmed'])->assertStatus(200);

        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'previous_status' => OrderStatus::PENDING->value,
            'new_status' => OrderStatus::CONFIRMED->value,
            'changed_by_user_id' => $admin->id,
        ]);
    }

    public function test_multiple_actual_transitions_create_correctly_ordered_history_records(): void
    {
        $admin = User::factory()->admin()->create();
        $order = Order::factory()->create(['status' => OrderStatus::PENDING]);

        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/admin/orders/{$order->id}/status", ['status' => 'confirmed']);
        $this->patchJson("/api/v1/admin/orders/{$order->id}/status", ['status' => 'processing']);

        $histories = $order->fresh()->statusHistories;
        $this->assertCount(2, $histories);
        $this->assertEquals(OrderStatus::PENDING, $histories[0]->previous_status);
        $this->assertEquals(OrderStatus::CONFIRMED, $histories[0]->new_status);
        $this->assertEquals(OrderStatus::CONFIRMED, $histories[1]->previous_status);
        $this->assertEquals(OrderStatus::PROCESSING, $histories[1]->new_status);
    }

    public function test_submitting_the_current_status_again_is_an_idempotent_no_op(): void
    {
        Event::fake([OrderStatusChanged::class]);

        $admin = User::factory()->admin()->create();
        $order = Order::factory()->create(['status' => OrderStatus::PENDING]);

        Sanctum::actingAs($admin);

        $response = $this->patchJson("/api/v1/admin/orders/{$order->id}/status", ['status' => 'pending']);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Order status is unchanged.',
            ]);

        $this->assertDatabaseCount('order_status_histories', 0);
        Event::assertNotDispatched(OrderStatusChanged::class);
    }

    public function test_two_requests_setting_the_same_target_status_result_in_one_actual_change_and_one_history_record(): void
    {
        $admin = User::factory()->admin()->create();
        $order = Order::factory()->create(['status' => OrderStatus::PENDING]);

        Sanctum::actingAs($admin);

        // 1st request -> change to confirmed
        $this->patchJson("/api/v1/admin/orders/{$order->id}/status", ['status' => 'confirmed'])->assertStatus(200);

        // 2nd request -> same status confirmed
        $this->patchJson("/api/v1/admin/orders/{$order->id}/status", ['status' => 'confirmed'])->assertStatus(200);

        $this->assertDatabaseCount('order_status_histories', 1);
    }

    public function test_delivered_and_cancelled_orders_are_terminal(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $deliveredOrder = Order::factory()->create(['status' => OrderStatus::DELIVERED]);
        $this->patchJson("/api/v1/admin/orders/{$deliveredOrder->id}/status", ['status' => 'processing'])->assertStatus(409);

        $cancelledOrder = Order::factory()->create(['status' => OrderStatus::CANCELLED]);
        $this->patchJson("/api/v1/admin/orders/{$cancelledOrder->id}/status", ['status' => 'confirmed'])->assertStatus(409);
    }

    public function test_cancelling_an_order_does_not_restore_product_stock(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->create(['available_stock' => 5]);
        $order = Order::factory()->create(['status' => OrderStatus::PENDING]);

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 2,
        ]);

        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/admin/orders/{$order->id}/status", ['status' => 'cancelled'])->assertStatus(200);

        $this->assertEquals(5, $product->fresh()->available_stock);
    }
}
