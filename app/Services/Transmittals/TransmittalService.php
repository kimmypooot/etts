<?php

namespace App\Services\Transmittals;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Enums\TransmittalDirection;
use App\Enums\TransmittalStatus;
use App\Models\AuditLog;
use App\Models\Office;
use App\Models\Transaction;
use App\Models\Transmittal;
use App\Models\TransmittalItem;
use App\Models\User;
use App\Services\Transactions\WorkflowException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Transmittal batches, in both directions. The two directions share the tables
 * and little else: each has its own eligible statuses, owner office and final
 * step (see {@see TransmittalDirection}).
 *
 *  - A transaction can sit in only one open (Draft) batch, in either direction.
 *    A Returned record is eligible both ways (ESD may hand it back, the field
 *    office may resubmit it); whichever batch is released first rewrites its
 *    status and would invalidate the other, so a draft hides the record from
 *    both pickers.
 *  - An ESD batch is for one field office only.
 *  - Only a Draft can be edited or deleted. Handing a batch over
 *    ({@see release()}, {@see transmit()}) re-checks every record, because it
 *    may have changed since it was added.
 *
 * Authorisation (who may do this) is the caller's job, through the policy.
 */
final class TransmittalService
{
    public const MAX_RECORDS = 500;

    /**
     * Transactions that could go into a batch right now. Not yet restricted to
     * an office: the caller does that.
     *
     * @return Builder<Transaction>
     */
    public function eligible(TransmittalDirection $direction): Builder
    {
        return Transaction::query()
            ->whereIn('transaction_status', TransactionStatus::values($direction->eligibleStatuses()))
            ->when($direction === TransmittalDirection::FoToRo, fn (Builder $query) => $query->where('transaction_type', TransactionType::Spels->value))
            ->whereNotExists(fn ($sub) => $sub->select(DB::raw(1))
                ->from('transmittal_items as ti')
                ->join('transmittals as t2', 't2.id', '=', 'ti.transmittal_id')
                ->whereColumn('ti.transaction_id', 'transactions.id')
                ->where('t2.status', TransmittalStatus::Draft->value));
    }

    /**
     * Starts a draft batch.
     *
     * An ESD batch takes transaction ids only and its office is whichever office
     * all of them belong to. A field office batch is for `$officeId`, may carry
     * manually described documents too, and goes to ESD.
     *
     * @param  list<int>  $ids
     * @param  list<array{name: ?string, description: ?string, type?: ?string, remarks?: ?string}>  $manual
     */
    public function createDraft(TransmittalDirection $direction, array $ids, string $remarks, User $actor, ?int $officeId = null, array $manual = []): Transmittal
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        if ($ids === [] && $manual === []) {
            throw new WorkflowException($direction === TransmittalDirection::EsdToFo
                ? 'No transactions were selected for the transmittal.'
                : 'Add at least one application or document to this transmittal.');
        }

        if (count($ids) > self::MAX_RECORDS) {
            throw new WorkflowException('Too many transactions for one transmittal (limit '.self::MAX_RECORDS.').');
        }

        $manual = array_map(fn (array $row) => $this->cleanManual($row), $manual);

