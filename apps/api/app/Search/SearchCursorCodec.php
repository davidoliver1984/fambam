<?php

namespace App\Search;

use Illuminate\Validation\ValidationException;
use JsonException;

final class SearchCursorCodec
{
    public function encode(SearchCursor $cursor): string
    {
        $payload = $this->base64UrlEncode(json_encode([
            'v' => 1,
            'group' => $cursor->group,
            'match_class' => $cursor->matchClass,
            'score' => $cursor->score,
            'tie_breaker' => $cursor->tieBreaker,
            'id' => $cursor->id,
        ], JSON_THROW_ON_ERROR));

        return $payload.'.'.$this->base64UrlEncode(hash_hmac('sha256', $payload, $this->key(), true));
    }

    public function decode(?string $encoded, string $group): ?SearchCursor
    {
        if ($encoded === null || $encoded === '') {
            return null;
        }

        try {
            [$payload, $signature] = array_pad(explode('.', $encoded, 3), 2, null);
            if (! is_string($payload) || ! is_string($signature)
                || ! hash_equals(hash_hmac('sha256', $payload, $this->key(), true), $this->base64UrlDecode($signature))) {
                return $this->invalid();
            }
            $value = json_decode($this->base64UrlDecode($payload), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($value) || ($value['v'] ?? null) !== 1 || ($value['group'] ?? null) !== $group
                || ! is_int($value['match_class'] ?? null) || ! is_numeric($value['score'] ?? null)
                || (! is_string($value['tie_breaker'] ?? null) && ($value['tie_breaker'] ?? null) !== null)
                || ! is_string($value['id'] ?? null)) {
                return $this->invalid();
            }

            return new SearchCursor($group, $value['match_class'], (float) $value['score'], $value['tie_breaker'], $value['id']);
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
        throw ValidationException::withMessages(['cursor' => ['The search cursor is invalid.']]);
    }
}
