<?php

namespace App\Search\Summaries;

final readonly class PhotoSearchSummary implements SearchSummary
{
    /**
     * @param  array{precision: string, value: ?string}|null  $historicalDate
     * @param  list<array{id: string, preferred_name: string}>  $people
     */
    public function __construct(
        private string $id,
        private string $mediaUploadId,
        private ?string $caption,
        private ?string $description,
        private ?string $locationDescription,
        private ?array $historicalDate,
        private array $people,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'media_upload_id' => $this->mediaUploadId,
            'caption' => $this->caption,
            'description' => $this->description,
            'location_description' => $this->locationDescription,
            'historical_date' => $this->historicalDate,
            'people' => $this->people,
        ];
    }
}
