<?php

namespace App\Tenancy;

use App\Models\FamilySpace;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final readonly class TenantOperationContext
{
    public function __construct(
        public string $familySpaceId,
        public int $actorUserId,
        public string $correlationId,
        public string $traceparent,
    ) {}

    public static function fromRequest(FamilySpace $familySpace, User $actor, Request $request): self
    {
        return new self(
            $familySpace->id,
            $actor->id,
            (string) ($request->attributes->get('correlation_id')
                ?? $request->header('X-Correlation-ID')
                ?? Str::uuid()),
            (string) ($request->attributes->get('traceparent') ?? self::newTraceparent()),
        );
    }

    public static function forBackground(string $familySpaceId, int $actorUserId): self
    {
        return new self(
            $familySpaceId,
            $actorUserId,
            (string) Str::uuid(),
            self::newTraceparent(),
        );
    }

    /** @return array{family_space_id: string, actor_user_id: int, correlation_id: string, traceparent: string} */
    public function toArray(): array
    {
        return [
            'family_space_id' => $this->familySpaceId,
            'actor_user_id' => $this->actorUserId,
            'correlation_id' => $this->correlationId,
            'traceparent' => $this->traceparent,
        ];
    }

    /** @param array{family_space_id: string, actor_user_id: int, correlation_id: string, traceparent: string} $context */
    public static function fromArray(array $context): self
    {
        return new self(
            $context['family_space_id'],
            $context['actor_user_id'],
            $context['correlation_id'],
            $context['traceparent'],
        );
    }

    public static function newTraceparent(): string
    {
        return sprintf('00-%s-%s-01', bin2hex(random_bytes(16)), bin2hex(random_bytes(8)));
    }
}
