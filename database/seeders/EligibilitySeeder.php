<?php

namespace Database\Seeders;

use App\Models\Eligibility;
use Illuminate\Database\Seeder;

/**
 * The eligibility titles of the legacy kiosk form (docs/KIOSK_LOOKUP_DATA.md
 * section 1) that the list does not have yet. Titles already on the list under
 * another wording are left alone, and nothing existing is changed. Safe to run
 * again.
 */
class EligibilitySeeder extends Seeder
{
    /** Kiosk titles that the list already carries, under the code given. */
    public const EXISTING = [
        'Career Service Professional' => 'CS-PROF',
        'Career Service Subprofessional' => 'CS-SUB',
        'Fire Officer' => 'FOE',
        'Penology Officer' => 'POE',
        'Basic Competency on Local Treasury' => 'BCLTE',
        'Professional Board Examination for Teachers' => 'PBET',
        'Presidential Decree No. 907' => 'HGE',
        'Barangay Official' => 'BOE',
        'Memorandum Circular No. 11' => 'MC 11',
        'Barangay Health Worker' => 'BHW',
        'Barangay Nutrition Scholar' => 'BNS',
        'Electronic Data Processing Specialist' => 'EDPSE',
        'Sanggunian Member First Level' => 'SME 1',
        'Sanggunian Member Second Level' => 'SME 2',
        'Meat Inspection and Safety' => 'MISE',
    ];

    /** Kiosk titles the list lacked, with the code they are filed under. */
    public const NEW = [
        'POLICE' => 'Police Officer',
        'VPR' => 'Veteran Preference Rating',
        'CSP-PR' => 'Career Service Professional (Preference Rating)',
        'CSS-PR' => 'Career Service Subprofessional (Preference Rating)',
        'RA 1080' => 'Republic Act No. 1080',
    ];

    public function run(): void
    {
        foreach (self::NEW as $code => $name) {
            Eligibility::firstOrCreate(['code' => $code], ['name' => $name]);
        }
    }
}
