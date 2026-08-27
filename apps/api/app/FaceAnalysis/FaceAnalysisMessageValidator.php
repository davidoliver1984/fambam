<?php

namespace App\FaceAnalysis;

use App\Enums\FaceAnalysisFailureCategory;
use JsonException;

class FaceAnalysisMessageValidator
{
    /** @return array<string, mixed> */
    public function decode(string $body, string $kind): array
    {
        try {
            $message = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidFaceAnalysisMessage('Message is not valid bounded JSON.');
        }
        if (! is_array($message) || array_is_list($message)) {
            throw new InvalidFaceAnalysisMessage('Message envelope is invalid.');
        }
        $common = [
            'contract_version', 'request_id', 'family_space_id', 'media_upload_id',
            'canonical_sha256', 'analysis_identity',
        ];
        $specific = $kind === 'completed'
            ? ['result_object_key', 'result_sha256', 'detected_face_count']
            : ['failure_category', 'failure_detail'];
        $this->exactKeys($message, [...$common, ...$specific]);
        if ($message['contract_version'] !== config('image-analysis.contract_version')) {
            throw new InvalidFaceAnalysisMessage('Unsupported contract version.');
        }
        foreach (['request_id', 'family_space_id', 'media_upload_id'] as $field) {
            if (! is_string($message[$field]) || preg_match('/^[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}$/', $message[$field]) !== 1) {
                throw new InvalidFaceAnalysisMessage("Invalid {$field}.");
            }
        }
        foreach (['canonical_sha256', 'result_sha256'] as $field) {
            if (array_key_exists($field, $message)
                && (! is_string($message[$field]) || preg_match('/^[a-f0-9]{64}$/', $message[$field]) !== 1)) {
                throw new InvalidFaceAnalysisMessage("Invalid {$field}.");
            }
        }
        $this->identity($message['analysis_identity']);

        if ($kind === 'completed') {
            if (! is_string($message['result_object_key']) || $message['result_object_key'] === '' || strlen($message['result_object_key']) > 512
                || ! is_int($message['detected_face_count']) || $message['detected_face_count'] < 0
                || $message['detected_face_count'] > (int) config('image-analysis.result.max_faces')) {
                throw new InvalidFaceAnalysisMessage('Completion result reference is invalid.');
            }
        } elseif (! is_string($message['failure_category'])
            || FaceAnalysisFailureCategory::tryFrom($message['failure_category']) === null
            || ! is_string($message['failure_detail'])
            || strlen($message['failure_detail']) > 512) {
            throw new InvalidFaceAnalysisMessage('Failure details are invalid.');
        }

        return $message;
    }

    public function requestId(string $body): string
    {
        try {
            $message = json_decode($body, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidFaceAnalysisMessage('Message is not valid JSON.');
        }
        $requestId = is_array($message) ? ($message['request_id'] ?? null) : null;
        if (! is_string($requestId) || preg_match('/^[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}$/', $requestId) !== 1) {
            throw new InvalidFaceAnalysisMessage('Message has no valid request identifier.');
        }

        return $requestId;
    }

    private function identity(mixed $identity): void
    {
        if (! is_array($identity) || array_is_list($identity)) {
            throw new InvalidFaceAnalysisMessage('Analysis identity is invalid.');
        }
        $this->exactKeys($identity, ['provider', 'model_identifier', 'model_weight_checksum', 'config_hash']);
        foreach (['provider' => 80, 'model_identifier' => 160] as $field => $maximum) {
            if (! is_string($identity[$field]) || $identity[$field] === '' || strlen($identity[$field]) > $maximum) {
                throw new InvalidFaceAnalysisMessage('Analysis identity is invalid.');
            }
        }
        foreach (['model_weight_checksum', 'config_hash'] as $field) {
            if (! is_string($identity[$field]) || preg_match('/^[a-f0-9]{64}$/', $identity[$field]) !== 1) {
                throw new InvalidFaceAnalysisMessage('Analysis identity is invalid.');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  list<string>  $keys
     */
    private function exactKeys(array $value, array $keys): void
    {
        sort($keys);
        $actual = array_keys($value);
        sort($actual);
        if ($actual !== $keys) {
            throw new InvalidFaceAnalysisMessage('Message fields do not match the contract.');
        }
    }
}
