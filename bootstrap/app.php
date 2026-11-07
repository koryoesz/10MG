<?php

use App\Exceptions\Api\ApiException;
use App\Exceptions\ApiErrorRenderer;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnforceIdempotency;
use App\Http\Middleware\ResolveTenant;
use App\Models\IdempotencyKey;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [AssignRequestId::class]);

        $middleware->alias([
            'tenant' => ResolveTenant::class,
            'idempotent' => EnforceIdempotency::class,
        ]);

        // Authenticate → ResolveTenant → SubstituteBindings: route-model binding must run
        // after the tenant is known so the tenant scope applies to bound models.
        $middleware->prependToPriorityList(SubstituteBindings::class, ResolveTenant::class);

        // Only claim an idempotency key once the request is known to be authorised.
        $middleware->appendToPriorityList(Authorize::class, EnforceIdempotency::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReport(ApiException::class);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return (new ApiErrorRenderer)($exception, $request);
        });
    })
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('loans:mark-overdue')->dailyAt('00:30')->withoutOverlapping()->onOneServer();
        $schedule->command('model:prune', ['--model' => [IdempotencyKey::class]])->daily();
    })
    ->create();
