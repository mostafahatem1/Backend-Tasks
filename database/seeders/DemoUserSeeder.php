<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Local development seeder providing deterministic user accounts for Postman testing.
 * WARNING: These credentials are for local development and testing environments only.
 */
class DemoUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $now = now();

        $users = [
            [
                'name' => 'Postman Admin',
                'phone' => '+201000000001',
                'password' => 'password',
                'role' => 'admin',
                'phone_verified_at' => $now,
            ],
            [
                'name' => 'Postman User',
                'phone' => '+201000000002',
                'password' => 'password',
                'role' => 'user',
                'phone_verified_at' => $now,
            ],
            [
                'name' => 'Verification User',
                'phone' => '+201000000003',
                'password' => 'password',
                'role' => 'user',
                'phone_verified_at' => null,
            ],
            [
                'name' => 'Other User',
                'phone' => '+201000000004',
                'password' => 'password',
                'role' => 'user',
                'phone_verified_at' => $now,
            ],
            [
                'name' => 'Password Reset User',
                'phone' => '+201000000005',
                'password' => 'password',
                'role' => 'user',
                'phone_verified_at' => $now,
            ],
        ];

        foreach ($users as $userData) {
            $user = new User();
            $user->name = $userData['name'];
            $user->phone = $userData['phone'];
            $user->password = $userData['password'];
            $user->role = $userData['role'];
            $user->phone_verified_at = $userData['phone_verified_at'];
            $user->save();
        }
    }
}
