<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Staff come from IMIS at sign-in, and offices and records from the legacy
 * import. Only the reference lists the legacy forms hard-coded are seeded.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([EligibilitySeeder::class, ExamCenterSeeder::class]);
    }
}
