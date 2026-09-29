<?php

namespace App\Enums;

enum UserType: string
{
    case Customer = 'customer';
    case Staff = 'staff';
}
