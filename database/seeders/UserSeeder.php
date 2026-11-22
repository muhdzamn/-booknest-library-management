<?php

/**
 * Student Name : 1. MUHAMMAD AKMAL SAFWAN BIN BADRO HISHAM
 *                2. MUHAMMAD AZAMUDDIN BIN AZMAWARIS
 * Student ID   : 1. ____________________
 *                2. ____________________
 */

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * Seed one local demo account per role (password: "password").
     *
     * Safe to re-run: an account that already exists is left untouched.
     */
    public function run(): void
    {
        $accounts = [
            ['name' => 'Admin User', 'email' => 'admin@example.com', 'factory' => User::factory()->admin()],
            ['name' => 'Librarian User', 'email' => 'librarian@example.com', 'factory' => User::factory()->librarian()],
            ['name' => 'Test User', 'email' => 'test@example.com', 'factory' => User::factory()],
        ];

        foreach ($accounts as $account) {
            if (User::where('email', $account['email'])->exists()) {
                continue;
            }

            $account['factory']->create(['name' => $account['name'], 'email' => $account['email']]);
        }
    }
}
