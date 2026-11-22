<?php

/**
 * Student Name : 1. MUHAMMAD AKMAL SAFWAN BIN BADRO HISHAM
 *                2. MUHAMMAD AZAMUDDIN BIN AZMAWARIS
 * Student ID   : 1. ____________________
 *                2. ____________________
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links books to the new authors and publishers tables.
 *
 * The Assignment 1 "author" string column is deliberately kept: the original
 * BookController, its validation and views still read and write it. Both new
 * keys are nullable so existing books and the original create/edit form keep
 * working, and deleting an author or publisher only unlinks the book.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->foreignId('author_id')->nullable()->after('author')->constrained()->nullOnDelete();
            $table->foreignId('publisher_id')->nullable()->after('author_id')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropConstrainedForeignId('publisher_id');
            $table->dropConstrainedForeignId('author_id');
        });
    }
};
