<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * The few date expressions the reports need that differ between MySQL/MariaDB
 * (production) and SQLite (the test suite). Keeping them in one place lets the
 * report queries stay a single, indexed GROUP BY instead of loading rows into
 * PHP to count them.
 */
final class Sql
{
    /** "2026-05" for a date column. */
    public static function month(string $column): string
    {
        return self::sqlite() ? "strftime('%Y-%m', {$column})" : "date_format({$column}, '%Y-%m')";
    }

    /** "2026-05-14" for a date column. */
    public static function day(string $column): string
    {
        return self::sqlite() ? "strftime('%Y-%m-%d', {$column})" : "date_format({$column}, '%Y-%m-%d')";
    }

    private static function sqlite(): bool
    {
        return DB::connection()->getDriverName() === 'sqlite';
    }
}
