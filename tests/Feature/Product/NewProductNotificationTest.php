<?php

namespace Tests\Feature\Product;

use App\Events\ProductCreated;
use App\Listeners\SendNewProductNotifications;
use App\Models\Product;
use App\Models\User;
use App\Notifications\NewProductNotification;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NewProductNotificationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_creating_a_product_dispatches_product_created(): void
    {
        Event::fake([ProductCreated::class]);
        Storage::fake('public');

        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $file = UploadedFile::fake()->image('monitor.jpg');

        $response = $this->postJson('/api/admin/products', [
            'title' => '4K Gaming Monitor',
            'price' => 399.99,
            'description' => 'Ultra HD 144Hz Monitor',
            'available_stock' => 15,
            'image' => $file,
        ]);

        $response->assertStatus(201);

        Event::assertDispatched(ProductCreated::class, function (ProductCreated $event) {
            return $event->title === '4K Gaming Monitor'
                && $event->price === '399.99'
                && $event->availableStock === 15;
        });
    }

    public function test_invalid_product_data_does_not_dispatch_product_created(): void
    {
        Event::fake([ProductCreated::class]);

        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/products', [
            'title' => '',
            'price' => -10,
        ]);

        $response->assertStatus(422);
        Event::assertNotDispatched(ProductCreated::class);
    }

    public function test_a_regular_user_cannot_trigger_the_event(): void
    {
        Event::fake([ProductCreated::class]);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/admin/products', [
            'title' => 'Product',
            'price' => 20,
            'description' => 'Desc',
            'available_stock' => 5,
        ]);

        $response->assertStatus(403);
        Event::assertNotDispatched(ProductCreated::class);
    }

    public function test_the_product_created_listener_is_queued(): void
    {
        Queue::fake();
        Storage::fake('public');

        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $file = UploadedFile::fake()->image('speaker.jpg');

        $response = $this->postJson('/api/admin/products', [
            'title' => 'Bluetooth Speaker',
            'price' => 79.99,
            'description' => 'Portable Speaker',
            'available_stock' => 50,
            'image' => $file,
        ]);

        $response->assertStatus(201);

        Queue::assertPushedOn('notifications', CallQueuedListener::class, function ($job) {
            return $job->class === SendNewProductNotifications::class;
        });
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_the_listener_creates_one_database_notification_for_every_regular_user(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $user3 = User::factory()->create();
        $admin1 = User::factory()->admin()->create();

        $product = Product::factory()->create([
            'title' => 'Smart Watch',
            'price' => 199.99,
        ]);

        $event = new ProductCreated($product);
        $listener = new SendNewProductNotifications();
        $listener->handle($event);

        $this->assertEquals(1, $user1->notifications()->count());
        $this->assertEquals(1, $user2->notifications()->count());
        $this->assertEquals(1, $user3->notifications()->count());
        $this->assertEquals(0, $admin1->notifications()->count());
    }

    public function test_the_database_notification_contains_expected_data(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create([
            'title' => 'Smart Watch',
            'price' => 199.99,
            'description' => 'Fitness Smart Watch',
            'available_stock' => 30,
            'image_path' => 'products/watch.jpg',
        ]);

        $event = new ProductCreated($product);
        $listener = new SendNewProductNotifications();
        $listener->handle($event);

        $notification = $user->notifications()->first();
        $this->assertNotNull($notification);

        $data = $notification->data;
        $this->assertEquals('product_created', $data['event']);
        $this->assertEquals($product->id, $data['product_id']);
        $this->assertEquals('Smart Watch', $data['title']);
        $this->assertEquals('199.99', $data['price']);
        $this->assertEquals('Fitness Smart Watch', $data['description']);
        $this->assertEquals(30, $data['available_stock']);
        $this->assertStringContainsString('products/watch.jpg', $data['image_url']);
        $this->assertArrayNotHasKey('image_path', $data);
    }

    public function test_retrying_the_listener_does_not_create_duplicates(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();

        $event = new ProductCreated($product);
        $listener = new SendNewProductNotifications();

        // Handle twice
        $listener->handle($event);
        $listener->handle($event);

        $this->assertEquals(1, $user->notifications()->count());
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_the_same_user_receives_separate_notifications_for_different_products(): void
    {
        $user = User::factory()->create();
        $product1 = Product::factory()->create();
        $product2 = Product::factory()->create();

        $event1 = new ProductCreated($product1);
        $event2 = new ProductCreated($product2);
        $listener = new SendNewProductNotifications();

        $listener->handle($event1);
        $listener->handle($event2);

        $this->assertEquals(2, $user->notifications()->count());
    }

    public function test_different_users_receive_distinct_notification_ids_for_the_same_product(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $product = Product::factory()->create();

        $event = new ProductCreated($product);
        $listener = new SendNewProductNotifications();
        $listener->handle($event);

        $notif1 = $user1->notifications()->first();
        $notif2 = $user2->notifications()->first();

        $this->assertNotNull($notif1);
        $this->assertNotNull($notif2);
        $this->assertNotEquals($notif1->id, $notif2->id);
    }

    public function test_admin_product_creation_returns_success_without_processing_notification_delivery_synchronously(): void
    {
        Queue::fake();
        Storage::fake('public');

        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $file = UploadedFile::fake()->image('laptop.jpg');

        $response = $this->postJson('/api/admin/products', [
            'title' => 'Gaming Laptop',
            'price' => 1499.99,
            'description' => 'High performance laptop',
            'available_stock' => 10,
            'image' => $file,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('products', ['title' => 'Gaming Laptop']);

        Queue::assertPushedOn('notifications', CallQueuedListener::class, function ($job) {
            return $job->class === SendNewProductNotifications::class;
        });
    }

    public function test_is_duplicate_notification_id_exception_identifies_duplicate_and_unrelated_exceptions(): void
    {
        $listener = new SendNewProductNotifications();
        $refMethod = new \ReflectionMethod(SendNewProductNotifications::class, 'isDuplicateNotificationIdException');
        $refMethod->setAccessible(true);

        // 1. MySQL duplicate key on notifications.PRIMARY
        $mysqlDuplicate = new \Illuminate\Database\QueryException(
            'mysql',
            'INSERT INTO notifications ...',
            [],
            new \Exception("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'new-product:1:user:2' for key 'notifications.PRIMARY'")
        );
        $this->assertTrue($refMethod->invoke($listener, $mysqlDuplicate));

        // 2. SQLite duplicate key on notifications.id
        $sqliteDuplicate = new \Illuminate\Database\QueryException(
            'sqlite',
            'INSERT INTO notifications ...',
            [],
            new \Exception("SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: notifications.id")
        );
        $this->assertTrue($refMethod->invoke($listener, $sqliteDuplicate));

        // 3. PostgreSQL duplicate key on notifications_pkey
        $postgresDuplicate = new \Illuminate\Database\QueryException(
            'pgsql',
            'INSERT INTO notifications ...',
            [],
            new \Exception("SQLSTATE[23505]: Unique violation: 7 ERROR: duplicate key value violates unique constraint \"notifications_pkey\"")
        );
        $this->assertTrue($refMethod->invoke($listener, $postgresDuplicate));

        // 4. Foreign key constraint violation (unrelated SQLSTATE 23000)
        $foreignKeyException = new \Illuminate\Database\QueryException(
            'mysql',
            'INSERT INTO notifications ...',
            [],
            new \Exception("SQLSTATE[23000]: Integrity constraint violation: 1452 Cannot add or update a child row: a foreign key constraint fails")
        );
        $this->assertFalse($refMethod->invoke($listener, $foreignKeyException));

        // 5. NOT NULL constraint failure (unrelated SQLSTATE 23000)
        $notNullException = new \Illuminate\Database\QueryException(
            'sqlite',
            'INSERT INTO notifications ...',
            [],
            new \Exception("SQLSTATE[23000]: Integrity constraint violation: 19 NOT NULL constraint failed: notifications.data")
        );
        $this->assertFalse($refMethod->invoke($listener, $notNullException));
    }
}
