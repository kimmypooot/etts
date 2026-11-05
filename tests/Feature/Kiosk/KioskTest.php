<?php

namespace Tests\Feature\Kiosk;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Http\Middleware\ResolveKioskTerminal;
use App\Models\AuditLog;
use App\Models\Eligibility;
use App\Models\IssuanceRequest;
use App\Models\KioskPairingCode;
use App\Models\KioskTerminal;
use App\Models\Office;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Kiosk\KioskPairing;
use App\Services\Transactions\WorkflowException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class KioskTest extends TestCase
{
    use RefreshDatabase;

    private KioskPairing $pairing;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('attachments');
        RateLimiter::clear('kiosk-pair');
        Office::create(['id' => 1, 'name' => 'Examination Services Division', 'code' => 'ESD']);
        Office::create(['id' => 2, 'name' => 'CSC Field Office - Samar', 'code' => 'FO-SAMAR']);
        Office::create(['id' => 10, 'name' => 'Public Assistance and Liaison Division', 'code' => 'PALD']);
        foreach ([['HGE', 'Honor Graduate'], ['BOE', 'Barangay Official'], ['MC 11', 'Skills Eligibility'], ['BHW', 'Barangay Health Worker']] as [$code, $name]) {
            Eligibility::create(['code' => $code, 'name' => $name]);
        }

        $this->pairing = app(KioskPairing::class);
        $this->admin = User::factory()->admin()->create(['username' => 'esd.officer']);
    }

    /**
     * A paired terminal and the device token for it.
     *
     * @return array{KioskTerminal, string}
     */
    private function pairedTerminal(int $office = 1, string $name = 'ESD counter 1'): array
    {
        $issued = $this->pairing->issueCode(Office::findOrFail($office), $name, $this->admin);
        ['terminal' => $terminal, 'token' => $token] = $this->pairing->redeem($issued['code'], '127.0.0.1');

        return [$terminal, $token];
    }

    private function device(string $token): static
    {
        return $this->withCookie(ResolveKioskTerminal::COOKIE, $token);
    }

    /**
     * @return array<string, mixed>
     */
    private function spelsInput(array $extra = []): array
    {
        return [
            'transaction_type' => 'Special Eligibility Application', 'privacy_acknowledged' => '1', 'website' => '',
            'lname' => 'Cruz', 'fname' => 'Ana', 'mname' => 'Reyes', 'sex' => 'Female', 'mobile' => '09171234567', 'email' => 'ana@example.com',
            'elig_code' => 'HGE',
            ...$extra,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function certInput(array $extra = []): array
    {
        return [
            'transaction_type' => 'Certification', 'privacy_acknowledged' => '1', 'website' => '',
            'lname' => 'Lim', 'fname' => 'Ben', 'sex' => 'Male', 'current_address' => '12 Rizal St., Tacloban City',
            'civil_status' => 'Single', 'date_of_birth' => '1990-05-04', 'purpose_of_request' => 'Employment', 'employment_status' => 'Employed',
            'eligibility' => 'Career Service Professional', 'date_of_exam_issuance' => '2015-03-15', 'place_of_exam_issuance' => 'Tacloban',
            ...$extra,
        ];
    }

    // ─── Pairing ──────────────────────────────────────────────────────────

    public function test_a_code_is_issued_in_a_readable_form_and_only_its_hash_is_kept(): void
    {
        $issued = $this->pairing->issueCode(Office::find(1), 'ESD counter 1', $this->admin);

        $this->assertMatchesRegularExpression('/^[A-HJKMNP-Z2-9]{4}-[A-HJKMNP-Z2-9]{4}$/', $issued['code']);
        $stored = KioskPairingCode::sole();
        $this->assertSame(64, strlen($stored->code_hash));
        $this->assertStringNotContainsString(str_replace('-', '', $issued['code']), json_encode($stored->getAttributes()));
        $this->assertEqualsWithDelta(now()->addMinutes(15)->timestamp, $stored->expires_at->timestamp, 5);
        $this->assertSame(1, AuditLog::where('action', 'kiosk.code_issued')->count());
    }

    public function test_a_code_needs_a_terminal_name(): void
    {
        $this->expectException(WorkflowException::class);
        $this->pairing->issueCode(Office::find(1), '   ', $this->admin);
    }

    public function test_a_code_pairs_one_device_once_and_the_token_is_never_stored(): void
    {
        $issued = $this->pairing->issueCode(Office::find(2), 'Samar kiosk', $this->admin);

        ['terminal' => $terminal, 'token' => $token] = $this->pairing->redeem(strtolower(str_replace('-', ' ', $issued['code'])), '10.0.0.5');

        $this->assertSame(2, $terminal->office_id);
        $this->assertSame('Samar kiosk', $terminal->name);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
        $this->assertNotSame($token, $terminal->token_hash);
        $this->assertSame(hash('sha256', $token), $terminal->token_hash);
        $this->assertSame($terminal->id, $this->pairing->terminalFor($token)->id);

        try {
            $this->pairing->redeem($issued['code'], '10.0.0.6');
            $this->fail('A code must work only once.');
        } catch (WorkflowException $e) {
            $this->assertStringContainsString('not valid', $e->getMessage());
        }
        $this->assertSame(1, KioskTerminal::count());
    }

    public function test_an_expired_or_unknown_code_gets_the_same_answer(): void
    {
        $issued = $this->pairing->issueCode(Office::find(1), 'Late kiosk', $this->admin);
        $this->travel(16)->minutes();

        $messages = [];
        foreach ([$issued['code'], 'ZZZZ-9999'] as $code) {
            try {
                $this->pairing->redeem($code, null);
            } catch (WorkflowException $e) {
                $messages[] = $e->getMessage();
            }
        }

        $this->assertCount(2, $messages);
        $this->assertSame($messages[0], $messages[1], 'A wrong guess must learn nothing.');
    }

    public function test_a_revoked_terminal_and_a_malformed_token_are_not_recognised(): void
    {
        [$terminal, $token] = $this->pairedTerminal();

        $this->pairing->revoke($terminal, $this->admin);

        $this->assertNull($this->pairing->terminalFor($token));
        $this->assertNull($this->pairing->terminalFor(null));
        $this->assertNull($this->pairing->terminalFor('not-a-token'));
        $this->assertNull($this->pairing->terminalFor(str_repeat('a', 64)));
    }

    // ─── The device ───────────────────────────────────────────────────────

    public function test_an_unpaired_device_is_sent_to_the_set_up_screen(): void
    {
        $this->get('/kiosk')->assertRedirect('/kiosk/setup');
        $this->get('/kiosk/request/certification')->assertRedirect('/kiosk/setup');
        $this->get('/kiosk/done')->assertRedirect('/kiosk/setup');
        $this->post('/kiosk/requests', $this->spelsInput())->assertRedirect('/kiosk/setup');

        $this->get('/kiosk/setup')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Kiosk/Setup'));
        $this->assertSame(0, Transaction::count());
    }

    public function test_a_valid_code_pairs_the_device_and_sets_a_strict_cookie(): void
    {
        $issued = $this->pairing->issueCode(Office::find(1), 'ESD counter 1', $this->admin);

        $response = $this->post('/kiosk/setup', ['code' => $issued['code']])->assertRedirect('/kiosk');

        $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === ResolveKioskTerminal::COOKIE);
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('strict', $cookie->getSameSite());
        $this->assertGreaterThan(now()->addYears(4)->timestamp, $cookie->getExpiresTime());
        $this->assertSame(1, KioskTerminal::count());
    }

    public function test_a_wrong_code_is_refused_and_attempts_are_limited(): void
    {
        $this->post('/kiosk/setup', ['code' => 'ZZZZ-9999'])->assertSessionHasErrors('code');

        foreach (range(1, 4) as $i) {
            $this->post('/kiosk/setup', ['code' => 'ZZZZ-999'.$i]);
        }

        $this->post('/kiosk/setup', ['code' => 'ZZZZ-9990'])->assertStatus(429);
        $this->assertSame(0, KioskTerminal::count());
    }

    public function test_a_paired_device_sees_the_menu_with_its_office(): void
    {
        [, $token] = $this->pairedTerminal(1, 'ESD counter 1');

        $this->device($token)->get('/kiosk')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Kiosk/Menu')
                ->has('types', 4)
                ->where('terminal.name', 'ESD counter 1')
                ->where('terminal.office_code', 'ESD')
                ->where('kiosk.base', '/kiosk')
                ->missing('auth'));
    }

    public function test_revoking_a_terminal_stops_it_on_its_very_next_request_and_clears_its_cookie(): void
    {
        [$terminal, $token] = $this->pairedTerminal();
        $this->device($token)->get('/kiosk')->assertOk();

        $this->pairing->revoke($terminal, $this->admin);

        $response = $this->device($token)->get('/kiosk')->assertRedirect('/kiosk/setup');
        $forgotten = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === ResolveKioskTerminal::COOKIE);
        $this->assertNotNull($forgotten);
        $this->assertLessThan(time(), $forgotten->getExpiresTime());
    }

    public function test_the_last_seen_time_is_kept_fresh_without_writing_on_every_click(): void
    {
        [$terminal, $token] = $this->pairedTerminal();
        $terminal->forceFill(['last_seen_at' => now()->subHour()])->save();

        $this->device($token)->get('/kiosk')->assertOk();
        $this->assertTrue($terminal->fresh()->last_seen_at->gt(now()->subMinute()));

        $stamp = $terminal->fresh()->last_seen_at;
        $this->travel(2)->minutes();
        $this->device($token)->get('/kiosk')->assertOk();
        $this->assertTrue($terminal->fresh()->last_seen_at->equalTo($stamp), 'Seen two minutes ago: no write.');
    }

    // ─── Isolation and headers ────────────────────────────────────────────

    public function test_the_kiosk_has_its_own_session_cookie_and_strict_headers(): void
    {
        $response = $this->get('/kiosk/setup')->assertOk();

        $names = collect($response->headers->getCookies())->map(fn ($c) => $c->getName());
        $this->assertContains('etts_kiosk', $names);
        $session = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === 'etts_kiosk');
        $this->assertTrue($session->isHttpOnly());
        $this->assertSame('strict', $session->getSameSite());

        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("script-src 'self'", $csp);
        $this->assertStringNotContainsString("script-src 'self' 'unsafe", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
    }

    public function test_a_kiosk_host_serves_only_the_kiosk(): void
    {
        config(['etts.kiosk.domains' => ['kiosk.test']]);

        $this->get('http://kiosk.test/login')->assertNotFound();
        $this->get('http://kiosk.test/admin/transactions')->assertNotFound();
        $this->get('http://kiosk.test/api/imis/summary')->assertNotFound();
        $this->get('http://localhost/login')->assertOk();
    }

    // ─── The form ─────────────────────────────────────────────────────────

    public function test_each_request_page_opens_and_special_eligibility_lists_the_eligibilities(): void
    {
        [, $token] = $this->pairedTerminal();

        foreach (['certification', 'authentication', 'certification-and-authentication'] as $slug) {
            $this->device($token)->get("/kiosk/request/{$slug}")->assertOk()->assertInertia(fn (Assert $page) => $page->component('Kiosk/Request')->where('spels', false)->has('purposes'));
        }

        $this->device($token)->get('/kiosk/request/special-eligibility')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('spels', true)
                ->has('eligibilities', 4)
                ->where('eligibilities', fn ($rows) => collect($rows)->where('featured', true)->pluck('code')->sort()->values()->all() === ['BOE', 'HGE', 'MC 11']));

        $this->device($token)->get('/kiosk/request/nonsense')->assertNotFound();
    }

    public function test_a_special_eligibility_application_is_filed_under_the_terminals_office(): void
    {
        [$terminal, $token] = $this->pairedTerminal(2, 'Samar kiosk');

        $this->device($token)->post('/kiosk/requests', $this->spelsInput())->assertRedirect('/kiosk/done');

        $record = Transaction::withoutGlobalScopes()->sole();
        $this->assertSame(TransactionStatus::Submitted, $record->transaction_status);
        $this->assertSame(TransactionType::Spels, $record->transaction_type);
        $this->assertSame(2, $record->office_id);
        $this->assertSame('HGE', $record->elig_code);
        $this->assertSame('Female', $record->sex);
        $this->assertNull($record->date_sent_to_ro);
        $this->assertSame(1, $terminal->fresh()->requests_submitted);

        $audit = AuditLog::where('action', 'kiosk.request_submitted')->sole();
        $this->assertNull($audit->user_id);
        $this->assertSame($terminal->id, $audit->after['terminal']);
    }

    public function test_the_visitor_cannot_choose_the_office_or_the_status(): void
    {
        [, $token] = $this->pairedTerminal(1);

        $this->device($token)->post('/kiosk/requests', $this->spelsInput(['office_id' => 2, 'transaction_status' => 'Approved', 'amount' => 1, 'remarks' => 'x']));

        $record = Transaction::withoutGlobalScopes()->sole();
        $this->assertSame(1, $record->office_id);
        $this->assertSame(TransactionStatus::Submitted, $record->transaction_status);
        $this->assertNull($record->amount);
        $this->assertNull($record->remarks);
    }

    public function test_a_certification_is_received_at_once_with_its_issuance_details_and_attachments(): void
    {
        [, $token] = $this->pairedTerminal(1);

        $this->device($token)->post('/kiosk/requests', $this->certInput([
            'purpose_of_request' => 'Others', 'purpose_others' => 'Visa application',
            'used_lastname' => 'Santos', 'used_firstname' => 'Bea',
            'attachment_1' => UploadedFile::fake()->image('id.jpg', 100, 100),
        ]))->assertRedirect('/kiosk/done');

        $record = Transaction::sole();
        $this->assertSame(TransactionStatus::Received, $record->transaction_status);
        $this->assertSame(today()->toDateString(), $record->date_received_ro->toDateString());
        $this->assertTrue($record->transaction_date->isToday());
        $this->assertNotSame('00:00:00', $record->transaction_date->format('H:i:s'), 'The time of the request is kept.');

        $issuance = IssuanceRequest::sole();
        $this->assertSame('Others: Visa application', $issuance->purpose_of_request);
        $this->assertSame('Santos', $issuance->used_lastname);
        $this->assertSame('1990-05-04', $issuance->date_of_birth->toDateString());
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}\.jpg$/', $issuance->attachment_1);
        Storage::disk('attachments')->assertExists($issuance->attachment_1);
    }

    public function test_the_pald_kiosk_files_under_esd(): void
    {
        [, $token] = $this->pairedTerminal(10, 'PACD front desk');

        $this->device($token)->post('/kiosk/requests', $this->certInput());

        $this->assertSame(1, Transaction::sole()->office_id, 'Filed under ESD, not PALD.');
    }

    public function test_the_confirmation_shows_once_and_carries_no_personal_detail_beyond_a_first_name(): void
    {
        [, $token] = $this->pairedTerminal(1);
        $this->device($token)->post('/kiosk/requests', $this->spelsInput());
        $id = Transaction::sole()->id;

        $page = $this->device($token)->get('/kiosk/done')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Kiosk/Done')
                ->where('receipt.id', $id)
                ->where('receipt.first_name', 'Ana')
                ->where('receipt.spels', true))->viewData('page')['props']['receipt'];

        $this->assertEqualsCanonicalizing(['id', 'type', 'first_name', 'office', 'spels'], array_keys($page));

        $this->device($token)->get('/kiosk/done')->assertRedirect('/kiosk');
    }

    public function test_history_is_encrypted_and_cleared_so_back_shows_nothing_of_the_last_visitor(): void
    {
        [, $token] = $this->pairedTerminal();

        $form = $this->device($token)->get('/kiosk/request/certification')->assertOk();
        $this->assertTrue($form->viewData('page')['encryptHistory'] ?? false, 'Kiosk pages keep their history encrypted.');

        $menu = $this->device($token)->get('/kiosk')->assertOk();
        $this->assertTrue($menu->viewData('page')['clearHistory'] ?? false, 'Returning to the menu rotates the history key.');

        $this->device($token)->post('/kiosk/requests', $this->spelsInput());
        $done = $this->device($token)->get('/kiosk/done')->assertOk();
        $this->assertTrue($done->viewData('page')['clearHistory'] ?? false, 'The confirmation clears what came before it.');
    }

    public function test_the_kiosk_form_does_not_invite_browser_autofill(): void
    {
        $source = file_get_contents(resource_path('js/Pages/Kiosk/Request.vue'));

        $this->assertStringContainsString('<form novalidate autocomplete="off"', $source);
        $this->assertDoesNotMatchRegularExpression('/autocomplete="(?!off")[a-z-]+"/', $source, 'A shared terminal must not offer earlier visitors\' details.');
    }
    public function test_a_failed_submission_leaves_no_orphaned_upload(): void
    {
        [, $token] = $this->pairedTerminal();
        AuditLog::creating(fn () => throw new \RuntimeException('audit down'));

        $this->device($token)->post('/kiosk/requests', $this->certInput(['attachment_1' => UploadedFile::fake()->image('id.jpg', 100, 100)]))
            ->assertSessionHasErrors('form');

        $this->assertSame(0, Transaction::withoutGlobalScopes()->count());
        $this->assertSame([], Storage::disk('attachments')->files(), 'The stored upload is removed with the rolled-back record.');
    }
    // ─── Refusals ─────────────────────────────────────────────────────────

    public function test_the_privacy_notice_must_be_accepted_and_the_basics_given(): void
    {
        [, $token] = $this->pairedTerminal();

        $this->device($token)->post('/kiosk/requests', ['transaction_type' => 'Special Eligibility Application'])
            ->assertSessionHasErrors(['privacy_acknowledged', 'lname', 'fname', 'elig_code']);
        $this->device($token)->post('/kiosk/requests', $this->certInput(['privacy_acknowledged' => '0']))->assertSessionHasErrors('privacy_acknowledged');
        $this->device($token)->post('/kiosk/requests', $this->certInput(['current_address' => '', 'purpose_of_request' => '']))->assertSessionHasErrors(['current_address', 'purpose_of_request']);
        $this->assertSame(0, Transaction::count());
    }

    public function test_unknown_types_eligibilities_and_purposes_are_refused(): void
    {
        [, $token] = $this->pairedTerminal();

        $this->device($token)->post('/kiosk/requests', $this->spelsInput(['transaction_type' => 'Anything']))->assertSessionHasErrors('transaction_type');
        $this->device($token)->post('/kiosk/requests', $this->spelsInput(['elig_code' => 'NOPE']))->assertSessionHasErrors('elig_code');
        $this->device($token)->post('/kiosk/requests', $this->certInput(['purpose_of_request' => 'Make me rich']))->assertSessionHasErrors('purpose_of_request');
        $this->device($token)->post('/kiosk/requests', $this->certInput(['purpose_of_request' => 'Others', 'purpose_others' => '']))->assertSessionHasErrors('purpose_others');
        $this->device($token)->post('/kiosk/requests', $this->certInput(['date_of_birth' => now()->addYear()->toDateString()]))->assertSessionHasErrors('date_of_birth');
        $this->assertSame(0, Transaction::count());
    }

    public function test_a_filled_honeypot_is_refused_without_creating_anything(): void
    {
        [, $token] = $this->pairedTerminal();

        $this->device($token)->post('/kiosk/requests', $this->spelsInput(['website' => 'http://spam.example']))->assertSessionHasErrors('form');

        $this->assertSame(0, Transaction::count());
    }

    public function test_attachments_are_checked_by_content_as_at_the_counter(): void
    {
        [, $token] = $this->pairedTerminal();

        $this->device($token)->post('/kiosk/requests', $this->certInput(['attachment_1' => UploadedFile::fake()->create('run.php', 5, 'text/x-php')]))->assertSessionHasErrors('attachment_1');

        $path = tempnam(sys_get_temp_dir(), 'etts');
        file_put_contents($path, '<?php echo "not an image";');
        $this->device($token)->post('/kiosk/requests', $this->certInput(['attachment_1' => new UploadedFile($path, 'id.jpg', 'image/jpeg', null, true)]));

        $this->assertSame(0, Transaction::count());
        $this->assertSame([], Storage::disk('attachments')->files());
    }

    public function test_the_duplicate_approval_rule_applies_at_the_kiosk_with_a_plain_message(): void
    {
        [, $token] = $this->pairedTerminal();
        Transaction::factory()->approved('2025-04-15')->forApplicant('Cruz', 'Ana', 'Reyes', 'HGE')->create(['office_id' => 2]);

        $response = $this->device($token)->post('/kiosk/requests', $this->spelsInput());

        $response->assertSessionHasErrors(['form', 'reason']);
        $this->assertSame('spels_duplicate', session('errors')->getBag('default')->get('reason')[0]);
        $this->assertStringContainsString('already on record', session('errors')->getBag('default')->get('form')[0]);
        $this->assertSame(1, Transaction::withoutGlobalScopes()->count());
    }

    public function test_the_duplicate_message_discloses_no_contact_details(): void
    {
        [, $token] = $this->pairedTerminal();
        Transaction::factory()->approved('2025-04-15')->forApplicant('Cruz', 'Ana', 'Reyes', 'HGE')->create(['office_id' => 2, 'mobile' => '09998887777', 'email' => 'secret@example.com', 'official_receipt' => 'OR-4242']);

        $this->device($token)->post('/kiosk/requests', $this->spelsInput())->assertSessionHasErrors('form');

        $message = implode(' ', session('errors')->getBag('default')->get('form'));
        foreach (['09998887777', 'secret@example.com', 'OR-4242', 'FO-SAMAR'] as $private) {
            $this->assertStringNotContainsString($private, $message);
        }
    }

    public function test_submissions_are_rate_limited_per_address(): void
    {
        [, $token] = $this->pairedTerminal();
        RateLimiter::clear('ip:127.0.0.1');

        $statuses = [];
        foreach (range(1, 12) as $i) {
            $statuses[] = $this->device($token)->post('/kiosk/requests', $this->spelsInput(['lname' => "Person{$i}", 'fname' => "Test{$i}"]))->getStatusCode();
        }

        $this->assertSame(429, end($statuses));
        $this->assertLessThanOrEqual(10, Transaction::withoutGlobalScopes()->count());
    }

    // ─── Staff side ───────────────────────────────────────────────────────

    public function test_administrators_manage_kiosks_and_the_code_is_shown_once(): void
    {
        $this->actingAs($this->admin)->post('/admin/kiosks/codes', ['office_id' => 1, 'name' => 'ESD counter 1'])->assertRedirect();

        $first = $this->actingAs($this->admin)->get('/admin/kiosks')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Kiosks/Index')
            ->where('issued.name', 'ESD counter 1')
            ->has('pending', 1)
            ->has('offices', 3));
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{4}-[A-Z2-9]{4}$/', $first->viewData('page')['props']['issued']['code']);

        $this->actingAs($this->admin)->get('/admin/kiosks')->assertInertia(fn (Assert $page) => $page->where('issued', null));
    }

    public function test_an_administrator_can_revoke_a_terminal(): void
    {
        [$terminal] = $this->pairedTerminal();

        $this->actingAs($this->admin)->post("/admin/kiosks/{$terminal->id}/revoke")->assertSessionHas('status');

        $this->assertFalse($terminal->fresh()->isActive());
        $this->assertSame(1, AuditLog::where('action', 'kiosk.revoked')->count());
    }

    public function test_only_administrators_reach_the_kiosk_admin(): void
    {
        [$terminal] = $this->pairedTerminal();

        $this->get('/admin/kiosks')->assertRedirect('/login');

        $fo = User::factory()->create(['office_id' => 2]);
        $this->actingAs($fo)->get('/admin/kiosks')->assertForbidden();
        $this->actingAs($fo)->post('/admin/kiosks/codes', ['office_id' => 1, 'name' => 'x'])->assertForbidden();
        $this->actingAs($fo)->post("/admin/kiosks/{$terminal->id}/revoke")->assertForbidden();
        $this->assertTrue($terminal->fresh()->isActive());
    }
}
