<?php

namespace App\Enums;

/**
 * Who pays 10MG's platform fee on a loan.
 */
enum PlatformFeeBearer: string
{
    /** A commission the lender owes 10MG; the borrower's cost is unchanged. */
    case Lender = 'lender';

    /** Charged to the borrower as an upfront fee, disclosed in the quote and deducted from the payout. */
    case Borrower = 'borrower';
}
