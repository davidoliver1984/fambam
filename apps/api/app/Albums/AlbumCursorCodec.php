<?php

namespace App\Albums;

use Illuminate\Validation\ValidationException;
use JsonException;

final class AlbumCursorCodec
{
    public function encode(AlbumCursor $cursor): string
    {
        $payload = $this->base64UrlEncode(json_encode([
            'v' => 1,
            'scope' => 'albums',
            'sort' => $cursor->sort,
            'filters' => $cursor->filters,
            'null_rank' => $cursor->nullRank,
            'value' => $cursor->value,
            'id' => $cursor->id,
        ], JSON_THROW_ON_ERROR));

        return $payload.'.'.$this->base64UrlEncode(hash_hmac('sha256', $payload, $this->key(), true));
    }

    public function decode(?string $encoded, AlbumListCriteria $criteria): ?AlbumCursor
    {
        if ($encoded === null || $encoded === '') {
            return null;
        }

        try {
            $parts = explode('.', $encoded);
            if (count($parts) !== 2) {
                return $this->invalid();
            }
            [$payload, $signature] = $parts;
            if (! hash_equals(hash_hmac('sha256', $payload, $this->key(), true), $this->base64UrlDecode($signature))) {
                return $this->invalid();
            }
            $value = json_decode($this->base64UrlDecode($payload), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($value) || ($value['v'] ?? null) !== 1 || ($value['scope'] ?? null) !== 'albums'
                || ($value['sort'] ?? null) !== $criteria->sort
                || ($value['filters'] ?? null) !== $criteria->fingerprint()
                || ! is_int($value['null_rank'] ?? null)
                || (! is_string($value['value'] ?? null) && ($value['value'] ?? null) !== null)
                || ! is_string($value['id'] ?? null)) {
                return $this->invalid();
            }

            return new AlbumCursor($value['sort'], $value['filters'], $value['null_rank'], $value['value'], $value['id']);
        } catch (JsonException|\ValueError) {
            return $this->invalid();
        }
    }

    private function key(): string
    {
        return (string) config('app.key');
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        $remainder = strlen($value) % 4;
        if ($remainder !== 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) {
            throw new \ValueError('Invalid base64url value.');
        }

        return $decoded;
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['cursor' => ['The Album cursor is invalid.']]);
    }
}
