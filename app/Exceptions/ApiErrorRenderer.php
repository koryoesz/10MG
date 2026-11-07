<?php

namespace App\Exceptions;

use App\Exceptions\Api\ApiException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Renders every API failure in a single envelope:
 * `{ "message": "...", "error": { "code": "MACHINE_CODE", "details": {...} } }`.
 */
class ApiErrorRenderer
{
    /**
     * Unique columns that clients supply and may be told about. Anything else is reported generically.
     *
     * @var list<string>
     */
    private const CLIENT_FACING_UNIQUE_FIELDS = [
        'email', 'slug', 'code', 'registration_number', 'phone', 'custom_domain', 'external_reference',
    ];

    /**
     * Fingerprint columns reported on the request field they were derived from.
     *
     * @var array<string, array{field: string, message: string}>
     */
    private const FINGERPRINT_FIELDS = [
        'bvn_hash' => ['field' => 'bvn', 'message' => 'This BVN is already linked to another business.'],
        'nin_hash' => ['field' => 'nin', 'message' => 'This NIN is already linked to another business.'],
    ];

    public function __invoke(Throwable $exception, ?Request $request = null): ?JsonResponse
    {
        if ($exception instanceof ApiException) {
            return $exception->render();
        }

        if ($exception instanceof UniqueConstraintViolationException) {
            return $this->duplicate($exception, $request);
        }

        [$status, $code, $message, $details] = match (true) {
            $exception instanceof ValidationException => [422, 'VALIDATION_FAILED', $exception->getMessage(), $exception->errors()],
            $exception instanceof AuthenticationException => [401, 'UNAUTHENTICATED', 'Unauthenticated.', []],
            $exception instanceof HttpExceptionInterface => [
                $exception->getStatusCode(),
                $this->httpCode($exception->getStatusCode()),
                $this->httpMessage($exception),
                [],
            ],
            default => [500, 'SERVER_ERROR', config('app.debug') ? $exception->getMessage() : 'Server error.', []],
        };

        $response = response()->json([
            'message' => $message,
            'error' => ['code' => $code, 'details' => (object) $details],
        ], $status);

        if ($exception instanceof HttpExceptionInterface) {
            $response->withHeaders($exception->getHeaders());
        }

        return $response;
    }

    /**
     * Two concurrent requests can both pass a `unique` validation rule before either inserts;
     * the database's unique index then rejects the loser. Answer it exactly as a sequential
     * duplicate would be answered (422 on the field) instead of leaking a 500 and SQL.
     */
    private function duplicate(UniqueConstraintViolationException $exception, ?Request $request): JsonResponse
    {
        $column = $this->duplicateColumn($exception->getMessage());

        if (isset(self::FINGERPRINT_FIELDS[$column])) {
            ['field' => $field, 'message' => $message] = self::FINGERPRINT_FIELDS[$column];

            return response()->json([
                'message' => $message,
                'error' => ['code' => 'VALIDATION_FAILED', 'details' => [$field => [$message]]],
            ], 422);
        }

        if ($column === null || ! in_array($column, self::CLIENT_FACING_UNIQUE_FIELDS, true)) {
            return response()->json([
                'message' => 'A record with these details already exists.',
                'error' => ['code' => 'DUPLICATE_RESOURCE', 'details' => (object) []],
            ], 409);
        }

        $field = $this->requestFieldFor($column, $request);
        $message = 'The '.str_replace('_', ' ', Str::afterLast($field, '.')).' has already been taken.';

        return response()->json([
            'message' => $message,
            'error' => ['code' => 'VALIDATION_FAILED', 'details' => [$field => [$message]]],
        ], 422);
    }

    /**
     * Extract the offending column from MySQL, PostgreSQL or SQLite unique-violation messages.
     */
    private function duplicateColumn(string $message): ?string
    {
        // PostgreSQL: Key (institution_id, email)=(…) already exists.
        if (preg_match('/Key \(([^)]+)\)=/', $message, $matches)) {
            return trim(Str::afterLast($matches[1], ','));
        }

        // SQLite: UNIQUE constraint failed: users.institution_id, users.email
        if (preg_match('/UNIQUE constraint failed: ([^\s(,]+(?:, [^\s(,]+)*)/', $message, $matches)) {
            return Str::afterLast(trim(Str::afterLast($matches[1], ',')), '.');
        }

        // MySQL: Duplicate entry '…' for key 'users.users_institution_id_email_unique'
        // (MySQL < 8 omits the "table." prefix, so match on the index name's suffix.)
        if (preg_match("/for key '(?:[a-z_]+\.)?([a-z_]+)'/", $message, $matches)) {
            $index = Str::chopEnd($matches[1], '_unique');

            foreach ([...array_keys(self::FINGERPRINT_FIELDS), ...self::CLIENT_FACING_UNIQUE_FIELDS] as $field) {
                if ($index === $field || str_ends_with($index, '_'.$field)) {
                    return $field;
                }
            }

            return $index;
        }

        return null;
    }

    /**
     * Report the error on the request's own field name (e.g. `institution.slug`) when there is one.
     */
    private function requestFieldFor(string $column, ?Request $request): string
    {
        foreach (array_keys(Arr::dot($request?->all() ?? [])) as $key) {
            if ($key === $column || str_ends_with($key, '.'.$column)) {
                return $key;
            }
        }

        return $column;
    }

    private function httpCode(int $status): string
    {
        return match ($status) {
            403 => 'FORBIDDEN',
            404 => 'NOT_FOUND',
            405 => 'METHOD_NOT_ALLOWED',
            429 => 'TOO_MANY_REQUESTS',
            default => 'HTTP_'.$status,
        };
    }

    private function httpMessage(HttpExceptionInterface $exception): string
    {
        return match ($exception->getStatusCode()) {
            403 => 'This action is unauthorized.',
            404 => 'Resource not found.',
            default => $exception->getMessage() ?: 'HTTP error.',
        };
    }
}
