<?php

namespace Tests\Unit;

use App\FaceAnalysis\FaceAnalysisMessageValidator;
use App\FaceAnalysis\InvalidFaceAnalysisMessage;
use Tests\TestCase;

class FaceAnalysisMessageValidatorTest extends TestCase
{
    public function test_completion_contract_rejects_unknown_fields(): void
    {
        $message = $this->completion();
        $message['embedding'] = [0.1];

        $this->expectException(InvalidFaceAnalysisMessage::class);
        app(FaceAnalysisMessageValidator::class)->decode(json_encode($message, JSON_THROW_ON_ERROR), 'completed');
    }

    public function test_request_identifier_can_be_resolved_without_trusting_tenant_fields(): void
    {
        $message = $this->completion();
        $message['family_space_id'] = 'claimed-but-not-trusted';

        $this->assertSame(
            '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            app(FaceAnalysisMessageValidator::class)->requestId(json_encode($message, JSON_THROW_ON_ERROR)),
        );
    }

    /** @return array<string, mixed> */
    private function completion(): array
    {
        return [
            'contract_version' => '1',
            'request_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            'family_space_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAW',
            'media_upload_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAX',
            'canonical_sha256' => str_repeat('a', 64),
            'analysis_identity' => [
                'provider' => 'insightface-onnxruntime',
                'model_identifier' => 'buffalo_l-v0.7',
                'model_weight_checksum' => str_repeat('b', 64),
                'config_hash' => str_repeat('c', 64),
            ],
            'result_object_key' => 'families/f/face-analysis/a/result.json',
            'result_sha256' => str_repeat('d', 64),
            'detected_face_count' => 0,
        ];
    }
}
