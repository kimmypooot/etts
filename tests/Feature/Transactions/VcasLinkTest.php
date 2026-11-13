<?php

namespace Tests\Feature\Transactions;

use App\Services\Transactions\VcasLink;
use Tests\TestCase;

class VcasLinkTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function parts(string $link): array
    {
        $this->assertStringStartsWith('vcas://', $link);

        return explode('/', substr($link, strlen('vcas://')));
    }

    /**
     * @return array<string, mixed>
     */
    private function full(array $extra = []): array
    {
        return [
            'transaction_type' => 'Certification', 'lname' => 'CRUZ', 'fname' => 'ANA', 'mname' => 'REYES', 'extn' => null,
            'date_of_birth' => '1990-01-02', 'place_of_birth' => 'Tacloban', 'sex' => 'Female', 'civil_status' => 'Single',
            'current_address' => 'Palo, Leyte', 'employment_status' => 'Employed', 'company_name' => 'DepEd', 'company_address' => 'Tacloban',
            'purpose_of_request' => 'Employment', 'purpose_others' => null,
            'used_lastname' => null, 'used_firstname' => null, 'used_middlename' => null, 'used_extn' => null,
            'eligibility' => 'CSE-PR', 'date_of_exam_issuance' => '2020-03-15', 'place_of_exam_issuance' => 'Tacloban',
            'book' => '12', 'page' => '34', 'sequence' => '5', 'examinee_no' => 'E-1', 'cert_no' => 'C-1', 'rating' => '85.5',
            'release_date' => '2020-06-01', 'effectivity_date' => null,
            ...$extra,
        ];
    }

    public function test_it_sends_29_values_in_the_legacy_order(): void
    {
        $parts = $this->parts(VcasLink::for($this->full()));

        $this->assertCount(29, $parts);
        $this->assertSame(rawurlencode('Certification'), $parts[0]);
        $this->assertSame('CRUZ', $parts[1]);
        $this->assertSame('NA', $parts[4]);
        $this->assertSame('85.5', $parts[26]);
        $this->assertSame('NA', $parts[28]);
    }

    public function test_empty_values_become_na(): void
    {
        $parts = $this->parts(VcasLink::for([]));

        $this->assertCount(29, $parts);
        $this->assertSame(array_fill(0, 29, 'NA'), $parts);
    }

    public function test_slashes_and_newlines_never_shift_fields(): void
    {
        $parts = $this->parts(VcasLink::for($this->full(['current_address' => "BLK 1/LOT 2\nBRGY X", 'cert_no' => 'A/12'])));

        $this->assertCount(29, $parts);
        $this->assertSame('BLK%201%2FLOT%202%20BRGY%20X', $parts[9]);
        $this->assertSame('A%2F12', $parts[25]);
    }

    public function test_others_purpose_is_sent_with_its_text(): void
    {
        $parts = $this->parts(VcasLink::for($this->full(['purpose_of_request' => 'Others', 'purpose_others' => 'Visa'])));

        $this->assertSame(rawurlencode('Others: Visa'), $parts[13]);
    }
}
