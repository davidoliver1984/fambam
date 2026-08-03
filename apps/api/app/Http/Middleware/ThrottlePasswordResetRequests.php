<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

class ThrottlePasswordResetRequests
{
    public function __construct(private readonly ThrottleRequests $throttle) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('POST') && $request->is('forgot-password')) {
            return $this->throttle->handle($request, $next, 'password-reset');
        }

        if ($request->isMethod('POST') && $request->is('reset-password')) {
            return $this->throttle->handle($request, $next, 'password-reset-submission');
        }

        return $next($request);
    }
}
