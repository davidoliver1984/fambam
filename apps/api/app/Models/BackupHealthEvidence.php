<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property CarbonImmutable|null $last_database_backup_at
 * @property CarbonImmutable|null $last_object_storage_verification_at
 * @property CarbonImmutable|null $last_restore_drill_at
 */
#[Fillable([
    'id', 'last_database_backup_at', 'database_backup_reference', 'database_backup_sha256',
    'last_object_storage_verification_at', 'object_storage_reference',
    'last_restore_drill_at', 'last_restore_drill_result', 'restore_drill_reference',
])]
class BackupHealthEvidence extends Model
{
    protected $table = 'backup_health_evidence';

    public $incrementing = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'last_database_backup_at' => 'immutable_datetime',
            'last_object_storage_verification_at' => 'immutable_datetime',
            'last_restore_drill_at' => 'immutable_datetime',
        ];
    }
}
