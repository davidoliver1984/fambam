<?php

namespace App\Search\Summaries;

final readonly class PersonSearchSummary implements SearchSummary
{
    public function __construct(
        private string $id,
        private string $preferredName,
        private ?string $relationshipToViewer = null,
        private ?string $portraitThumbnailUrl = null,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'preferred_name' => $this->preferredName,
            'relationship_to_viewer' => $this->relationshipToViewer,
            'portrait_thumbnail_url' => $this->portraitThumbnailUrl,
        ];
    }
}
