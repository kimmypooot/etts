<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * An eligibility a transaction can be filed under. Transactions refer to it by
 * `code` (text), not by id.
 */
#[Fillable(['code', 'name'])]
class Eligibility extends Model
{
    protected $table = 'eligibilities';

    /** Number of transactions filed under a code. */
    public static function usageCount(string $code): int
    {
        return Transaction::where('elig_code', $code)->count();
    }
}
