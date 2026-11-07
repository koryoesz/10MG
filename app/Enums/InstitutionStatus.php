<?php

namespace App\Enums;

enum InstitutionStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Suspended = 'suspended';
}
