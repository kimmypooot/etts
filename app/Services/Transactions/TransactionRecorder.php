<?php

namespace App\Services\Transactions;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\AuditLog;
use App\Models\IssuanceRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Spels\DuplicateCheck;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Creates and edits transactions. The status never changes here except for the
 * one case below; moving a record along its flow is {@see TransactionWorkflow}.
 *
 *  - A new SPELS application starts `submitted` (it still has to be received).
 *    A new certification or authentication is received at ESD straight away,
 *    so it starts `received` with both dates set to the filing date.
 *  - A SPELS application cannot be saved when the same applicant already holds
 *    an approved record for the same eligibility.
 *  - A certification or authentication is priced from its copies:
 *    COE copies x 100 + authentication copies x 50, and its details read
 *    "COE-1, AUTH-2". When no copies are given on an edit, the stored amount
 *    and details are kept.
 *  - A record cannot change family (SPELS <-> certification/authentication):
 *    one has an issuance request and the other does not.
 *  - An edit carries the version it was opened at; if the record changed since,
 *    the save is refused instead of overwriting the other person's work.
 *  - Recording the payment on a SPELS application filed at ESD confirms the
 *    walk-in and marks it received (legacy "Details and payment" behaviour).
 */
final class TransactionRecorder
{
    private const TRANSACTION_FIELDS = [
        'office_id', 'lname', 'fname', 'mname', 'extn', 'sex', 'mobile', 'email', 'elig_code',
        'official_receipt', 'or_date', 'amount', 'remarks', 'transaction_details',
    ];

    private const ISSUANCE_FIELDS = [
        'current_address', 'civil_status', 'date_of_birth', 'place_of_birth', 'purpose_of_request',
        'employment_status', 'company_name', 'company_address', 'used_lastname', 'used_firstname',
        'used_middlename', 'used_extn', 'used_address', 'eligibility', 'date_of_exam_issuance',
        'place_of_exam_issuance', 'book', 'page', 'sequence', 'examinee_no', 'cert_no', 'rating',
        'effectivity_date', 'release_date',
    ];

