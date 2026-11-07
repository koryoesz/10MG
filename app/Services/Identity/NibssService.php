<?php

namespace App\Services\Identity;

use App\Contracts\BvnRegistry;

/**
 * NIBSS BVN "boolean match": submits the claimed details and receives, per field,
 * whether they match the record held against the BVN. The BVN record itself is never
 * returned, which keeps personal data we do not need off our systems.
 */
class NibssService extends IdentityRegistryClient implements BvnRegistry
{
    /**
     * NIBSS field name → our field name.
     */
    private const FIELDS = [
        'firstName' => 'first_name',
        'middleName' => 'middle_name',
        'lastName' => 'last_name',
        'dateOfBirth' => 'date_of_birth',
        'phoneNumber' => 'phone',
    ];

    public function matchBvn(IdentityQuery $query): ProviderMatch
    {
        $response = $this->post('/bvnr/v2/bvn/match', [
            'bvn' => $query->number,
            'firstName' => $query->firstName,
            'middleName' => $query->middleName,
            'lastName' => $query->lastName,
            'dateOfBirth' => $query->dateOfBirth->format('d-M-Y'),
            'phoneNumber' => $query->phone,
        ]);

        return $this->toMatch($response, $query->middleName === null ? array_diff_key(self::FIELDS, ['middleName' => true]) : self::FIELDS);
    }

    protected function provider(): string
    {
        return 'nibss';
    }

    protected function numberField(): string
    {
        return 'bvn';
    }
}
