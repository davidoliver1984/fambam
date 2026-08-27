<?php

namespace Tests\Feature;

use App\Enums\MediaUploadState;
use App\Jobs\DispatchFaceAnalysis;
use App\Models\AuditEvent;
use App\Models\FamilySpace;
use App\Models\MediaUpload;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FaceAnalysisReprocessingTest extends TestCase
{
    use RefreshDatabase;

    public function test_reprocessing_rejects_an_unscoped_invocation(): void
    {
        Queue::fake();

        $this->artisan('fambam:reprocess-face-analysis')->assertExitCode(2);

        Queue::assertNothingPushed();
        $this->assertDatabaseMissing('audit_events', ['action' => 'face_analysis.reprocessing_requested']);
    }

    public function test_family_scoped_reprocessing_is_audited_and_bounded(): void
    {
        Queue::fake();
        $family = FamilySpace::factory()->create();
        $actor = User::factory()->create();
        $upload = MediaUpload::factory()->create([
            'family_space_id' => $family->id,
            'user_id' => $actor->id,
            'state' => MediaUploadState::Ready,
            'canonical_object_key' => "families/{$family->id}/media/upload/canonical.jpg",
            'canonical_sha256' => str_repeat('a', 64),
        ]);

        $this->artisan('fambam:reprocess-face-analysis', [
            '--family-space' => $family->id,
            '--actor' => (string) $actor->id,
            '--media-upload' => [$upload->id],
        ])->assertSuccessful();

        Queue::assertPushed(DispatchFaceAnalysis::class, fn (DispatchFaceAnalysis $job): bool => $job->mediaUploadId === $upload->id);
        $audit = AuditEvent::query()->where('action', 'face_analysis.reprocessing_requested')->sole();
        $this->assertSame('media_uploads', $audit->metadata['scope']);
        $this->assertSame([$upload->id], $audit->metadata['media_upload_ids']);
    }
}
