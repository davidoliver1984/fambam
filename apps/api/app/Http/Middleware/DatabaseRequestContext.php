<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Tenancy\DatabaseTenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class DatabaseRequestContext
{
    public function __construct(private readonly DatabaseTenantContext $databaseTenantContext) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        return DB::transaction(function () use ($request, $next): Response {
            $user = $request->user();

            if ($user instanceof User) {
                $this->databaseTenantContext->establishUser($user);
            }

            try {
                return $next($request);
            } finally {
                $this->databaseTenantContext->clear();
            }
        });
    }
}
