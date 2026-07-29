<?php

namespace Tests\Feature\Product;

use App\Events\ProductRestocked;
use App\Listeners\SendBackInStockNotifications;
use App\Models\Product;
use App\Models\ProductStockNotificationRequest;
use App\Models\User;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BackInStockNotificationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_an_unauthenticated_user_cannot_request_a_stock_notification(): void
    {
        $product = Product::factory()->create(['available_stock' => 0]);

        $response = $this->postJson("/api/v1/products/{$product->id}/stock-notification-requests");

        $response->assertStatus(401);
    }

    public function test_a_request_cannot_be_created_while_the_product_is_in_stock(): void
    {
        $product = Product::factory()->create(['available_stock' => 10]);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/v1/products/{$product->id}/stock-notification-requests");

        $response->assertStatus(409)
            ->assertJson([
                'message' => 'Product is currently in stock.',
            ]);

        $this->assertDatabaseCount('product_stock_notification_requests', 0);
    }

    public function test_an_authenticated_user_can_request_a_notification_for_an_out_of_stock_product(): void
    {
        $product = Product::factory()->create(['available_stock' => 0]);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/v1/products/{$product->id}/stock-notification-requests");

        $response->assertStatus(201)
            ->assertJson([
                'message' => 'Stock notification request created successfully.',
                'data' => [
                    'product_id' => $product->id,
                ],
            ]);

        $this->assertDatabaseHas('product_stock_notification_requests', [
            'user_id' => $user->id,
            'product_id' => $product->id,
        ]);
    }

    public function test_submitting_the_same_request_again_is_idempotent(): void
    {
        $product = Product::factory()->create(['available_stock' => 0]);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $firstResponse = $this->postJson("/api/v1/products/{$product->id}/stock-notification-requests");
        $firstResponse->assertStatus(201);

        $secondResponse = $this->postJson("/api/v1/products/{$product->id}/stock-notification-requests");
        $secondResponse->assertStatus(200)
            ->assertJson([
                'message' => 'Stock notification request already exists.',
                'data' => [
                    'product_id' => $product->id,
                ],
            ]);

        $this->assertDatabaseCount('product_stock_notification_requests', 1);
    }

    public function test_different_users_can_request_notifications_for_the_same_product(): void
    {
        $product = Product::factory()->create(['available_stock' => 0]);

        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        Sanctum::actingAs($user1);
        $this->postJson("/api/v1/products/{$product->id}/stock-notification-requests")->assertStatus(201);

        Sanctum::actingAs($user2);
        $this->postJson("/api/v1/products/{$product->id}/stock-notification-requests")->assertStatus(201);

        $this->assertDatabaseCount('product_stock_notification_requests', 2);
    }

    public function test_a_product_restocked_event_is_dispatched_when_stock_changes_from_0_to_a_positive_value(): void
    {
        Event::fake([ProductRestocked::class]);

        $product = Product::factory()->create(['available_stock' => 0]);

        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $response = $this->patchJson("/api/v1/admin/products/{$product->id}", [
            'available_stock' => 10,
        ]);

        $response->assertStatus(200);

        Event::assertDispatched(ProductRestocked::class, function (ProductRestocked $event) use ($product) {
            return $event->productId === $product->id
                && $event->availableStock === 10;
        });
    }

    public function test_product_restocked_is_not_dispatched_for_non_restock_updates(): void
    {
        Event::fake([ProductRestocked::class]);

        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        // Case A: Positive to positive (5 -> 10)
        $productA = Product::factory()->create(['available_stock' => 5]);
        $this->patchJson("/api/v1/admin/products/{$productA->id}", ['available_stock' => 10])->assertStatus(200);

        // Case B: Positive to zero (5 -> 0)
        $productB = Product::factory()->create(['available_stock' => 5]);
        $this->patchJson("/api/v1/admin/products/{$productB->id}", ['available_stock' => 0])->assertStatus(200);

        // Case C: Title update when stock remains zero (0 -> 0)
        $productC = Product::factory()->create(['available_stock' => 0]);
        $this->patchJson("/api/v1/admin/products/{$productC->id}", ['title' => 'Updated Title'])->assertStatus(200);

        Event::assertNotDispatched(ProductRestocked::class);
    }

    public function test_the_restock_listener_is_queued_on_the_notifications_queue(): void
    {
        Queue::fake();

        $product = Product::factory()->create(['available_stock' => 0]);

        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $response = $this->patchJson("/api/v1/admin/products/{$product->id}", [
            'available_stock' => 15,
        ]);

        $response->assertStatus(200);

        Queue::assertPushedOn('notifications', CallQueuedListener::class, function ($job) {
            return $job->class === SendBackInStockNotifications::class;
        });
    }

    public function test_updating_stock_from_0_to_a_positive_value_returns_normal_response_without_synchronous_delivery(): void
    {
        Queue::fake();

        $product = Product::factory()->create(['available_stock' => 0]);

        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $response = $this->patchJson("/api/v1/admin/products/{$product->id}", [
            'available_stock' => 20,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Product updated successfully.',
                'data' => [
                    'available_stock' => 20,
                ],
            ]);

        Queue::assertPushedOn('notifications', CallQueuedListener::class, function ($job) {
            return $job->class === SendBackInStockNotifications::class;
        });
    }

    public function test_only_users_with_active_requests_receive_the_back_in_stock_notification(): void
    {
        Storage::fake('public');

        $product = Product::factory()->create(['available_stock' => 0]);

        $requester = User::factory()->create();
        $nonRequester = User::factory()->create();

        ProductStockNotificationRequest::create([
            'user_id' => $requester->id,
            'product_id' => $product->id,
        ]);

        Queue::fake();
        $product->update(['available_stock' => 10]);

        $event = new ProductRestocked($product->fresh());
        $listener = new SendBackInStockNotifications();
        $listener->handle($event);

        $this->assertEquals(1, $requester->notifications()->count());
        $this->assertEquals(0, $nonRequester->notifications()->count());
    }

    public function test_users_without_requests_receive_no_notification(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['available_stock' => 0]);

        Queue::fake();
        $product->update(['available_stock' => 10]);

        $event = new ProductRestocked($product->fresh());
        $listener = new SendBackInStockNotifications();
        $listener->handle($event);

        $this->assertEquals(0, $user->notifications()->count());
    }

    public function test_notification_data_contains_expected_fields(): void
    {
        Storage::fake('public');

        $product = Product::factory()->create([
            'title' => 'NVIDIA RTX 4090',
            'price' => 1599.99,
            'available_stock' => 0,
            'image_path' => 'products/gpu.jpg',
        ]);

        $user = User::factory()->create();
        ProductStockNotificationRequest::create([
            'user_id' => $user->id,
            'product_id' => $product->id,
        ]);

        Queue::fake();
        $product->update(['available_stock' => 5]);

        $event = new ProductRestocked($product->fresh());
        $listener = new SendBackInStockNotifications();
        $listener->handle($event);

        $notification = $user->notifications()->first();
        $this->assertNotNull($notification);

        $data = $notification->data;
        $this->assertEquals('product_back_in_stock', $data['event']);
        $this->assertEquals($product->id, $data['product_id']);
        $this->assertEquals('NVIDIA RTX 4090', $data['title']);
        $this->assertEquals('1599.99', $data['price']);
        $this->assertEquals(5, $data['available_stock']);
        $this->assertStringContainsString('products/gpu.jpg', $data['image_url']);
        $this->assertArrayNotHasKey('image_path', $data);
    }

    public function test_the_active_request_is_deleted_after_successful_notification_delivery(): void
    {
        Storage::fake('public');

        $product = Product::factory()->create(['available_stock' => 0]);

        $user = User::factory()->create();
        ProductStockNotificationRequest::create([
            'user_id' => $user->id,
            'product_id' => $product->id,
        ]);

        $this->assertDatabaseCount('product_stock_notification_requests', 1);

        Queue::fake();
        $product->update(['available_stock' => 10]);

        $event = new ProductRestocked($product->fresh());
        $listener = new SendBackInStockNotifications();
        $listener->handle($event);

        $this->assertDatabaseCount('product_stock_notification_requests', 0);
    }

    public function test_running_the_same_listener_more_than_once_is_idempotent(): void
    {
        Storage::fake('public');

        $product = Product::factory()->create(['available_stock' => 0]);

        $user = User::factory()->create();
        ProductStockNotificationRequest::create([
            'user_id' => $user->id,
            'product_id' => $product->id,
        ]);

        Queue::fake();
        $product->update(['available_stock' => 10]);

        $event = new ProductRestocked($product->fresh());
        $listener = new SendBackInStockNotifications();

        $listener->handle($event);
        $listener->handle($event);

        $this->assertEquals(1, $user->notifications()->count());
        $this->assertDatabaseCount('product_stock_notification_requests', 0);
    }

    public function test_different_users_receive_different_deterministic_notification_ids(): void
    {
        Storage::fake('public');

        $product = Product::factory()->create(['available_stock' => 0]);

        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        ProductStockNotificationRequest::create([
            'user_id' => $user1->id,
            'product_id' => $product->id,
        ]);

        ProductStockNotificationRequest::create([
            'user_id' => $user2->id,
            'product_id' => $product->id,
        ]);

        Queue::fake();
        $product->update(['available_stock' => 10]);

        $event = new ProductRestocked($product->fresh());
        $listener = new SendBackInStockNotifications();
        $listener->handle($event);

        $notif1 = $user1->notifications()->first();
        $notif2 = $user2->notifications()->first();

        $this->assertNotNull($notif1);
        $this->assertNotNull($notif2);
        $this->assertNotEquals($notif1->id, $notif2->id);
    }

    public function test_two_separate_restock_cycles_work_correctly(): void
    {
        Storage::fake('public');

        $product = Product::factory()->create(['available_stock' => 0]);
        $user = User::factory()->create();

        // Cycle 1
        ProductStockNotificationRequest::create([
            'user_id' => $user->id,
            'product_id' => $product->id,
        ]);

        Queue::fake();
        $product->update(['available_stock' => 5]);
        $event1 = new ProductRestocked($product->fresh());
        $listener = new SendBackInStockNotifications();
        $listener->handle($event1);

        $this->assertEquals(1, $user->notifications()->count());
        $this->assertDatabaseCount('product_stock_notification_requests', 0);

        // Cycle 2: Out of stock again -> request -> restock again
        $product->update(['available_stock' => 0]);

        ProductStockNotificationRequest::create([
            'user_id' => $user->id,
            'product_id' => $product->id,
        ]);

        $product->update(['available_stock' => 10]);
        $event2 = new ProductRestocked($product->fresh());
        $listener->handle($event2);

        $this->assertEquals(2, $user->notifications()->count());
        $this->assertDatabaseCount('product_stock_notification_requests', 0);
    }

    public function test_if_the_product_becomes_out_of_stock_again_before_listener_runs_no_notification_is_sent(): void
    {
        Storage::fake('public');

        $product = Product::factory()->create(['available_stock' => 0]);
        $user = User::factory()->create();

        ProductStockNotificationRequest::create([
            'user_id' => $user->id,
            'product_id' => $product->id,
        ]);

        Queue::fake();

        // Stock goes 0 -> 5 (event generated)
        $product->update(['available_stock' => 5]);
        $event = new ProductRestocked($product->fresh());

        // Stock goes 5 -> 0 before listener runs
        $product->update(['available_stock' => 0]);

        $listener = new SendBackInStockNotifications();
        $listener->handle($event);

        $this->assertEquals(0, $user->notifications()->count());
        $this->assertDatabaseCount('product_stock_notification_requests', 1);
    }
}
