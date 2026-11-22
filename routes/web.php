<?php

/**
 * Student Name : 1. MUHAMMAD AKMAL SAFWAN BIN BADRO HISHAM
 *                2. MUHAMMAD AZAMUDDIN BIN AZMAWARIS
 * Student ID   : 1. ____________________
 *                2. ____________________
 */

use App\Http\Controllers\BookController;
use App\Livewire\Books\BookManager;
use App\Livewire\Borrowings\BorrowingManager;
use App\Livewire\Dashboard;
use App\Livewire\Reservations\ReservationManager;
use Illuminate\Support\Facades\Route;

Route::view('/', 'home')->name('home');

// Assignment 1: the seven RESTful book routes (unchanged).
Route::resource('books', BookController::class);

Route::middleware(['auth', 'verified'])->group(function () {
    // Phase 6: BookNest dashboard (replaces the starter kit's placeholder view).
    Route::livewire('dashboard', Dashboard::class)->name('dashboard');

    // Phase 3: Livewire book management module, alongside the routes above.
    Route::livewire('manage/books', BookManager::class)->name('books.manage');

    // Phase 4: circulation desk (staff) / own borrowing history (students).
    Route::livewire('borrowings', BorrowingManager::class)->name('borrowings.index');

    // Phase 5: reservation requests (students) and review (staff).
    Route::livewire('reservations', ReservationManager::class)->name('reservations.index');
});

require __DIR__.'/settings.php';
