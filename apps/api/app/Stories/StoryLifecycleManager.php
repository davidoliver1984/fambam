<?php

namespace App\Stories;

use App\Models\Story;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class StoryLifecycleManager
{
    public function delete(Story $story): void
    {
        DB::transaction(function () use ($story): void {
            $story = Story::query()->withTrashed()->lockForUpdate()->findOrFail($story->id);
            if ($story->trashed()) {
                return;
            }
            $operationId = (string) Str::ulid();
            $story->update(['deletion_operation_id' => $operationId]);
            $story->delete();
            $story->comments()->whereNull('deleted_at')->update([
                'deleted_at' => now(),
                'deleted_with_story_operation_id' => $operationId,
                'updated_at' => now(),
            ]);
        });
    }

    public function restore(Story $story): void
    {
        DB::transaction(function () use ($story): void {
            $story = Story::query()->withTrashed()->lockForUpdate()->findOrFail($story->id);
            if (! $story->trashed()) {
                return;
            }
            $operationId = $story->deletion_operation_id;
            $story->restore();
            if ($operationId !== null) {
                $story->comments()->withTrashed()
                    ->where('deleted_with_story_operation_id', $operationId)
                    ->update([
                        'deleted_at' => null,
                        'deleted_with_story_operation_id' => null,
                        'updated_at' => now(),
                    ]);
            }
            $story->update(['deletion_operation_id' => null]);
        });
    }
}
