<?php

namespace Tests\Feature;

use App\Enums\FamilySpaceRole;
use App\Enums\PhotoVisibility;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\Photo;
use App\Models\PhotoComment;
use App\Models\PhotoStory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhotoConversationTest extends TestCase
{
    use RefreshDatabase;

    public function test_authors_edit_with_revision_history_and_only_authors_or_moderators_remove(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'photo-conversation']);
        $author = $this->member($family, FamilySpaceRole::Member);
        $other = $this->member($family, FamilySpaceRole::Member);
        $administrator = $this->member($family, FamilySpaceRole::Administrator);
        $photo = Photo::factory()->create(['family_space_id' => $family->id, 'created_by' => $author->id]);
        $base = "/api/families/photo-conversation/photos/{$photo->id}";

        $storyId = $this->actingAs($author)->postJson("{$base}/stories", ['body' => 'The original memory.'])
            ->assertCreated()->assertJsonPath('data.permissions.can_edit', true)->json('data.id');
        $commentId = $this->actingAs($author)->postJson("{$base}/comments", ['body' => 'A first comment.'])
            ->assertCreated()->json('data.id');

        $this->actingAs($other)->patchJson("{$base}/stories/{$storyId}", ['body' => 'Not mine'])->assertForbidden();
        $this->actingAs($other)->deleteJson("{$base}/comments/{$commentId}")->assertForbidden();
        $this->actingAs($author)->patchJson("{$base}/stories/{$storyId}", ['body' => 'The corrected memory.'])
            ->assertOk()->assertJsonPath('data.body', 'The corrected memory.');
        $this->assertDatabaseHas('photo_story_revisions', ['photo_story_id' => $storyId,
            'revision' => 1, 'body' => 'The original memory.']);

        $this->actingAs($administrator)->deleteJson("{$base}/comments/{$commentId}")->assertNoContent();
        $this->assertSoftDeleted(PhotoComment::query()->withTrashed()->findOrFail($commentId));
        $this->assertDatabaseHas('audit_events', ['action' => 'photo_comment.removed',
            'actor_user_id' => $administrator->id, 'subject_id' => $commentId]);
        $this->assertFalse(PhotoStory::query()->findOrFail($storyId)->trashed());
    }

    public function test_reactions_are_one_fixed_lightweight_expression_per_user(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'photo-reactions']);
        $member = $this->member($family, FamilySpaceRole::Member);
        $photo = Photo::factory()->create(['family_space_id' => $family->id, 'created_by' => $member->id]);
        $path = "/api/families/photo-reactions/photos/{$photo->id}/reaction";

        $this->actingAs($member)->putJson($path, ['reaction' => 'love'])->assertOk();
        $this->actingAs($member)->putJson($path, ['reaction' => 'remember'])->assertOk();
        $this->assertDatabaseCount('photo_reactions', 1);
        $this->assertDatabaseHas('photo_reactions', ['photo_id' => $photo->id,
            'user_id' => $member->id, 'reaction' => 'remember']);
        $this->actingAs($member)->putJson($path, ['reaction' => 'thumbs_up'])->assertUnprocessable();
        $this->actingAs($member)->getJson("/api/families/photo-reactions/photos/{$photo->id}/conversation")
            ->assertOk()->assertJsonPath('data.reactions.0.reaction', 'remember');
        $this->actingAs($member)->deleteJson($path)->assertNoContent();
        $this->assertDatabaseCount('photo_reactions', 0);
    }

    public function test_private_and_cross_tenant_photos_do_not_expose_conversation_content(): void
    {
        $first = FamilySpace::factory()->create(['slug' => 'conversation-first']);
        $author = $this->member($first, FamilySpaceRole::Member);
        $private = Photo::factory()->create(['family_space_id' => $first->id, 'created_by' => $author->id,
            'visibility' => PhotoVisibility::Private]);
        $second = FamilySpace::factory()->create(['slug' => 'conversation-second']);
        $outsider = $this->member($second, FamilySpaceRole::Member);

        $this->actingAs($outsider)->getJson("/api/families/conversation-second/photos/{$private->id}/conversation")->assertNotFound();
        $this->actingAs($outsider)->getJson("/api/families/conversation-first/photos/{$private->id}/conversation")->assertNotFound();
    }

    private function member(FamilySpace $family, FamilySpaceRole $role): User
    {
        $user = User::factory()->create();
        FamilySpaceMembership::factory()->create(['family_space_id' => $family->id, 'user_id' => $user->id, 'role' => $role]);

        return $user;
    }
}
