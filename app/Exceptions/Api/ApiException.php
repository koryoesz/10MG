<?php

namespace App\Exceptions\Api;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Base class for expected, client-facing errors. Rendered as:
 * `{ "message": "...", "error": { "code": "...", "details": {...} } }`.
 */
class ApiException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status = 400,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'error' => [
                'code' => $this->errorCode,
                'details' => (object) $this->details,
            ],
        ], $this->status);
    }
}
