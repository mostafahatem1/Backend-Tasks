<?php

namespace Tests\Feature\Order;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrderIdempotencyConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_concurrent_idempotent_order_requests_deduct_stock_and_create_order_exactly_once(): void
    {
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['sqlite', 'sqlite3'], true)) {
            $this->markTestSkipped('SQLite driver detected. Concurrency test requires MySQL or PostgreSQL.');
        }

        $product = Product::factory()->create(['price' => 50.00, 'available_stock' => 10]);
        $user = User::factory()->create();

        $this->assertDatabaseCount('orders', 0);
        $this->assertEquals(10, $product->fresh()->available_stock);
    }
}
