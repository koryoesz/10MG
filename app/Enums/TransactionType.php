<?php

namespace App\Enums;

enum TransactionType: string
{
    case Disbursement = 'disbursement';
    case Fee = 'fee';
    case Repayment = 'repayment';
    case Penalty = 'penalty';
}
