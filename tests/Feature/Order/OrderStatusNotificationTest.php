<?php

namespace Tests\Feature\Order;

use App\Enums\OrderStatus;
use App\Events\OrderStatusChanged;
use App\Listeners\SendOrderStatusChangedNotification;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\User;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderStatusNotificationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_a_real_status_change_dispatches_order_status_changed_exactly_once(): void
    {
        Event::fake([OrderStatusChanged::class]);

        $user = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $order = Order::factory()->create(['user_id' => $user->id, 'status' => OrderStatus::PENDING]);

        Sanctum::actingAs($admin);

        $response = $this->patchJson("/api/admin/orders/{$order->id}/status", [
            'status' => 'confirmed',
        ]);

        $response->assertStatus(200);

        Event::assertDispatched(OrderStatusChanged::class, 1);
    }

    public function test_sending_the_current_status_again_dispatches_no_event(): void
    {
        Event::fake([OrderStatusChanged::class]);

        $admin = User::factory()->admin()->create();
        $order = Order::factory()->create(['status' => OrderStatus::PENDING]);

        Sanctum::actingAs($admin);

        $response = $this->patchJson("/api/admin/orders/{$order->id}/status", [
            'status' => 'pending',
        ]);

        $response->assertStatus(200);

        Event::assertNotDispatched(OrderStatusChanged::class);
    }

    public function test_an_invalid_transition_dispatches_no_event(): void
    {
        Event::fake([OrderStatusChanged::class]);

        $admin = User::factory()->admin()->create();
        $order = Order::factory()->create(['status' => OrderStatus::PENDING]);

        Sanctum::actingAs($admin);

        $response = $this->patchJson("/api/admin/orders/{$order->id}/status", [
            'status' => 'shipped',
        ]);

        $response->assertStatus(409);

        Event::assertNotDispatched(OrderStatusChanged::class);
    }

    public function test_the_queued_listener_is_pushed_to_the_notifications_queue(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $order = Order::factory()->create(['user_id' => $user->id, 'status' => OrderStatus::PENDING]);

        Sanctum::actingAs($admin);

        $response = $this->patchJson("/api/admin/orders/{$order->id}/status", [
            'status' => 'confirmed',
        ]);

        $response->assertStatus(200);

        Queue::assertPushedOn('notifications', CallQueuedListener::class, function ($job) {
            return $job->class === SendOrderStatusChangedNotification::class;
        });
    }

    public function test_the_status_update_api_returns_http_200_without_processing_notification_delivery_synchronously(): void
    {
        Queue::fake();

        $admin = User::factory()->admin()->create();
        $order = Order::factory()->create(['status' => OrderStatus::PENDING]);

        Sanctum::actingAs($admin);

        $response = $this->patchJson("/api/admin/orders/{$order->id}/status", [
            'status' => 'confirmed',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Order status updated successfully.',
            ]);

        Queue::assertPushedOn('notifications', CallQueuedListener::class, function ($job) {
            return $job->class === SendOrderStatusChangedNotification::class;
        });
    }

    public function test_the_order_owner_receives_one_database_notification_after_listener_execution(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $order = Order::factory()->create(['user_id' => $owner->id, 'status' => OrderStatus::PENDING]);

        $history = OrderStatusHistory::create([
            'order_id' => $order->id,
            'previous_status' => OrderStatus::PENDING,
            'new_status' => OrderStatus::CONFIRMED,
            'changed_by_user_id' => $admin->id,
            'changed_at' => now(),
        ]);

        $event = new OrderStatusChanged($history, $owner->id);
        $listener = new SendOrderStatusChangedNotification();
        $listener->handle($event);

        $this->assertEquals(1, $owner->notifications()->count());
        $this->assertEquals(0, $admin->notifications()->count());
    }

    public function test_the_admin_actor_does_not_receive_the_order_owner_notification_unless_they_own_the_order(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $order = Order::factory()->create(['user_id' => $owner->id, 'status' => OrderStatus::PENDING]);

        $history = OrderStatusHistory::create([
            'order_id' => $order->id,
            'previous_status' => OrderStatus::PENDING,
            'new_status' => OrderStatus::CONFIRMED,
            'changed_by_user_id' => $admin->id,
            'changed_at' => now(),
        ]);

        $event = new OrderStatusChanged($history, $owner->id);
        $listener = new SendOrderStatusChangedNotification();
        $listener->handle($event);

        $this->assertEquals(1, $owner->notifications()->count());
        $this->assertEquals(0, $admin->notifications()->count());
    }

    public function test_notification_data_contains_expected_fields(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $order = Order::factory()->create(['user_id' => $owner->id, 'status' => OrderStatus::PENDING]);

        $history = OrderStatusHistory::create([
            'order_id' => $order->id,
            'previous_status' => OrderStatus::PENDING,
            'new_status' => OrderStatus::CONFIRMED,
            'changed_by_user_id' => $admin->id,
            'changed_at' => now(),
        ]);

        $event = new OrderStatusChanged($history, $owner->id);
        $listener = new SendOrderStatusChangedNotification();
        $listener->handle($event);

        $notification = $owner->notifications()->first();
        $this->assertNotNull($notification);

        $data = $notification->data;
        $this->assertEquals('order_status_changed', $data['event']);
        $this->assertEquals($order->id, $data['order_id']);
        $this->assertEquals('pending', $data['previous_status']);
        $this->assertEquals('confirmed', $data['new_status']);
        $this->assertEquals($admin->id, $data['changed_by_user_id']);
        $this->assertNotNull($data['changed_at']);
    }

    public function test_retrying_the_same_listener_does_not_create_duplicate_notifications(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $order = Order::factory()->create(['user_id' => $owner->id, 'status' => OrderStatus::PENDING]);

        $history = OrderStatusHistory::create([
            'order_id' => $order->id,
            'previous_status' => OrderStatus::PENDING,
            'new_status' => OrderStatus::CONFIRMED,
            'changed_by_user_id' => $admin->id,
            'changed_at' => now(),
        ]);

        $event = new OrderStatusChanged($history, $owner->id);
        $listener = new SendOrderStatusChangedNotification();

        $listener->handle($event);
        $listener->handle($event);

        $this->assertEquals(1, $owner->notifications()->count());
    }

    public function test_different_history_records_for_the_same_order_create_separate_notifications(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $order = Order::factory()->create(['user_id' => $owner->id, 'status' => OrderStatus::PENDING]);

        $h1 = OrderStatusHistory::create([
            'order_id' => $order->id,
            'previous_status' => OrderStatus::PENDING,
            'new_status' => OrderStatus::CONFIRMED,
            'changed_by_user_id' => $admin->id,
            'changed_at' => now(),
        ]);

        $h2 = OrderStatusHistory::create([
            'order_id' => $order->id,
            'previous_status' => OrderStatus::CONFIRMED,
            'new_status' => OrderStatus::PROCESSING,
            'changed_by_user_id' => $admin->id,
            'changed_at' => now(),
        ]);

        $listener = new SendOrderStatusChangedNotification();
        $listener->handle(new OrderStatusChanged($h1, $owner->id));
        $listener->handle(new OrderStatusChanged($h2, $owner->id));

        $this->assertEquals(2, $owner->notifications()->count());
    }

    public function test_different_orders_create_distinct_notification_ids(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->admin()->create();

        $o1 = Order::factory()->create(['user_id' => $owner->id, 'status' => OrderStatus::PENDING]);
        $o2 = Order::factory()->create(['user_id' => $owner->id, 'status' => OrderStatus::PENDING]);

        $h1 = OrderStatusHistory::create([
            'order_id' => $o1->id,
            'previous_status' => OrderStatus::PENDING,
            'new_status' => OrderStatus::CONFIRMED,
            'changed_by_user_id' => $admin->id,
            'changed_at' => now(),
        ]);

        $h2 = OrderStatusHistory::create([
            'order_id' => $o2->id,
            'previous_status' => OrderStatus::PENDING,
            'new_status' => OrderStatus::CONFIRMED,
            'changed_by_user_id' => $admin->id,
            'changed_at' => now(),
        ]);

        $listener = new SendOrderStatusChangedNotification();
        $listener->handle(new OrderStatusChanged($h1, $owner->id));
        $listener->handle(new OrderStatusChanged($h2, $owner->id));

        $notifs = $owner->notifications()->get();
        $this->assertCount(2, $notifs);
        $this->assertNotEquals($notifs[0]->id, $notifs[1]->id);
    }

    public function test_a_notification_listener_failure_does_not_remove_or_roll_back_status_or_history(): void
    {
        $admin = User::factory()->admin()->create();
        $order = Order::factory()->create(['status' => OrderStatus::PENDING]);

        $listener = function (OrderStatusChanged $event) {
            throw new \RuntimeException('Simulated event dispatch failure');
        };

        Event::listen(OrderStatusChanged::class, $listener);

        try {
            Sanctum::actingAs($admin);

            $response = $this->patchJson("/api/admin/orders/{$order->id}/status", [
                'status' => 'confirmed',
            ]);

            $response->assertStatus(200)
                ->assertJson([
                    'message' => 'Order status updated successfully.',
                ]);

            $this->assertEquals(OrderStatus::CONFIRMED, $order->fresh()->status);
            $this->assertDatabaseCount('order_status_histories', 1);
        } finally {
            $dispatcher = Event::getFacadeRoot();
            if ($dispatcher) {
                $dispatcher->forget(OrderStatusChanged::class);
            }
        }
    }
}
