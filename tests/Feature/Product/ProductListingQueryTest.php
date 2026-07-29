<?php

namespace Tests\Feature\Product;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductListingQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_unauthenticated_user_still_cannot_list_products(): void
    {
        $response = $this->getJson('/api/v1/products');

        $response->assertStatus(401);
    }

    public function test_the_default_product_list_returns_paginated_and_sorted_products(): void
    {
        Product::factory()->count(20)->create();

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/products');

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Products retrieved successfully.',
            ])
            ->assertJsonStructure([
                'message',
                'data' => [
                    '*' => [
                        'id',
                        'title',
                        'price',
                        'description',
                        'available_stock',
                        'image_url',
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

    public function test_products_can_be_searched_by_title(): void
    {
        $targetProduct = Product::factory()->create([
            'title' => 'Gaming Laptop Core i7',
            'description' => 'High performance work station',
        ]);
        Product::factory()->create([
            'title' => 'Office Mechanical Keyboard',
            'description' => 'Quiet typing switches',
        ]);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/products?search=Laptop');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals($targetProduct->id, $response->json('data.0.id'));
    }

    public function test_products_can_be_searched_by_description(): void
    {
        $targetProduct = Product::factory()->create([
            'title' => 'Gaming Mouse',
            'description' => 'Ergonomic gaming design with high DPI sensor',
        ]);
        Product::factory()->create([
            'title' => 'Desk Lamp',
            'description' => 'LED workspace light',
        ]);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/products?search=ergonomic');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals($targetProduct->id, $response->json('data.0.id'));
    }

    public function test_products_can_be_filtered_by_minimum_price(): void
    {
        Product::factory()->create(['price' => 10.00]);
        $midProduct = Product::factory()->create(['price' => 50.00]);
        $highProduct = Product::factory()->create(['price' => 100.00]);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/products?min_price=50.00');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));
        $retrievedIds = array_column($response->json('data'), 'id');
        $this->assertContains($midProduct->id, $retrievedIds);
        $this->assertContains($highProduct->id, $retrievedIds);
    }

    public function test_products_can_be_filtered_by_maximum_price(): void
    {
        $cheapProduct = Product::factory()->create(['price' => 10.00]);
        $midProduct = Product::factory()->create(['price' => 50.00]);
        Product::factory()->create(['price' => 100.00]);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/products?max_price=50.00');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));
        $retrievedIds = array_column($response->json('data'), 'id');
        $this->assertContains($cheapProduct->id, $retrievedIds);
        $this->assertContains($midProduct->id, $retrievedIds);
    }

    public function test_products_can_be_filtered_by_an_inclusive_price_range(): void
    {
        Product::factory()->create(['price' => 10.00]);
        $product2 = Product::factory()->create(['price' => 50.00]);
        $product3 = Product::factory()->create(['price' => 75.00]);
        Product::factory()->create(['price' => 100.00]);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/products?min_price=50.00&max_price=75.00');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));
        $retrievedIds = array_column($response->json('data'), 'id');
        $this->assertContains($product2->id, $retrievedIds);
        $this->assertContains($product3->id, $retrievedIds);
    }

    public function test_products_can_be_filtered_by_stock_status(): void
    {
        $inStockProduct = Product::factory()->create(['available_stock' => 10]);
        $outOfStockProduct = Product::factory()->create(['available_stock' => 0]);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $inStockResponse = $this->getJson('/api/v1/products?stock_status=in_stock');
        $inStockResponse->assertStatus(200);
        $this->assertCount(1, $inStockResponse->json('data'));
        $this->assertEquals($inStockProduct->id, $inStockResponse->json('data.0.id'));

        $outOfStockResponse = $this->getJson('/api/v1/products?stock_status=out_of_stock');
        $outOfStockResponse->assertStatus(200);
        $this->assertCount(1, $outOfStockResponse->json('data'));
        $this->assertEquals($outOfStockProduct->id, $outOfStockResponse->json('data.0.id'));
    }

    public function test_products_can_be_sorted_by_price_ascending(): void
    {
        Product::factory()->create(['price' => 100.00]);
        Product::factory()->create(['price' => 25.00]);
        Product::factory()->create(['price' => 50.00]);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/products?sort_by=price&sort_direction=asc');

        $response->assertStatus(200);
        $prices = array_column($response->json('data'), 'price');
        $this->assertEquals(['25.00', '50.00', '100.00'], $prices);
    }

    public function test_products_can_be_sorted_by_available_stock_descending(): void
    {
        Product::factory()->create(['available_stock' => 5]);
        Product::factory()->create(['available_stock' => 20]);
        Product::factory()->create(['available_stock' => 10]);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/products?sort_by=available_stock&sort_direction=desc');

        $response->assertStatus(200);
        $stocks = array_column($response->json('data'), 'available_stock');
        $this->assertEquals([20, 10, 5], $stocks);
    }

    public function test_products_can_be_sorted_by_title(): void
    {
        Product::factory()->create(['title' => 'Banana Phone']);
        Product::factory()->create(['title' => 'Apple Laptop']);
        Product::factory()->create(['title' => 'Cherry Monitor']);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/products?sort_by=title&sort_direction=asc');

        $response->assertStatus(200);
        $titles = array_column($response->json('data'), 'title');
        $this->assertEquals(['Apple Laptop', 'Banana Phone', 'Cherry Monitor'], $titles);
    }

    public function test_pagination_works_correctly_for_second_page(): void
    {
        Product::factory()->count(25)->create();

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/products?per_page=10&page=2');

        $response->assertStatus(200);
        $this->assertCount(10, $response->json('data'));
        $this->assertEquals(2, $response->json('meta.current_page'));
        $this->assertEquals(25, $response->json('meta.total'));
        $this->assertEquals(3, $response->json('meta.last_page'));
    }

    public function test_pagination_links_preserve_active_query_parameters(): void
    {
        Product::factory()->count(25)->create([
            'title' => 'Gaming Gear Product',
            'price' => 150.00,
        ]);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/products?search=Gaming&sort_by=price&sort_direction=asc&per_page=10&page=1');

        $response->assertStatus(200);
        $nextLink = $response->json('links.next');
        $this->assertNotNull($nextLink);
        $this->assertStringContainsString('search=Gaming', $nextLink);
        $this->assertStringContainsString('sort_by=price', $nextLink);
        $this->assertStringContainsString('sort_direction=asc', $nextLink);
        $this->assertStringContainsString('per_page=10', $nextLink);
    }

    public function test_empty_result_returns_empty_data_array_and_null_from_and_to_metadata(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/products');

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Products retrieved successfully.',
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

    public function test_multiple_filters_can_be_combined(): void
    {
        $target = Product::factory()->create([
            'title' => 'Pro Gaming Monitor',
            'price' => 300.00,
            'available_stock' => 5,
        ]);
        Product::factory()->create([
            'title' => 'Pro Office Chair',
            'price' => 150.00,
            'available_stock' => 0,
        ]);
        Product::factory()->create([
            'title' => 'Pro Wireless Mouse',
            'price' => 50.00,
            'available_stock' => 10,
        ]);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/products?search=Pro&min_price=100&stock_status=in_stock&sort_by=price&sort_direction=asc');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals($target->id, $response->json('data.0.id'));
    }

    public function test_invalid_query_parameters_return_http_422(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/products?stock_status=invalid_status')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['stock_status']);

        $this->getJson('/api/v1/products?sort_by=invalid_column')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sort_by']);

        $this->getJson('/api/v1/products?sort_direction=invalid_direction')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sort_direction']);

        $this->getJson('/api/v1/products?per_page=101')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['per_page']);

        $this->getJson('/api/v1/products?page=0')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['page']);

        $this->getJson('/api/v1/products?min_price=-5')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['min_price']);

        $this->getJson('/api/v1/products?min_price=100&max_price=50')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['max_price']);
    }

    public function test_an_invalid_sort_field_cannot_be_passed_to_the_sql_order_clause(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/products?sort_by=password');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['sort_by']);
    }
}
