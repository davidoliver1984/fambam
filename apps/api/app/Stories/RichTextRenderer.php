<?php

namespace App\Stories;

final class RichTextRenderer
{
    public function __construct(private readonly RichTextDocument $documents) {}

    /**
     * @param  array<string, mixed>  $document
     * @param  callable(string): (array{label: string, url: string}|null)  $resolveMention
     */
    public function html(array $document, callable $resolveMention, string $vocabulary = RichTextDocument::FULL): string
    {
        $this->documents->validate($document, $vocabulary);

        return implode('', array_map(function (array $block) use ($resolveMention): string {
            if ($block['type'] === 'horizontal_rule') {
                return '<hr>';
            }
            $content = implode('', array_map(function (array $inline) use ($resolveMention): string {
                if ($inline['type'] === 'mention') {
                    $resolved = isset($inline['mention_id']) ? $resolveMention($inline['mention_id']) : null;
                    if ($resolved === null) {
                        return htmlspecialchars($inline['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    }

                    return sprintf('<a href="%s">%s</a>',
                        htmlspecialchars($resolved['url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                        htmlspecialchars($resolved['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
                }
                $text = htmlspecialchars($inline['text'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                foreach ($inline['marks'] ?? [] as $mark) {
                    $tag = $mark === 'bold' ? 'strong' : 'em';
                    $text = "<{$tag}>{$text}</{$tag}>";
                }

                return $text;
            }, $block['content']));
            $tag = match ($block['type']) {
                'heading_2' => 'h2',
                'heading_3' => 'h3',
                default => 'p',
            };

            return "<{$tag}>{$content}</{$tag}>";
        }, $document['blocks']));
    }
}
