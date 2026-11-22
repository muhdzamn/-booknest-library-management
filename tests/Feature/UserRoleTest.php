<?php

use App\Enums\UserRole;
use App\Models\User;
use Laravel\Fortify\Features;

test('registration always creates a student, even when a role is submitted', function () {
    $this->skipUnlessFortifyHas(Features::registration());

    $this->post(route('register.store'), [
        'name' => 'Sneaky Student',
        'email' => 'sneaky@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'role' => 'admin',
    ])->assertSessionHasNoErrors();

    expect(User::firstWhere('email', 'sneaky@example.com')->role)->toBe(UserRole::Student);
});

test('role cannot be set through mass assignment', function () {
    $user = User::create([
        'name' => 'Mass Assigner',
        'email' => 'mass@example.com',
        'password' => 'password',
        'role' => 'admin',
    ]);

    expect($user->fresh()->role)->toBe(UserRole::Student);

    $user->update(['role' => 'librarian']);

    expect($user->fresh()->role)->toBe(UserRole::Student);
});

test('only admins and librarians count as staff', function (Closure $makeUser, bool $isStaff, bool $isAdmin) {
    $user = $makeUser();

    expect($user->isStaff())->toBe($isStaff)
        ->and($user->isAdmin())->toBe($isAdmin);
})->with([
    'admin' => [fn () => User::factory()->admin()->create(), true, true],
    'librarian' => [fn () => User::factory()->librarian()->create(), true, false],
    'student' => [fn () => User::factory()->create(), false, false],
]);
