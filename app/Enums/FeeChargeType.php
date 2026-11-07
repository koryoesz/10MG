<?php

namespace App\Enums;

enum FeeChargeType: string
{
    /** Deducted from the disbursed amount. */
    case Upfront = 'upfront';

    /** Added to the repayable amount and spread across installments. */
    case Capitalized = 'capitalized';
}
