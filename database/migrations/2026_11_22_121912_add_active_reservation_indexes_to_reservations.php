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
 * Database-level guarantees for the reservation rules, so duplicate or
 * simultaneous submissions cannot break them:
 *
 * - one active (pending or approved) reservation per student per book;
 * - one approved hold per book (each catalogue book is one copy).
 *
 * Approved holds past expires_at are marked "expired" by the Actions inside
 * the same transaction before these rules are checked, so a lapsed hold
 * never blocks a new reservation or approval.
 *
 * SQLite and PostgreSQL support partial indexes. MySQL/MariaDB do not;
 * there, the Actions' row locks serialise concurrent submissions.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! $this->supportsPartialIndexes()) {
            return;
        }

        DB::statement("CREATE UNIQUE INDEX reservations_one_active_per_student_book ON reservations (user_id, book_id) WHERE status IN ('pending', 'approved')");
        DB::statement("CREATE UNIQUE INDEX reservations_one_hold_per_book ON reservations (book_id) WHERE status = 'approved'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! $this->supportsPartialIndexes()) {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS reservations_one_hold_per_book');
        DB::statement('DROP INDEX IF EXISTS reservations_one_active_per_student_book');
    }

    private function supportsPartialIndexes(): bool
    {
        return in_array(DB::getDriverName(), ['sqlite', 'pgsql'], true);
    }
};
