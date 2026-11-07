<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Lending rules
    |--------------------------------------------------------------------------
    */

    'max_open_loans_per_customer' => (int) env('LENDING_MAX_OPEN_LOANS', 3),

    /*
    | Default KYC settings for a lender that has not chosen its own (each lender can change
    | them through PATCH /kyc-settings): require verification before applying, which
    | registers to consult, and whether the BVN phone number must match.
    */
    'kyc' => [
        'required' => (bool) env('LENDING_REQUIRE_KYC', true),
        'bvn_check' => (bool) env('LENDING_KYC_BVN_CHECK', true),
        'nin_check' => (bool) env('LENDING_KYC_NIN_CHECK', true),
        'phone_match' => (bool) env('LENDING_KYC_PHONE_MATCH', false),
    ],

    /*
    | 10MG's platform fee: the default for any lender a platform operator has not set
    | individually. Rate in basis points of the principal (50 = 0.5%); bearer is `lender`
    | (commission, borrower cost unchanged) or `borrower` (an upfront fee on the loan).
    */
    'platform_fee' => [
        'rate_bps' => (int) env('PLATFORM_FEE_BPS', 50),
        'bearer' => env('PLATFORM_FEE_BEARER', 'lender'),
        'max_rate_bps' => 1000,
    ],

    /*
    | Four-eyes control: the user who approved a loan may not also disburse it.
    */
    'enforce_four_eyes' => (bool) env('LENDING_ENFORCE_FOUR_EYES', true),

    /*
    | Upper bound for any amount accepted by the API, in minor units (₦10 trillion).
    | Keeps arithmetic far away from 64-bit integer overflow.
    */
    'max_amount' => 1_000_000_000_000_000,

    /*
    |--------------------------------------------------------------------------
    | Idempotency
    |--------------------------------------------------------------------------
    |
    | How long a stored response can be replayed, and the lease for the lock that
    | serialises concurrent requests carrying the same key.
    |
    */

    'idempotency' => [
        'ttl_hours' => (int) env('IDEMPOTENCY_TTL_HOURS', 24),
        'lock_seconds' => (int) env('IDEMPOTENCY_LOCK_SECONDS', 30),
    ],

];
