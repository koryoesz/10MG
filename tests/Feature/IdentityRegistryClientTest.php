<?php

namespace Tests\Feature;

use App\Exceptions\Api\IdentityProviderUnavailable;
use App\Services\Identity\BvnService;
use App\Services\Identity\IdentityQuery;
use App\Services\Identity\NibssService;
use App\Services\Identity\NimcService;
use App\Services\Identity\NinService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IdentityRegistryClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_sandbox_mode_answers_locally_with_a_full_match(): void
    {
        Http::fake();

        $check = app(BvnService::class)->verify($this->lookup());

        $this->assertTrue($check->verified);
        $this->assertStringStartsWith('NIBSS-SBX-', $check->reference);
        $this->assertSame(['first_name' => true, 'last_name' => true, 'date_of_birth' => true, 'phone' => true], $check->fieldMatches);
        Http::assertNothingSent();
    }

    public function test_live_nibss_call_authenticates_and_sends_the_bvn_match_request(): void
    {
        config(['services.nibss.mode' => 'live', 'services.nibss.client_id' => 'client-1', 'services.nibss.client_secret' => 'secret-1']);
        Http::fake([
            'api.nibss-plc.com.ng/oauth/token' => Http::response(['access_token' => 'tok-123', 'expires_in' => 3600]),
            'api.nibss-plc.com.ng/bvnr/v2/bvn/match' => Http::response([
                'responseCode' => '00',
                'responseMessage' => 'Successful',
                'reference' => 'NIBSS-778899',
                'data' => ['fieldMatches' => ['firstName' => true, 'lastName' => true, 'dateOfBirth' => true, 'phoneNumber' => true]],
            ]),
        ]);

        $match = app(NibssService::class)->matchBvn($this->lookup());

        $this->assertTrue($match->found);
        $this->assertSame('NIBSS-778899', $match->reference);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.nibss-plc.com.ng/oauth/token'
            && $request['grant_type'] === 'client_credentials'
            && $request['client_id'] === 'client-1');
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.nibss-plc.com.ng/bvnr/v2/bvn/match'
            && $request->hasHeader('Authorization', 'Bearer tok-123')
            && $request->hasHeader('X-Request-Id')
            && $request['bvn'] === '12345678901'
            && $request['dateOfBirth'] === '21-Jun-1984'
            && $request['phoneNumber'] === '+2348031234567');
    }

    public function test_access_token_is_cached_between_lookups(): void
    {
        config(['services.nimc.mode' => 'live']);
        Http::fake([
            'api.nimc.gov.ng/oauth/token' => Http::response(['access_token' => 'nimc-tok']),
            'api.nimc.gov.ng/api/v1/nin/verify' => Http::response([
                'responseCode' => '00', 'responseMessage' => 'Successful', 'reference' => 'NIMC-1',
                'data' => ['fieldMatches' => ['firstname' => true, 'surname' => true, 'birthdate' => true, 'telephoneno' => true]],
            ]),
        ]);

        $nin = app(NinService::class);
        $this->assertTrue($nin->verify($this->lookup('98765434321'))->verified);
        $this->assertTrue($nin->verify($this->lookup('98765434321'))->verified);

        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.nimc.gov.ng/api/v1/nin/verify'
            && $request['nin'] === '98765434321'
            && $request['surname'] === 'Okafor'
            && $request['birthdate'] === '1984-06-21');
    }

    public function test_number_not_found_fails_every_required_field(): void
    {
        config(['services.nimc.mode' => 'live']);
        Http::fake([
            'api.nimc.gov.ng/oauth/token' => Http::response(['access_token' => 'nimc-tok']),
            'api.nimc.gov.ng/api/v1/nin/verify' => Http::response(['responseCode' => '01', 'responseMessage' => 'NIN not found']),
        ]);

        $check = app(NinService::class)->verify($this->lookup('98765434321'));

        $this->assertFalse($check->verified);
        $this->assertSame(NinService::REQUIRED_FIELDS, $check->failedFields);
        $this->assertSame('NIN not found with NIMC.', $check->reason);
    }

    public function test_unreachable_provider_raises_a_503_error(): void
    {
        config(['services.nimc.mode' => 'live']);
        Http::fake(['api.nimc.gov.ng/*' => Http::failedConnection()]);

        $this->expectException(IdentityProviderUnavailable::class);

        app(NimcService::class)->matchNin($this->lookup('98765434321'));
    }

    private function lookup(string $number = '12345678901'): IdentityQuery
    {
        return new IdentityQuery($number, 'Adaeze', 'Okafor', CarbonImmutable::parse('1984-06-21'), '+2348031234567');
    }
}
