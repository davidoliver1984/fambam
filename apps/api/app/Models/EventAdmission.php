<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property CarbonImmutable $admitted_at
 * @property CarbonImmutable|null $revoked_at
 * @property-read FamilyEvent $event
 * @property-read FamilySpaceMembership $membership
 * @property-read User|null $revoker
 */
#[Fillable(['family_space_id', 'event_id', 'family_space_membership_id', 'admitted_at', 'revoked_at', 'revoked_by'])]
class EventAdmission extends Model
{
    use HasUlids;

    public $incrementing = false;

    protected $keyType = 'string';

    /** @return BelongsTo<FamilyEvent, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(FamilyEvent::class, 'event_id');
    }

    /** @return BelongsTo<FamilySpaceMembership, $this> */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(FamilySpaceMembership::class, 'family_space_membership_id');
    }

    /** @return BelongsTo<User, $this> */
    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    protected function casts(): array
    {
        return ['admitted_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }
}
