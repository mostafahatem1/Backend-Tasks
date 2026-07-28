<?php

namespace Tests\Feature\Order;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrderCreationConcurrencyTest extends TestCase
{
    public function test_concurrent_order_attempts_against_same_stock(): void
    {
        $driver = DB::connection()->getDriverName();

        if (in_array(strtolower($driver), ['sqlite', 'sqlite3'], true)) {
            $this->markTestSkipped('SQLite driver does not support row-level locking (lockForUpdate) or parallel process transaction concurrency testing.');
        }

        if (! function_exists('proc_open')) {
            $this->markTestSkipped('proc_open function is not available in current environment for running parallel CLI child processes.');
        }

        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $product = Product::factory()->create(['available_stock' => 1]);

        $artisan = base_path('artisan');

        $script1 = sprintf(
            'use App\Models\User; use App\Services\OrderService; $user = User::find(%d); $service = app(OrderService::class); try { $service->createOrder($user, [["product_id" => %d, "quantity" => 1]]); echo "SUCCESS"; } catch (\App\Exceptions\InsufficientStockException $e) { echo "INSUFFICIENT_STOCK"; }',
            $user1->id,
            $product->id
        );

        $script2 = sprintf(
            'use App\Models\User; use App\Services\OrderService; $user = User::find(%d); $service = app(OrderService::class); try { $service->createOrder($user, [["product_id" => %d, "quantity" => 1]]); echo "SUCCESS"; } catch (\App\Exceptions\InsufficientStockException $e) { echo "INSUFFICIENT_STOCK"; }',
            $user2->id,
            $product->id
        );

        $cmd1 = [PHP_BINARY, $artisan, 'tinker', '--execute', $script1];
        $cmd2 = [PHP_BINARY, $artisan, 'tinker', '--execute', $script2];

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc1 = proc_open($cmd1, $descriptors, $pipes1);
        $proc2 = proc_open($cmd2, $descriptors, $pipes2);

        if (! is_resource($proc1) || ! is_resource($proc2)) {
            $this->markTestSkipped('Could not spawn parallel processes for concurrency testing.');
        }

        $out1 = stream_get_contents($pipes1[1]);
        $out2 = stream_get_contents($pipes2[1]);

        fclose($pipes1[0]);
        fclose($pipes1[1]);
        fclose($pipes1[2]);
        proc_close($proc1);

        fclose($pipes2[0]);
        fclose($pipes2[1]);
        fclose($pipes2[2]);
        proc_close($proc2);

        $outputs = [$out1, $out2];
        $successCount = 0;
        $insufficientCount = 0;

        foreach ($outputs as $out) {
            if (str_contains($out, 'SUCCESS')) {
                $successCount++;
            }
            if (str_contains($out, 'INSUFFICIENT_STOCK')) {
                $insufficientCount++;
            }
        }

        $this->assertEquals(1, $successCount, 'Exactly one order attempt must succeed.');
        $this->assertEquals(1, $insufficientCount, 'Exactly one order attempt must receive insufficient stock.');
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_items', 1);
        $this->assertEquals(0, $product->fresh()->available_stock);
    }
}
