<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotFoundExceptionHandlingTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_product_returns_json_product_not_found(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/products/99999');

        $response->assertStatus(404)
            ->assertExactJson([
                'message' => 'Product not found.',
            ]);
    }

    public function test_missing_order_returns_json_order_not_found(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/orders/99999');

        $response->assertStatus(404)
            ->assertExactJson([
                'message' => 'Order not found.',
            ]);
    }

    public function test_unknown_api_endpoint_returns_json_endpoint_not_found(): void
    {
        $response = $this->getJson('/api/v1/nonexistent-endpoint');

        $response->assertStatus(404)
            ->assertExactJson([
                'message' => 'Endpoint not found.',
            ]);
    }

    public function test_admin_updating_missing_order_status_returns_json_order_not_found(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $response = $this->patchJson('/api/v1/admin/orders/99999/status', [
            'status' => 'confirmed',
        ]);

        $response->assertStatus(404)
            ->assertExactJson([
                'message' => 'Order not found.',
            ]);
    }

    public function test_non_api_route_preserves_normal_web_404_behavior(): void
    {
        $response = $this->get('/nonexistent-web-page');

        $response->assertStatus(404);
        $this->assertStringContainsString('text/html', (string) $response->headers->get('content-type'));
    }
}
