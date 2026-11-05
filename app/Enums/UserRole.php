<?php

namespace App\Enums;

/**
 * A staff member ETTS role. The values are the `application_roles.code`
 * that IMIS sends at sign-in; IMIS is the only place they are granted.
 *
 * Legacy mapping (IMIS role names Admin, User and Field Office under the
 * `eris` system): Admin => admin; User and Field Office => field_office.
 */
enum UserRole: string
{
    /** ESD staff: sees every office and manages reference data. */
    case Admin = 'admin';

    /** Field or satellite office staff: sees only records of their own office. */
    case FieldOffice = 'field_office';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrator',
            self::FieldOffice => 'Field Office',
        };
    }
}
