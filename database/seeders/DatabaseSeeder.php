<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (config('admin.seed.email') && config('admin.seed.password')) {
            $this->call(AdminSeeder::class);
        } else {
            $this->command?->warn('Administrator seed skipped. Set ADMIN_EMAIL and ADMIN_PASSWORD to create the first administrator.');
        }
    }
}
