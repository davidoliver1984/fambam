<?php

namespace Tests\Feature;

use App\Enums\AlbumVisibility;
use App\Enums\FamilySpaceRole;
use App\Enums\PhotoVisibility;
use App\Models\Album;
use App\Models\AlbumGrant;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\Person;
use App\Models\PersonAccountLink;
use App\Models\Photo;
use App\Models\PhotoComment;
use App\Models\PhotoReaction;
use App\Models\Story;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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
        $album = $this->albumWithPhoto($family, $author, $photo);
        $base = "/api/families/photo-conversation/photos/{$photo->id}";

        $storyId = $this->actingAs($author)->postJson("{$base}/stories", ['body' => 'The original memory.'])
            ->assertCreated()->assertJsonPath('data.permissions.can_edit', true)->json('data.id');
        $commentId = $this->actingAs($author)->postJson("{$base}/comments", ['body' => 'A first comment.', 'album_id' => $album->id])
            ->assertCreated()->json('data.id');

        $this->actingAs($other)->patchJson("{$base}/stories/{$storyId}", ['body' => 'Not mine'])->assertForbidden();
        $this->actingAs($other)->deleteJson("{$base}/comments/{$commentId}")->assertForbidden();
        $this->actingAs($author)->patchJson("{$base}/stories/{$storyId}", ['body' => 'The corrected memory.'])
            ->assertOk()->assertJsonPath('data.body', 'The corrected memory.');
        $this->assertDatabaseHas('story_revisions', ['story_id' => $storyId,
            'revision' => 1]);
        $this->assertSame(
            'The original memory.',
            Story::query()->findOrFail($storyId)->revisions()->firstOrFail()->body['blocks'][0]['content'][0]['text'],
        );

        $this->actingAs($administrator)->deleteJson("{$base}/comments/{$commentId}")->assertNoContent();
        $this->assertSoftDeleted(PhotoComment::query()->withTrashed()->findOrFail($commentId));
        $this->assertDatabaseHas('audit_events', ['action' => 'photo_comment.removed',
            'actor_user_id' => $administrator->id, 'subject_id' => $commentId]);
        $this->assertFalse(Story::query()->findOrFail($storyId)->trashed());
    }

    public function test_reactions_are_one_fixed_lightweight_expression_per_user(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'photo-reactions']);
        $member = $this->member($family, FamilySpaceRole::Member);
        $photo = Photo::factory()->create(['family_space_id' => $family->id, 'created_by' => $member->id]);
        $album = $this->albumWithPhoto($family, $member, $photo);
        $path = "/api/families/photo-reactions/photos/{$photo->id}/reaction";

        $this->actingAs($member)->putJson($path, ['reaction' => 'love', 'album_id' => $album->id])->assertOk();
        $this->actingAs($member)->putJson($path, ['reaction' => 'remember', 'album_id' => $album->id])->assertOk();
        $this->assertDatabaseCount('photo_reactions', 1);
        $this->assertDatabaseHas('photo_reactions', ['photo_id' => $photo->id,
            'user_id' => $member->id, 'reaction' => 'remember']);
        $this->actingAs($member)->putJson($path, ['reaction' => 'thumbs_up', 'album_id' => $album->id])->assertUnprocessable();
        $this->actingAs($member)->getJson("/api/families/photo-reactions/photos/{$photo->id}/conversation?album_id={$album->id}")
            ->assertOk()->assertJsonPath('data.reactions.0.reaction', 'remember');
        $this->actingAs($member)->deleteJson("{$path}?album_id={$album->id}")->assertNoContent();
        $this->assertDatabaseCount('photo_reactions', 0);
    }

    public function test_comments_support_one_level_of_replies_and_expose_linked_people(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'photo-replies']);
        $author = $this->member($family, FamilySpaceRole::Member);
        $replier = $this->member($family, FamilySpaceRole::Member);
        $person = Person::factory()->create([
            'family_space_id' => $family->id,
            'preferred_name' => 'Sarah Mercer',
            'created_by' => $author->id,
        ]);
        PersonAccountLink::query()->create([
            'family_space_id' => $family->id,
            'person_id' => $person->id,
            'user_id' => $author->id,
            'created_by' => $author->id,
        ]);
        $photo = Photo::factory()->create(['family_space_id' => $family->id, 'created_by' => $author->id]);
        $album = $this->albumWithPhoto($family, $author, $photo);
        $base = "/api/families/{$family->slug}/photos/{$photo->id}";

        $parentId = $this->actingAs($author)->postJson("{$base}/comments", [
            'body' => 'Mum looks so happy here.',
            'album_id' => $album->id,
        ])->assertCreated()->assertJsonPath('data.author.person_id', $person->id)->json('data.id');

        $replyId = $this->actingAs($replier)->postJson("{$base}/comments", [
            'body' => 'She really was — even in that wind!',
            'album_id' => $album->id,
            'parent_comment_id' => $parentId,
        ])->assertCreated()->json('data.id');

        $this->assertDatabaseHas('photo_comments', [
            'id' => $replyId,
            'parent_comment_id' => $parentId,
        ]);
        $this->actingAs($author)->getJson("{$base}/conversation?album_id={$album->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.comments')
            ->assertJsonCount(1, 'data.comments.0.replies')
            ->assertJsonPath('data.comments.0.parent_comment_id', null)
            ->assertJsonPath('data.comments.0.is_deleted', false)
            ->assertJsonPath('data.comments.0.replies.0.parent_comment_id', $parentId)
            ->assertJsonPath('data.comments.0.replies.0.is_deleted', false)
            ->assertJsonMissingPath('data.comments.0.replies.0.replies')
            ->assertJsonPath('data.comments.0.replies.0.body', 'She really was — even in that wind!');

        $this->actingAs($author)->postJson("{$base}/comments", [
            'body' => 'Replies cannot be nested again.',
            'album_id' => $album->id,
            'parent_comment_id' => $replyId,
        ])->assertNotFound();
    }

    public function test_reply_parent_must_be_an_active_top_level_comment_in_the_same_photo_and_album(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'reply-context']);
        $member = $this->member($family, FamilySpaceRole::Member);
        $firstPhoto = Photo::factory()->create(['family_space_id' => $family->id, 'created_by' => $member->id]);
        $secondPhoto = Photo::factory()->create(['family_space_id' => $family->id, 'created_by' => $member->id]);
        $firstAlbum = $this->albumWithPhoto($family, $member, $firstPhoto);
        $secondAlbum = $this->albumWithPhoto($family, $member, $firstPhoto);
        $this->albumWithPhoto($family, $member, $secondPhoto);
        $firstBase = "/api/families/{$family->slug}/photos/{$firstPhoto->id}";
        $secondBase = "/api/families/{$family->slug}/photos/{$secondPhoto->id}";
        $parentId = $this->actingAs($member)->postJson("{$firstBase}/comments", [
            'body' => 'Only this context may reply.',
            'album_id' => $firstAlbum->id,
        ])->assertCreated()->json('data.id');

        $this->actingAs($member)->postJson("{$firstBase}/comments", [
            'body' => 'Wrong Album.',
            'album_id' => $secondAlbum->id,
            'parent_comment_id' => $parentId,
        ])->assertNotFound();
        $secondPhotoAlbum = $secondPhoto->albums()->firstOrFail();
        $this->actingAs($member)->postJson("{$secondBase}/comments", [
            'body' => 'Wrong Photo.',
            'album_id' => $secondPhotoAlbum->id,
            'parent_comment_id' => $parentId,
        ])->assertNotFound();

        $otherFamily = FamilySpace::factory()->create(['slug' => 'reply-context-other']);
        $otherMember = $this->member($otherFamily, FamilySpaceRole::Member);
        $otherPhoto = Photo::factory()->create(['family_space_id' => $otherFamily->id, 'created_by' => $otherMember->id]);
        $otherAlbum = $this->albumWithPhoto($otherFamily, $otherMember, $otherPhoto);
        $this->actingAs($otherMember)->postJson("/api/families/{$otherFamily->slug}/photos/{$otherPhoto->id}/comments", [
            'body' => 'Wrong Family Space.',
            'album_id' => $otherAlbum->id,
            'parent_comment_id' => $parentId,
        ])->assertNotFound();
    }

    public function test_deleted_parent_is_a_redacted_tombstone_only_while_active_replies_remain(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'reply-tombstones']);
        $author = $this->member($family, FamilySpaceRole::Member);
        $replier = $this->member($family, FamilySpaceRole::Member);
        $photo = Photo::factory()->create(['family_space_id' => $family->id, 'created_by' => $author->id]);
        $album = $this->albumWithPhoto($family, $author, $photo);
        $base = "/api/families/{$family->slug}/photos/{$photo->id}";
        $parentId = $this->actingAs($author)->postJson("{$base}/comments", [
            'body' => 'Secret deleted parent content.',
            'album_id' => $album->id,
        ])->assertCreated()->json('data.id');
        $replyId = $this->actingAs($replier)->postJson("{$base}/comments", [
            'body' => 'A reply that remains.',
            'album_id' => $album->id,
            'parent_comment_id' => $parentId,
        ])->assertCreated()->json('data.id');

        $this->actingAs($author)->deleteJson("{$base}/comments/{$parentId}")->assertNoContent();
        $this->actingAs($replier)->getJson("{$base}/conversation?album_id={$album->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.comments')
            ->assertJsonPath('data.comments.0.is_deleted', true)
            ->assertJsonPath('data.comments.0.body', 'Comment deleted')
            ->assertJsonPath('data.comments.0.body_document', null)
            ->assertJsonPath('data.comments.0.body_html', null)
            ->assertJsonPath('data.comments.0.author', null)
            ->assertJsonPath('data.comments.0.permissions.can_edit', false)
            ->assertJsonPath('data.comments.0.permissions.can_remove', false)
            ->assertJsonPath('data.comments.0.replies.0.id', $replyId)
            ->assertJsonMissing(['Secret deleted parent content.']);
        $this->actingAs($replier)->postJson("{$base}/comments", [
            'body' => 'Cannot reply after deletion.',
            'album_id' => $album->id,
            'parent_comment_id' => $parentId,
        ])->assertNotFound();

        $this->actingAs($replier)->deleteJson("{$base}/comments/{$replyId}")->assertNoContent();
        $this->actingAs($replier)->getJson("{$base}/conversation?album_id={$album->id}")
            ->assertOk()->assertJsonCount(0, 'data.comments');

        $unrepliedId = $this->actingAs($author)->postJson("{$base}/comments", [
            'body' => 'No replies here.',
            'album_id' => $album->id,
        ])->assertCreated()->json('data.id');
        $this->actingAs($author)->deleteJson("{$base}/comments/{$unrepliedId}")->assertNoContent();
        $this->actingAs($author)->getJson("{$base}/conversation?album_id={$album->id}")
            ->assertOk()->assertJsonCount(0, 'data.comments');
    }

    public function test_active_reply_counts_include_replies_and_exclude_tombstones_and_deleted_replies(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'reply-counts']);
        $member = $this->member($family, FamilySpaceRole::Member);
        $photo = Photo::factory()->create(['family_space_id' => $family->id, 'created_by' => $member->id]);
        $album = $this->albumWithPhoto($family, $member, $photo);
        $base = "/api/families/{$family->slug}/photos/{$photo->id}";
        $parentId = $this->actingAs($member)->postJson("{$base}/comments", [
            'body' => 'Root.', 'album_id' => $album->id,
        ])->assertCreated()->json('data.id');
        $replyId = $this->actingAs($member)->postJson("{$base}/comments", [
            'body' => 'Reply.', 'album_id' => $album->id, 'parent_comment_id' => $parentId,
        ])->assertCreated()->json('data.id');

        $photoData = $this->actingAs($member)->getJson("/api/families/{$family->slug}/photos")
            ->assertOk()->collect('data.items')->firstWhere('id', $photo->id);
        $this->assertSame(2, $photoData['comment_count']);
        $this->actingAs($member)->getJson("/api/families/{$family->slug}/albums/{$album->id}")
            ->assertOk()->assertJsonPath('data.photos.0.conversation.comment_count', 2);

        $this->actingAs($member)->deleteJson("{$base}/comments/{$parentId}")->assertNoContent();
        $photoData = $this->actingAs($member)->getJson("/api/families/{$family->slug}/photos")
            ->collect('data.items')->firstWhere('id', $photo->id);
        $this->assertSame(1, $photoData['comment_count']);
        $this->actingAs($member)->deleteJson("{$base}/comments/{$replyId}")->assertNoContent();
        $photoData = $this->actingAs($member)->getJson("/api/families/{$family->slug}/photos")
            ->collect('data.items')->firstWhere('id', $photo->id);
        $this->assertSame(0, $photoData['comment_count']);
    }

    public function test_new_comments_and_reactions_require_an_album_scope(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'conversation-album-required']);
        $member = $this->member($family, FamilySpaceRole::Member);
        $photo = Photo::factory()->create(['family_space_id' => $family->id, 'created_by' => $member->id]);
        $base = "/api/families/{$family->slug}/photos/{$photo->id}";

        foreach ([[], ['album_id' => null]] as $scope) {
            $this->actingAs($member)->postJson("{$base}/comments", [
                'body' => 'Album context is required.',
                ...$scope,
            ])->assertUnprocessable()->assertJsonValidationErrors('album_id');
            $this->actingAs($member)->putJson("{$base}/reaction", [
                'reaction' => 'love',
                ...$scope,
            ])->assertUnprocessable()->assertJsonValidationErrors('album_id');
        }

        $this->assertDatabaseCount('photo_comments', 0);
        $this->assertDatabaseCount('photo_reactions', 0);
    }

    public function test_contributor_interactions_require_an_album_contribution_grant(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'contributor-interactions']);
        $owner = $this->member($family, FamilySpaceRole::Owner);
        $contributor = $this->member($family, FamilySpaceRole::Contributor);
        $membership = FamilySpaceMembership::query()->where('family_space_id', $family->id)
            ->where('user_id', $contributor->id)->sole();
        $photo = Photo::factory()->create(['family_space_id' => $family->id, 'created_by' => $owner->id,
            'visibility' => PhotoVisibility::Private]);
        $album = Album::query()->create(['family_space_id' => $family->id, 'created_by' => $owner->id,
            'name' => 'Selected memories', 'visibility' => AlbumVisibility::Selected]);
        $grant = AlbumGrant::query()->create(['family_space_id' => $family->id, 'album_id' => $album->id,
            'family_space_membership_id' => $membership->id, 'can_view' => true, 'can_contribute' => false,
            'granted_by' => $owner->id]);
        $album->photos()->attach($photo->id, ['id' => (string) Str::ulid(),
            'family_space_id' => $family->id, 'position' => 1, 'added_by' => $owner->id]);
        $base = "/api/families/contributor-interactions/photos/{$photo->id}";
        $person = Person::factory()->create([
            'family_space_id' => $family->id,
            'preferred_name' => 'Private directory identity',
            'created_by' => $owner->id,
        ]);
        PersonAccountLink::query()->create([
            'family_space_id' => $family->id,
            'person_id' => $person->id,
            'user_id' => $owner->id,
            'created_by' => $owner->id,
        ]);
        $this->actingAs($owner)->postJson("{$base}/comments", [
            'body' => 'Visible comment, protected Person link.',
            'album_id' => $album->id,
        ])->assertCreated();

        $this->actingAs($contributor)->getJson($base)->assertOk();
        $this->actingAs($contributor)->getJson("{$base}/conversation?album_id={$album->id}")
            ->assertOk()
            ->assertJsonPath('data.comments.0.author.name', $owner->name)
            ->assertJsonPath('data.comments.0.author.person_id', null)
            ->assertJsonPath('data.comments.0.author.portrait_thumbnail_url', null);
        $this->actingAs($contributor)->postJson("{$base}/stories", ['body' => 'View only'])->assertForbidden();
        $this->actingAs($contributor)->postJson("{$base}/comments", ['body' => 'View only', 'album_id' => $album->id])->assertForbidden();
        $this->actingAs($contributor)->putJson("{$base}/reaction", ['reaction' => 'love', 'album_id' => $album->id])->assertForbidden();

        $grant->update(['can_contribute' => true]);

        $this->actingAs($contributor)->postJson("{$base}/stories", ['body' => 'A contributed story.'])->assertCreated();
        $this->actingAs($contributor)->postJson("{$base}/comments", ['body' => 'A contributed comment.', 'album_id' => $album->id])->assertCreated();
        $this->actingAs($contributor)->putJson("{$base}/reaction", ['reaction' => 'love', 'album_id' => $album->id])->assertOk();
    }

    public function test_self_removal_is_not_a_moderation_audit_but_moderator_removal_is(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'conversation-removal-audit']);
        $author = $this->member($family, FamilySpaceRole::Member);
        $administrator = $this->member($family, FamilySpaceRole::Administrator);
        $photo = Photo::factory()->create(['family_space_id' => $family->id, 'created_by' => $author->id]);
        $album = $this->albumWithPhoto($family, $author, $photo);
        $base = "/api/families/conversation-removal-audit/photos/{$photo->id}";

        $ownStory = $this->actingAs($author)->postJson("{$base}/stories", ['body' => 'My story.'])
            ->assertCreated()->json('data.id');
        $ownComment = $this->actingAs($author)->postJson("{$base}/comments", ['body' => 'My comment.', 'album_id' => $album->id])
            ->assertCreated()->json('data.id');
        $this->actingAs($author)->deleteJson("{$base}/stories/{$ownStory}")->assertNoContent();
        $this->actingAs($author)->deleteJson("{$base}/comments/{$ownComment}")->assertNoContent();
        $this->assertDatabaseMissing('audit_events', ['action' => 'story.removed', 'subject_id' => $ownStory]);
        $this->assertDatabaseMissing('audit_events', ['action' => 'photo_comment.removed', 'subject_id' => $ownComment]);

        $moderatedStory = $this->actingAs($author)->postJson("{$base}/stories", ['body' => 'Moderated story.'])
            ->assertCreated()->json('data.id');
        $moderatedComment = $this->actingAs($author)->postJson("{$base}/comments", ['body' => 'Moderated comment.', 'album_id' => $album->id])
            ->assertCreated()->json('data.id');
        $this->actingAs($administrator)->deleteJson("{$base}/stories/{$moderatedStory}")->assertNoContent();
        $this->actingAs($administrator)->deleteJson("{$base}/comments/{$moderatedComment}")->assertNoContent();
        $this->assertDatabaseHas('audit_events', ['action' => 'story.removed',
            'actor_user_id' => $administrator->id, 'subject_id' => $moderatedStory]);
        $this->assertDatabaseHas('audit_events', ['action' => 'photo_comment.removed',
            'actor_user_id' => $administrator->id, 'subject_id' => $moderatedComment]);
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

    public function test_album_conversations_are_independent_legacy_is_direct_only_and_readding_restores_history(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'album-conversation-scope']);
        $member = $this->member($family, FamilySpaceRole::Member);
        $photo = Photo::factory()->create(['family_space_id' => $family->id, 'created_by' => $member->id]);
        $first = $this->albumWithPhoto($family, $member, $photo);
        $second = $this->albumWithPhoto($family, $member, $photo);
        $base = "/api/families/album-conversation-scope/photos/{$photo->id}";

        PhotoComment::query()->create([
            'family_space_id' => $family->id,
            'photo_id' => $photo->id,
            'album_id' => null,
            'author_id' => $member->id,
            'body' => 'Legacy comment',
        ]);
        PhotoReaction::query()->create([
            'family_space_id' => $family->id,
            'photo_id' => $photo->id,
            'album_id' => null,
            'user_id' => $member->id,
            'reaction' => 'remember',
        ]);
        $this->actingAs($member)->postJson("{$base}/comments", [
            'album_id' => $first->id,
            'body' => 'First Album only',
        ])->assertCreated();
        $this->actingAs($member)->putJson("{$base}/reaction", [
            'album_id' => $first->id,
            'reaction' => 'love',
        ])->assertOk();

        $this->actingAs($member)->getJson("{$base}/conversation?album_id={$first->id}")
            ->assertOk()
            ->assertJsonPath('data.conversation_scope', 'album')
            ->assertJsonPath('data.comments.0.body', 'First Album only')
            ->assertJsonCount(1, 'data.comments')
            ->assertJsonPath('data.reactions.0.reaction', 'love');
        $this->actingAs($member)->getJson("{$base}/conversation?album_id={$second->id}")
            ->assertOk()->assertJsonCount(0, 'data.comments')->assertJsonCount(0, 'data.reactions');
        $this->actingAs($member)->getJson("{$base}/conversation")
            ->assertOk()
            ->assertJsonPath('data.conversation_scope', 'legacy')
            ->assertJsonPath('data.comments.0.body', 'Legacy comment')
            ->assertJsonPath('data.comments.0.permissions.can_edit', false)
            ->assertJsonPath('data.reactions.0.reaction', 'remember')
            ->assertJsonPath('data.permissions.can_interact', false);

        $first->photos()->detach($photo->id);
        $this->actingAs($member)->getJson("{$base}/conversation?album_id={$first->id}")->assertNotFound();
        $first->photos()->attach($photo->id, [
            'id' => (string) Str::ulid(),
            'family_space_id' => $family->id,
            'position' => 1,
            'added_by' => $member->id,
        ]);
        $this->actingAs($member)->getJson("{$base}/conversation?album_id={$first->id}")
            ->assertOk()->assertJsonPath('data.comments.0.body', 'First Album only')
            ->assertJsonPath('data.reactions.0.reaction', 'love');
    }

    private function member(FamilySpace $family, FamilySpaceRole $role): User
    {
        $user = User::factory()->create();
        FamilySpaceMembership::factory()->create(['family_space_id' => $family->id, 'user_id' => $user->id, 'role' => $role]);

        return $user;
    }

    private function albumWithPhoto(FamilySpace $family, User $creator, Photo $photo): Album
    {
        $album = Album::query()->create([
            'family_space_id' => $family->id,
            'created_by' => $creator->id,
            'name' => 'Family memories',
            'visibility' => AlbumVisibility::FamilySpace,
        ]);
        $album->photos()->attach($photo->id, [
            'id' => (string) Str::ulid(),
            'family_space_id' => $family->id,
            'position' => 1,
            'added_by' => $creator->id,
        ]);

        return $album;
    }
}