    public function __construct(
        private DuplicateCheck $duplicates,
        private AttachmentStore $attachments,
        private TransactionWorkflow $workflow,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Validated form input.
     */
    public function create(array $data, ?User $actor, ?UploadedFile $attachment1 = null, ?UploadedFile $attachment2 = null): Transaction
    {
        $type = TransactionType::from($data['transaction_type']);
        $fields = $this->clean($data);
        $stored = [];

        try {
            return DB::transaction(function () use ($type, $fields, $actor, $attachment1, $attachment2, &$stored) {
            if ($type->isSpels()) {
                $this->assertNoDuplicate($fields, null);
            }

            $attributes = Arr::only($fields, self::TRANSACTION_FIELDS) + [
                'transaction_type' => $type,
                'transaction_date' => $fields['transaction_date'],
            ];

            if ($type->isIssuance()) {
                [$attributes['amount'], $attributes['transaction_details']] = $this->priced($fields) ?? [$attributes['amount'] ?? null, $attributes['transaction_details'] ?? null];
                // The filing date may carry the time (the kiosk records when); the two
                // date columns take the day only.
                $day = substr($fields['transaction_date'], 0, 10);
                $attributes += [
                    'transaction_status' => TransactionStatus::Received,
                    'date_sent_to_ro' => $day,
                    'date_received_ro' => $day,
                ];
            } else {
                $attributes += ['transaction_status' => TransactionStatus::Submitted];
            }

            $transaction = Transaction::create($attributes);

            if ($type->isIssuance()) {
                IssuanceRequest::create([
                    ...Arr::only($fields, self::ISSUANCE_FIELDS),
                    'transaction_id' => $transaction->id,
                    'sex' => $fields['sex'] ?? null,
                    'attachment_1' => ($stored[] = $this->attachments->store($attachment1)),
                    'attachment_2' => ($stored[] = $this->attachments->store($attachment2)),
                ]);
            }

            AuditLog::record('transaction.created', $transaction,
                after: ['summary' => "Created a {$type->value} transaction for {$this->name($transaction)}.", 'status' => $transaction->transaction_status->value],
                user: $actor,
            );

            return $transaction;
            });
        } catch (\Throwable $e) {
            // The record was rolled back: do not leave its uploads behind with nothing pointing at them.
            foreach (array_filter($stored) as $name) {
                $this->attachments->forget($name);
            }

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $data  Validated form input.
     * @param  string|null  $version  The record's `updated_at` as the form was opened.
     */
    public function update(Transaction $transaction, array $data, User $actor, ?string $version = null, ?UploadedFile $attachment1 = null, ?UploadedFile $attachment2 = null): Transaction
    {
        $fields = $this->clean($data);

        return DB::transaction(function () use ($transaction, $fields, $actor, $version, $attachment1, $attachment2) {
            $locked = Transaction::query()->with(['office', 'issuanceRequest'])->whereKey($transaction->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                throw new WorkflowException('The transaction no longer exists.');
            }

            if ($version !== null && $locked->updated_at?->format('Y-m-d H:i:s') !== $version) {
                throw new WorkflowException('This record was modified elsewhere since you opened it. Reload the page to get the latest data before saving.', 'conflict');
            }

            $type = TransactionType::from($fields['transaction_type']);

            if ($type->isSpels() !== $locked->transaction_type->isSpels()) {
                throw new WorkflowException('A SPELS application cannot be changed into a certification or authentication, or the reverse. Delete it and file a new one.');
            }

            if ($type->isSpels()) {
                $this->assertNoDuplicate($fields, $locked->id);
            }

            $attributes = Arr::only($fields, self::TRANSACTION_FIELDS) + ['transaction_type' => $type];

            if ($locked->transaction_date->toDateString() !== $fields['transaction_date']) {
                $attributes['transaction_date'] = $fields['transaction_date'];
            }

            if ($type->isIssuance() && ($priced = $this->priced($fields)) !== null) {
                [$attributes['amount'], $attributes['transaction_details']] = $priced;
            }

            $changed = $this->dirtyKeys($locked, $attributes);
            $locked->forceFill($attributes)->save();

            if ($type->isIssuance()) {
                $issuance = $locked->issuanceRequest ?? new IssuanceRequest(['transaction_id' => $locked->id]);
                $values = [...Arr::only($fields, self::ISSUANCE_FIELDS), 'sex' => $fields['sex'] ?? null];

                foreach ([1 => $attachment1, 2 => $attachment2] as $slot => $upload) {
                    if (($name = $this->attachments->store($upload)) !== null) {
                        $values["attachment_{$slot}"] = $name;
                        $changed[] = "attachment_{$slot}";
                    }
                }

                $changed = [...$changed, ...$this->dirtyKeys($issuance, $values)];
                $issuance->forceFill($values)->save();
            }

            $this->confirmWalkIn($locked, $fields, $actor, $changed);

            AuditLog::record('transaction.updated', $locked,
                after: ['summary' => 'Edited the transaction details'.($changed ? ' ('.implode(', ', array_unique($changed)).')' : ' (no changes)').'.', 'changed' => array_values(array_unique($changed))],
                user: $actor,
            );

            return $locked->fresh();
        });
    }

    /**
     * Quick edit of the fields staff correct most: the OR number, details,
     * remarks and the three dates. Dates must run in order.
     *
     * @param  array<string, mixed>  $data
     */
    public function quickEdit(Transaction $transaction, array $data, User $actor): Transaction
    {
        $fields = $this->clean($data);

        $sent = $fields['date_sent_to_ro'] ?? null;
        $received = $fields['date_received_ro'] ?? null;
        $completed = $fields['date_completed'] ?? null;

        if ($sent !== null && $received !== null && $sent > $received) {
            throw new WorkflowException('The transmitted date must be on or before the received date.');
        }
        if ($received !== null && $completed !== null && $received > $completed) {
            throw new WorkflowException('The received date must be on or before the completed date.');
        }

        return DB::transaction(function () use ($transaction, $fields, $actor) {
            $locked = Transaction::query()->whereKey($transaction->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                throw new WorkflowException('The transaction no longer exists.');
            }

            $attributes = Arr::only($fields, ['official_receipt', 'transaction_details', 'remarks', 'date_sent_to_ro', 'date_received_ro', 'date_completed']);
            $changed = $this->dirtyKeys($locked, $attributes);
            $locked->forceFill($attributes)->save();

            AuditLog::record('transaction.quick_edited', $locked,
                after: ['summary' => 'Quick-edited '.($changed ? implode(', ', $changed) : 'nothing').'.', 'changed' => $changed],
                user: $actor,
            );

            return $locked;
        });
    }

    // ─── Rules ────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $fields
     */
    private function assertNoDuplicate(array $fields, ?int $excludeId): void
    {
        $result = $this->duplicates->check($fields['lname'] ?? null, $fields['fname'] ?? null, $fields['mname'] ?? null, $fields['elig_code'] ?? null, $excludeId);

        if ($result->blocked) {
            throw new WorkflowException($this->duplicates->message($result), 'spels_duplicate');
        }
    }

    /**
     * Saving the payment of a SPELS application filed at ESD confirms the walk-in.
     *
     * @param  array<string, mixed>  $fields
     * @param  list<string>  $changed
     */
    private function confirmWalkIn(Transaction $transaction, array $fields, User $actor, array &$changed): void
    {
        $transaction->loadMissing('office');

        if (! $transaction->transaction_type->isSpels()
            || $transaction->transaction_status !== TransactionStatus::Submitted
            || ! ($transaction->office?->isEsd() ?? false)
            || blank($transaction->official_receipt)) {
            return;
        }

        $date = $transaction->transaction_date->toDateString();
        $transaction->forceFill(['date_sent_to_ro' => $date])->save();
        $this->workflow->receive($transaction, $date, $actor);
        $changed[] = 'status (walk-in confirmed)';
    }

    /**
     * Amount and details for a certification or authentication priced from its
     * copies, or null when no copies were given.
     *
     * @param  array<string, mixed>  $fields
     * @return array{int, string}|null
     */
    private function priced(array $fields): ?array
    {
        $coe = max(0, (int) ($fields['coe_copies'] ?? 0));
        $auth = max(0, (int) ($fields['auth_copies'] ?? 0));

        if ($coe + $auth === 0) {
            return null;
        }

        $details = array_filter([$coe > 0 ? "COE-{$coe}" : null, $auth > 0 ? "AUTH-{$auth}" : null]);

        return [$coe * config('etts.prices.coe') + $auth * config('etts.prices.auth'), implode(', ', $details)];
    }

    // ─── Plumbing ─────────────────────────────────────────────────────────

    /**
     * Trims every string, turns empty ones into null, and folds the purpose
     * "Others" with its free text into one value.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function clean(array $data): array
    {
        $clean = array_map(function ($value) {
            if (is_string($value)) {
                $value = preg_replace('/\s+/u', ' ', trim($value)) ?? '';
                // Free text keeps its line breaks; only single-line fields are collapsed below.
            }

            return $value === '' ? null : $value;
        }, Arr::except($data, ['attachment_1', 'attachment_2']));

        foreach (['remarks', 'transaction_details', 'current_address', 'company_address', 'used_address'] as $multiline) {
            if (isset($data[$multiline]) && is_string($data[$multiline])) {
                $clean[$multiline] = trim($data[$multiline]) === '' ? null : trim($data[$multiline]);
            }
        }

        if (($clean['purpose_of_request'] ?? null) === 'Others' && filled($clean['purpose_others'] ?? null)) {
            $clean['purpose_of_request'] = 'Others: '.$clean['purpose_others'];
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return list<string>
     */
    private function dirtyKeys(\Illuminate\Database\Eloquent\Model $model, array $attributes): array
    {
        $copy = $model->replicate();
        $copy->setRawAttributes($model->getAttributes(), true);
        $copy->forceFill($attributes);

        return array_values(array_diff(array_keys($copy->getDirty()), ['updated_at', 'transaction_id']));
    }

    private function name(Transaction $transaction): string
    {
        return trim($transaction->lname.', '.$transaction->fname);
    }
}
