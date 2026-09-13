<?php

namespace App\Stories;

final class StoryHeading
{
    private const MAX_CHARACTERS = 120;

    public function __construct(private readonly RichTextDocument $documents) {}

    /**
     * @param  array<string, mixed>  $document
     * @param  callable(string): (string|null)  $resolveMentionLabel
     */
    public function derive(array $document, callable $resolveMentionLabel): string
    {
        $this->documents->validate($document);
        $first = $document['blocks'][0] ?? null;
        if (is_array($first) && in_array($first['type'], ['heading_2', 'heading_3'], true)) {
            return $this->inlineText($first['content'], $resolveMentionLabel);
        }

        $parts = [];
        foreach ($document['blocks'] as $block) {
            if (isset($block['content'])) {
                $parts[] = $this->inlineText($block['content'], $resolveMentionLabel);
            }
        }
        $text = trim(implode(' ', $parts));
        if (mb_strlen($text) <= self::MAX_CHARACTERS) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, self::MAX_CHARACTERS)).'…';
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  callable(string): (string|null)  $resolveMentionLabel
     */
    private function inlineText(array $nodes, callable $resolveMentionLabel): string
    {
        return implode('', array_map(function (array $node) use ($resolveMentionLabel): string {
            if ($node['type'] === 'text') {
                return $node['text'];
            }
            $resolved = isset($node['mention_id']) ? $resolveMentionLabel($node['mention_id']) : null;

            return $resolved ?? $node['label'];
        }, $nodes));
    }
}
