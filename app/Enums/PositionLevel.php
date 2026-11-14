<?php

namespace App\Enums;

/**
 * The level of government positions an eligibility qualifies its holder for.
 */
enum PositionLevel: string
{
    case First = 'FIRST_LEVEL';
    case Second = 'SECOND_LEVEL';
    case Third = 'THIRD_LEVEL';

    public function label(): string
    {
        return match ($this) {
            self::First => 'First level',
            self::Second => 'Second level',
            self::Third => 'Third level',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $case) => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }
}
