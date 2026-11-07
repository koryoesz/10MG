<?php

namespace App\Services;

use App\Models\InstitutionKycSetting;
use App\Repositories\Contracts\KycSettingsRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A lender's own KYC policy: whether borrowers must pass identity checks before applying,
 * and which checks (BVN, NIN, phone match) are run.
 */
class KycSettingsService
{
    /**
     * @var list<string>
     */
    private const SWITCHES = ['kyc_required', 'bvn_check_enabled', 'nin_check_enabled', 'phone_match_required'];

    public function __construct(
        private KycSettingsRepositoryInterface $settings,
        private AuditLogger $audit,
    ) {}

    public function current(): InstitutionKycSetting
    {
        return $this->settings->current();
    }

    /**
     * Partially update the current tenant's KYC settings, auditing only what changed.
     * Changes apply to the next application; loans already applied for are unaffected.
     *
     * @param  array{kyc_required?: bool, bvn_check_enabled?: bool, nin_check_enabled?: bool, phone_match_required?: bool}  $attributes
     *
     * @throws ValidationException
     */
    public function update(array $attributes): InstitutionKycSetting
    {
        return DB::transaction(function () use ($attributes): InstitutionKycSetting {
            $settings = $this->settings->currentForUpdate();
            $before = $settings->only(self::SWITCHES);
            $settings->fill($attributes);

            if ($settings->kyc_required && $settings->enabledChecks() === []) {
                throw ValidationException::withMessages([
                    'kyc_required' => ['Enable at least one identity check (BVN or NIN), or turn off the KYC requirement.'],
                ]);
            }

            if ($settings->phone_match_required && ! $settings->bvn_check_enabled && ! $settings->nin_check_enabled) {
                throw ValidationException::withMessages([
                    'phone_match_required' => ['Phone matching needs the BVN or NIN check to be enabled.'],
                ]);
            }

            $settings = $this->settings->save($settings);

            // Compare against the values in force before (platform defaults for a lender's first change),
            // so the audit shows only what the lender actually switched.
            $changed = array_diff_assoc($settings->only(self::SWITCHES), $before);

            if ($changed !== []) {
                $this->audit->record('institution.kyc_settings_updated', $settings, array_intersect_key($before, $changed), $changed);
            }

            return $settings;
        });
    }
}
