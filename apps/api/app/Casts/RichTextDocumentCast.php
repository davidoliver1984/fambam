<?php

namespace App\Casts;

use App\Stories\RichTextDocument;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/** @implements CastsAttributes<array<string, mixed>|null, mixed> */
final class RichTextDocumentCast implements CastsAttributes
{
    public function __construct(
        private readonly string $vocabulary = RichTextDocument::FULL,
        private readonly ?string $plainTextColumn = null,
    ) {}

    /** @param array<string, mixed> $attributes @return array<string, mixed>|null */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null) {
            return null;
        }

        $decoded = is_string($value) ? json_decode($value, true, flags: JSON_THROW_ON_ERROR) : $value;

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        $documents = new RichTextDocument;
        $document = $documents->normalize($value, $this->vocabulary);
        $values = [$key => $document === null ? null : json_encode($document, JSON_THROW_ON_ERROR)];
        if ($this->plainTextColumn !== null) {
            $values[$this->plainTextColumn] = $document === null ? null : $documents->plainText($document);
        }

        return $values;
    }
}
