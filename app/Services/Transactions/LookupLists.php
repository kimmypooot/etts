<?php

namespace App\Services\Transactions;

use App\Enums\AcquisitionCategory;
use App\Models\Eligibility;
use App\Models\ExamCenter;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\In;

/**
 * The pick lists of the request forms (kiosk, ESD, field office, processing
 * page) and the rules that hold a submission to them. One place, so a form and
 * its validation offer the same choices.
 *
 * A record's current value is always kept as a choice, so a record saved before
 * a list changed (or imported with a spelling the list no longer has) can still
 * be saved without being forced to change.
 */
final class LookupLists
{
    /**
     * Eligibilities a special eligibility application can be filed under: the
     * ones obtained by special grant.
     *
     * @return Collection<int, Eligibility>
     */
    public static function spelsEligibilities(?string $keepCode = null): Collection
    {
        return Eligibility::query()
            ->where(fn ($query) => $query
                ->where('acquisition_category', AcquisitionCategory::SpecialGrant->value)
                ->when(filled($keepCode), fn ($inner) => $inner->orWhere('code', $keepCode)))
            ->orderBy('name')
            ->get(['code', 'name']);
    }

    /** A special eligibility code must be a special grant, or the record's current code. */
    public static function spelsCodeRule(?string $currentCode = null): Exists
    {
        return Rule::exists('eligibilities', 'code')->where(fn ($query) => $query
            ->where('acquisition_category', AcquisitionCategory::SpecialGrant->value)
            ->when(filled($currentCode), fn ($inner) => $inner->orWhere('code', $currentCode)));
    }

    /**
     * Titles a certification or authentication can be for: every eligibility.
     * Requests store the title as text.
     *
     * @return list<string>
     */
    public static function issuanceEligibilities(?string $keep = null): array
    {
        return self::withKept(Eligibility::orderBy('name')->pluck('name')->all(), $keep);
    }

    /**
     * Places of examination / issuance that are switched on. Requests store the
     * name as text.
     *
     * @return list<string>
     */
    public static function examCenters(?string $keep = null): array
    {
        return self::withKept(ExamCenter::where('is_active', true)->orderBy('name')->pluck('name')->all(), $keep);
    }

    public static function issuanceEligibilityRule(?string $current = null): In
    {
        return Rule::in(self::issuanceEligibilities($current));
    }

    public static function examCenterRule(?string $current = null): In
    {
        return Rule::in(self::examCenters($current));
    }

    /** @return list<array{value: string, label: string}> */
    public static function asOptions(array $values): array
    {
        return array_map(fn (string $value) => ['value' => $value, 'label' => $value], $values);
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private static function withKept(array $values, ?string $keep): array
    {
        if (filled($keep) && ! in_array($keep, $values, true)) {
            array_unshift($values, $keep);
        }

        return $values;
    }
}
