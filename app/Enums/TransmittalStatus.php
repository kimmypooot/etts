<?php

namespace App\Enums;

/**
 * A transmittal batch is a Draft until it is handed over. Only a Draft can be
 * edited or deleted.
 */
enum TransmittalStatus: string
{
    case Draft = 'Draft';
    case Released = 'Released';
    case Transmitted = 'Transmitted';
}
