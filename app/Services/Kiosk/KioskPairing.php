<?php

namespace App\Services\Kiosk;

use App\Models\AuditLog;
use App\Models\KioskPairingCode;
use App\Models\KioskTerminal;
use App\Models\Office;
use App\Models\User;
use App\Services\Transactions\WorkflowException;
use Illuminate\Support\Facades\DB;

/**
 * Pairs a kiosk device to an office.
 *
 * An administrator signed in to ETTS issues a one-time code for an office; the
 * device enters it on its set-up screen and receives a random token that it
 * keeps in a cookie. So the only secret typed on the public screen is a short
 * code that works once and expires, never a staff password.
 *
 * Neither the code nor the token is stored, only their SHA-256 hashes (both are
 * long random values, so a plain hash is enough). A terminal can be revoked at
 * any time and stops working on its next request.
 */
final class KioskPairing
{
    /** Letters and digits with nothing that looks like another (no 0, O, 1, I, L). */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /**
     * Issues a pairing code. The plain code is returned once and cannot be read again.
     *
     * @return array{code: string, expires_at: \Illuminate\Support\Carbon}
     */
    public function issueCode(Office $office, string $terminalName, User $admin): array
    {
        $name = trim($terminalName);

        if ($name === '' || mb_strlen($name) > 80) {
            throw new WorkflowException('Give the terminal a name of up to 80 characters, for example "ESD counter 1".');
        }

        $code = $this->randomCode();
        $expires = now()->addMinutes(config('etts.kiosk.pairing_minutes'));

        KioskPairingCode::create([
            'code_hash' => $this->hash($this->normalise($code)),
            'office_id' => $office->id,
            'terminal_name' => $name,
            'created_by' => $admin->id,
            'expires_at' => $expires,
        ]);

        AuditLog::record('kiosk.code_issued', $office, after: ['summary' => "Issued a kiosk pairing code for {$name} at {$office->code}."], user: $admin);

        return ['code' => $code, 'expires_at' => $expires];
    }

    /**
     * Redeems a code on a device. The code works once: two devices racing for
     * it cannot both win.
     *
     * @return array{terminal: KioskTerminal, token: string}
     */
    public function redeem(string $code, ?string $ip): array
    {
        $hash = $this->hash($this->normalise($code));

        return DB::transaction(function () use ($hash, $ip) {
            $pairing = KioskPairingCode::query()->where('code_hash', $hash)->lockForUpdate()->first();

            // One message for every reason, so a guess learns nothing.
            if ($pairing === null || ! $pairing->isUsable()) {
                throw new WorkflowException('That code is not valid. Ask ESD for a new one.');
            }

            $pairing->update(['used_at' => now()]);

            $token = bin2hex(random_bytes(32));
            $terminal = KioskTerminal::create([
                'name' => $pairing->terminal_name,
                'office_id' => $pairing->office_id,
                'token_hash' => $this->hash($token),
                'paired_by' => $pairing->created_by,
                'paired_at' => now(),
                'last_seen_at' => now(),
                'last_ip' => $ip,
            ]);

            // The device did this, not the administrator who issued the code.
            AuditLog::record('kiosk.paired', $terminal, after: ['summary' => "Paired the kiosk {$terminal->name} at {$pairing->office->code}.", 'code_issued_by' => $pairing->created_by]);

            return ['terminal' => $terminal, 'token' => $token];
        });
    }

    /**
     * The active terminal a device token belongs to, or null.
     */
    public function terminalFor(?string $token): ?KioskTerminal
    {
        if ($token === null || ! preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }

        $terminal = KioskTerminal::query()->with('office')->where('token_hash', $this->hash($token))->first();

        return $terminal?->isActive() ? $terminal : null;
    }

    public function revoke(KioskTerminal $terminal, User $admin): void
    {
        if (! $terminal->isActive()) {
            return;
        }

        $terminal->update(['revoked_at' => now()]);

        AuditLog::record('kiosk.revoked', $terminal, after: ['summary' => "Revoked the kiosk {$terminal->name}."], user: $admin);
    }

    /** A code as shown to people: two groups of four ("K7M4-92QX"). */
    private function randomCode(): string
    {
        $chars = '';
        for ($i = 0; $i < 8; $i++) {
            $chars .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return substr($chars, 0, 4).'-'.substr($chars, 4);
    }

    /** Case, spaces and the dash do not matter when typing a code. */
    private function normalise(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
    }

    private function hash(string $value): string
    {
        return hash('sha256', $value);
    }
}
