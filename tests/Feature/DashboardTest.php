<?php

use App\Livewire\Dashboard;
use App\Models\Book;
use App\Models\Borrowing;
use App\Models\Category;
use App\Models\Reservation;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('unverified users must verify their email first', function () {
    $this->actingAs(User::factory()->unverified()->create())
        ->get(route('dashboard'))
        ->assertRedirect(route('verification.notice'));
});

test('each role gets the branded BookNest dashboard and navigation', function (Closure $makeUser, string $roleBadge) {
    $user = $makeUser();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSeeLivewire(Dashboard::class)
        ->assertSee('<title>', false)
        ->assertSee('Dashboard · BookNest', false)
        ->assertSee('Welcome back, '.$user->name)
        ->assertSee($roleBadge)
        ->assertSeeInOrder(['Dashboard', 'Book Management', 'Borrowings', 'Reservations'])
        ->assertSee(route('books.index'))
        ->assertDontSee('Laravel Starter Kit')
        ->assertDontSee('github.com/laravel/livewire-starter-kit');
})->with([
    'admin' => [fn () => User::factory()->admin()->create(), 'Admin'],
    'librarian' => [fn () => User::factory()->librarian()->create(), 'Librarian'],
    'student' => [fn () => User::factory()->create(), 'Student'],
]);

/**
 * A small library with one of every situation the statistics distinguish.
 *
 * @return array{student: User, otherStudent: User}
 */
function seedDashboardLibrary(): array
{
    $fiction = Category::factory()->create();
    $technology = Category::factory()->create();
    $student = User::factory()->create(['name' => 'Aina Student']);
    $otherStudent = User::factory()->create(['name' => 'Zara Private']);

    // 1. On loan to the student (not overdue).
    Borrowing::factory()->for($student)->for(Book::factory()->for($fiction)->state(['title' => 'Aina Loan Book']))
        ->create(['due_at' => now()->addDays(7)]);

    // 2. Overdue loan to the other student.
    Borrowing::factory()->for($otherStudent)->for(Book::factory()->for($technology)->state(['title' => 'Zara Secret Book']))
        ->create(['borrowed_at' => now()->subDays(20), 'due_at' => now()->subDays(3)]);

    // 3. On hold for the student (approved, unexpired).
    Reservation::factory()->for($student)->for(Book::factory()->for($fiction))->approved()->create();

    // 4. Available: returned by the student earlier; the other student has a pending request.
    $returnedBook = Book::factory()->for($technology)->create();
    Borrowing::factory()->for($student)->for($returnedBook)->returned()->create();
    Reservation::factory()->for($otherStudent)->for($returnedBook)->create();

    // 5. Available: a lapsed hold does not count; the student has a pending request.
    $lapsedBook = Book::factory()->for($fiction)->create();
    Reservation::factory()->for($otherStudent)->for($lapsedBook)->lapsed()->create();
    Reservation::factory()->for($student)->for($lapsedBook)->create();

    return ['student' => $student, 'otherStudent' => $otherStudent];
}

test('staff statistics are library-wide and follow the availability rules', function (Closure $makeStaff) {
    seedDashboardLibrary();

    $dashboard = Livewire::actingAs($makeStaff())->test(Dashboard::class)->instance();

    expect($dashboard->catalogueStats)->toBe(['books' => 5, 'categories' => 2, 'available' => 2])
        ->and($dashboard->circulationStats)->toBe([
            'active' => 2,
            'overdue' => 1,
            'returned' => 1,
            'pending' => 2,
            'onHold' => 1,
        ]);
})->with([
    'admin' => [fn () => User::factory()->admin()->create()],
    'librarian' => [fn () => User::factory()->librarian()->create()],
]);

test('student statistics count only their own loans and reservations', function () {
    ['student' => $student] = seedDashboardLibrary();

    $dashboard = Livewire::actingAs($student)->test(Dashboard::class)->instance();

    expect($dashboard->catalogueStats)->toBe(['books' => 5, 'categories' => 2, 'available' => 2])
        ->and($dashboard->circulationStats)->toBe([
            'active' => 1,
            'overdue' => 0,
            'returned' => 1,
            'pending' => 1,
            'onHold' => 1,
        ]);
});

test('students never see other students or their activity', function () {
    ['student' => $student] = seedDashboardLibrary();

    $component = Livewire::actingAs($student)
        ->test(Dashboard::class)
        ->assertSee('My Recent Loans')
        ->assertSee('Aina Loan Book')
        ->assertSee('Ready for you to collect')
        ->assertDontSee('Zara Private')
        ->assertDontSee('Zara Secret Book')
        ->assertDontSee('Overdue loans need follow-up')
        ->assertDontSee('Accounts');

    $instance = $component->instance();

    expect($instance->recentBorrowings->pluck('user_id')->unique()->all())->toBe([$student->id])
        ->and($instance->recentReservations->pluck('user_id')->unique()->all())->toBe([$student->id])
        ->and($instance->overdueBorrowings)->toBeEmpty()
        ->and($instance->accountStats)->toBe([]);
});

test('staff see activity across students and the overdue list', function () {
    seedDashboardLibrary();

    Livewire::actingAs(User::factory()->librarian()->create())
        ->test(Dashboard::class)
        ->assertSee('Recent Borrowing Activity')
        ->assertSee('Recent Reservation Activity')
        ->assertSee('Zara Private')
        ->assertSee('Aina Student')
        ->assertSee('Overdue loans need follow-up')
        ->assertSee('Zara Secret Book')
        ->assertDontSee('Accounts');
});

test('only admins see account totals by role', function () {
    User::factory()->count(2)->create();
    User::factory()->librarian()->create();
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test(Dashboard::class)
        ->assertSee('Accounts')
        ->assertSet('accountStats', ['admin' => 1, 'librarian' => 1, 'student' => 2]);
});

test('an empty library shows zeros and empty states, not errors', function () {
    Livewire::actingAs(User::factory()->librarian()->create())
        ->test(Dashboard::class)
        ->assertSet('catalogueStats', ['books' => 0, 'categories' => 0, 'available' => 0])
        ->assertSee('No borrowing activity yet.')
        ->assertSee('No reservation activity yet.');
});
