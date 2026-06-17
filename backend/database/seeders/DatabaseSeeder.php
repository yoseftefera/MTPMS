<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Main database seeder — orchestrates all seeders in dependency order.
 *
 * Execution order:
 *   1. RolesAndPermissionsSeeder  — creates 8 roles and 20 permissions (Spatie)
 *   2. DemoTenantSeeder           — creates a demo tenant with one user per role
 *                                   and representative procurement data
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            DemoTenantSeeder::class,
        ]);
    }
}
