<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class DemoProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $pngBinary = base64_decode('iVBORw0KGgoAAAANSU5EUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        Storage::disk('public')->put('products/demo-placeholder.png', $pngBinary);

        $imagePath = 'products/demo-placeholder.png';

        $products = [
            [
                'title' => 'Wireless Headphones',
                'price' => 1499.99,
                'description' => 'Wireless headphones with active noise cancellation.',
                'available_stock' => 25,
                'image_path' => $imagePath,
            ],
            [
                'title' => 'Mechanical Keyboard',
                'price' => 899.50,
                'description' => 'Mechanical keyboard with RGB backlighting.',
                'available_stock' => 12,
                'image_path' => $imagePath,
            ],
            [
                'title' => 'USB-C Hub',
                'price' => 649.00,
                'description' => 'Multi-port USB-C hub for laptops and tablets.',
                'available_stock' => 0,
                'image_path' => $imagePath,
            ],
            [
                'title' => 'Gaming Mouse',
                'price' => 499.99,
                'description' => 'Ergonomic gaming mouse with programmable buttons.',
                'available_stock' => 40,
                'image_path' => $imagePath,
            ],
            [
                'title' => '4K Monitor',
                'price' => 8999.00,
                'description' => 'Twenty-seven-inch 4K IPS monitor.',
                'available_stock' => 5,
                'image_path' => $imagePath,
            ],
            [
                'title' => 'Ergonomic Chair',
                'price' => 3500.00,
                'description' => 'Adjustable mesh ergonomic office chair.',
                'available_stock' => 15,
                'image_path' => $imagePath,
            ],
            [
                'title' => 'Desk Mat',
                'price' => 150.00,
                'description' => 'Large waterproof desk pad mouse mat.',
                'available_stock' => 50,
                'image_path' => $imagePath,
            ],
            [
                'title' => 'USB Desk Fan',
                'price' => 85.00,
                'description' => 'Quiet portable mini desk fan.',
                'available_stock' => 30,
                'image_path' => $imagePath,
            ],
            [
                'title' => 'Cable Organizer',
                'price' => 45.00,
                'description' => 'Silicone cable holder clips for cable management.',
                'available_stock' => 100,
                'image_path' => $imagePath,
            ],
            [
                'title' => 'Web Camera HD',
                'price' => 1200.00,
                'description' => '1080p full HD webcam with built-in microphone.',
                'available_stock' => 0,
                'image_path' => $imagePath,
            ],
            [
                'title' => 'Laptop Stand',
                'price' => 350.00,
                'description' => 'Aluminum folding cooling laptop stand.',
                'available_stock' => 18,
                'image_path' => $imagePath,
            ],
            [
                'title' => 'Smart Speaker',
                'price' => 1850.00,
                'description' => 'Voice-controlled smart home audio speaker.',
                'available_stock' => 8,
                'image_path' => $imagePath,
            ],
            [
                'title' => 'Screen Cleaner Kit',
                'price' => 60.00,
                'description' => 'Microfiber cloth and spray screen cleaning kit.',
                'available_stock' => 75,
                'image_path' => $imagePath,
            ],
            [
                'title' => 'Portable SSD 1TB',
                'price' => 2499.00,
                'description' => 'High-speed external solid state drive.',
                'available_stock' => 10,
                'image_path' => $imagePath,
            ],
            [
                'title' => 'Bluetooth Earbuds',
                'price' => 750.00,
                'description' => 'Compact wireless earbuds with charging case.',
                'available_stock' => 0,
                'image_path' => $imagePath,
            ],
            [
                'title' => 'Mechanical Keycaps',
                'price' => 299.99,
                'description' => 'Custom PBT keycap set for mechanical keyboards.',
                'available_stock' => 20,
                'image_path' => $imagePath,
            ],
            [
                'title' => 'Desk Lamp LED',
                'price' => 420.00,
                'description' => 'Dimmable eye-caring LED desk lamp with USB port.',
                'available_stock' => 14,
                'image_path' => $imagePath,
            ],
            [
                'title' => 'Power Strip Surge Protector',
                'price' => 210.00,
                'description' => 'Multi-outlet power strip with USB charging ports.',
                'available_stock' => 35,
                'image_path' => $imagePath,
            ],
            [
                'title' => 'Standing Desk Converter',
                'price' => 4200.00,
                'description' => 'Height adjustable dual-tier standing desk converter.',
                'available_stock' => 4,
                'image_path' => $imagePath,
            ],
            [
                'title' => 'Noise Cancelling Earplugs',
                'price' => 120.00,
                'description' => 'Reusable silicone earplugs for work and sleep.',
                'available_stock' => 0,
                'image_path' => $imagePath,
            ],
        ];

        foreach ($products as $productData) {
            Product::create($productData);
        }
    }
}
