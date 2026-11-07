<?php

namespace App\Support\OpenApi;

use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\RouteInfo;

/**
 * Documents the cross-cutting headers that middleware (not controllers) consume.
 */
class DocumentRequestHeaders
{
    public function __invoke(Operation $operation, RouteInfo $routeInfo): void
    {
        $middleware = $routeInfo->route->gatherMiddleware();
        $authenticated = in_array('auth:sanctum', $middleware, true);

        if (in_array('idempotent', $middleware, true)) {
            $operation->addParameters([
                $this->header('Idempotency-Key', true, 'Unique key (e.g. a UUID) per logical operation. Retries with the same key replay the original response.'),
            ]);
        }

        if (in_array('tenant', $middleware, true) && ! $authenticated) {
            $operation->addParameters([$this->header('X-Tenant', true, 'Institution slug, e.g. `lagos-mfb`.')]);
        }

        if (in_array('tenant:optional', $middleware, true)) {
            $operation->addParameters([$this->header('X-Tenant', false, 'Institution slug. Omit to log in as a platform operator.')]);
        }

        $operation->addParameters([$this->header('X-Request-Id', false, 'Optional trace id echoed back on the response and stored on audit entries.')]);
    }

    private function header(string $name, bool $required, string $description): Parameter
    {
        return Parameter::make($name, 'header')
            ->setSchema(Schema::fromType(new StringType))
            ->required($required)
            ->description($description);
    }
}
