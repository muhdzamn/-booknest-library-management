<?php

/**
 * Student Name : 1. MUHAMMAD AKMAL SAFWAN BIN BADRO HISHAM
 *                2. MUHAMMAD AZAMUDDIN BIN AZMAWARIS
 * Student ID   : 1. ____________________
 *                2. ____________________
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 audit requirement: borrowing history must survive a deleted
 * account. Phase 2 cascaded borrowings.user_id on delete; it now becomes
 * nullable and is cleared instead, so the loan record itself is kept.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('borrowings', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        Schema::table('borrowings', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     *
     * Restoring NOT NULL requires every row to have a borrower, so rows whose
     * borrower account was deleted are removed first. This loses that history.
     */
    public function down(): void
    {
        DB::table('borrowings')->whereNull('user_id')->delete();

        Schema::table('borrowings', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        Schema::table('borrowings', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
