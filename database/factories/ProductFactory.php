<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->words(3, true),
            'price' => fake()->randomFloat(2, 10, 500),
            'description' => fake()->paragraph(),
            'available_stock' => fake()->numberBetween(0, 100),
            'image_path' => 'products/' . fake()->uuid() . '.jpg',
        ];
    }
}
