<?php

/**
 * Student Name : 1. MUHAMMAD AKMAL SAFWAN BIN BADRO HISHAM
 *                2. MUHAMMAD AZAMUDDIN BIN AZMAWARIS
 * Student ID   : 1. ____________________
 *                2. ____________________
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Availability rule: each catalogue book is one circulating copy, so a book
 * may have at most one open loan (returned_at IS NULL) at any time.
 *
 * A partial unique index enforces this in the database, so two librarians
 * issuing the same book at the same moment cannot both succeed. SQLite and
 * PostgreSQL support partial indexes. MySQL/MariaDB do not; there,
 * IssueBookAction's row lock on the book serialises concurrent issues.
 */
return new class extends Migration
{
    private const INDEX = 'borrowings_one_active_loan_per_book';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if ($this->supportsPartialIndexes()) {
            DB::statement('CREATE UNIQUE INDEX '.self::INDEX.' ON borrowings (book_id) WHERE returned_at IS NULL');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if ($this->supportsPartialIndexes()) {
            DB::statement('DROP INDEX IF EXISTS '.self::INDEX);
        }
    }

    private function supportsPartialIndexes(): bool
    {
        return in_array(DB::getDriverName(), ['sqlite', 'pgsql'], true);
    }
};
