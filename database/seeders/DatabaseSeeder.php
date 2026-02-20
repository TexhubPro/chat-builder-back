<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            SubscriptionPlanSeeder::class,
        ]);

        // User::factory(10)->create();

        User::create([
            'name' => 'Test User',
            'email' => 'texus.tj@gmail.com',
            'password' => bcrypt('Shod63mm'),
            'role' => 'admin',
            'status' => true,

        ]);
    }
}
