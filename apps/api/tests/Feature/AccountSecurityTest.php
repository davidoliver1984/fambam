<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\User;
use App\Services\AuthenticateUser;
use App\Services\PwnedPasswordVerifier;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;
use PragmaRX\Google2FA\Google2FA;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Stringable;
use Tests\TestCase;

class AccountSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_reset_enforces_the_shared_password_policy(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);

        $this->postJson('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'too-short',
            'password_confirmation' => 'too-short',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_argon2id_is_the_default_and_distinguishes_passwords_after_byte_72(): void
    {
        $this->assertContains('argon2id', password_algos());
        $this->assertSame('argon2id', config('hashing.driver'));

        $password = str_repeat('a', 72).'first-distinct-suffix';
        $differentPassword = str_repeat('a', 72).'second-distinct-suffix';
        $user = User::factory()->create(['password' => Hash::make($password)]);

        $this->assertStringStartsWith('$argon2id$', $user->password);
        $this->postJson('/login', [
            'email' => $user->email,
            'password' => $differentPassword,
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertGuest();
    }

    public function test_existing_bcrypt_password_is_verified_and_rehashed_to_argon2id_on_login(): void
    {
        $password = 'legacy-bcrypt-passphrase';
        $bcryptHash = Hash::driver('bcrypt')->make($password);
        $this->assertTrue(Hash::check($password, $bcryptHash));
        $user = User::factory()->create();
        DB::table('users')->where('id', $user->id)->update(['password' => $bcryptHash]);
        $user->refresh();

        $this->postJson('/login', [
            'email' => $user->email,
            'password' => $password,
        ])->assertOk();

        $this->assertStringStartsWith('$argon2id$', $user->refresh()->password);
        $this->assertTrue(Hash::check($password, $user->password));
    }

    public function test_login_path_verifies_a_hash_for_unknown_revoked_and_wrong_password_states(): void
    {
        $dummyHash = (string) config('account-security.dummy_password_hash');
        $unknownHasher = $this->createMock(Hasher::class);
        $unknownHasher->expects($this->once())
            ->method('check')
            ->with('attempted-password', $dummyHash)
            ->willReturn(false);
        $this->assertNull((new AuthenticateUser($unknownHasher, $dummyHash))->attempt(
            'missing@example.test',
            'attempted-password',
        ));

        $revoked = User::factory()->create(['revoked_at' => now()]);
        $revokedHasher = $this->createMock(Hasher::class);
        $revokedHasher->expects($this->once())
            ->method('check')
            ->with('attempted-password', $revoked->password)
            ->willReturn(true);
        $this->assertNull((new AuthenticateUser($revokedHasher, $dummyHash))->attempt(
            $revoked->email,
            'attempted-password',
        ));

        $active = User::factory()->create();
        $wrongPasswordHasher = $this->createMock(Hasher::class);
        $wrongPasswordHasher->expects($this->once())
            ->method('check')
            ->with('attempted-password', $active->password)
            ->willReturn(false);
        $this->assertNull((new AuthenticateUser($wrongPasswordHasher, $dummyHash))->attempt(
            $active->email,
            'attempted-password',
        ));
    }

    public function test_compromised_password_verifier_uses_the_accepted_short_timeout(): void
    {
        $verifier = app(UncompromisedVerifier::class);
        $timeout = (new \ReflectionProperty(PwnedPasswordVerifier::class, 'timeoutSeconds'))
            ->getValue($verifier);

        $this->assertInstanceOf(PwnedPasswordVerifier::class, $verifier);
        $this->assertSame(1.5, $timeout);
    }

    public function test_compromised_password_is_rejected_with_padded_range_request(): void
    {
        config(['account-security.compromised_password_check.enabled' => true]);
        $password = 'known-compromised-passphrase';
        $hash = strtoupper(sha1($password));
        $user = User::factory()->create();
        $token = Password::createToken($user);
        $http = app(Factory::class);
        $http->fake([
            'api.pwnedpasswords.com/*' => Http::response(substr($hash, 5).':42'),
        ]);

        $this->postJson('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => $password,
            'password_confirmation' => $password,
        ])->assertUnprocessable()->assertJsonValidationErrors('password');

        $http->assertSent(fn ($request): bool => $request->hasHeader('Add-Padding')
            && $request->url() === 'https://api.pwnedpasswords.com/range/'.substr($hash, 0, 5));
    }

    public function test_compromised_password_timeout_fails_open_without_logging_password_material(): void
    {
        config(['account-security.compromised_password_check.enabled' => true]);
        $password = 'private-timeout-passphrase';
        $hash = strtoupper(sha1($password));
        $prefix = substr($hash, 0, 5);
        $user = User::factory()->create();
        $token = Password::createToken($user);
        app(Factory::class)->fake(fn () => throw new ConnectionException(
            "Timeout requesting https://api.pwnedpasswords.com/range/{$prefix} for {$password} {$hash}",
        ));
        $logger = new RecordingSecurityLogger;
        app()->instance(LoggerInterface::class, $logger);

        $this->postJson('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => $password,
            'password_confirmation' => $password,
        ])->assertOk();

        $this->assertTrue(Hash::check($password, $user->refresh()->password));
        $this->assertSame([[
            'level' => 'warning',
            'message' => 'Compromised-password screening could not be completed.',
            'context' => ['provider' => 'haveibeenpwned'],
        ]], $logger->records);
        $logged = json_encode($logger->records, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($password, $logged);
        $this->assertStringNotContainsString($hash, $logged);
        $this->assertStringNotContainsString($prefix, $logged);
        $this->assertStringNotContainsString('api.pwnedpasswords.com', $logged);
    }

    public function test_compromised_password_remote_failure_fails_open_with_clean_status_log(): void
    {
        config(['account-security.compromised_password_check.enabled' => true]);
        $password = 'private-remote-failure-passphrase';
        $hash = strtoupper(sha1($password));
        $user = User::factory()->create();
        $token = Password::createToken($user);
        app(Factory::class)->fake([
            'api.pwnedpasswords.com/*' => Http::response('Provider unavailable', 503),
        ]);
        $logger = new RecordingSecurityLogger;
        app()->instance(LoggerInterface::class, $logger);

        $this->postJson('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => $password,
            'password_confirmation' => $password,
        ])->assertOk();

        $this->assertTrue(Hash::check($password, $user->refresh()->password));
        $this->assertSame([[
            'level' => 'warning',
            'message' => 'Compromised-password screening could not be completed.',
            'context' => ['provider' => 'haveibeenpwned', 'status' => 503],
        ]], $logger->records);
        $logged = json_encode($logger->records, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($password, $logged);
        $this->assertStringNotContainsString($hash, $logged);
        $this->assertStringNotContainsString(substr($hash, 0, 5), $logged);
        $this->assertStringNotContainsString('api.pwnedpasswords.com', $logged);
    }

    public function test_password_reset_revokes_sessions_and_remember_token(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $rememberToken = $user->remember_token;
        $this->insertSession('owned-session', $user);
        $this->insertSession('other-session', $other);
        $token = Password::createToken($user);

        $this->postJson('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'replacement-passphrase',
            'password_confirmation' => 'replacement-passphrase',
        ])->assertOk();

        $this->assertDatabaseMissing('sessions', ['id' => 'owned-session']);
        $this->assertDatabaseHas('sessions', ['id' => 'other-session']);
        $this->assertNotSame($rememberToken, $user->refresh()->remember_token);
        $this->assertRevocationAudit($user, 'password_reset');
    }

    public function test_user_can_change_password_and_revoke_every_session(): void
    {
        $user = User::factory()->create(['password' => Hash::make('current-password')]);
        $rememberToken = $user->remember_token;
        $this->insertSession('owned-session', $user);

        $this->actingAs($user)->putJson('/api/user/password', [
            'current_password' => 'current-password',
            'password' => 'replacement-passphrase',
            'password_confirmation' => 'replacement-passphrase',
        ])->assertNoContent();

        $this->assertTrue(Hash::check('replacement-passphrase', $user->refresh()->password));
        $this->assertNotSame($rememberToken, $user->remember_token);
        $this->assertDatabaseMissing('sessions', ['id' => 'owned-session']);
        $this->assertRevocationAudit($user, 'password_changed', $user);
    }

    public function test_user_can_explicitly_log_out_everywhere(): void
    {
        $user = User::factory()->create();
        $rememberToken = $user->remember_token;
        $this->insertSession('owned-session', $user);

        $this->actingAs($user)
            ->postJson('/api/user/revoke-sessions')
            ->assertNoContent();

        $this->assertNotSame($rememberToken, $user->refresh()->remember_token);
        $this->assertDatabaseMissing('sessions', ['id' => 'owned-session']);
        $this->assertRevocationAudit($user, 'user_requested', $user);
    }

    public function test_password_reset_requests_are_rate_limited_by_email_and_ip(): void
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/forgot-password', ['email' => 'relative@example.test'])->assertOk();
        }

        $this->postJson('/forgot-password', ['email' => 'relative@example.test'])->assertTooManyRequests();
    }

    public function test_password_reset_token_submissions_are_rate_limited(): void
    {
        $input = [
            'token' => 'invalid-token',
            'email' => 'relative@example.test',
            'password' => 'replacement-passphrase',
            'password_confirmation' => 'replacement-passphrase',
        ];

        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->postJson('/reset-password', $input)->assertUnprocessable();
        }

        $this->postJson('/reset-password', $input)->assertTooManyRequests();
    }

    public function test_two_factor_limiter_falls_back_to_ip_without_a_pending_login(): void
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/two-factor-challenge', ['code' => '000000'])
                ->assertUnprocessable();
        }

        $this->postJson('/two-factor-challenge', ['code' => '000000'])
            ->assertTooManyRequests();
    }

    public function test_operator_revocation_ends_sessions_and_prevents_future_login(): void
    {
        $user = User::factory()->create(['password' => Hash::make('current-password')]);
        $rememberToken = $user->remember_token;
        $this->insertSession('revoked-session', $user);

        $this->artisan('fambam:revoke-user', [
            'email' => $user->email,
            '--operator' => 'ops-review@example.test',
            '--force' => true,
        ])->assertSuccessful();

        $this->assertNotNull($user->refresh()->revoked_at);
        $this->assertNotSame($rememberToken, $user->remember_token);
        $this->assertDatabaseMissing('sessions', ['id' => 'revoked-session']);
        $this->assertRevocationAudit($user, 'operator_revoked', metadata: [
            'actor_type' => 'console_operator',
            'operator_reference' => 'ops-review@example.test',
        ]);
        $this->postJson('/login', [
            'email' => $user->email,
            'password' => 'current-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_user_can_enable_confirm_and_use_optional_totp(): void
    {
        $user = User::factory()->create(['password' => Hash::make('current-password')]);

        $this->actingAs($user)
            ->postJson('/user/confirm-password', ['password' => 'current-password'])
            ->assertSuccessful();
        $this->postJson('/user/two-factor-authentication')->assertSuccessful();

        $user->refresh();
        $this->assertNotNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_confirmed_at);
        $this->getJson('/user/two-factor-qr-code')->assertOk()->assertJsonStructure(['svg']);
        $this->getJson('/user/two-factor-recovery-codes')->assertNotFound();
        $firstGeneration = $this->postJson('/user/two-factor-recovery-codes')
            ->assertOk()
            ->assertJsonCount(8, 'recovery_codes')
            ->json('recovery_codes');
        $this->getJson('/user/two-factor-recovery-codes')->assertNotFound();
        $secondGeneration = $this->postJson('/user/two-factor-recovery-codes')
            ->assertOk()
            ->assertJsonCount(8, 'recovery_codes')
            ->json('recovery_codes');
        $this->assertNotSame($firstGeneration, $secondGeneration);
        $recoveryCode = $secondGeneration[0];

        $secret = decrypt($user->two_factor_secret);
        $code = app(Google2FA::class)->getCurrentOtp($secret);
        $this->postJson('/user/confirmed-two-factor-authentication', ['code' => $code])->assertSuccessful();
        $this->assertNotNull($user->refresh()->two_factor_confirmed_at);

        $this->postJson('/logout')->assertNoContent();
        $this->postJson('/login', [
            'email' => $user->email,
            'password' => 'current-password',
        ])->assertOk()->assertJsonPath('two_factor', true);
        $invalidCode = '000000';
        while (app(Google2FA::class)->verifyKey($secret, $invalidCode)) {
            $invalidCode = str_pad((string) (((int) $invalidCode + 1) % 1_000_000), 6, '0', STR_PAD_LEFT);
        }
        $this->postJson('/two-factor-challenge', ['code' => $invalidCode])->assertUnprocessable();
        $this->postJson('/two-factor-challenge', ['recovery_code' => $recoveryCode])->assertNoContent();
        $this->assertAuthenticatedAs($user);

        $this->postJson('/user/confirm-password', ['password' => 'current-password'])
            ->assertSuccessful();
        $this->deleteJson('/user/two-factor-authentication')->assertSuccessful();
        $this->assertNull($user->refresh()->two_factor_secret);

        $this->assertDatabaseHas('audit_events', ['action' => 'authentication.mfa_confirmed']);
        $this->assertDatabaseHas('audit_events', ['action' => 'authentication.mfa_failed']);
        $this->assertDatabaseHas('audit_events', ['action' => 'authentication.mfa_succeeded']);
        $this->assertDatabaseHas('audit_events', ['action' => 'authentication.mfa_disabled']);
    }

    public function test_recovery_code_regeneration_requires_recent_password_confirmation(): void
    {
        $user = User::factory()->create(['password' => Hash::make('current-password')]);
        $this->actingAs($user)
            ->postJson('/user/confirm-password', ['password' => 'current-password'])
            ->assertSuccessful();
        $this->postJson('/user/two-factor-authentication')->assertSuccessful();

        $this->flushSession();
        $this->actingAs($user)
            ->postJson('/user/two-factor-recovery-codes')
            ->assertStatus(423)
            ->assertJsonMissingPath('recovery_codes');
    }

    public function test_revoked_user_cannot_complete_an_in_flight_two_factor_challenge(): void
    {
        $user = User::factory()->create(['password' => Hash::make('current-password')]);
        $this->actingAs($user)
            ->postJson('/user/confirm-password', ['password' => 'current-password'])
            ->assertSuccessful();
        $this->postJson('/user/two-factor-authentication')->assertSuccessful();
        $user->refresh();
        $secret = decrypt($user->two_factor_secret);
        $recoveryCode = $user->recoveryCodes()[0];
        $confirmationCode = app(Google2FA::class)->getCurrentOtp($secret);
        $this->postJson('/user/confirmed-two-factor-authentication', ['code' => $confirmationCode])
            ->assertSuccessful();
        $this->postJson('/logout')->assertNoContent();

        $pendingLogin = $this->postJson('/login', [
            'email' => $user->email,
            'password' => 'current-password',
        ])->assertOk()->assertJsonPath('two_factor', true);
        $this->assertGuest();
        $pendingLogin->assertSessionHas('login.id', $user->id);

        $this->artisan('fambam:revoke-user', [
            'email' => $user->email,
            '--operator' => 'security-review@example.test',
            '--force' => true,
        ])->assertSuccessful();

        $rejectedChallenge = $this->postJson('/two-factor-challenge', [
            'recovery_code' => $recoveryCode,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('recovery_code');
        $this->assertGuest();
        $rejectedChallenge->assertSessionMissing('login.id');
        $this->assertDatabaseMissing('audit_events', [
            'action' => 'authentication.mfa_succeeded',
            'subject_id' => (string) $user->id,
        ]);
    }

    /** @param array<string, mixed> $metadata */
    private function assertRevocationAudit(
        User $subject,
        string $cause,
        ?User $actor = null,
        array $metadata = [],
    ): void {
        $event = AuditEvent::query()
            ->where('action', 'account.access_revoked')
            ->where('subject_id', (string) $subject->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame($actor?->id, $event->actor_user_id);
        $this->assertSame(['cause' => $cause] + $metadata, $event->metadata);
    }

    private function insertSession(string $id, User $user): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'AccountSecurityTest',
            'payload' => 'test-payload',
            'last_activity' => now()->timestamp,
        ]);
    }
}

final class RecordingSecurityLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /** @param array<string, mixed> $context */
    public function log($level, Stringable|string $message, array $context = []): void
    {
        $this->records[] = [
            'level' => $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