        return DB::transaction(function () use ($direction, $ids, $remarks, $actor, $officeId, $manual) {
            $records = $ids === []
                ? collect()
                : $this->eligible($direction)->whereIn('id', $ids)->lockForUpdate()->get();

            if ($records->count() !== count($ids)) {
                throw new WorkflowException('One or more selected transactions are no longer available for transmittal.');
            }

            if ($direction === TransmittalDirection::EsdToFo) {
                $offices = $records->pluck('office_id')->unique();

                if ($offices->count() > 1) {
                    throw new WorkflowException('You cannot bundle transactions from different field offices into a single transmittal.');
                }

                $target = (int) $offices->first();
                $origin = null;
            } else {
                if (! $officeId) {
                    throw new WorkflowException('Your account is not mapped to a field office.');
                }

                if ($records->contains(fn (Transaction $record) => $record->office_id !== $officeId)) {
                    throw new WorkflowException('One or more selected applications belong to a different office.');
                }

                $target = (int) Office::where('code', 'ESD')->value('id');
                $origin = $officeId;
            }

            $transmittal = Transmittal::create([
                'transmittal_no' => $direction->prefix().'-'.now()->format('Ymd-His').'-'.strtoupper(bin2hex(random_bytes(2))),
                'direction' => $direction,
                'target_office_id' => $target,
                'origin_office_id' => $origin,
                'status' => TransmittalStatus::Draft,
                'created_by_imis_id' => $actor->imis_user_id,
                'created_by_name' => $actor->name,
                'created_at' => now(),
                'remarks' => mb_substr(trim($remarks), 0, 200),
            ]);

            foreach ($records as $record) {
                $transmittal->items()->create(['transaction_id' => $record->id, 'is_manual' => false]);
            }

            foreach ($manual as $row) {
                $transmittal->items()->create($row);
            }

            $this->audit('transmittal.created', $transmittal, $actor, "Created transmittal {$transmittal->transmittal_no} with {$records->count()} transaction(s) and ".count($manual).' other document(s).');

            return $transmittal;
        });
    }

    public function addRecord(Transmittal $transmittal, int $transactionId, User $actor): TransmittalItem
    {
        return $this->lockedDraft($transmittal, function (Transmittal $locked) use ($transactionId, $actor) {
            $record = $this->eligible($locked->direction)->whereKey($transactionId)->lockForUpdate()->first();

            if ($record === null) {
                throw new WorkflowException('This transaction is no longer available to add.');
            }

            if ($record->office_id !== $locked->fieldOfficeId()) {
                throw new WorkflowException('This transaction belongs to a different field office than this transmittal.');
            }

            $item = $locked->items()->create(['transaction_id' => $record->id, 'is_manual' => false]);
            $this->audit('transmittal.item_added', $locked, $actor, "Added transaction #{$record->id} to {$locked->transmittal_no}.");

            return $item;
        });
    }

    /**
     * @param  array{name: ?string, description: ?string, type?: ?string, remarks?: ?string}  $data
     */
    public function addManual(Transmittal $transmittal, array $data, User $actor): TransmittalItem
    {
        $row = $this->cleanManual($data);

        return $this->lockedDraft($transmittal, function (Transmittal $locked) use ($row, $actor) {
            $item = $locked->items()->create($row);
            $this->audit('transmittal.item_added', $locked, $actor, "Added the document \"{$row['manual_name']}\" to {$locked->transmittal_no}.");

            return $item;
        });
    }

    /**
     * @param  array{name: ?string, description: ?string, type?: ?string, remarks?: ?string}  $data
     */
    public function updateManual(Transmittal $transmittal, int $itemId, array $data, User $actor): TransmittalItem
    {
        $row = $this->cleanManual($data);

        return $this->lockedDraft($transmittal, function (Transmittal $locked) use ($itemId, $row, $actor) {
            $item = $locked->items()->whereKey($itemId)->where('is_manual', true)->first()
                ?? throw new WorkflowException('That document is not part of this transmittal.');

            $item->update($row);
            $this->audit('transmittal.item_edited', $locked, $actor, "Edited the document \"{$row['manual_name']}\" in {$locked->transmittal_no}.");

            return $item;
        });
    }

    public function removeItem(Transmittal $transmittal, int $itemId, User $actor): void
    {
        $this->lockedDraft($transmittal, function (Transmittal $locked) use ($itemId, $actor) {
            $item = $locked->items()->whereKey($itemId)->first()
                ?? throw new WorkflowException('That row is not part of this transmittal.');

            $item->delete();
            $this->audit('transmittal.item_removed', $locked, $actor, "Removed a row from {$locked->transmittal_no}.");
        });
    }

    public function updateRemarks(Transmittal $transmittal, string $remarks, User $actor): Transmittal
    {
        return $this->lockedDraft($transmittal, function (Transmittal $locked) use ($remarks, $actor) {
            $locked->update(['remarks' => mb_substr(trim($remarks), 0, 200)]);
            $this->audit('transmittal.edited', $locked, $actor, "Edited the remarks of {$locked->transmittal_no}.");

            return $locked;
        });
    }

    /**
     * The note shown beside a transaction on a routing slip (its details or its
     * remarks), edited while the batch is still a draft.
     */
    public function updateRecordNote(Transmittal $transmittal, int $transactionId, string $field, string $value, User $actor): void
    {
        if (! in_array($field, ['transaction_details', 'remarks'], true)) {
            throw new WorkflowException('That field cannot be edited here.');
        }

        $this->lockedDraft($transmittal, function (Transmittal $locked) use ($transactionId, $field, $value, $actor) {
            if (! $locked->items()->where('transaction_id', $transactionId)->exists()) {
                throw new WorkflowException('That transaction is not part of this transmittal.');
            }

            $record = Transaction::query()->whereKey($transactionId)->lockForUpdate()->first()
                ?? throw new WorkflowException('The transaction no longer exists.');

            $record->forceFill([$field => trim($value) === '' ? null : trim($value)])->save();
            $this->audit('transmittal.note_edited', $locked, $actor, "Edited {$field} of transaction #{$transactionId} in {$locked->transmittal_no}.");
        });
    }

    /**
     * Hands an ESD batch to the field office. Each transaction gets its release
     * date; an approved SPELS application becomes Approved/Released and a
     * completed certification or authentication becomes Released.
     *
     * A disapproved or returned application is being handed back, not
     * released: its status is left alone and only its document is marked
     * released. (The legacy release rewrote every SPELS record to
     * Approved/Released, which would have turned a disapproval into an approval
     * and counted it against the applicant under the one-approval rule.)
     */
    public function release(Transmittal $transmittal, User $actor): Transmittal
    {
        return $this->lockedDraft($transmittal, function (Transmittal $locked) use ($actor) {
            if ($locked->direction !== TransmittalDirection::EsdToFo) {
                throw new WorkflowException('Only a transmittal to a field office is released.');
            }

            $records = $this->recordsOf($locked);
            $today = today()->toDateString();

            foreach ($records as $record) {
                $from = $record->transaction_status;
                $to = match ($from) {
                    TransactionStatus::Approved => TransactionStatus::ApprovedReleased,
                    TransactionStatus::Completed => TransactionStatus::Released,
                    default => $from,
                };

                $record->forceFill([
                    'transaction_status' => $to,
                    'doc_release_date' => $today,
                    'document_status' => 'Released',
                    'action_officer' => $actor->username,
                ])->save();

                AuditLog::record('transaction.released', $record,
                    before: ['status' => $from->value],
                    after: ['status' => $to->value, 'summary' => "Document released in transmittal {$locked->transmittal_no}.".($to === $from ? ' Status unchanged.' : " Status set to {$to->value}.")],
                    user: $actor,
                );
            }

            $locked->update(['status' => TransmittalStatus::Released]);
            $this->audit('transmittal.released', $locked, $actor, "Released transmittal {$locked->transmittal_no} ({$records->count()} transaction(s)).");

            return $locked;
        });
    }

    /**
     * Sends a field office batch up to the Regional Office: its applications
     * become forwarded, dated `$date`.
     */
    public function transmit(Transmittal $transmittal, string $date, User $actor): Transmittal
    {
        return $this->lockedDraft($transmittal, function (Transmittal $locked) use ($date, $actor) {
            if ($locked->direction !== TransmittalDirection::FoToRo) {
                throw new WorkflowException('Only a transmittal to the Regional Office is transmitted.');
            }

            $manual = $locked->items()->where('is_manual', true)->count();
            $records = $this->recordsOf($locked);

            if ($records->isEmpty() && $manual === 0) {
                throw new WorkflowException('Add at least one application or document to this transmittal.');
            }

            foreach ($records as $record) {
                $from = $record->transaction_status;
                $record->forceFill(['transaction_status' => TransactionStatus::Forwarded, 'date_sent_to_ro' => $date])->save();

                AuditLog::record('transaction.status_changed', $record,
                    before: ['status' => $from->value],
                    after: ['status' => TransactionStatus::Forwarded->value, 'summary' => "Transmitted to the Regional Office in batch {$locked->transmittal_no} on {$date}."],
                    user: $actor,
                );
            }

            $locked->update(['status' => TransmittalStatus::Transmitted]);
            $this->audit('transmittal.transmitted', $locked, $actor, "Transmitted {$locked->transmittal_no}: {$records->count()} application(s) forwarded, {$manual} other document(s) included.");

            return $locked;
        });
    }

    public function delete(Transmittal $transmittal, User $actor): void
    {
        $this->lockedDraft($transmittal, function (Transmittal $locked) use ($actor) {
            $this->audit('transmittal.deleted', $locked, $actor, "Deleted the draft transmittal {$locked->transmittal_no}.");
            $locked->delete();
        });
    }

    /**
     * The rows of a batch as the screens and the slip show them: each
     * transaction with its name, type and notes (read from the archive when it
     * has since been deleted), or the manual document.
     *
     * @return list<array<string, mixed>>
     */
    public function describeItems(Transmittal $transmittal): array
    {
        $items = $transmittal->items()->get();
        $ids = $items->pluck('transaction_id')->filter()->all();

        $live = Transaction::withoutGlobalScopes()->whereIn('id', $ids)->get()->keyBy('id');
        $archived = DB::table('deleted_transactions')->whereIn('id', array_diff($ids, $live->keys()->all()))->get()->keyBy('id');

        return $items->map(function (TransmittalItem $item) use ($live, $archived) {
            if ($item->is_manual) {
                return [
                    'id' => $item->id,
                    'is_manual' => true,
                    'name' => $item->manual_name,
                    'description' => $item->manual_description,
                    'doc_type' => $item->manual_doc_type,
                    'remarks' => $item->remarks,
                ];
            }

            $record = $live[$item->transaction_id] ?? $archived[$item->transaction_id] ?? null;
            $type = $record?->transaction_type;

            return [
                'id' => $item->id,
                'is_manual' => false,
                'transaction_id' => $item->transaction_id,
                'name' => $record ? trim($record->lname.', '.$record->fname) : 'Transaction #'.$item->transaction_id.' (not found)',
                'type' => $type instanceof TransactionType ? $type->value : $type,
                'status' => $record ? ($record->transaction_status instanceof TransactionStatus ? $record->transaction_status->value : $record->transaction_status) : null,
                'eligibility' => $record?->elig_code,
                'details' => $record?->transaction_details,
                'record_remarks' => $record?->remarks,
                'remarks' => $item->remarks,
                'deleted' => $record !== null && ! isset($live[$item->transaction_id]),
            ];
        })->all();
    }

    // ─── Plumbing ─────────────────────────────────────────────────────────

    /**
     * Runs `$work` on a freshly locked draft; refuses anything already handed over.
     *
     * @template T
     *
     * @param  callable(Transmittal): T  $work
     * @return T
     */
    private function lockedDraft(Transmittal $transmittal, callable $work): mixed
    {
        return DB::transaction(function () use ($transmittal, $work) {
            $locked = Transmittal::query()->whereKey($transmittal->getKey())->lockForUpdate()->first()
                ?? throw new WorkflowException('Transmittal record not found.');

            if (! $locked->isDraft()) {
                throw new WorkflowException($locked->direction === TransmittalDirection::FoToRo
                    ? 'This transmittal has already been transmitted.'
                    : 'This transmittal has already been released and can no longer be edited.');
            }

            return $work($locked);
        });
    }

    /**
     * The live transactions of a batch, locked and re-checked: each must still
     * be in a status this direction accepts, and belong to the batch office.
     *
     * @return Collection<int, Transaction>
     */
    private function recordsOf(Transmittal $transmittal): Collection
    {
        $ids = $transmittal->items()->where('is_manual', false)->whereNotNull('transaction_id')->pluck('transaction_id')->all();
        $records = Transaction::query()->whereIn('id', $ids)->lockForUpdate()->get();

        $missing = array_diff($ids, $records->pluck('id')->all());
        if ($missing !== []) {
            throw new WorkflowException('Transaction #'.reset($missing).' no longer exists. Remove it from this transmittal first.');
        }

        $allowed = $transmittal->direction->eligibleStatuses();

        foreach ($records as $record) {
            $wrongStatus = ! in_array($record->transaction_status, $allowed, true);
            $wrongType = $transmittal->direction === TransmittalDirection::FoToRo && ! $record->transaction_type->isSpels();
            $wrongOffice = $record->office_id !== $transmittal->fieldOfficeId();

            if ($wrongStatus || $wrongType || $wrongOffice) {
                throw new WorkflowException(sprintf(
                    'Cannot continue: %s is now "%s" and is no longer eligible. Remove it from this transmittal and try again.',
                    trim($record->lname.', '.$record->fname),
                    $record->transaction_status->value,
                ));
            }
        }

        return $records;
    }

    /**
     * @param  array{name: ?string, description: ?string, type?: ?string, remarks?: ?string}  $row
     * @return array{is_manual: true, manual_name: string, manual_description: string, manual_doc_type: ?string, remarks: ?string}
     */
    private function cleanManual(array $row): array
    {
        $name = mb_substr(trim((string) ($row['name'] ?? '')), 0, 150);
        $description = mb_substr(trim((string) ($row['description'] ?? '')), 0, 255);
        $type = mb_substr(trim((string) ($row['type'] ?? '')), 0, 100);
        $remarks = mb_substr(trim((string) ($row['remarks'] ?? '')), 0, 200);

        if ($name === '') {
            throw new WorkflowException('A name is required to identify a manually added document.');
        }

        if ($description === '') {
            throw new WorkflowException('A description is required for a manually added document.');
        }

        return [
            'is_manual' => true,
            'manual_name' => $name,
            'manual_description' => $description,
            'manual_doc_type' => $type !== '' ? $type : null,
            'remarks' => $remarks !== '' ? $remarks : null,
        ];
    }

    private function audit(string $action, Transmittal $transmittal, User $actor, string $summary): void
    {
        AuditLog::record($action, $transmittal, after: ['summary' => $summary], user: $actor);
    }
}
