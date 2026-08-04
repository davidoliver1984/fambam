<?php

use App\Http\Middleware\DatabaseRequestContext;
use App\Http\Middleware\PreventRecoveryCodeDisclosure;
use App\Http\Middleware\RequestContext;
use App\Http\Middleware\ResolveFamilySpace;
use App\Http\Middleware\ThrottlePasswordResetRequests;
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
        $middleware->alias([
            'database-context' => DatabaseRequestContext::class,
            'family-space' => ResolveFamilySpace::class,
        ]);
        $middleware->prependToPriorityList(SubstituteBindings::class, ResolveFamilySpace::class);
        $middleware->prependToPriorityList(ResolveFamilySpace::class, DatabaseRequestContext::class);
        $middleware->redirectGuestsTo(null);
        $middleware->statefulApi();
        $middleware->api(prepend: [RequestContext::class]);
        $middleware->web(append: [
            PreventRecoveryCodeDisclosure::class,
            ThrottlePasswordResetRequests::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
