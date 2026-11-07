<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToInstitution;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'display_name', 'logo_url', 'primary_color', 'secondary_color',
    'support_email', 'support_phone', 'website_url', 'custom_domain',
])]
class InstitutionBranding extends Model
{
    use BelongsToInstitution, HasUlids;
}
