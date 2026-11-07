<?php

namespace App\Http\Middleware;

use App\Enums\IdempotencyStatus;
use App\Exceptions\Api\IdempotencyKeyMissing;
use App\Exceptions\Api\IdempotencyKeyReused;
use App\Exceptions\Api\IdempotencyRequestInProgress;
use App\Models\IdempotencyKey;
use App\Repositories\Contracts\IdempotencyKeyRepositoryInterface;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes a mutating endpoint safe to retry.
 *
 * The first request with a given `Idempotency-Key` (scoped per user) is executed and its
 * response stored. Retries with the same key and the same payload get the stored response
 * back with `Idempotent-Replayed: true`; the same key with a different payload is rejected;
 * a retry that arrives while the original is still running gets 409. Server errors (5xx)
 * are not stored, so the client may retry them.
 */
class EnforceIdempotency
{
    public function __construct(private IdempotencyKeyRepositoryInterface $keys) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');

        if (! is_string($key) || $key === '' || strlen($key) > 255) {
            throw new IdempotencyKeyMissing;
        }

        $userId = $request->user()->getAuthIdentifier();
        $fingerprint = $this->fingerprint($request);

        $lock = Cache::lock('idempotency:'.$userId.':'.hash('sha256', $key), config('lending.idempotency.lock_seconds'));

        if (! $lock->get()) {
            throw new IdempotencyRequestInProgress;
        }

        try {
            $record = $this->findLiveRecord($userId, $key);

            if ($record !== null) {
                return $this->replay($record, $fingerprint);
            }

            $record = $this->claim($request, $userId, $key, $fingerprint);
            $response = $next($request);

            if ($response->getStatusCode() >= 500) {
                $this->keys->delete($record);

                return $response;
            }

            $this->keys->complete($record, $response->getStatusCode(), (string) $response->getContent());

            return $response;
        } finally {
            $lock->release();
        }
    }

    private function findLiveRecord(string $userId, string $key): ?IdempotencyKey
    {
        $record = $this->keys->find($userId, $key);

        if ($record?->expires_at->isPast()) {
            $this->keys->delete($record);

            return null;
        }

        return $record;
    }

    private function replay(IdempotencyKey $record, string $fingerprint): Response
    {
        if (! hash_equals($record->request_hash, $fingerprint)) {
            throw new IdempotencyKeyReused;
        }

        if ($record->status !== IdempotencyStatus::Completed) {
            throw new IdempotencyRequestInProgress;
        }

        return response($record->response_body, $record->response_status)
            ->header('Content-Type', 'application/json')
            ->header('Idempotent-Replayed', 'true');
    }

    private function claim(Request $request, string $userId, string $key, string $fingerprint): IdempotencyKey
    {
        try {
            return $this->keys->create([
                'user_id' => $userId,
                'idempotency_key' => $key,
                'request_method' => $request->method(),
                'request_path' => $request->path(),
                'request_hash' => $fingerprint,
                'status' => IdempotencyStatus::Processing,
                'expires_at' => now()->addHours(config('lending.idempotency.ttl_hours')),
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new IdempotencyRequestInProgress;
        }
    }

    private function fingerprint(Request $request): string
    {
        $payload = $request->all();
        $this->sortRecursively($payload);

        return hash('sha256', $request->method().'|'.$request->path().'|'.json_encode($payload));
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    private function sortRecursively(array &$payload): void
    {
        ksort($payload);

        foreach ($payload as &$value) {
            if (is_array($value)) {
                $this->sortRecursively($value);
            }
        }
    }
}
