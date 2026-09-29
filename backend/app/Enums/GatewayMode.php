<?php

namespace App\Enums;

enum GatewayMode: string
{
    case Test = 'test';
    case Live = 'live';
}
