<?php

namespace App\Stories;

use Illuminate\Validation\ValidationException;

final class RichTextDocument
{
    public const FULL = 'full';

    public const COMMENT = 'comment';

    private const MAX_BLOCKS = 100;

    private const MAX_INLINE_NODES = 500;

    private const MAX_TEXT_CHARACTERS = 50000;

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public function validate(array $document, string $vocabulary = self::FULL): array
    {
        if (($document['schema_version'] ?? null) !== 1 || ! is_array($document['blocks'] ?? null)) {
            $this->invalid('The rich-text document must use schema version 1 and contain blocks.');
        }
        if (array_diff(array_keys($document), ['schema_version', 'blocks']) !== []) {
            $this->invalid('The rich-text document contains unsupported fields.');
        }
        if (count($document['blocks']) > self::MAX_BLOCKS) {
            $this->invalid('The rich-text document contains too many blocks.');
        }

        $inlineCount = 0;
        $characterCount = 0;
        foreach ($document['blocks'] as $block) {
            if (! is_array($block) || ! is_string($block['type'] ?? null)) {
                $this->invalid('Every rich-text block must have a valid type.');
            }
            $allowedBlocks = $vocabulary === self::COMMENT
                ? ['paragraph']
                : ['paragraph', 'heading_2', 'heading_3', 'horizontal_rule'];
            if (! in_array($block['type'], $allowedBlocks, true)) {
                $this->invalid('The rich-text document contains an unsupported block type.');
            }
            if ($block['type'] === 'horizontal_rule') {
                if (array_keys($block) !== ['type']) {
                    $this->invalid('A horizontal rule cannot contain content or attributes.');
                }

                continue;
            }
            if (array_diff(array_keys($block), ['type', 'content']) !== [] || ! is_array($block['content'] ?? null)) {
                $this->invalid('A text block must contain only an inline content array.');
            }
            foreach ($block['content'] as $inline) {
                $inlineCount++;
                if ($inlineCount > self::MAX_INLINE_NODES || ! is_array($inline)) {
                    $this->invalid('The rich-text document contains too many or invalid inline nodes.');
                }
                $type = $inline['type'] ?? null;
                if ($type === 'text') {
                    $allowed = $vocabulary === self::COMMENT ? ['type', 'text'] : ['type', 'text', 'marks'];
                    if (array_diff(array_keys($inline), $allowed) !== [] || ! is_string($inline['text'] ?? null)) {
                        $this->invalid('A text node contains unsupported fields.');
                    }
                    $marks = $inline['marks'] ?? [];
                    if (! is_array($marks) || array_diff($marks, ['bold', 'italic']) !== []
                        || count($marks) !== count(array_unique($marks)) || ($vocabulary === self::COMMENT && $marks !== [])) {
                        $this->invalid('A text node contains unsupported marks.');
                    }
                    $characterCount += mb_strlen($inline['text']);

                    continue;
                }
                if ($type !== 'mention' || array_diff(array_keys($inline), ['type', 'mention_id', 'person_id', 'label']) !== []
                    || ! is_string($inline['person_id'] ?? null) || ! is_string($inline['label'] ?? null)
                    || (isset($inline['mention_id']) && ! is_string($inline['mention_id']))) {
                    $this->invalid('A mention node is invalid.');
                }
                $characterCount += mb_strlen($inline['label']);
            }
        }
        if ($characterCount > self::MAX_TEXT_CHARACTERS) {
            $this->invalid('The rich-text document is too large.');
        }

        return $document;
    }

    /** @param array<string, mixed> $document */
    public function plainText(array $document): string
    {
        $blocks = [];
        foreach ($document['blocks'] as $block) {
            if (($block['type'] ?? null) === 'horizontal_rule') {
                continue;
            }
            $blocks[] = implode('', array_map(
                fn (array $inline): string => (string) ($inline['type'] === 'mention' ? $inline['label'] : $inline['text']),
                $block['content'],
            ));
        }

        return implode("\n\n", $blocks);
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['body' => $message]);
    }
}
