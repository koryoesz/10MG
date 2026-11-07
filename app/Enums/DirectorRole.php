<?php

namespace App\Enums;

/**
 * The capacity in which a person is linked to a borrowing business.
 */
enum DirectorRole: string
{
    case Owner = 'owner';
    case Director = 'director';
}
