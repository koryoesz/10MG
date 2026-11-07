<?php

namespace App\Services\Identity;

use App\Exceptions\Api\IdentityProviderUnavailable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Str;
use Throwable;

/**
 * HTTP transport shared by the NIBSS and NIMC integrations: OAuth2 client-credentials
 * token (cached), JSON request with timeout and retry, and a uniform response shape.
 *
 * In `sandbox` mode the request is built exactly as in `live` mode but answered locally
 * with a successful match, so the whole flow runs without provider credentials.
 */
abstract class IdentityRegistryClient
{
    public function __construct(
        protected Http $http,
        protected Cache $cache,
    ) {}

    /**
     * Key under `config/services.php`, e.g. `nibss`.
     */
    abstract protected function provider(): string;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws IdentityProviderUnavailable
     */
    protected function post(string $path, array $payload): array
    {
        if ($this->config('mode') !== 'live') {
            return $this->sandboxResponse($payload);
        }

        try {
            return $this->request()
                ->withToken($this->accessToken())
                ->withHeaders(['X-Request-Id' => (string) Str::uuid()])
                ->post($path, $payload)
                ->throw()
                ->json();
        } catch (ConnectionException|RequestException $exception) {
            report($exception);

            throw new IdentityProviderUnavailable(details: ['provider' => $this->provider()]);
        }
    }

    /**
     * Interpret the provider's envelope: `{responseCode, responseMessage, reference, data: {fieldMatches}}`.
     * Code `00` means the number was found; anything else means it was not.
     *
     * @param  array<string, mixed>  $response
     * @param  array<string, string>  $fieldMap  Provider field name → our field name.
     */
    protected function toMatch(array $response, array $fieldMap): ProviderMatch
    {
        $matches = [];

        foreach ($fieldMap as $theirs => $ours) {
            $matches[$ours] = (bool) data_get($response, "data.fieldMatches.{$theirs}", false);
        }

        $code = (string) ($response['responseCode'] ?? '99');

        return new ProviderMatch(
            provider: $this->provider(),
            found: $code === '00',
            fieldMatches: $code === '00' ? $matches : array_map(fn (): bool => false, $matches),
            reference: $response['reference'] ?? null,
            responseCode: $code,
            responseMessage: (string) ($response['responseMessage'] ?? 'Unknown response'),
        );
    }

    /**
     * A successful response in the provider's own shape: every submitted field matched.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function sandboxResponse(array $payload): array
    {
        $fields = array_diff(array_keys(array_filter($payload, fn (mixed $value): bool => $value !== null)), [$this->numberField()]);

        return [
            'responseCode' => '00',
            'responseMessage' => 'Successful',
            'reference' => strtoupper($this->provider()).'-SBX-'.Str::upper(Str::random(12)),
            'data' => ['fieldMatches' => array_fill_keys($fields, true)],
        ];
    }

    /**
     * The payload key that carries the identity number itself (not a matchable field).
     */
    abstract protected function numberField(): string;

    private function request(): PendingRequest
    {
        return $this->http->baseUrl($this->config('base_url'))
            ->acceptJson()
            ->asJson()
            ->timeout((int) $this->config('timeout', 10))
            ->retry(2, 250, fn (Throwable $exception): bool => $exception instanceof ConnectionException);
    }

    private function accessToken(): string
    {
        return $this->cache->remember("identity:{$this->provider()}:token", now()->addMinutes(50), function (): string {
            return $this->http->baseUrl($this->config('base_url'))
                ->acceptJson()
                ->asForm()
                ->timeout((int) $this->config('timeout', 10))
                ->post('/oauth/token', [
                    'grant_type' => 'client_credentials',
                    'client_id' => $this->config('client_id'),
                    'client_secret' => $this->config('client_secret'),
                ])
                ->throw()
                ->json('access_token');
        });
    }

    private function config(string $key, mixed $default = null): mixed
    {
        return config("services.{$this->provider()}.{$key}", $default);
    }
}
