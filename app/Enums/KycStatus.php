<?php

namespace App\Enums;

/**
 * Identity verification state of a borrowing business's owner (BVN + NIN).
 */
enum KycStatus: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Failed = 'failed';

    /** The lender runs no identity checks. Only ever computed, never stored. */
    case NotRequired = 'not_required';
}
