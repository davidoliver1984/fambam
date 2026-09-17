<?php

namespace App\PhotoEditing;

use App\Media\GeneratedMediaVariant;

interface PhotoEditRenderer
{
    /** @param array<string, mixed> $recipe
     * @param  array<string, mixed>|null  $restore
     */
    public function render(string $canonicalPath, array $recipe, ?array $restore): GeneratedMediaVariant;
}
