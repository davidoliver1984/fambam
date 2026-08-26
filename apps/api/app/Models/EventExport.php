<?php

namespace App\Models;

use App\Enums\EventExportState;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property EventExportState $state
 * @property CarbonImmutable|null $expires_at
 */
#[Fillable([
    'family_space_id', 'event_id', 'requested_by', 'state', 'object_key',
    'archive_sha256', 'byte_size', 'photo_count', 'failure_reason', 'expires_at',
])]
class EventExport extends Model
{
    use HasUlids;

    public $incrementing = false;

    protected $keyType = 'string';

    /** @return BelongsTo<FamilyEvent, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(FamilyEvent::class, 'event_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'state' => EventExportState::class,
            'byte_size' => 'integer',
            'photo_count' => 'integer',
            'expires_at' => 'immutable_datetime',
        ];
    }
}
