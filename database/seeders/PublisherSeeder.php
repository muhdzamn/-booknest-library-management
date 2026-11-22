<?php

/**
 * Student Name : 1. MUHAMMAD AKMAL SAFWAN BIN BADRO HISHAM
 *                2. MUHAMMAD AZAMUDDIN BIN AZMAWARIS
 * Student ID   : 1. ____________________
 *                2. ____________________
 */

namespace Database\Seeders;

use App\Models\Book;
use App\Models\Publisher;
use Illuminate\Database\Seeder;

class PublisherSeeder extends Seeder
{
    /**
     * Seed the publishers of the five original BookNest books and link each
     * book by title. Contact emails are left empty rather than invented.
     *
     * Safe to re-run: publishers are matched by name and links are re-applied.
     */
    public function run(): void
    {
        $publisherByTitle = [
            'The Silent Patient' => 'Celadon Books',
            'Laravel: Up & Running' => "O'Reilly Media",
            'Clean Code' => 'Prentice Hall',
            'Steve Jobs' => 'Simon & Schuster',
            'Where the Crawdads Sing' => "G. P. Putnam's Sons",
        ];

        foreach ($publisherByTitle as $title => $publisherName) {
            $publisher = Publisher::firstOrCreate(['name' => $publisherName]);

            Book::where('title', $title)->update(['publisher_id' => $publisher->id]);
        }
    }
}
