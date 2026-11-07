<?php

namespace App\Enums;

enum InterestMethod: string
{
    case Flat = 'flat';
    case ReducingBalance = 'reducing_balance';
}
