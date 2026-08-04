<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Queries\FamilySpaceMembershipQuery;
use App\Queries\FamilySpaceQuery;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveFamilySpace
{
    public function __construct(
        private readonly FamilySpaceQuery $familySpaces,
        private readonly FamilySpaceMembershipQuery $memberships,
        private readonly TenantContext $tenantContext,
    ) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User $user */
        $user = $request->user();
        $slug = (string) $request->route('familySpace');
        $familySpace = $this->familySpaces->resolveAccessibleSlug($user, $slug);
        $membership = $this->memberships->activeForUser($familySpace, $user);

        $familySpace->setRelation('memberships', new Collection([$membership]));
        $this->tenantContext->establish($familySpace, $membership, $user);
        $request->route()?->setParameter('familySpace', $familySpace);

        try {
            return $next($request);
        } finally {
            $this->tenantContext->clear();
        }
    }
}
