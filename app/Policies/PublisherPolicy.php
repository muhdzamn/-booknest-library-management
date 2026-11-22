<?php

/**
 * Student Name : 1. MUHAMMAD AKMAL SAFWAN BIN BADRO HISHAM
 *                2. MUHAMMAD AZAMUDDIN BIN AZMAWARIS
 * Student ID   : 1. ____________________
 *                2. ____________________
 */

namespace App\Policies;

use App\Models\Publisher;
use App\Models\User;

class PublisherPolicy
{
    public function viewAny(?User $user): bool
    {
        return true;
    }

    public function view(?User $user, Publisher $publisher): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->isStaff();
    }

    public function update(User $user, Publisher $publisher): bool
    {
        return $user->isStaff();
    }

    /**
     * Deleting a publisher unlinks its books, so it is restricted to admins.
     */
    public function delete(User $user, Publisher $publisher): bool
    {
        return $user->isAdmin();
    }
}
