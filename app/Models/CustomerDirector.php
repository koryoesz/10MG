<?php

namespace App\Models;

use App\Enums\DirectorRole;
use App\Support\Tenancy\BelongsToInstitution;
use Database\Factories\CustomerDirectorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person behind a borrowing business (owner or director) whose BVN and NIN were checked
 * against NIBSS and NIMC. Plain numbers are encrypted and never serialised.
 */
#[Fillable([
    'role', 'first_name', 'middle_name', 'last_name', 'date_of_birth', 'phone',
    'bvn_reference', 'bvn_verified_at', 'nin_reference', 'nin_verified_at', 'verification_checks',
])]
#[Hidden(['bvn', 'bvn_hash', 'nin', 'nin_hash'])]
class CustomerDirector extends Model
{
    /** @use HasFactory<CustomerDirectorFactory> */
    use BelongsToInstitution, HasFactory, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => DirectorRole::class,
            'date_of_birth' => 'immutable_date',
            'bvn' => 'encrypted',
            'nin' => 'encrypted',
            'bvn_verified_at' => 'immutable_datetime',
            'nin_verified_at' => 'immutable_datetime',
            'verification_checks' => 'array',
        ];
    }

    /**
     * Keyed fingerprint of an identity number, used for uniqueness without storing it in clear.
     */
    public static function fingerprint(string $number): string
    {
        return hash_hmac('sha256', $number, (string) config('app.key'));
    }

    /**
     * Store an identity number encrypted, with its fingerprint and the last four digits for display.
     */
    public function setIdentityNumber(string $type, string $number): static
    {
        $this->setAttribute($type, $number);
        $this->setAttribute("{$type}_hash", self::fingerprint($number));
        $this->setAttribute("{$type}_last4", substr($number, -4));

        return $this;
    }

    public function fullName(): string
    {
        return implode(' ', array_filter([$this->first_name, $this->middle_name, $this->last_name]));
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
