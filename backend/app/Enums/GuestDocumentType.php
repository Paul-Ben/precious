<?php

namespace App\Enums;

enum GuestDocumentType: string
{
    case NationalId = 'NATIONAL_ID';
    case Passport = 'PASSPORT';
    case DriversLicence = 'DRIVERS_LICENCE';
    case VotersCard = 'VOTERS_CARD';
    case Other = 'OTHER';
}
