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

        // User::create([
        //     'name' => 'Test User',
        //     'email' => 'texus.tj@gmail.com',
        //     'password' => bcrypt('Shod63mm'),
        //     'role' => 'admin',
        //     'status' => true,
        // ]);
        User::create([
            'name' => 'Malok Admin',
            'email' => 'malik@liddo.ai',
            'password' => bcrypt('Malik2026'),
            'role' => 'admin',
            'status' => true,
        ]);
    }
}
