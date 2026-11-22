<?php

/**
 * Student Name : 1. MUHAMMAD AKMAL SAFWAN BIN BADRO HISHAM
 *                2. MUHAMMAD AZAMUDDIN BIN AZMAWARIS
 * Student ID   : 1. ____________________
 *                2. ____________________
 */

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    /**
     * Seed the three BookNest categories (ids 1, 2 and 3 in this order).
     */
    public function run(): void
    {
        Category::create(['name' => 'Fiction']);
        Category::create(['name' => 'Technology']);
        Category::create(['name' => 'Biography']);
    }
}
