<?php

namespace App\Support;

use App\Enums\TransactionType;
use App\Models\Eligibility;
use App\Models\Office;
use App\Models\Transaction;

/**
 * What the transaction form needs, shared by the ESD screens and the field
 * office portal: the options, the blank record, a stored record as form values
 * and its attachment links. Each portal passes its own URL base and attachment
 * route, so the one form page works under either.
 */
final class TransactionFormPresenter
{
    /**
     * @param  string  $base  URL prefix of the portal's transaction screens.
     * @param  string  $attachmentRoute  Name of the route that shows an attachment.
     * @param  int|null  $lockedOfficeId  When set, the form can only file at this office.
     */
    public function __construct(
        private string $base,
        private string $attachmentRoute,
        private ?int $lockedOfficeId = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function create(TransactionType $type, int $defaultOfficeId): array
    {
        return [
            'mode' => 'create',
            'base' => $this->base,
            'officeLocked' => $this->lockedOfficeId !== null,
            'record' => [
                'transaction_type' => $type->value,
                'office_id' => $this->lockedOfficeId ?? $defaultOfficeId,
                'transaction_date' => today()->toDateString(),
            ],
            'options' => $this->options(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function edit(Transaction $transaction): array
    {
        $transaction->loadMissing('issuanceRequest');
        $issuance = $transaction->issuanceRequest;

        return [
            'mode' => 'edit',
            'base' => $this->base,
            'officeLocked' => $this->lockedOfficeId !== null,
            'id' => $transaction->id,
            'status' => $transaction->transaction_status->value,
            'version' => $transaction->updated_at?->format('Y-m-d H:i:s'),
            'record' => [
                ...$transaction->only('transaction_type', 'office_id', 'lname', 'fname', 'mname', 'extn', 'sex', 'mobile', 'email', 'elig_code', 'official_receipt', 'amount', 'remarks', 'transaction_details'),
                'transaction_type' => $transaction->transaction_type->value,
                'transaction_date' => $transaction->transaction_date->toDateString(),
                'or_date' => $transaction->or_date?->toDateString(),
                ...($issuance ? [
                    ...$issuance->only('current_address', 'civil_status', 'place_of_birth', 'employment_status', 'company_name', 'company_address', 'used_lastname', 'used_firstname', 'used_middlename', 'used_extn', 'used_address', 'eligibility', 'place_of_exam_issuance', 'book', 'page', 'sequence', 'examinee_no', 'cert_no', 'rating'),
                    'purpose_of_request' => $this->purpose($issuance->purpose_of_request)[0],
                    'purpose_others' => $this->purpose($issuance->purpose_of_request)[1],
                    'date_of_birth' => $issuance->date_of_birth?->toDateString(),
                    'date_of_exam_issuance' => $issuance->date_of_exam_issuance?->toDateString(),
                    'effectivity_date' => $issuance->effectivity_date?->toDateString(),
                    'release_date' => $issuance->release_date?->toDateString(),
                ] : []),
                ...$this->copies($transaction->transaction_details),
            ],
            'attachments' => $issuance ? array_values(array_filter([
                $issuance->attachment_1 ? ['slot' => 1, 'url' => route($this->attachmentRoute, [$transaction->id, 1]), 'type' => pathinfo($issuance->attachment_1, PATHINFO_EXTENSION)] : null,
                $issuance->attachment_2 ? ['slot' => 2, 'url' => route($this->attachmentRoute, [$transaction->id, 2]), 'type' => pathinfo($issuance->attachment_2, PATHINFO_EXTENSION)] : null,
            ])) : [],
            'options' => $this->options(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function options(): array
    {
        return [
            'types' => array_map(fn (TransactionType $type) => ['value' => $type->value, 'label' => $type->value], TransactionType::cases()),
            'offices' => Office::query()
                ->when($this->lockedOfficeId, fn ($query, $id) => $query->whereKey($id))
                ->orderBy('name')->get(['id', 'code', 'name'])
                ->map(fn (Office $office) => ['value' => $office->id, 'label' => "{$office->code} — {$office->name}"])->all(),
            'eligibilities' => Eligibility::orderBy('code')->get(['code', 'name'])->map(fn (Eligibility $eligibility) => ['value' => $eligibility->code, 'label' => "{$eligibility->code} — {$eligibility->name}"])->all(),
            'purposes' => config('etts.purposes'),
            'prices' => config('etts.prices'),
            'maxKb' => config('etts.attachments.max_kb'),
        ];
    }

    /**
     * "Others: lost the original" is shown as the purpose "Others" and its text.
     *
     * @return array{?string, ?string}
     */
    private function purpose(?string $stored): array
    {
        if ($stored !== null && str_starts_with($stored, 'Others: ')) {
            return ['Others', substr($stored, 8)];
        }

        return [$stored, null];
    }

    /**
     * The copies behind a stored "COE-1, AUTH-2" (also the legacy "Auth-2").
     *
     * @return array{coe_copies: int, auth_copies: int}
     */
    private function copies(?string $details): array
    {
        preg_match('/COE-(\d+)/i', (string) $details, $coe);
        preg_match('/AUTH-(\d+)/i', (string) $details, $auth);

        return ['coe_copies' => (int) ($coe[1] ?? 0), 'auth_copies' => (int) ($auth[1] ?? 0)];
    }
}
