<?php

/**
 * Student Name : 1. MUHAMMAD AKMAL SAFWAN BIN BADRO HISHAM
 *                2. MUHAMMAD AZAMUDDIN BIN AZMAWARIS
 * Student ID   : 1. ____________________
 *                2. ____________________
 */

namespace Database\Seeders;

use App\Models\Author;
use App\Models\Book;
use Illuminate\Database\Seeder;

class AuthorSeeder extends Seeder
{
    /**
     * Seed one author per original BookNest book and link each book by its
     * existing "author" string. The original book columns are not changed.
     *
     * Safe to re-run: authors are matched by name and links are re-applied.
     */
    public function run(): void
    {
        $biographies = [
            'Alex Michaelides' => 'British-Cypriot novelist and screenwriter. The Silent Patient was his debut novel.',
            'Matt Stauffer' => 'Web developer, author and partner at Tighten, known for his writing and teaching on Laravel.',
            'Robert C. Martin' => 'Software engineer known as "Uncle Bob", a co-author of the Agile Manifesto and writer on software craftsmanship.',
            'Walter Isaacson' => 'American journalist and biographer, former chair of CNN and former CEO of the Aspen Institute.',
            'Delia Owens' => 'American wildlife scientist and author whose first novel was Where the Crawdads Sing.',
        ];

        foreach ($biographies as $name => $biography) {
            $author = Author::firstOrCreate(['name' => $name], ['biography' => $biography]);

            Book::where('author', $name)->update(['author_id' => $author->id]);
        }
    }
}
