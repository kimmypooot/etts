<?php

namespace App\Enums;

/**
 * How an eligibility is obtained: by passing a CSC examination, by passing a
 * board or bar examination, or by a special grant under a law or CSC issuance.
 */
enum AcquisitionCategory: string
{
    case Exam = 'EXAM';
    case BoardBar = 'BOARD_BAR';
    case SpecialGrant = 'SPECIAL_GRANT';

    public function label(): string
    {
        return match ($this) {
            self::Exam => 'Exam',
            self::BoardBar => 'Board / Bar',
            self::SpecialGrant => 'Special grant',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $case) => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }
}
