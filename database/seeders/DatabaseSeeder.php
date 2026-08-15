<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Creates the first administrator, and the words on the public site.
     *
     * Everything else comes from eswachh:import. This exists so there is a way
     * into a fresh install; the password is meant to be changed immediately.
     */
    public function run(): void
    {
        $this->createAdministrator();

        // Runs whether or not the administrator already existed: having
        // somebody able to sign in and having a home page are separate
        // concerns, and a re-seed should fix either one independently.
        $this->call(SiteContentSeeder::class);
        // Safe to run again: only blank pages are filled.
        $this->call(PolicySeeder::class);
    }

    private function createAdministrator(): void
    {
        $email = env('ADMIN_EMAIL', 'admin@eswachh.test');

        if (User::withTrashed()->firstWhere('email', $email)) {
            $this->command->info("Administrator already exists: {$email}");

            return;
        }

        User::create([
            'name' => 'Administrator',
            'email' => $email,
            'role' => UserRole::SuperAdmin,
            'password' => Hash::make(env('ADMIN_PASSWORD', 'password')),
            'email_verified_at' => now(),
            'status' => true,
            // A super admin belongs to no single branch and sees them all.
            'branch_id' => null,
        ]);

        $this->command->info("Administrator created: {$email}");
        $this->command->warn('Change this password before anyone else can reach the site.');
    }
}
