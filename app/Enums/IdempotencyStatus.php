<?php

namespace App\Enums;

enum IdempotencyStatus: string
{
    case Processing = 'processing';
    case Completed = 'completed';
}
