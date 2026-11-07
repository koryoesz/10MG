<?php

namespace App\Enums;

enum InstallmentStatus: string
{
    case Pending = 'pending';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Overdue = 'overdue';
}
