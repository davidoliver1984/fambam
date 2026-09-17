<?php

namespace App\Models;

use App\Enums\FamilyExportScope;
use App\Enums\FamilyExportState;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property FamilyExportScope $scope
 * @property FamilyExportState $state
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $cancelled_at
 * @property CarbonImmutable|null $generation_started_at
 * @property CarbonImmutable|null $generation_finished_at
 * @property CarbonImmutable|null $storage_reconciled_at
 */
#[Fillable([
    'family_space_id', 'requested_by', 'scope', 'state', 'object_key',
    'archive_sha256', 'byte_size', 'photo_count', 'failure_reason', 'expires_at',
    'collection_id', 'album_id', 'selection_checksum', 'cancelled_at', 'generation_started_at',
    'generation_finished_at', 'storage_reconciled_at',
])]
class FamilyExport extends Model
{
    use HasUlids;

    public $incrementing = false;

    protected $keyType = 'string';

    /** @return BelongsTo<FamilySpace, $this> */
    public function familySpace(): BelongsTo
    {
        return $this->belongsTo(FamilySpace::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<Collection, $this> */
    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class);
    }

    /** @return BelongsTo<Album, $this> */
    public function album(): BelongsTo
    {
        return $this->belongsTo(Album::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'scope' => FamilyExportScope::class,
            'state' => FamilyExportState::class,
            'byte_size' => 'integer',
            'photo_count' => 'integer',
            'expires_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'generation_started_at' => 'immutable_datetime',
            'generation_finished_at' => 'immutable_datetime',
            'storage_reconciled_at' => 'immutable_datetime',
        ];
    }
}
