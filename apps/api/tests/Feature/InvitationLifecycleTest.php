<?php

namespace Tests\Feature;

use App\Enums\FamilySpaceRole;
use App\Enums\InvitationStatus;
use App\Enums\MembershipState;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\Invitation;
use App\Models\InvitationClaim;
use App\Models\User;
use App\Notifications\InvitationIssued;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class InvitationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_can_bootstrap_only_the_first_account(): void
    {
        $this->artisan('fambam:bootstrap-user', [
            'email' => 'owner@example.test',
            '--name' => 'Archive Owner',
            '--timezone' => 'Europe/London',
        ])
            ->expectsQuestion('Password (minimum 15 characters)', 'a-very-long-passphrase')
            ->expectsQuestion('Confirm password', 'a-very-long-passphrase')
            ->assertSuccessful();

        $owner = User::query()->sole();
        $this->assertFalse($owner->can_create_family_spaces);
        $this->assertNotNull($owner->email_verified_at);
        $this->assertTrue(Hash::check('a-very-long-passphrase', $owner->password));
        $this->assertDatabaseHas('audit_events', ['action' => 'account.bootstrapped']);
        $familySpace = FamilySpace::query()->sole();
        $this->assertSame('family-archive', $familySpace->slug);
        $this->assertDatabaseHas('family_space_memberships', [
            'family_space_id' => $familySpace->id,
            'user_id' => $owner->id,
            'role' => FamilySpaceRole::Owner->value,
            'state' => MembershipState::Active->value,
        ]);

        $this->artisan('fambam:bootstrap-user', ['email' => 'second@example.test'])
            ->assertFailed();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_only_family_space_administrators_can_manage_invitations(): void
    {
        $ordinaryUser = User::factory()->create();
        $familySpace = FamilySpace::factory()->create();

        $this->actingAs($ordinaryUser)
            ->postJson('/api/invitations', [
                'family_space_id' => $familySpace->id,
                'email' => 'relative@example.test',
                'role' => FamilySpaceRole::Member->value,
            ])
            ->assertNotFound();

        $this->actingAs($ordinaryUser)
            ->getJson('/api/invitations?family_space_id='.$familySpace->id)
            ->assertNotFound();

        FamilySpaceMembership::factory()->create([
            'family_space_id' => $familySpace->id,
            'user_id' => $ordinaryUser->id,
            'role' => FamilySpaceRole::Member,
        ]);
        $this->actingAs($ordinaryUser)
            ->postJson('/api/invitations', [
                'family_space_id' => $familySpace->id,
                'email' => 'relative@example.test',
                'role' => FamilySpaceRole::Member->value,
            ])
            ->assertForbidden();
    }

    public function test_guest_cannot_issue_an_invitation(): void
    {
        $this->postJson('/api/invitations', ['email' => 'relative@example.test'])
            ->assertUnauthorized();
    }

    public function test_invitation_is_issued_with_only_a_hash_stored_and_an_audit_event(): void
    {
        $owner = User::factory()->create();
        $rawToken = $this->issueInvitation($owner, 'Relative@Example.test');
        $invitation = Invitation::query()->sole();

        $this->assertSame('relative@example.test', $invitation->email);
        $this->assertNotSame($rawToken, $invitation->token_hash);
        $this->assertSame(hash('sha256', $rawToken), $invitation->token_hash);
        $this->assertTrue($invitation->expires_at->isFuture());
        $this->assertSame(FamilySpaceRole::Member, $invitation->role);
        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $owner->id,
            'action' => 'invitation.issued',
            'subject_id' => (string) $invitation->id,
            'ip_address' => '127.0.0.1',
        ]);
    }

    public function test_exchange_consumes_the_email_token_and_acceptance_creates_a_verified_account(): void
    {
        $owner = User::factory()->create();
        $rawToken = $this->issueInvitation($owner, 'relative@example.test');

        $exchange = $this->postJson('/api/invitations/exchange', ['token' => $rawToken])
            ->assertOk()
            ->assertJsonPath('data.email', 'relative@example.test');
        $claimToken = $exchange->json('data.claim_token');

        $this->assertNull(Invitation::query()->sole()->token_hash);
        $this->postJson('/api/invitations/exchange', ['token' => $rawToken])->assertUnprocessable();

        $this->postJson('/api/invitations/accept', [
            'claim_token' => $claimToken,
            'name' => 'Invited Relative',
            'password' => 'a-very-long-passphrase',
            'password_confirmation' => 'a-very-long-passphrase',
            'timezone' => 'Europe/Paris',
        ])->assertCreated()->assertJsonPath('data.email', 'relative@example.test');

        $relative = User::query()->where('email', 'relative@example.test')->sole();
        $this->assertSame('Invited Relative', $relative->name);
        $this->assertSame('Europe/Paris', $relative->timezone);
        $this->assertNotNull($relative->email_verified_at);
        $this->assertTrue(Hash::check('a-very-long-passphrase', $relative->password));
        $invitation = Invitation::query()->sole();
        $this->assertDatabaseHas('family_space_memberships', [
            'family_space_id' => $invitation->family_space_id,
            'user_id' => $relative->id,
            'role' => FamilySpaceRole::Member->value,
            'state' => MembershipState::Active->value,
            'invitation_id' => $invitation->id,
        ]);
        $this->assertSame(InvitationStatus::Accepted, $invitation->status);
        $this->assertNotNull($invitation->accepted_at);
        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $relative->id,
            'action' => 'invitation.accepted',
            'subject_id' => (string) $invitation->id,
        ]);

        $this->postJson('/api/invitations/accept', [
            'claim_token' => $claimToken,
            'name' => 'Duplicate Relative',
            'password' => 'a-very-long-passphrase',
            'password_confirmation' => 'a-very-long-passphrase',
            'timezone' => 'UTC',
        ])->assertUnprocessable();
        $this->assertDatabaseCount('users', 2);
    }

    public function test_invited_email_cannot_be_overridden_during_acceptance(): void
    {
        $owner = User::factory()->create();
        $rawToken = $this->issueInvitation($owner, 'relative@example.test');
        $claimToken = $this->postJson('/api/invitations/exchange', ['token' => $rawToken])
            ->json('data.claim_token');

        $this->postJson('/api/invitations/accept', [
            'claim_token' => $claimToken,
            'name' => 'Invited Relative',
            'email' => 'attacker@example.test',
            'password' => 'a-very-long-passphrase',
            'password_confirmation' => 'a-very-long-passphrase',
            'timezone' => 'UTC',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertDatabaseMissing('users', ['email' => 'attacker@example.test']);
    }

    public function test_existing_account_can_join_and_a_removed_membership_is_reactivated_in_place(): void
    {
        $owner = User::factory()->create();
        $existingUser = User::factory()->create(['email' => 'relative@example.test']);
        $firstToken = $this->issueInvitation($owner, $existingUser->email);
        $firstClaim = $this->postJson('/api/invitations/exchange', ['token' => $firstToken])
            ->json('data.claim_token');

        $this->actingAs(User::factory()->create())
            ->postJson('/api/invitations/accept', ['claim_token' => $firstClaim])
            ->assertUnprocessable();
        $this->actingAs($existingUser)
            ->postJson('/api/invitations/accept', ['claim_token' => $firstClaim])
            ->assertCreated();
        $membership = FamilySpaceMembership::query()
            ->where('user_id', $existingUser->id)
            ->sole();
        $membershipId = $membership->id;
        $familySpaceId = $membership->family_space_id;
        $membership->update([
            'state' => MembershipState::Removed,
            'removed_at' => now(),
            'removed_by' => $owner->id,
        ]);

        $secondToken = $this->issueInvitation($owner, $existingUser->email);
        $secondClaim = $this->postJson('/api/invitations/exchange', ['token' => $secondToken])
            ->json('data.claim_token');
        $this->actingAs($existingUser)
            ->postJson('/api/invitations/accept', ['claim_token' => $secondClaim])
            ->assertCreated();

        $reactivated = FamilySpaceMembership::query()
            ->where('family_space_id', $familySpaceId)
            ->where('user_id', $existingUser->id)
            ->sole();
        $this->assertSame($membershipId, $reactivated->id);
        $this->assertSame(MembershipState::Active, $reactivated->state);
        $this->assertNull($reactivated->removed_at);
        $this->assertNull($reactivated->removed_by);
        $this->assertDatabaseHas('audit_events', ['action' => 'family_space.member_reactivated']);
    }

    public function test_expired_short_lived_claim_cannot_create_an_account(): void
    {
        $owner = User::factory()->create();
        $rawToken = $this->issueInvitation($owner, 'relative@example.test');
        $claimToken = $this->postJson('/api/invitations/exchange', ['token' => $rawToken])
            ->json('data.claim_token');
        InvitationClaim::query()->sole()->update(['expires_at' => now()->subSecond()]);

        $this->postJson('/api/invitations/accept', [
            'claim_token' => $claimToken,
            'name' => 'Invited Relative',
            'password' => 'a-very-long-passphrase',
            'password_confirmation' => 'a-very-long-passphrase',
            'timezone' => 'UTC',
        ])->assertUnprocessable();

        $this->assertDatabaseMissing('users', ['email' => 'relative@example.test']);
    }

    public function test_resend_rotates_the_token_on_the_same_invitation(): void
    {
        $owner = User::factory()->create();
        $oldToken = $this->issueInvitation($owner, 'relative@example.test');
        $invitation = Invitation::query()->sole();
        $newToken = null;

        Notification::fake();
        $this->actingAs($owner)
            ->postJson("/api/invitations/{$invitation->id}/resend")
            ->assertOk();
        Notification::assertSentOnDemand(
            InvitationIssued::class,
            function (InvitationIssued $notification) use (&$newToken): bool {
                $newToken = $this->tokenFromUrl($notification->acceptUrl);

                return true;
            },
        );

        $this->assertSame($invitation->id, Invitation::query()->sole()->id);
        $this->assertNotSame($oldToken, $newToken);
        $this->postJson('/api/invitations/exchange', ['token' => $oldToken])->assertUnprocessable();
        $this->postJson('/api/invitations/exchange', ['token' => $newToken])->assertOk();
        $this->assertDatabaseHas('audit_events', ['action' => 'invitation.resent']);
    }

    public function test_revoked_invitation_and_claim_are_rejected(): void
    {
        $owner = User::factory()->create();
        $rawToken = $this->issueInvitation($owner, 'relative@example.test');
        $invitation = Invitation::query()->sole();
        $claimToken = $this->postJson('/api/invitations/exchange', ['token' => $rawToken])
            ->json('data.claim_token');

        $this->actingAs($owner)
            ->postJson("/api/invitations/{$invitation->id}/revoke")
            ->assertOk()
            ->assertJsonPath('data.status', 'revoked');

        $this->assertDatabaseCount('invitation_claims', 0);
        $this->postJson('/api/invitations/accept', [
            'claim_token' => $claimToken,
            'name' => 'Invited Relative',
            'password' => 'a-very-long-passphrase',
            'password_confirmation' => 'a-very-long-passphrase',
            'timezone' => 'UTC',
        ])->assertUnprocessable();
        $this->assertDatabaseHas('audit_events', ['action' => 'invitation.revoked']);
    }

    public function test_expired_invitation_is_persisted_and_audited_when_touched(): void
    {
        $rawToken = str_repeat('x', 64);
        $invitation = Invitation::factory()->create([
            'token_hash' => hash('sha256', $rawToken),
            'expires_at' => now()->subMinute(),
        ]);

        $this->postJson('/api/invitations/exchange', ['token' => $rawToken])
            ->assertUnprocessable();

        $this->assertSame(InvitationStatus::Expired, $invitation->refresh()->status);
        $this->assertNull($invitation->token_hash);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'invitation.expired',
            'subject_id' => (string) $invitation->id,
        ]);
    }

    public function test_active_member_and_duplicate_pending_invitation_are_rejected(): void
    {
        $owner = User::factory()->create();
        $familySpace = $this->ownedFamilySpace($owner);
        $member = User::factory()->create(['email' => 'member@example.test']);
        FamilySpaceMembership::factory()->create([
            'family_space_id' => $familySpace->id,
            'user_id' => $member->id,
        ]);

        $this->actingAs($owner)
            ->postJson('/api/invitations', [
                'family_space_id' => $familySpace->id,
                'email' => 'member@example.test',
                'role' => FamilySpaceRole::Member->value,
            ])
            ->assertUnprocessable();

        $this->issueInvitation($owner, 'relative@example.test');
        $this->actingAs($owner)
            ->postJson('/api/invitations', [
                'family_space_id' => $familySpace->id,
                'email' => 'relative@example.test',
                'role' => FamilySpaceRole::Member->value,
            ])
            ->assertUnprocessable();
        $this->assertDatabaseCount('invitations', 1);
    }

    private function issueInvitation(User $owner, string $email): string
    {
        Notification::fake();
        $rawToken = null;
        $familySpace = $this->ownedFamilySpace($owner);

        $this->actingAs($owner)
            ->withHeader('User-Agent', 'Fambam invitation test')
            ->postJson('/api/invitations', [
                'family_space_id' => $familySpace->id,
                'email' => $email,
                'role' => FamilySpaceRole::Member->value,
            ])
            ->assertCreated();

        Notification::assertSentOnDemand(
            InvitationIssued::class,
            function (
                InvitationIssued $notification,
                array $channels,
                AnonymousNotifiable $notifiable,
            ) use (&$rawToken, $email): bool {
                $rawToken = $this->tokenFromUrl($notification->acceptUrl);

                return $channels === ['mail']
                    && $notifiable->routes['mail'] === strtolower($email)
                    && str_contains($notification->acceptUrl, '#token=')
                    && parse_url($notification->acceptUrl, PHP_URL_QUERY) === null;
            },
        );

        $this->assertIsString($rawToken);

        return $rawToken;
    }

    private function ownedFamilySpace(User $owner): FamilySpace
    {
        $existing = FamilySpace::query()
            ->whereHas('memberships', fn ($query) => $query
                ->where('user_id', $owner->id)
                ->where('role', FamilySpaceRole::Owner->value)
                ->where('state', MembershipState::Active->value))
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $familySpace = FamilySpace::factory()->create();
        FamilySpaceMembership::factory()->create([
            'family_space_id' => $familySpace->id,
            'user_id' => $owner->id,
            'role' => FamilySpaceRole::Owner,
            'state' => MembershipState::Active,
        ]);

        return $familySpace;
    }

    private function tokenFromUrl(string $url): string
    {
        $fragment = parse_url($url, PHP_URL_FRAGMENT);
        parse_str(is_string($fragment) ? $fragment : '', $parameters);

        return (string) ($parameters['token'] ?? '');
    }
}
