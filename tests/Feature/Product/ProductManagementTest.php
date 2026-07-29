<?php

namespace Tests\Feature\Product;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_unauthenticated_visitor_cannot_list_products(): void
    {
        $response = $this->getJson('/api/v1/products');

        $response->assertStatus(401);
    }

    public function test_an_authenticated_regular_user_can_list_products(): void
    {
        $product1 = Product::factory()->create();
        $product2 = Product::factory()->create();
        $product3 = Product::factory()->create();

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
            ]);

        $this->assertEquals($product3->id, $response->json('data.0.id'));
        $this->assertEquals($product2->id, $response->json('data.1.id'));
        $this->assertEquals($product1->id, $response->json('data.2.id'));
    }

    public function test_an_authenticated_regular_user_can_view_one_product(): void
    {
        $product = Product::factory()->create([
            'title' => 'Test Gaming Mouse',
            'price' => 49.99,
        ]);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson("/api/v1/products/{$product->id}");

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Product retrieved successfully.',
                'data' => [
                    'id' => $product->id,
                    'title' => 'Test Gaming Mouse',
                    'price' => '49.99',
                ],
            ]);
    }

    public function test_viewing_a_missing_product_returns_json_404(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/products/9999');

        $response->assertStatus(404);
    }

    public function test_an_unauthenticated_user_cannot_create_a_product(): void
    {
        $response = $this->postJson('/api/v1/admin/products', [
            'title' => 'Sample Product',
            'price' => 29.99,
            'description' => 'Description',
            'available_stock' => 5,
        ]);

        $response->assertStatus(401);
        $this->assertDatabaseCount('products', 0);
    }

    public function test_an_authenticated_regular_user_cannot_create_a_product(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/admin/products', [
            'title' => 'Sample Product',
            'price' => 29.99,
            'description' => 'Description',
            'available_stock' => 5,
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'message' => 'Admin access is required.',
            ]);

        $this->assertDatabaseCount('products', 0);
    }

    public function test_an_admin_can_create_a_product(): void
    {
        Storage::fake('public');

        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $file = UploadedFile::fake()->image('keyboard.jpg', 400, 400);

        $response = $this->postJson('/api/v1/admin/products', [
            'title' => 'Mechanical Keyboard',
            'price' => 129.99,
            'description' => 'RGB Mechanical Keyboard',
            'available_stock' => 20,
            'image' => $file,
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'message' => 'Product created successfully.',
                'data' => [
                    'title' => 'Mechanical Keyboard',
                    'price' => '129.99',
                    'available_stock' => 20,
                ],
            ])
            ->assertJsonMissingPath('data.image_path');

        $this->assertNotNull($response->json('data.image_url'));

        $this->assertDatabaseHas('products', [
            'title' => 'Mechanical Keyboard',
            'price' => 129.99,
            'available_stock' => 20,
        ]);

        $product = Product::first();
        $this->assertStringStartsWith('products/', $product->image_path);
        Storage::disk('public')->assertExists($product->image_path);
    }

    public function test_product_creation_validates_inputs(): void
    {
        Storage::fake('public');

        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        // Missing required fields
        $missingResponse = $this->postJson('/api/v1/admin/products', []);
        $missingResponse->assertStatus(422)
            ->assertJsonValidationErrors(['title', 'price', 'description', 'available_stock', 'image']);

        // Negative price & negative stock & invalid decimal & invalid file
        $invalidFile = UploadedFile::fake()->create('document.pdf', 100);
        $invalidResponse = $this->postJson('/api/v1/admin/products', [
            'title' => 'Bad Product',
            'price' => -10.00,
            'description' => 'Desc',
            'available_stock' => -5,
            'image' => $invalidFile,
        ]);
        $invalidResponse->assertStatus(422)
            ->assertJsonValidationErrors(['price', 'available_stock', 'image']);

        // Too many decimal places
        $decimalFile = UploadedFile::fake()->image('test.png');
        $decimalResponse = $this->postJson('/api/v1/admin/products', [
            'title' => 'Decimal Product',
            'price' => 99.999,
            'description' => 'Desc',
            'available_stock' => 5,
            'image' => $decimalFile,
        ]);
        $decimalResponse->assertStatus(422)
            ->assertJsonValidationErrors(['price']);
    }

    public function test_a_regular_user_cannot_update_a_product(): void
    {
        $product = Product::factory()->create([
            'title' => 'Original Title',
        ]);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->patchJson("/api/v1/admin/products/{$product->id}", [
            'title' => 'Hacked Title',
        ]);

        $response->assertStatus(403);
        $this->assertEquals('Original Title', $product->fresh()->title);
    }

    public function test_an_admin_can_update_product_fields_without_changing_the_image(): void
    {
        Storage::fake('public');

        $product = Product::factory()->create([
            'title' => 'Old Title',
            'price' => 50.00,
            'image_path' => 'products/existing.jpg',
        ]);

        Storage::disk('public')->put('products/existing.jpg', 'fake content');

        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $response = $this->patchJson("/api/v1/admin/products/{$product->id}", [
            'title' => 'New Title',
            'price' => 75.50,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Product updated successfully.',
                'data' => [
                    'title' => 'New Title',
                    'price' => '75.50',
                ],
            ]);

        $this->assertEquals('products/existing.jpg', $product->fresh()->image_path);
        Storage::disk('public')->assertExists('products/existing.jpg');
    }

    public function test_an_admin_can_replace_a_product_image(): void
    {
        Storage::fake('public');

        $product = Product::factory()->create([
            'image_path' => 'products/old_image.jpg',
        ]);
        Storage::disk('public')->put('products/old_image.jpg', 'old content');

        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $newFile = UploadedFile::fake()->image('new_image.png');

        $response = $this->patchJson("/api/v1/admin/products/{$product->id}", [
            'image' => $newFile,
        ]);

        $response->assertStatus(200);

        $freshProduct = $product->fresh();
        $this->assertNotEquals('products/old_image.jpg', $freshProduct->image_path);
        Storage::disk('public')->assertMissing('products/old_image.jpg');
        Storage::disk('public')->assertExists($freshProduct->image_path);
    }

    public function test_product_update_validates_invalid_price_stock_and_image(): void
    {
        $product = Product::factory()->create();

        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $response = $this->patchJson("/api/v1/admin/products/{$product->id}", [
            'price' => -20,
            'available_stock' => -1,
            'image' => 'not-an-image',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['price', 'available_stock', 'image']);
    }

    public function test_a_regular_user_cannot_delete_a_product(): void
    {
        Storage::fake('public');

        $product = Product::factory()->create([
            'image_path' => 'products/file.jpg',
        ]);
        Storage::disk('public')->put('products/file.jpg', 'content');

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->deleteJson("/api/v1/admin/products/{$product->id}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('products', ['id' => $product->id]);
        Storage::disk('public')->assertExists('products/file.jpg');
    }

    public function test_an_admin_can_delete_a_product(): void
    {
        Storage::fake('public');

        $product = Product::factory()->create([
            'image_path' => 'products/to_delete.jpg',
        ]);
        Storage::disk('public')->put('products/to_delete.jpg', 'content');

        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $response = $this->deleteJson("/api/v1/admin/products/{$product->id}");

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Product deleted successfully.',
            ]);

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
        Storage::disk('public')->assertMissing('products/to_delete.jpg');
    }

    public function test_deleting_a_product_whose_image_file_is_already_missing_still_succeeds(): void
    {
        Storage::fake('public');

        $product = Product::factory()->create([
            'image_path' => 'products/missing.jpg',
        ]);

        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $response = $this->deleteJson("/api/v1/admin/products/{$product->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('products', ['id' => $product->id]);
    }

    public function test_admin_routes_reject_unauthenticated_requests_with_http_401(): void
    {
        $this->postJson('/api/v1/admin/products')->assertStatus(401);
        $this->patchJson('/api/v1/admin/products/1')->assertStatus(401);
        $this->deleteJson('/api/v1/admin/products/1')->assertStatus(401);
    }
}
