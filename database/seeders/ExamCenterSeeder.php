<?php

namespace Database\Seeders;

use App\Models\ExamCenter;
use Illuminate\Database\Seeder;

/**
 * The places of examination / issuance from the legacy kiosk form
 * (database/data/exam_centers.php). Safe to run again: a name already there is
 * left as it is.
 */
class ExamCenterSeeder extends Seeder
{
    /** Places outside the Philippines, from docs/KIOSK_LOOKUP_DATA.md section 3. */
    private const ABROAD = [
        'Bangkok, Thailand' => 'Thailand',
        'Chicago, Illinois, Usa' => 'USA',
        'Doha, Qatar' => 'Qatar',
        'Hong Kong' => 'Hong Kong',
        'Los Angeles, California' => 'USA',
        'Paris, France' => 'France',
        'Riyadh, Ksa' => 'Saudi Arabia',
        'San Francisco, California' => 'USA',
        'Tokyo, Japan' => 'Japan',
        'Vienna, Austria' => 'Austria',
        'Washington Dc, Usa' => 'USA',
    ];

    public function run(): void
    {
        foreach (require database_path('data/exam_centers.php') as $name) {
            ExamCenter::firstOrCreate(['name' => $name], ['country' => self::ABROAD[$name] ?? 'Philippines']);
        }
    }
}
