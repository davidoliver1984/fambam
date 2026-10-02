<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/** @implements CastsAttributes<string|null, mixed> */
final class DatabaseBinary implements CastsAttributes
{
    /** @param array<string, mixed> $attributes */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_resource($value)) {
            $metadata = stream_get_meta_data($value);
            if ($metadata['seekable']) {
                rewind($value);
            }
            $contents = stream_get_contents($value);
            if ($contents !== false) {
                return $contents;
            }
        }

        if (is_string($value)) {
            return $value;
        }

        throw new InvalidArgumentException("{$key} is not readable binary data.");
    }

    /** @param array<string, mixed> $attributes */
    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if (! is_string($value) || $model->getConnection()->getDriverName() !== 'pgsql') {
            return $value;
        }

        $stream = fopen('php://memory', 'r+');
        if ($stream === false || fwrite($stream, $value) === false || ! rewind($stream)) {
            throw new InvalidArgumentException("{$key} could not be prepared as binary data.");
        }

        return $stream;
    }
}
