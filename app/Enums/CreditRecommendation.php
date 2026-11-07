<?php

namespace App\Enums;

enum CreditRecommendation: string
{
    case Approve = 'approve';
    case Review = 'review';
    case Decline = 'decline';
}
