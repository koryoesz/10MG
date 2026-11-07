<?php

namespace App\Services\Identity;

use App\Contracts\NinRegistry;

/**
 * NIMC NIN verification: submits the claimed details and receives, per field, whether they
 * match the National Identity Database record for the NIN.
 */
class NimcService extends IdentityRegistryClient implements NinRegistry
{
    /**
     * NIMC field name → our field name.
     */
    private const FIELDS = [
        'firstname' => 'first_name',
        'middlename' => 'middle_name',
        'surname' => 'last_name',
        'birthdate' => 'date_of_birth',
        'telephoneno' => 'phone',
    ];

    public function matchNin(IdentityQuery $query): ProviderMatch
    {
        $response = $this->post('/api/v1/nin/verify', [
            'nin' => $query->number,
            'firstname' => $query->firstName,
            'middlename' => $query->middleName,
            'surname' => $query->lastName,
            'birthdate' => $query->dateOfBirth->format('Y-m-d'),
            'telephoneno' => $query->phone,
        ]);

        return $this->toMatch($response, $query->middleName === null ? array_diff_key(self::FIELDS, ['middlename' => true]) : self::FIELDS);
    }

    protected function provider(): string
    {
        return 'nimc';
    }

    protected function numberField(): string
    {
        return 'nin';
    }
}
