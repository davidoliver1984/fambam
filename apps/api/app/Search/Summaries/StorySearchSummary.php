<?php

namespace App\Search\Summaries;

final readonly class StorySearchSummary implements SearchSummary
{
    /** @param array{type: string, id: string} $subject */
    public function __construct(
        private string $id,
        private string $heading,
        private string $excerpt,
        private array $subject,
        private string $createdAt,
    ) {}

    public function toArray(): array
    {
        return ['id' => $this->id, 'heading' => $this->heading, 'excerpt' => $this->excerpt,
            'subject' => $this->subject, 'created_at' => $this->createdAt];
    }
}
